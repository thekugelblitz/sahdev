<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * CronProcessor
 *
 * Batch-analyzes tickets via AI to extract sentiment, urgency, client tone,
 * and a ticket summary. Triggered by the WHMCS CronJob hook.
 */
class CronProcessor
{
    private $settings;
    private $provider     = null;
    private $fallback     = null;
    private $cronPrompt   = null;
    private $systemPrompt = null;

    public function __construct()
    {
        $this->loadSettings();
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * Main cron entry point.
     *
     * When $verbose = false (default, used by the CronJob hook), all exceptions
     * are swallowed so the WHMCS cron never crashes.
     *
     * When $verbose = true (used by the manual "Run Analysis Now" button), a
     * diagnostic array is returned instead so the admin can see what happened.
     *
     * @return array{tickets_found:int, analyzed:int, skipped:int, errors:array<string>}
     */
    public function run(bool $verbose = false): array
    {
        $result = [
            'tickets_found' => 0,
            'analyzed'      => 0,
            'skipped'       => 0,
            'errors'        => [],
        ];

        try {
            $settings = $this->settings;

            if (empty($settings['cron_insights_enabled'])) {
                $result['errors'][] = 'Cron insights are disabled in settings.';
                return $result;
            }

            // Validate provider is available before looping over tickets
            if (!$this->provider) {
                $msg = 'No AI provider could be initialised. Check your provider configuration under AI Providers tab (missing or invalid API key / URL).';
                $result['errors'][] = $msg;
                $this->logError(0, $msg);
                return $result;
            }

            $intervalHours = (int) ($settings['cron_insights_interval_hours'] ?? 6);
            $maxPerRun     = max(1, (int) ($settings['cron_insights_max_per_run'] ?? 20));

            // Build list of statuses to include
            $statuses = $this->getTicketStatuses();
            $result['statuses_checked'] = $statuses;

            // Tickets NOT analyzed within the interval window
            $cutoff          = Carbon::now()->subHours($intervalHours);
            $recentlyAnalyzed = Capsule::table('tblsahdev_sentiment')
                ->where('analyzed_at', '>=', $cutoff)
                ->pluck('ticket_id')
                ->toArray();

            $tickets = Capsule::table('tbltickets')
                ->whereIn('status', $statuses)
                ->whereNotIn('id', $recentlyAnalyzed)
                ->orderBy('lastreply', 'asc') // oldest last-reply first = most urgent
                ->limit($maxPerRun)
                ->pluck('id')
                ->toArray();

            $result['tickets_found'] = count($tickets);

            if (empty($tickets)) {
                $result['errors'][] = 'No eligible tickets found. Either no tickets are in the configured statuses (' . implode(', ', $statuses) . '), or all have been analyzed within the last ' . $intervalHours . ' hour(s).';
                return $result;
            }

            foreach ($tickets as $ticketId) {
                try {
                    $this->analyzeTicket((int) $ticketId);
                    $result['analyzed']++;
                } catch (\Throwable $e) {
                    $msg = "Ticket #{$ticketId}: " . $e->getMessage();
                    $result['errors'][] = $msg;
                    $result['skipped']++;
                    $this->logError($ticketId, $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'Fatal error: ' . $e->getMessage();
            $this->logError(0, 'CronProcessor::run() fatal: ' . $e->getMessage());
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Per-ticket analysis
    // -------------------------------------------------------------------------

    /**
     * Analyze a single ticket and upsert insights into tblsahdev_sentiment.
     * Throws on any failure so the caller can decide how to handle it.
     */
    public function analyzeTicket(int $ticketId): void
    {
        if (!$this->provider) {
            throw new \Exception('No AI provider available for cron analysis.');
        }

        // --- Collect admin reply stats without AI ---
        $adminStats = $this->collectAdminStats($ticketId);

        // --- Build a lightweight context for the AI ---
        $context = $this->buildLightContext($ticketId);

        // --- Call AI ---
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

        $execMs = round((microtime(true) - $startTime) * 1000);

        // --- Parse response ---
        $insights = $this->parseInsights($rawResponse);

        // --- Upsert into tblsahdev_sentiment ---
        $now      = Carbon::now();
        $existing = Capsule::table('tblsahdev_sentiment')
            ->where('ticket_id', $ticketId)
            ->first();

        $data = [
            'score'             => max(1, min(10, (int) ($insights['SENTIMENT_SCORE'] ?? 5))),
            'label'             => $this->sanitizeString($insights['SENTIMENT_LABEL'] ?? 'Neutral', 32),
            'urgency'           => $this->sanitizeString($insights['URGENCY'] ?? 'Medium', 16),
            'client_tone'       => $this->sanitizeString($insights['CLIENT_TONE'] ?? 'Neutral', 64),
            'ticket_summary'    => substr($insights['TICKET_SUMMARY'] ?? '', 0, 65000),
            'admin_reply_count' => $adminStats['admin_reply_count'],
            'last_admin_id'     => $adminStats['last_admin_id'],
            'last_admin_name'   => $adminStats['last_admin_name'],
            'analyzed_at'       => $now,
            'updated_at'        => $now,
        ];

        if ($existing) {
            Capsule::table('tblsahdev_sentiment')
                ->where('ticket_id', $ticketId)
                ->update($data);
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
            // Audit is best-effort; never fail a successful analysis because of logging
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the list of ticket statuses to analyse.
     * Reads from the `cron_insights_statuses` setting (comma-separated) when
     * present; falls back to the two most common WHMCS "needs admin reply"
     * statuses so the feature works out-of-the-box.
     */
    public function getTicketStatuses(): array
    {
        $raw = $this->settings['cron_insights_statuses'] ?? '';
        if (!empty($raw)) {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        // WHMCS default statuses that mean "client replied / needs admin response"
        return ['Customer-Reply', 'Awaiting Reply', 'Open'];
    }

    private function loadSettings(): void
    {
        $this->settings = (array) Capsule::table('tblsahdev_settings')->first();

        if (empty($this->settings)) {
            throw new \Exception('Sahdev settings not found. Please activate the module.');
        }

        // Load prompt template
        $tpl = Capsule::table('tblsahdev_prompt_templates')
            ->where('prompt_key', 'cron_insights')
            ->first();

        $this->cronPrompt   = $tpl ? $tpl->content : $this->defaultCronUserPrompt();
        $this->systemPrompt = $this->defaultCronSystemPrompt();

        // Load primary provider
        $primaryId   = (int) ($this->settings['primary_provider_id'] ?? 1);
        $primaryData = Capsule::table('tblsahdev_providers')->where('id', $primaryId)->first();

        if (!$primaryData) {
            throw new \Exception("Primary AI provider (ID #{$primaryId}) not found in database.");
        }

        $this->settings['model_name']    = $primaryData->model_name;
        $this->settings['api_url']       = $primaryData->api_url ?? '';
        $this->settings['provider_type'] = $primaryData->provider_type;
        $this->settings['api_key']       = !empty($primaryData->api_key) ? decrypt($primaryData->api_key) : '';

        $this->provider = $this->initProvider($primaryData);

        if (!$this->provider) {
            throw new \Exception(
                "Could not initialise AI provider '{$primaryData->name}' (type: {$primaryData->provider_type}). " .
                "Check that the API key and URL are set correctly in the AI Providers tab."
            );
        }

        // Fallback provider (optional — don't throw if missing)
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

    /**
     * Build settings array for the provider call, using cron-specific prompts.
     */
    private function buildProviderSettings(): array
    {
        return array_merge($this->settings, [
            'system_prompt'          => $this->systemPrompt,
            'user_prompt_template'   => $this->cronPrompt,
            'temperature'            => 0.2,
            'max_tokens'             => 1024,
            'quality_scorer_enabled' => 0,
        ]);
    }

    /**
     * Build a lean context array — no images, max 30 messages.
     */
    private function buildLightContext(int $ticketId): array
    {
        $ticket = Capsule::table('tbltickets')
            ->select('id', 'tid', 'did', 'userid', 'contactid', 'name', 'email', 'title', 'message', 'status', 'urgency', 'lastreply', 'date')
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

        $replies = Capsule::table('tblticketreplies')
            ->select('userid', 'adminid', 'message', 'date')
            ->where('tid', $ticketId)
            ->orderBy('id', 'asc')
            ->limit(30)
            ->get();

        foreach ($replies as $reply) {
            $messages[] = [
                'admin'   => !empty($reply->adminid),
                'date'    => $reply->date,
                'message' => $reply->message,
            ];
        }

        return [
            'subject'            => $ticket->title,
            'priority'           => $ticket->urgency,
            'department'         => $department,
            'client_name'        => $clientName,
            'services_summary'   => '',
            'messages'           => $messages,
            'attachments_text'   => '',
            'attachments_images' => [],
        ];
    }

    private function collectAdminStats(int $ticketId): array
    {
        $adminReplies = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->whereNotNull('adminid')
            ->where('adminid', '>', 0)
            ->orderBy('id', 'desc')
            ->get(['adminid']);

        $adminReplyCount = count($adminReplies);
        $lastAdminId     = null;
        $lastAdminName   = null;

        if ($adminReplyCount > 0) {
            $lastAdminId = (int) $adminReplies[0]->adminid;
            $admin = Capsule::table('tbladmins')
                ->select('firstname', 'lastname')
                ->where('id', $lastAdminId)
                ->first();
            if ($admin) {
                $lastAdminName = trim($admin->firstname . ' ' . $admin->lastname) ?: 'Admin #' . $lastAdminId;
            }
        }

        return [
            'admin_reply_count' => $adminReplyCount,
            'last_admin_id'     => $lastAdminId,
            'last_admin_name'   => $lastAdminName,
        ];
    }

    /**
     * Parse the AI response — handles both clean JSON arrays and responses where
     * the JSON is embedded inside a CLIENT_REPLY or text field.
     */
    private function parseInsights(array $rawResponse): array
    {
        // Happy path: provider returned the correct keys at root level
        if (!empty($rawResponse['SENTIMENT_SCORE']) || isset($rawResponse['SENTIMENT_LABEL'])) {
            return $rawResponse;
        }

        // Some providers wrap JSON inside a text / CLIENT_REPLY field
        $text = $rawResponse['CLIENT_REPLY'] ?? $rawResponse['text'] ?? $rawResponse['response'] ?? '';
        if (empty($text)) {
            $text = json_encode($rawResponse);
        }

        // Strip markdown code fences
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/```/', '', $text);

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded) && isset($decoded['SENTIMENT_SCORE'])) {
            return $decoded;
        }

        return [
            'SENTIMENT_SCORE'  => 5,
            'SENTIMENT_LABEL'  => 'Neutral',
            'URGENCY'          => 'Medium',
            'CLIENT_TONE'      => 'Neutral',
            'TICKET_SUMMARY'   => 'Analysis could not be parsed from AI response.',
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
    // Default prompts (used when DB template is not yet seeded)
    // -------------------------------------------------------------------------

    private function defaultCronSystemPrompt(): string
    {
        return "You are a ticket triage specialist. Analyze support ticket conversations and output ONLY a valid JSON object. No extra text, no markdown fences, no explanation.";
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
  "TICKET_SUMMARY": <string: 3-6 sentence plain-text summary of the entire ticket conversation, what the issue is, current status, and what is needed>
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
