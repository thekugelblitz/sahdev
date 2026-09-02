<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/TaskProviderResolver.php';
require_once __DIR__ . '/WhmcsTicketTagHelper.php';
require_once __DIR__ . '/TicketDataExtractor.php';
require_once __DIR__ . '/AutopilotProcessor.php';

/**
 * CronProcessor
 *
 * Batch-analyzes tickets via AI to extract sentiment, urgency, client tone,
 * and a ticket summary.  Triggered by the WHMCS CronJob hook.
 *
 * Re-analysis logic (smart, reply-driven):
 *  - A ticket is SKIPPED if it was already analyzed AND tbltickets.lastreply
 *    has not changed since the last analysis.  No new reply = no re-analysis.
 *  - A ticket IS re-analyzed when a new reply/note is added (lastreply changed)
 *    AND the minimum cooldown (cron_insights_interval_hours) has elapsed since
 *    the last analysis, preventing rapid-fire processing on busy tickets.
 *  - A ticket that has never been analyzed is always queued.
 */
class CronProcessor
{
    private $settings;
    private $provider     = null;
    private $fallback     = null;
    private $cronPrompt        = null;
    private $cronSystemPrompt  = null;

    public function __construct()
    {
        $this->loadSettings();
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * @param bool $verbose  When true (manual "Run Now" button) returns a rich
     *                       diagnostic array.  When false (CronJob hook) all
     *                       exceptions are swallowed to protect the WHMCS cron.
     * @return array{tickets_found:int, analyzed:int, skipped:int, errors:string[]}
     */
    public function run(bool $verbose = false): array
    {
        if (!$verbose) {
            @set_time_limit(600);
            @ignore_user_abort(true);
        }

        $result = [
            'tickets_found'    => 0,
            'analyzed'         => 0,
            'skipped'          => 0,
            'errors'           => [],
            'statuses_checked' => [],
            'autopilot'        => null,
        ];
        $analyzedTicketIds = [];

        try {
            $settings = $this->settings;

            if (empty($settings['cron_insights_enabled'])) {
                $result['errors'][] = 'Cron insights are disabled in settings.';
                return $result;
            }

            if (!$this->provider) {
                $msg = 'No AI provider could be initialised. Check your provider configuration (missing/invalid API key or URL).';
                $result['errors'][] = $msg;
                $this->logError(0, $msg);
                return $result;
            }

            $statuses      = $this->getTicketStatuses();
            $maxPerRun     = max(1, (int) ($settings['cron_insights_max_per_run'] ?? 20));
            $cooldownHours = (int) ($settings['cron_insights_interval_hours'] ?? 6);
            $cooldownCutoff = Carbon::now()->subHours($cooldownHours);

            $result['statuses_checked'] = $statuses;

            // Find tickets that need analysis:
            //  1. Status matches configured list
            //  2. Either never analyzed OR lastreply changed since last analysis
            //     AND minimum cooldown has passed (prevents reprocessing on every
            //     rapid successive reply)
            //
            // We join tblsahdev_sentiment to compare lastreply vs ticket_last_reply_at.

            $tickets = Capsule::table('tbltickets as t')
                ->leftJoin('tblsahdev_sentiment as s', 's.ticket_id', '=', 't.id')
                ->whereIn('t.status', $statuses)
                ->where(function ($q) use ($cooldownCutoff) {
                    $q->whereNull('s.ticket_id') // Never analyzed
                      ->orWhere(function ($inner) {
                          // Bypass cooldown if there is a new reply
                          $inner->whereRaw('t.lastreply > s.ticket_last_reply_at');
                      })
                      ->orWhere('s.analyzed_at', '<', $cooldownCutoff); // Stale analysis
                })
                ->orderBy('t.lastreply', 'asc')
                ->limit($maxPerRun)
                ->pluck('t.id')
                ->toArray();

            $result['tickets_found'] = count($tickets);

            if (empty($tickets)) {
                $result['errors'][] =
                    'No tickets needed analysis. Either no tickets have the configured statuses (' .
                    implode(', ', $statuses) . '), or all analyzed tickets have no new replies since the last run.';
                return $result;
            }

            foreach ($tickets as $ticketId) {
                try {
                    $this->analyzeTicket((int) $ticketId);
                    $result['analyzed']++;
                    $analyzedTicketIds[] = (int) $ticketId;
                } catch (\Throwable $e) {
                    $msg = "Ticket #{$ticketId}: " . $e->getMessage();
                    $result['errors'][] = $msg;
                    $result['skipped']++;
                    $this->logError($ticketId, $e->getMessage());
                }
            }

            // Autopilot: attempt auto-replies for tickets just analyzed
            try {
                $autopilot = new AutopilotProcessor($this->settings, $this->provider, $this->fallback);
                $result['autopilot'] = $autopilot->run($analyzedTicketIds, $verbose);
            } catch (\Throwable $e) {
                $result['autopilot'] = ['enabled' => false, 'errors' => ['Autopilot fatal: ' . $e->getMessage()]];
            }

            // Proactive Server Telemetry & Incident Cluster Evaluation
            try {
                require_once __DIR__ . '/ServerTelemetryService.php';
                require_once __DIR__ . '/IncidentDetectionService.php';
                ServerTelemetryService::pollActiveServers(false);
                IncidentDetectionService::evaluateClusters();
            } catch (\Throwable $e) {
                // Background telemetry/incident detection is best-effort and must never fail cron
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'Fatal error: ' . $e->getMessage();
            $this->logError(0, 'CronProcessor::run() fatal: ' . $e->getMessage());
        } finally {
            if (!$verbose) {
                $this->persistInsightsCronRun($result);
            }
        }

        return $result;
    }

    /**
     * Records the outcome of the automatic WHMCS CronJob run so admins can verify
     * batch processing in Ticket Insights.
     */
    private function persistInsightsCronRun(array $result): void
    {
        try {
            $found    = (int) ($result['tickets_found'] ?? 0);
            $analyzed = (int) ($result['analyzed'] ?? 0);
            $skipped  = (int) ($result['skipped'] ?? 0);
            $errCount = count($result['errors'] ?? []);

            if ($found === 0 && $analyzed === 0) {
                $msg = ($errCount > 0)
                    ? implode(' ', array_slice($result['errors'], 0, 2))
                    : 'No tickets in queue (all up to date or no matching statuses).';
            } else {
                $msg = sprintf(
                    'Batch: %d in queue, %d analyzed successfully, %d failed.',
                    $found,
                    $analyzed,
                    $skipped
                );
            }

            Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'insights_cron_last_run_at'   => Carbon::now(),
                'insights_cron_last_found'    => $found,
                'insights_cron_last_analyzed'  => $analyzed,
                'insights_cron_last_skipped'   => $skipped,
                'insights_cron_last_message'   => substr($msg, 0, 500),
                'updated_at'                   => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Never break WHMCS cron
        }
    }

    // -------------------------------------------------------------------------
    // Per-ticket analysis
    // -------------------------------------------------------------------------

    public function analyzeTicket(int $ticketId): void
    {
        if (!$this->provider) {
            throw new \Exception('No AI provider available for cron analysis.');
        }

        $adminStats = $this->collectAdminStats($ticketId);
        $context    = $this->buildLightContext($ticketId);

        // Capture the ticket's current lastreply so we can store it
        $ticketLastReply = Capsule::table('tbltickets')
            ->where('id', $ticketId)
            ->value('lastreply');

        $settingsForProvider = $this->buildProviderSettings();
        $startTime = microtime(true);

        try {
            $rawResponse = $this->provider->generateResponse(
                $context,
                $settingsForProvider,
                'Neutral',
                ''
            );
        } catch (\Exception $primaryErr) {
            if ($this->fallback) {
                $fallbackSettings = $settingsForProvider;
                $fallbackSettings['model_name'] = $this->settings['fallback_model_name'] ?? $settingsForProvider['model_name'];
                $rawResponse = $this->fallback->generateResponse(
                    $context,
                    $fallbackSettings,
                    'Neutral',
                    ''
                );
            } else {
                throw $primaryErr;
            }
        }

        $execMs  = round((microtime(true) - $startTime) * 1000);
        $insights = $this->parseInsights($rawResponse);

        $tagList = WhmcsTicketTagHelper::normalizeTagList($insights['TAGS'] ?? []);

        $now      = Carbon::now();
        $existing = Capsule::table('tblsahdev_sentiment')->where('ticket_id', $ticketId)->first();

        $data = [
            'score'                  => max(1, min(10, (int) ($insights['SENTIMENT_SCORE'] ?? 5))),
            'label'                  => $this->sanitizeString($insights['SENTIMENT_LABEL'] ?? 'Neutral', 32),
            'urgency'                => $this->sanitizeString($insights['URGENCY'] ?? 'Medium', 16),
            'client_tone'            => $this->sanitizeString($insights['CLIENT_TONE'] ?? 'Neutral', 64),
            'ticket_summary'         => substr($insights['TICKET_SUMMARY'] ?? '', 0, 65000),
            'admin_reply_count'      => $adminStats['admin_reply_count'],
            'last_admin_name'        => $adminStats['last_admin_name'],
            'ticket_last_reply_at'   => $ticketLastReply,  // store lastreply snapshot
            'analyzed_at'            => $now,
            'updated_at'             => $now,
        ];
        if (Capsule::schema()->hasTable('tblsahdev_sentiment') && Capsule::schema()->hasColumn('tblsahdev_sentiment', 'ai_tags_json')) {
            $data['ai_tags_json'] = $tagList === [] ? null : json_encode($tagList);
        }

        if ($existing) {
            Capsule::table('tblsahdev_sentiment')->where('ticket_id', $ticketId)->update($data);
        } else {
            $data['ticket_id']  = $ticketId;
            $data['created_at'] = $now;
            Capsule::table('tblsahdev_sentiment')->insert($data);
        }

        // Audit trail (best-effort)
        try {
            Capsule::table('tblsahdev_audit_trail')->insert([
                'ticket_id'         => $ticketId,
                'admin_id'          => 0,
                'action_type'       => 'cron_insights',
                'provider_used'     => $settingsForProvider['provider_type'] ?? 'unknown',
                'prompt_text'       => null,
                'response_text'     => json_encode($insights),
                'tokens_used'       => $this->provider->getLastTokenUsage(),
                'execution_time_ms' => $execMs,
                'created_at'        => $now,
            ]);
        } catch (\Throwable $e) {
            // Best-effort only
        }

        // WHMCS Tag Cloud: replace ai-* tags from last run with fresh AI tags (when enabled)
        if (!empty($this->settings['auto_tagging'])) {
            try {
                WhmcsTicketTagHelper::syncSahdevAiTags($ticketId, $tagList);
            } catch (\Throwable $e) {
                // Never break cron
            }
        }
    }

    // -------------------------------------------------------------------------
    // Queue helper — returns ticket IDs needing analysis (used by manual trigger)
    // -------------------------------------------------------------------------

    /**
     * Returns array of ticket IDs that need (re-)analysis, using the same
     * logic as run() but without processing them.
     */
    public function getQueue(): array
    {
        $settings      = $this->settings;
        $statuses      = $this->getTicketStatuses();
        $cooldownHours = (int) ($settings['cron_insights_interval_hours'] ?? 6);
        $maxPerRun     = max(1, (int) ($settings['cron_insights_max_per_run'] ?? 20));
        $cooldownCutoff = Carbon::now()->subHours($cooldownHours);

        return Capsule::table('tbltickets as t')
            ->leftJoin('tblsahdev_sentiment as s', 's.ticket_id', '=', 't.id')
            ->whereIn('t.status', $statuses)
            ->where(function ($q) use ($cooldownCutoff) {
                $q->whereNull('s.ticket_id')
                  ->orWhere(function ($inner) {
                      $inner->whereRaw('t.lastreply > s.ticket_last_reply_at');
                  })
                  ->orWhere('s.analyzed_at', '<', $cooldownCutoff);
            })
            ->orderBy('t.lastreply', 'asc')
            ->limit($maxPerRun)
            ->pluck('t.id')
            ->toArray();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function getTicketStatuses(): array
    {
        $raw = $this->settings['cron_insights_statuses'] ?? '';
        if (!empty($raw)) {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        return ['Customer-Reply', 'Awaiting Reply', 'Open'];
    }

    private function loadSettings(): void
    {
        $this->settings = (array) Capsule::table('tblsahdev_settings')->first();

        if (empty($this->settings)) {
            throw new \Exception('Sahdev settings not found. Please activate the module.');
        }

        $tplUser = Capsule::table('tblsahdev_prompt_templates')
            ->where('prompt_key', 'cron_insights')
            ->first();
        $tplSys  = Capsule::table('tblsahdev_prompt_templates')
            ->where('prompt_key', 'cron_insights_system')
            ->first();

        $this->cronPrompt       = $tplUser ? $tplUser->content : $this->defaultCronUserPrompt();
        $this->cronSystemPrompt = $tplSys ? $tplSys->content : $this->defaultCronSystemPrompt();

        $primaryId   = TaskProviderResolver::resolveProviderId(TaskProviderResolver::TASK_CRON_INSIGHTS, null, $this->settings);
        $primaryData = Capsule::table('tblsahdev_providers')->where('id', $primaryId)->first();

        if (!$primaryData) {
            throw new \Exception("Primary AI provider (ID #{$primaryId}) not found.");
        }

        $this->settings['model_name']    = $primaryData->model_name;
        $this->settings['api_url']       = $primaryData->api_url ?? '';
        $this->settings['provider_type'] = $primaryData->provider_type;
        $this->settings['api_key']       = !empty($primaryData->api_key) ? decrypt($primaryData->api_key) : '';

        $this->provider = $this->initProvider($primaryData);

        if (!$this->provider) {
            throw new \Exception(
                "Could not initialise provider '{$primaryData->name}' ({$primaryData->provider_type}). " .
                "Check API key and URL in the AI Providers tab."
            );
        }

        $fallbackId = (int) ($this->settings['fallback_provider_id'] ?? 0);
        if ($fallbackId > 0 && $fallbackId !== $primaryId) {
            $fallbackData = Capsule::table('tblsahdev_providers')->where('id', $fallbackId)->first();
            if ($fallbackData) {
                $this->fallback = $this->initProvider($fallbackData);
                $this->settings['fallback_model_name'] = $fallbackData->model_name;
            }
        }
    }

    private function initProvider($providerData)
    {
        if (!$providerData) return null;

        switch ($providerData->provider_type) {
            case 'google':
                $key = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
                if (empty($key)) return null;
                return new GoogleAIProvider($key);

            case 'lmstudio':
                if (empty($providerData->api_url)) return null;
                $key = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
                return new LMStudioAIProvider($providerData->api_url, $key);

            case 'replicate':
                if (empty($providerData->api_url)) return null;
                $key = !empty($providerData->api_key) ? decrypt($providerData->api_key) : '';
                if (empty($key)) return null;
                return new ReplicateAIProvider($providerData->api_url, $key);
        }

        return null;
    }

    private function buildProviderSettings(): array
    {
        return array_merge($this->settings, [
            'system_prompt'          => $this->cronSystemPrompt,
            'user_prompt_template'   => $this->cronPrompt,
            'temperature'            => 0.38,
            // Respect global Sahdev max_tokens. If unavailable, use configurable
            // fallback with a minimum standard of 4096.
            'max_tokens'             => ((int) ($this->settings['max_tokens'] ?? 0)) > 0
                ? (int) $this->settings['max_tokens']
                : max(4096, (int) ($this->settings['max_tokens_fallback'] ?? 4096)),
            'quality_scorer_enabled' => 0,
        ]);
    }

    private function buildLightContext(int $ticketId): array
    {
        $ticket = Capsule::table('tbltickets')
            ->select('id', 'tid', 'did', 'userid', 'name', 'title', 'message', 'status', 'urgency', 'lastreply', 'date')
            ->where('id', $ticketId)
            ->first();

        if (!$ticket) {
            throw new \Exception("Ticket #{$ticketId} not found.");
        }

        $department = Capsule::table('tblticketdepartments')
            ->where('id', $ticket->did)
            ->value('name') ?? 'General';

        $clientName = $ticket->name ?: 'Unknown Client';
        if ($ticket->userid) {
            $client = Capsule::table('tblclients')
                ->select('firstname', 'lastname')
                ->where('id', $ticket->userid)
                ->first();
            if ($client) {
                $clientName = trim($client->firstname . ' ' . $client->lastname) ?: $clientName;
            }
        }

        $messages   = [];
        $messages[] = [
            'admin'   => false,
            'date'    => $ticket->date,
            'message' => $ticket->message,
        ];

        // Use 'admin' column (WHMCS stores admin username string, not an ID)
        $replies = Capsule::table('tblticketreplies')
            ->select('userid', 'admin', 'message', 'date')
            ->where('tid', $ticketId)
            ->orderBy('id', 'asc')
            ->limit(30)
            ->get();

        foreach ($replies as $reply) {
            $messages[] = [
                'admin'   => !empty($reply->admin),   // non-empty string = admin reply
                'date'    => $reply->date,
                'message' => $reply->message,
            ];
        }

        $scrub = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $globalMax = (int) ($this->settings['context_enrichment_max_chars'] ?? 2500);
        $cronServicesCap = min(800, max(300, $globalMax));

        $servicesSummary = '';
        try {
            $extractor = new TicketDataExtractor($ticketId, null);
            $servicesSummary = $extractor->buildServicesSummaryForTicket($ticket, $scrub, $cronServicesCap);
        } catch (\Throwable $e) {
            $servicesSummary = '';
        }

        return [
            'subject'            => $ticket->title,
            'priority'           => $ticket->urgency,
            'department'         => $department,
            'client_name'        => $clientName,
            'services_summary'   => $servicesSummary,
            'messages'           => $messages,
            'attachments_text'   => '',
            'attachments_images' => [],
        ];
    }

    /**
     * Count admin replies and get last admin name.
     * WHMCS uses the `admin` column (username string) in tblticketreplies —
     * there is NO `adminid` column in that table.
     */
    private function collectAdminStats(int $ticketId): array
    {
        // Fetch all replies where admin username is set (non-empty string)
        $adminReplies = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->whereNotNull('admin')
            ->where('admin', '!=', '')
            ->orderBy('id', 'desc')
            ->pluck('admin')
            ->toArray();

        $adminReplyCount = count($adminReplies);
        $lastAdminName   = $adminReplyCount > 0 ? $adminReplies[0] : null;

        return [
            'admin_reply_count' => $adminReplyCount,
            'last_admin_name'   => $lastAdminName,
        ];
    }

    private function parseInsights(array $rawResponse): array
    {
        if (!empty($rawResponse['SENTIMENT_SCORE']) || isset($rawResponse['SENTIMENT_LABEL'])) {
            if (!isset($rawResponse['TAGS'])) {
                $rawResponse['TAGS'] = [];
            }
            return $rawResponse;
        }

        $text = $rawResponse['CLIENT_REPLY'] ?? $rawResponse['text'] ?? $rawResponse['response'] ?? '';
        if (empty($text)) {
            $text = json_encode($rawResponse);
        }

        $text    = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text    = preg_replace('/```/', '', $text);
        $decoded = json_decode(trim($text), true);

        if (is_array($decoded) && isset($decoded['SENTIMENT_SCORE'])) {
            if (!isset($decoded['TAGS'])) {
                $decoded['TAGS'] = [];
            }
            return $decoded;
        }

        return [
            'SENTIMENT_SCORE'  => 5,
            'SENTIMENT_LABEL'  => 'Neutral',
            'URGENCY'          => 'Medium',
            'CLIENT_TONE'      => 'Neutral',
            'TICKET_SUMMARY'   => 'Analysis could not be parsed from AI response.',
            'TAGS'             => [],
        ];
    }

    private function sanitizeString(?string $value, int $maxLen): string
    {
        if ($value === null) return '';
        return substr(strip_tags($value), 0, $maxLen);
    }

    private function logError(int $ticketId, string $message): void
    {
        try {
            Capsule::table('tblsahdev_audit_trail')->insert([
                'ticket_id'     => $ticketId,
                'admin_id'      => 0,
                'action_type'   => 'cron_insights_error',
                'response_text' => $message,
                'created_at'    => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Swallow — logging must never crash the cron
        }
    }

    // -------------------------------------------------------------------------
    // Default prompts
    // -------------------------------------------------------------------------

    private function defaultCronSystemPrompt(): string
    {
        return "You are a senior support lead triaging hosting and billing tickets for an internal team. Your job is to read the conversation and output one JSON object only — no markdown fences, no preamble, no explanation outside JSON.\n\nHow to write TICKET_SUMMARY:\n- Sound like a real handoff from an experienced tech: concrete, plain language, specific facts (product, error text, deadlines, who is waiting on what).\n- Aim for 3–6 sentences when the ticket has real substance; shorter for trivial threads.\n- Avoid generic AI phrasing: do not use filler such as \"It is important to note\", \"The client is seeking assistance\", \"It appears that\", \"Overall, the conversation indicates\", or hollow signposting.\n- Prefer short, information-dense sentences. If something is unclear, say what is unknown and what would confirm it — do not invent details.\n\nTAGS must align with the substance of the thread (same themes you would mention to a colleague), using only the ai-* slug format described in the user message.\n\nAll JSON keys required by the user message must be present and valid.";
    }

    private function defaultCronUserPrompt(): string
    {
        return <<<'PROMPT'
=== TASK ===
Analyze the support ticket conversation below and output ONLY a valid JSON object exactly matching this schema. No extra text.

=== SCHEMA ===
{
  "SENTIMENT_SCORE": <integer 1-10, where 1=very satisfied/calm and 10=extremely frustrated/angry>,
  "SENTIMENT_LABEL": <"Satisfied" | "Neutral" | "Frustrated" | "Angry">,
  "URGENCY": <"Low" | "Medium" | "High" | "Critical">,
  "CLIENT_TONE": <one of: "Polite", "Neutral", "Impatient", "Demanding", "Angry", "Threatening", "Confused", "Appreciative">,
  "TICKET_SUMMARY": <string: 3-6 sentence plain-text summary of the entire ticket conversation, what the issue is, current status, and what is needed>,
  "TAGS": <JSON array of 2-6 short topic slugs for the WHMCS Tag Cloud. Rules: each string must start with the prefix ai- (examples: ai-billing, ai-ssl, ai-dns, ai-outage, ai-email, ai-abuse); after ai- use only lowercase letters, digits, and hyphens; no spaces; pick themes that match this ticket (product area, failure type, billing, security, abuse, email, DNS, etc.)>
}

=== URGENCY GUIDE ===
Critical = service is completely down or data is at risk
High = major disruption, client explicitly escalating or threatening to leave
Medium = functional issue affecting daily operations
Low = informational question or minor inconvenience

=== TICKET DATA ===
Client: {{CLIENT_NAME}}
Department: {{DEPARTMENT}}
Subject: {{SUBJECT}}

=== CONVERSATION ===
{{MESSAGES}}
PROMPT;
    }
}
