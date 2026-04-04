<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * CronProcessor
 *
 * Batch-analyzes all "Awaiting Reply" tickets via AI to extract
 * sentiment, urgency, client tone, and a ticket summary.
 * Triggered by the WHMCS CronJob hook.
 */
class CronProcessor
{
    private $settings;
    private $provider    = null;
    private $fallback    = null;
    private $cronPrompt  = null;
    private $systemPrompt = null;

    /** JSON schema the AI must return for cron insights. */
    const REQUIRED_KEYS = ['SENTIMENT_SCORE', 'SENTIMENT_LABEL', 'URGENCY', 'CLIENT_TONE', 'TICKET_SUMMARY'];

    public function __construct()
    {
        $this->loadSettings();
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * Main cron entry.  Called from the CronJob hook.
     * Silently swallows all exceptions so the WHMCS cron never crashes.
     */
    public function run(): void
    {
        try {
            $settings = $this->settings;

            if (empty($settings['cron_insights_enabled'])) {
                return;
            }

            $intervalHours = (int) ($settings['cron_insights_interval_hours'] ?? 6);
            $maxPerRun     = max(1, (int) ($settings['cron_insights_max_per_run'] ?? 20));

            // Tickets in "Awaiting Reply" status
            $cutoff = Carbon::now()->subHours($intervalHours);

            $tickets = Capsule::table('tbltickets')
                ->where('status', 'Awaiting Reply')
                ->whereNotExists(function ($query) use ($cutoff) {
                    $query->from('tblsahdev_sentiment')
                          ->whereColumn('tblsahdev_sentiment.ticket_id', 'tbltickets.id')
                          ->where(function ($q) use ($cutoff) {
                              $q->whereNull('tblsahdev_sentiment.analyzed_at')
                                ->orWhere('tblsahdev_sentiment.analyzed_at', '<', $cutoff);
                          })
                          ->whereNotNull('tblsahdev_sentiment.analyzed_at')
                          // Invert: we WANT tickets NOT analyzed recently, so use a presence sub-select differently
                    ;
                })
                ->limit($maxPerRun)
                ->pluck('id')
                ->toArray();

            // Simpler approach: get tickets analyzed longer ago than the interval (or never analyzed)
            $recentlyAnalyzed = Capsule::table('tblsahdev_sentiment')
                ->where('analyzed_at', '>=', $cutoff)
                ->pluck('ticket_id')
                ->toArray();

            $tickets = Capsule::table('tbltickets')
                ->where('status', 'Awaiting Reply')
                ->whereNotIn('id', $recentlyAnalyzed)
                ->orderBy('lastreply', 'asc') // oldest-last-reply first = most urgent
                ->limit($maxPerRun)
                ->pluck('id')
                ->toArray();

            foreach ($tickets as $ticketId) {
                try {
                    $this->analyzeTicket((int) $ticketId);
                } catch (\Throwable $e) {
                    // Log but continue with next ticket
                    $this->logError($ticketId, $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->logError(0, 'CronProcessor::run() fatal: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Per-ticket analysis
    // -------------------------------------------------------------------------

    /**
     * Analyze a single ticket and upsert insights into tblsahdev_sentiment.
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
                'Neutral',       // tone irrelevant for analysis-only prompt
                ''               // no extra instruction
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
        $now = Carbon::now();
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
            $data['ticket_id']   = $ticketId;
            $data['created_at']  = $now;
            Capsule::table('tblsahdev_sentiment')->insert($data);
        }

        // Log to audit trail (lightweight)
        try {
            Capsule::table('tblsahdev_audit_trail')->insert([
                'ticket_id'      => $ticketId,
                'admin_id'       => 0, // cron has no admin
                'action_type'    => 'cron_insights',
                'provider_used'  => $settingsForProvider['provider_type'] ?? 'unknown',
                'prompt_text'    => null,
                'response_text'  => json_encode($insights),
                'tokens_used'    => $this->provider->getLastTokenUsage(),
                'execution_time_ms' => $execMs,
                'created_at'     => $now,
            ]);
        } catch (\Throwable $e) {
            // Audit is best-effort
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loadSettings(): void
    {
        $this->settings = (array) Capsule::table('tblsahdev_settings')->first();

        if (empty($this->settings)) {
            throw new \Exception('Sahdev settings not found.');
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
        if ($primaryData) {
            $this->settings['model_name']    = $primaryData->model_name;
            $this->settings['api_url']       = $primaryData->api_url ?? '';
            $this->settings['provider_type'] = $primaryData->provider_type;
            $this->settings['api_key']       = !empty($primaryData->api_key) ? decrypt($primaryData->api_key) : '';
            $this->provider = $this->initProvider($primaryData);
        }

        // Load fallback provider
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
     * Build a settings array specifically for the cron provider call,
     * overriding the system & user prompt with our cron-specific ones.
     */
    private function buildProviderSettings(): array
    {
        return array_merge($this->settings, [
            'system_prompt'       => $this->systemPrompt,
            'user_prompt_template' => $this->cronPrompt,
            'temperature'         => 0.2,  // low temperature for consistent JSON
            'max_tokens'          => 1024,
            // Disable quality scorer injection for cron
            'quality_scorer_enabled' => 0,
        ]);
    }

    /**
     * Build a lean context array (no images, no heavy attachments).
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

        // Messages: opening message + all replies (max 30 to cap tokens)
        $messages = [];
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
            $isAdmin = !empty($reply->adminid);
            $messages[] = [
                'admin'   => $isAdmin,
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

    /**
     * Gather admin reply statistics directly from DB (no AI).
     */
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
     * Parse the AI response into the expected insights array.
     * Handles both raw JSON and JSON embedded in markdown code blocks.
     */
    private function parseInsights(array $rawResponse): array
    {
        // The provider returns a decoded array. For cron_insights the keys are at root level.
        $hasRequired = !empty($rawResponse['SENTIMENT_SCORE']) || !empty($rawResponse['SENTIMENT_LABEL']);

        if ($hasRequired) {
            return $rawResponse;
        }

        // Sometimes providers wrap in a text field — try to extract JSON from text
        $text = $rawResponse['CLIENT_REPLY'] ?? $rawResponse['text'] ?? $rawResponse['response'] ?? '';
        if (empty($text)) {
            $text = json_encode($rawResponse);
        }

        // Strip markdown code fences
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/```/', '', $text);

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Return a safe fallback
        return [
            'SENTIMENT_SCORE'  => 5,
            'SENTIMENT_LABEL'  => 'Neutral',
            'URGENCY'          => 'Medium',
            'CLIENT_TONE'      => 'Neutral',
            'TICKET_SUMMARY'   => 'Analysis could not be parsed.',
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
                'ticket_id'    => $ticketId,
                'admin_id'     => 0,
                'action_type'  => 'cron_insights_error',
                'response_text' => $message,
                'created_at'   => Carbon::now(),
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
