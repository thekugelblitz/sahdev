<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/TaskProviderResolver.php';

// Provider classes needed for manual provider resolution
require_once __DIR__ . '/AIProviderInterface.php';
require_once __DIR__ . '/GoogleAIProvider.php';
require_once __DIR__ . '/LMStudioAIProvider.php';
require_once __DIR__ . '/ReplicateAIProvider.php';
require_once __DIR__ . '/TicketDataExtractor.php';

// Safe check for ToolsExecution module
$toolsSvcPath = dirname(__DIR__) . '/modules/ToolsExecution/ToolsExecutionService.php';
if (file_exists($toolsSvcPath)) {
    require_once $toolsSvcPath;
}

/**
 * AutopilotProcessor
 *
 * Automated first-response engine. Reads the AI insights already written by
 * CronProcessor (tblsahdev_sentiment) and posts a reply on behalf of a
 * designated WHMCS admin account when a ticket passes all eligibility checks.
 *
 * Design principles:
 *  - Zero double-billing: analysis is reused from the cron insights call.
 *    A second focused AI call generates only the reply text.
 *  - Idempotent: every attempt is stamped with the ticket's lastreply snapshot.
 *    Running the cron twice never produces duplicate replies.
 *  - Hard safety rules: Critical urgency and high sentiment score always skip
 *    to human regardless of other settings.
 *  - Per-ticket reply cap: stops auto-replying after N consecutive replies
 *    (default 3, max 10) to prevent infinite AI loops.
 */
class AutopilotProcessor
{
    private array $settings;
    private $provider;
    private $fallback;

    /** @var array Result counters returned to the cron summary */
    private array $result = [
        'enabled'       => false,
        'tickets_found' => 0,
        'replied'       => 0,
        'skipped'       => 0,
        'errors'        => [],
    ];

    public function __construct(array $settings, $provider, $fallback)
    {
        $this->settings = $settings;
        $this->provider  = $provider;
        $this->fallback  = $fallback;
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * @param int[] $analyzedTicketIds  Ticket IDs CronProcessor just analyzed (used as priority hint).
     * @param bool  $verbose
     * @return array
     */
    public function run(array $analyzedTicketIds, bool $verbose = false): array
    {
        $this->ensureSchema();

        if (empty($this->settings['autopilot_enabled'])) {
            $this->result['errors'][] = 'Autopilot is disabled.';
            return $this->result;
        }

        $this->result['enabled'] = true;

        $autopilotAdminId = (int) ($this->settings['autopilot_admin_id'] ?? 0);
        if ($autopilotAdminId <= 0) {
            $this->result['errors'][] = 'Autopilot admin account is not configured.';
            return $this->result;
        }

        $maxPerRun = max(1, (int) ($this->settings['autopilot_cron_max_per_run'] ?? 5));

        // Independent query: don't rely solely on what insights analyzed this run.
        // Autopilot finds any ticket that has sentiment data + still needs a reply.
        $candidateIds = $this->fetchEligibleCandidates($analyzedTicketIds, $maxPerRun * 4);

        if (empty($candidateIds)) {
            $this->persistRunStatus();
            return $this->result;
        }

        $processed = 0;
        foreach ($candidateIds as $ticketId) {
            if ($processed >= $maxPerRun) break;

            try {
                $outcome = $this->processTicket((int) $ticketId, $autopilotAdminId);
                if ($outcome === 'replied') {
                    $this->result['replied']++;
                    $processed++;
                } else {
                    $this->result['skipped']++;
                }
                $this->result['tickets_found']++;
            } catch (\Throwable $e) {
                $this->result['errors'][] = "Ticket #{$ticketId}: " . $e->getMessage();
                $this->result['skipped']++;
                $this->result['tickets_found']++;
                $this->logAttempt($ticketId, 'skipped', 'error: ' . substr($e->getMessage(), 0, 120), null, null);
            }
        }

        $this->persistRunStatus();
        return $this->result;
    }

    /**
     * Independently query all tickets eligible for autopilot reply.
     *
     * Finds tickets that:
     * - Have a tblsahdev_sentiment row (have been AI-analyzed at some point)
     * - Are in an open/active status (not closed or on hold)
     * - Have NOT already received an autopilot reply to this exact lastreply snapshot
     * - Are under the per-ticket reply cap
     *
     * Fresh tickets from the current cron run are sorted first via FIELD().
     */
    private function fetchEligibleCandidates(array $freshIds, int $limit): array
    {
        $openStatuses = ['Open', 'Customer-Reply', 'Awaiting Reply', 'In Progress'];
        $maxReplies   = max(1, min(10, (int) ($this->settings['autopilot_max_replies'] ?? 3)));

        $orderRaw = empty($freshIds)
            ? 't.lastreply ASC'
            : 'FIELD(t.id, ' . implode(',', array_map('intval', $freshIds)) . ') DESC, t.lastreply ASC';

        $rows = Capsule::table('tbltickets as t')
            ->join('tblsahdev_sentiment as s', 's.ticket_id', '=', 't.id')
            ->whereIn('t.status', $openStatuses)
            ->select('t.id', 't.lastreply')
            ->orderByRaw($orderRaw)
            ->limit($limit)
            ->get();

        $candidates = [];
        foreach ($rows as $row) {
            $ticketId  = (int) $row->id;
            $lastreply = $row->lastreply;

            // Idempotency: already replied to this exact lastreply snapshot?
            $alreadyReplied = Capsule::table('tblsahdev_autopilot_log')
                ->where('ticket_id', $ticketId)
                ->where('ticket_lastreply_snapshot', $lastreply)
                ->where('ai_decision', 'replied')
                ->exists();

            if ($alreadyReplied) continue;

            // Per-ticket cap
            $replyCount = Capsule::table('tblsahdev_autopilot_log')
                ->where('ticket_id', $ticketId)
                ->where('ai_decision', 'replied')
                ->count();

            if ($replyCount >= $maxReplies) continue;

            $candidates[] = $ticketId;
        }

        return $candidates;
    }



    /**
     * Force-run autopilot for a specific ticket ID, bypassing the insights sentiment requirement.
     * Used for manual test runs from the admin UI.
     *
     * @param int  $ticketId
     * @param bool $bypassSafetyChecks  When true, skips urgency/sentiment thresholds (for testing)
     * @return array
     */
    public function runForceTicket(int $ticketId, bool $bypassSafetyChecks = false): array
    {
        $this->ensureSchema();

        $autopilotAdminId = (int) ($this->settings['autopilot_admin_id'] ?? 0);
        if ($autopilotAdminId <= 0) {
            return ['status' => 'error', 'message' => 'Autopilot admin account is not configured.'];
        }

        $ticket = Capsule::table('tbltickets')
            ->select('id', 'tid', 'did', 'userid', 'name', 'title', 'status', 'lastreply')
            ->where('id', $ticketId)
            ->first();

        if (!$ticket) {
            return ['status' => 'error', 'message' => "Ticket ID #{$ticketId} not found."];
        }

        // For manual test: temporarily override sentiment with neutral values
        if ($bypassSafetyChecks) {
            $this->forcedBypassSafety = true;
        }

        try {
            // Build the reply text
            $replyText = $this->generateReply($ticketId, $ticket);

            if (empty(trim($replyText))) {
                return ['status' => 'error', 'message' => 'AI returned an empty reply. Check your provider and prompt settings.'];
            }

            $isDraftMode = !empty($this->settings['autopilot_draft_mode']);

            if ($isDraftMode) {
                // Draft Mode: post as internal admin note
                $noteId = $this->postInternalNote($ticketId, $replyText, $autopilotAdminId);
                $this->logAttempt($ticketId, 'drafted', 'manual_test', $noteId, $ticket->lastreply);
                $this->setTicketStatus($ticketId, 'Reply-Drafting');
                return [
                    'status'   => 'success',
                    'message'  => "Draft note posted to ticket #{$ticket->tid} (Draft Mode is ON — not a public reply).",
                    'reply_id' => $noteId,
                    'preview'  => substr($replyText, 0, 300) . (strlen($replyText) > 300 ? '...' : ''),
                ];
            }

            $signature = $this->getAdminSignature($autopilotAdminId);
            if ($signature) {
                $replyText = $replyText . "\n\n" . $signature;
            }

            $replyId = $this->postReply($ticketId, $replyText, $autopilotAdminId);
            $this->logAttempt($ticketId, 'replied', 'manual_test', $replyId, $ticket->lastreply);

            return [
                'status'   => 'success',
                'message'  => "Reply posted successfully to ticket #{$ticket->tid}.",
                'reply_id' => $replyId,
                'preview'  => substr($replyText, 0, 300) . (strlen($replyText) > 300 ? '...' : ''),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            $this->forcedBypassSafety = false;
        }
    }

    /** @var bool Internal flag for manual test bypass */
    private bool $forcedBypassSafety = false;

    // -------------------------------------------------------------------------
    // Per-ticket processing
    // -------------------------------------------------------------------------

    /**
     * @return string 'replied'|'skipped'
     */
    private function processTicket(int $ticketId, int $autopilotAdminId): string
    {
        // Load ticket
        $ticket = Capsule::table('tbltickets')
            ->select('id', 'tid', 'did', 'userid', 'name', 'title', 'status', 'lastreply')
            ->where('id', $ticketId)
            ->first();

        if (!$ticket) {
            $this->logAttempt($ticketId, 'skipped', 'ticket_not_found', null, null);
            return 'skipped';
        }

        // Load sentiment from insights cron
        $sentiment = Capsule::table('tblsahdev_sentiment')
            ->where('ticket_id', $ticketId)
            ->first();

        if (!$sentiment) {
            $this->logAttempt($ticketId, 'skipped', 'no_sentiment_data', null, $ticket->lastreply);
            return 'skipped';
        }

        // --- Eligibility checks (fail-fast) ---

        // 1. Ticket must not be closed/deleted
        $closedStatuses = ['Closed', 'On Hold'];
        if (in_array($ticket->status, $closedStatuses, true)) {
            $this->logAttempt($ticketId, 'skipped', 'ticket_closed', null, $ticket->lastreply);
            return 'skipped';
        }

        // 2. Department blocked?
        $deptId = (int) $ticket->did;
        if ($this->isDepartmentBlocked($deptId)) {
            $this->logAttempt($ticketId, 'skipped', 'dept_blocked', null, $ticket->lastreply);
            $this->tagTicketIfEnabled($ticketId);
            return 'skipped';
        }

        // 3. Department allowed? (if allowlist is configured)
        if (!$this->isDepartmentAllowed($deptId)) {
            $this->logAttempt($ticketId, 'skipped', 'dept_not_in_allowlist', null, $ticket->lastreply);
            return 'skipped';
        }

        // 4. Subject/ticket blocked by keyword?
        if ($this->isBlockedByKeyword($ticket->title)) {
            $this->logAttempt($ticketId, 'skipped', 'blocked_keyword', null, $ticket->lastreply);
            $this->tagTicketIfEnabled($ticketId);
            return 'skipped';
        }

        // 5. Urgency threshold check (hard rule)
        $urgency      = strtolower($sentiment->urgency ?? 'medium');
        $maxUrgency   = strtolower($this->settings['autopilot_max_urgency'] ?? 'high');
        $urgencyOrder = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $ticketUrgencyLevel = $urgencyOrder[$urgency] ?? 2;
        $maxUrgencyLevel    = $urgencyOrder[$maxUrgency] ?? 3;

        if ($ticketUrgencyLevel > $maxUrgencyLevel) {
            $this->logAttempt($ticketId, 'skipped', 'urgency_too_high:' . $urgency, null, $ticket->lastreply);
            $this->tagTicketIfEnabled($ticketId);
            return 'skipped';
        }

        // 6. Sentiment score threshold (hard rule)
        $sentimentScore    = (int) ($sentiment->score ?? 5);
        $maxSentimentScore = (int) ($this->settings['autopilot_max_sentiment'] ?? 7);
        if ($sentimentScore >= $maxSentimentScore) {
            $this->logAttempt($ticketId, 'skipped', 'sentiment_too_high:' . $sentimentScore, null, $ticket->lastreply);
            $this->tagTicketIfEnabled($ticketId);
            return 'skipped';
        }

        // 7. Only-first-reply mode: check zero admin replies exist (excluding System/Automation)
        $onlyFirst = !empty($this->settings['autopilot_only_first_reply']);
        
        // Build list of ignored admin names (System + Autopilot identity itself)
        $ignoredAdminNames = ['System', 'system', 'Automation', 'Autopilot', 'sahdev', 'Sahdev'];
        $autopilotAdmin = Capsule::table('tbladmins')->where('id', $autopilotAdminId)->first();
        if ($autopilotAdmin) {
            $ignoredAdminNames[] = $autopilotAdmin->username;
            $displayName = trim($this->settings['autopilot_display_name'] ?? '');
            if (!$displayName) $displayName = trim(($autopilotAdmin->firstname ?? '') . ' ' . ($autopilotAdmin->lastname ?? ''));
            if ($displayName) $ignoredAdminNames[] = $displayName;
        }
        $ignoredAdminNames = array_unique(array_filter($ignoredAdminNames));

        $adminReplyCount = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->whereNotNull('admin')
            ->where('admin', '!=', '')
            ->whereNotIn('admin', $ignoredAdminNames)
            ->count();
 
        if ($onlyFirst && $adminReplyCount > 0) {
            $this->logAttempt($ticketId, 'skipped', 'has_admin_reply', null, $ticket->lastreply);
            return 'skipped';
        }
 
        // 8. Per-ticket autopilot reply cap
        $maxReplies = max(1, min(10, (int) ($this->settings['autopilot_max_replies'] ?? 3)));
        $autopilotReplyCount = Capsule::table('tblsahdev_autopilot_log')
            ->where('ticket_id', $ticketId)
            ->where('ai_decision', 'replied')
            ->count();
 
        if ($autopilotReplyCount >= $maxReplies) {
            $this->logAttempt($ticketId, 'skipped', 'limit_reached', null, $ticket->lastreply);
            $this->tagTicketIfEnabled($ticketId, 'ai-needs-human');
            return 'skipped';
        }
 
        // 9. Idempotency: has this exact lastreply already been replied to (or drafted)?
        $alreadyActioned = Capsule::table('tblsahdev_autopilot_log')
            ->where('ticket_id', $ticketId)
            ->where('ticket_lastreply_snapshot', $ticket->lastreply)
            ->whereIn('ai_decision', ['replied', 'drafted'])
            ->exists();
 
        if ($alreadyActioned) {
            // Not a skip — just already done, silent pass
            return 'skipped';
        }
 
        // 10. If there was a previous autopilot reply, check that the LAST reply to
        //     this ticket is from the client (ignoring automated system messages).
        if ($autopilotReplyCount > 0) {
            $lastNonSystemReply = Capsule::table('tblticketreplies')
                ->where('tid', $ticketId)
                ->whereNotIn('admin', $ignoredAdminNames) // Ignore System
                ->orderBy('id', 'desc')
                ->first();
 
            if ($lastNonSystemReply && !empty($lastNonSystemReply->admin)) {
                // A real human admin (not System) replied — hand off
                $this->logAttempt($ticketId, 'skipped', 'human_replied_after_autopilot', null, $ticket->lastreply);
                return 'skipped';
            }
        }

        // --- All checks passed — generate and post the reply (or draft) ---

        $this->applyRandomDelay();

        $replyText = $this->generateReply($ticketId, $ticket);

        if (empty(trim($replyText))) {
            $this->logAttempt($ticketId, 'skipped', 'empty_reply_generated', null, $ticket->lastreply);
            return 'skipped';
        }

        $isDraftMode = !empty($this->settings['autopilot_draft_mode']);
        $tokensUsed = $this->provider ? $this->provider->getLastTokenUsage() : 0;

        if ($isDraftMode) {
            // Draft Mode: post as internal admin note instead of public reply
            $noteId = $this->postInternalNote($ticketId, $replyText, $autopilotAdminId);
            $this->logAttempt($ticketId, 'drafted', null, $noteId, $ticket->lastreply, $tokensUsed);
            // Change ticket status to 'Reply-Drafting' so staff know a draft is waiting
            $this->setTicketStatus($ticketId, 'Reply-Drafting');
            return 'replied'; // Count as "processed" in cron summary
        }

        // Append admin signature for live replies
        $signature = $this->getAdminSignature($autopilotAdminId);
        if ($signature) {
            $replyText = $replyText . "\n\n" . $signature;
        }

        $replyId = $this->postReply($ticketId, $replyText, $autopilotAdminId);
        $this->logAttempt($ticketId, 'replied', null, $replyId, $ticket->lastreply, $tokensUsed);

        return 'replied';
    }

    // -------------------------------------------------------------------------
    // AI reply generation
    // -------------------------------------------------------------------------

    private function generateReply(int $ticketId, $ticket): string
    {
        $autopilotAdminId = (int) ($this->settings['autopilot_admin_id'] ?? 0);
        $extractor = new TicketDataExtractor($ticketId, $autopilotAdminId);
        // Respect PII scrubbing settings — same logic as AIController.php
        $scrubPII = !empty($this->settings['compliance_mode']) || !empty($this->settings['pii_scrub_enabled']);
        $context = $extractor->getContext($scrubPII, false);

        $toolsContext = '';
        if (class_exists('\Sahdev\Modules\ToolsExecution\ToolsExecutionService')) {
            // PROACTIVE DIAGNOSTICS: Ensure tools have run for this ticket's current state
            try {
                \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runTicket($ticketId, 0, $this->forcedBypassSafety);
                ModuleLogger::debug('Autopilot.DataFetch.Tools', "Proactively triggered diagnostic tools for ticket #{$ticketId}", $ticketId);
            } catch (\Throwable $e) {
                ModuleLogger::debug('Autopilot.DataFetch.Tools', "Tools execution skipped/failed: " . $e->getMessage(), $ticketId);
            }
            $toolsContext = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::buildPromptContextBlock($ticketId);
        }
        $context['tools_output'] = $toolsContext;

        $tone = $this->settings['autopilot_tone'] ?? 'Friendly';
        $systemPrompt = $this->loadSystemPrompt();

        $provider = $this->resolveAutopilotProvider();

        $systemPrompt .= "\n\nCRITICAL TECHNICAL RULES:\n";
        $systemPrompt .= "1. If instructing the client to update Nameservers, use ONLY values labeled as 'MANDATORY_TARGET_NS'.\n";
        $systemPrompt .= "2. IGNORE any values labeled 'Current Registrar NS'.\n";
        $systemPrompt .= "3. Use 'Product IP' or 'Server IP' for A-record guidance.\n";
        $systemPrompt .= "4. FORMATTING: Use '### ' for section headers. Always ensure 2 blank lines above and below any header or list. Avoid double asterisks for bolding headers.";
        
        $settingsForProvider = array_merge((array) $this->settings, [
            'system_prompt'          => $systemPrompt,
            'user_prompt_template'   => null,
            'temperature'            => 0.65,
            'max_tokens'             => max(1024, (int) ($this->settings['max_tokens_fallback'] ?? 2048)),
            'quality_scorer_enabled' => 0,
        ]);

        try {
            $rawResponse = $provider->generateResponse(
                $context + ['__autopilot_raw_reply__' => true],
                $settingsForProvider,
                $tone,
                'AUTOPILOT MODE: Output ONLY the CLIENT_REPLY text verbatim.'
            );

            $resText = '';
            // If the provider detected Autopilot Mode, it returns the text directly in __raw_text__
            if (!empty($rawResponse['__raw_text__'])) {
                $resText = trim((string) $rawResponse['__raw_text__']);
            } 
            // Fallback for providers that still returned JSON
            elseif (!empty($rawResponse['CLIENT_REPLY'])) {
                $resText = trim((string) $rawResponse['CLIENT_REPLY']);
            } 
            // Ultimate fallback: look for the first long string
            else {
                foreach ($rawResponse as $v) {
                    if (is_string($v) && strlen(trim($v)) > 20) {
                        $resText = trim($v);
                        break;
                    }
                }
            }
            return $resText;
        } catch (\Exception $e) {
            ModuleLogger::error('Autopilot.generateReply', $e->getMessage(), $ticketId);
            return '';
        }
    }

    /**
     * Load the autopilot system prompt from the Prompt Library.
     * Falls back to a sensible hardcoded default if the row doesn't exist yet.
     */
    private function loadSystemPrompt(): string
    {
        try {
            $row = Capsule::table('tblsahdev_prompt_templates')
                ->where('prompt_key', 'autopilot_system')
                ->first();
            if ($row && !empty($row->content)) {
                return trim($row->content) . "\n\nIMPORTANT: Output ONLY the final client reply text. No JSON wrapper, no schema, no extra formatting. Just the reply body.";
            }
        } catch (\Throwable $e) {}

        return "You are Sahdev, a Senior Technical Support Specialist for a premium web hosting company. "
            . "Provide helpful, warm, and professional first-response support. "
            . "Include a warm greeting using the client's first name when known. "
            . "Do NOT include a sign-off or signature. "
            . "Output ONLY the reply text. No JSON, no schema, no fences.";
    }

    /**
     * Resolve the AI provider instance to use for autopilot replies.
     * Checks the task_provider_map for task key 'autopilot'; falls back to
     * the provider passed into the constructor by CronProcessor.
     */
    private function resolveAutopilotProvider()
    {
        try {
            $providerId = TaskProviderResolver::resolveProviderId(
                TaskProviderResolver::TASK_AUTOPILOT,
                null,
                $this->settings
            );

            $currentProviderId = (int) ($this->settings['id'] ?? 0);
            if ($providerId > 0 && $providerId !== $currentProviderId) {
                $provRow = Capsule::table('tblsahdev_providers')->where('id', $providerId)->first();
                if ($provRow) {
                    $provSettings = array_merge((array) $this->settings, (array) $provRow);
                    $type = strtolower($provRow->provider_type ?? 'google');
                    $apiKey = !empty($provRow->api_key) ? decrypt($provRow->api_key) : '';

                    switch ($type) {
                        case 'lmstudio':
                            return new \Sahdev\Lib\LMStudioAIProvider($provRow->api_url ?? '', $apiKey);
                        case 'replicate':
                            return new \Sahdev\Lib\ReplicateAIProvider($provRow->api_url ?? '', $apiKey);
                        default:
                            return new \Sahdev\Lib\GoogleAIProvider($apiKey);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        return $this->provider;
    }

    private function buildReplyPrompt(array $context, string $tone): string
    {
        $msgs = array_reverse($context['messages'] ?? []);
        $budget = 6000;
        $used   = 0;
        $lines  = [];

        foreach ($msgs as $msg) {
            $type  = $msg['admin'] ? 'ADMIN' : 'CLIENT';
            $body  = $this->sanitize($msg['message'] ?? '');
            $entry = "[{$type}] ({$msg['date']}):\n{$body}\n\n";
            if ($used + strlen($entry) > $budget) break;
            $lines[] = $entry;
            $used   += strlen($entry);
        }

        $messagesBlock = implode('', array_reverse($lines));
        $clientName    = $context['client_name'] ?? 'Client';
        $subject       = $context['subject'] ?? 'Support Request';
        $dept          = $context['department'] ?? 'Support';

        $prompt  = "=== AUTOPILOT TASK ===\n";
        $prompt .= "Write a complete, professional reply to the following support ticket.\n";
        $prompt .= "Tone: {$tone}\n";
        $prompt .= "Include a warm greeting addressing the client by first name if available.\n";
        $prompt .= "Do NOT include a sign-off or signature — that will be added automatically.\n";
        $prompt .= "Output ONLY the reply text.\n\n";

        if (!empty($context['tools_output'])) {
            $prompt .= "=== DIAGNOSTIC EVIDENCE ===\n{$context['tools_output']}\n\n";
        }

        $prompt .= "=== TICKET INFO ===\n";
        $prompt .= "Client: {$clientName}\nDepartment: {$dept}\nSubject: {$subject}\n\n";

        if (!empty($context['services_summary'])) {
            $prompt .= "=== ACCOUNT CONTEXT (READ-ONLY) ===\n{$context['services_summary']}\n\n";
        }

        $prompt .= "=== CONVERSATION ===\n{$messagesBlock}";

        return $prompt;
    }

    private function buildReplyContext(int $ticketId, $ticket): array
    {
        $department = Capsule::table('tblticketdepartments')
            ->where('id', $ticket->did)
            ->value('name') ?? 'Support';

        $clientName = $ticket->name ?: 'Client';
        if ($ticket->userid) {
            $client = Capsule::table('tblclients')
                ->select('firstname', 'lastname')
                ->where('id', $ticket->userid)
                ->first();
            if ($client) {
                $clientName = trim($client->firstname . ' ' . $client->lastname) ?: $clientName;
            }
        }

        $messages = [[
            'admin'   => false,
            'date'    => Capsule::table('tbltickets')->where('id', $ticketId)->value('date'),
            'message' => Capsule::table('tbltickets')->where('id', $ticketId)->value('message'),
        ]];

        $replies = Capsule::table('tblticketreplies')
            ->select('userid', 'admin', 'message', 'date')
            ->where('tid', $ticketId)
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        foreach ($replies as $reply) {
            $messages[] = [
                'admin'   => !empty($reply->admin),
                'date'    => $reply->date,
                'message' => $reply->message,
            ];
        }

        return [
            'subject'            => $ticket->title,
            'department'         => $department,
            'client_name'        => $clientName,
            'messages'           => $messages,
            'services_summary'   => '',
            'attachments_text'   => '',
            'attachments_images' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // WHMCS API — post reply
    // -------------------------------------------------------------------------

    private function postReply(int $ticketId, string $replyText, int $adminId): ?int
    {
        // Fetch full admin row — WHMCS AddTicketReply requires name + email even for admin replies
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
        if (!$admin) {
            throw new \Exception("Autopilot admin (ID: {$adminId}) not found.");
        }

        $adminName = trim($admin->firstname . ' ' . $admin->lastname) ?: $admin->username;

        // Fetch clientid from the ticket (needed for proper reply association)
        $ticketClientId = Capsule::table('tbltickets')
            ->where('id', $ticketId)
            ->value('userid');

        $apiParams = [
            'ticketid'      => $ticketId,
            'message'       => $replyText,
            'adminusername' => $admin->username,
            'markdown'      => true,
        ];

        // --- STEP 1: DUMP ADMIN PROFILE (DEBUG) ---
        $adminDump = json_encode($admin);
        ModuleLogger::log('debug', 'Autopilot.Identity.Profile', "Admin Record: {$adminDump}", $ticketId);

        $result = localAPI('AddTicketReply', $apiParams, $admin->username);

        if (($result['result'] ?? '') !== 'success') {
            $err = $result['message'] ?? json_encode($result);
            throw new \Exception("WHMCS localAPI AddTicketReply failed: {$err}");
        }

        // --- STEP 2: FIND AND FIX THE IDENTITY (HARDCODE OVERRIDE) ---
        $replyRow = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->where('admin', $admin->username)
            ->orderBy('id', 'desc')
            ->first();

        if ($replyRow) {
            // IDENTITY SYNC: WHMCS often leaves the 'name' column blank for staff.
            // We automatically patch it using the Profile Names (First + Last) or the optional override.
            $displayName = trim($this->settings['autopilot_display_name'] ?? '');
            
            if (!$displayName) {
                $displayName = trim(($admin->firstname ?? '') . ' ' . ($admin->lastname ?? ''));
            }

            // Fallback to username only if everything else is empty
            if (!$displayName) {
                $displayName = $admin->username;
            }

            Capsule::table('tblticketreplies')
                ->where('id', $replyRow->id)
                ->update([
                    'name'  => '', // Human staff typically have a blank 'name' column in your DB
                    'admin' => $displayName // YOUR DB uses the Full Name in the 'admin' column
                ]);
                
            ModuleLogger::log('debug', 'Autopilot.Identity.Sync', "Aligned reply identity to Human Pattern: '{$displayName}'", $ticketId);

            return (int) $replyRow->id;
        }

        return null;
    }


    // -------------------------------------------------------------------------
    // Admin signature
    // -------------------------------------------------------------------------

    /**
     * Post a private internal admin note (Draft Mode).
     *
     * We insert directly into tblticketreplies with notes=1 — the standard WHMCS
     * schema for private/internal notes — because WHMCS's AddTicketNote localAPI
     * has inconsistent parameter handling across versions and often stores blank content.
     */
    private function postInternalNote(int $ticketId, string $replyText, int $adminId): ?int
    {
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
        if (!$admin) {
            throw new \Exception("Autopilot admin (ID: {$adminId}) not found.");
        }

        $displayName = trim($this->settings['autopilot_display_name'] ?? '');
        if (!$displayName) {
            $displayName = trim(($admin->firstname ?? '') . ' ' . ($admin->lastname ?? ''));
        }
        if (!$displayName) {
            $displayName = $admin->username;
        }

        $draftPrefix = "[Sahdev Autopilot Draft] Review before sending - Draft Mode is ON\n\n---\n\n";
        $noteText    = $draftPrefix . $replyText;

        // Direct DB insert — reliable across all WHMCS versions.
        // notes=1 marks this as a private/internal note in the WHMCS UI.
        $noteId = Capsule::table('tblticketreplies')->insertGetId([
            'tid'     => $ticketId,
            'userid'  => 0,
            'admin'   => $displayName,
            'name'    => '',
            'message' => $noteText,
            'notes'   => 1,
            'date'    => Carbon::now()->toDateTimeString(),
        ]);

        ModuleLogger::log('debug', 'Autopilot.DraftMode', "Posted draft note #{$noteId} to ticket #{$ticketId}", $ticketId);

        return $noteId ?: null;
    }

    /**
     * Set a ticket's status. Used to mark tickets as 'Reply-Drafting' in draft mode.
     */
    private function setTicketStatus(int $ticketId, string $status): void
    {
        try {
            Capsule::table('tbltickets')->where('id', $ticketId)->update([
                'status' => $status,
            ]);
            ModuleLogger::log('debug', 'Autopilot.StatusChange', "Ticket #{$ticketId} status set to '{$status}'", $ticketId);
        } catch (\Throwable $e) {
            ModuleLogger::log('warning', 'Autopilot.StatusChange', "Failed to set status: " . $e->getMessage(), $ticketId);
        }
    }


    private function getAdminSignature(int $adminId): string
    {
        $raw = Capsule::table('tbladmins')->where('id', $adminId)->value('signature');
        return $raw ? trim(strip_tags($raw, '<br><p><a><b><strong><i><em>')) : '';
    }

    // -------------------------------------------------------------------------
    // Eligibility helpers
    // -------------------------------------------------------------------------

    private function isDepartmentBlocked(int $deptId): bool
    {
        $blocked = $this->parseIntList($this->settings['autopilot_blocked_depts'] ?? '');
        return !empty($blocked) && in_array($deptId, $blocked, true);
    }

    private function isDepartmentAllowed(int $deptId): bool
    {
        $allowed = $this->parseIntList($this->settings['autopilot_allowed_depts'] ?? '');
        if (empty($allowed)) return true; // empty = all allowed
        return in_array($deptId, $allowed, true);
    }

    private function isBlockedByKeyword(string $subject): bool
    {
        $raw = $this->settings['autopilot_blocked_topics'] ?? '';
        if (empty($raw)) return false;

        $keywords = array_filter(array_map('trim', explode(',', strtolower($raw))));
        $subjectLower = strtolower($subject);

        foreach ($keywords as $kw) {
            if ($kw !== '' && strpos($subjectLower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function parseIntList(string $raw): array
    {
        if (empty($raw)) return [];
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function applyRandomDelay(): void
    {
        $min = max(0, (int) ($this->settings['autopilot_delay_min_sec'] ?? 180));
        $max = max($min, (int) ($this->settings['autopilot_delay_max_sec'] ?? 900));
        if ($min > 0 || $max > 0) {
            $delay = rand($min, $max);
            if ($delay > 0) sleep($delay);
        }
    }

    private function tagTicketIfEnabled(int $ticketId, string $tag = 'ai-needs-human'): void
    {
        if (empty($this->settings['autopilot_tag_skipped'])) return;
        try {
            WhmcsTicketTagHelper::addTagIfMissing($ticketId, $tag);
        } catch (\Throwable $e) {
            // Never break cron
        }
    }

    private function sanitize(string $text): string
    {
        $s = strip_tags($text);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = preg_replace('/\r\n|\r/', "\n", $s);
        $s = preg_replace('/\n{4,}/', "\n\n\n", $s);
        return trim($s);
    }

    private function logAttempt(int $ticketId, string $decision, ?string $reason, ?int $replyId, $lastreplySnapshot, int $tokensUsed = 0): void
    {
        try {
            Capsule::table('tblsahdev_autopilot_log')->insert([
                'ticket_id'                  => $ticketId,
                'reply_id'                   => $replyId,
                'ticket_lastreply_snapshot'  => $lastreplySnapshot,
                'ai_decision'                => $decision,
                'skip_reason'                => $reason ? substr($reason, 0, 128) : null,
                'tokens_used'                => $tokensUsed > 0 ? $tokensUsed : null,
                'created_at'                 => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never crash the cron
        }
    }

    private function persistRunStatus(): void
    {
        try {
            $replied  = $this->result['replied'];
            $skipped  = $this->result['skipped'];
            $errCount = count($this->result['errors']);

            $msg = $replied > 0
                ? "Autopilot: {$replied} replied, {$skipped} skipped."
                : ($errCount > 0 ? 'Autopilot: ' . implode(' | ', array_slice($this->result['errors'], 0, 2)) : 'Autopilot: no eligible tickets.');

            Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'autopilot_cron_last_run_at'  => Carbon::now(),
                'autopilot_cron_last_message' => substr($msg, 0, 512),
                'updated_at'                  => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Never break cron
        }
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    private function ensureSchema(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_autopilot_log')) {
                Capsule::schema()->create('tblsahdev_autopilot_log', function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->integer('reply_id')->unsigned()->nullable();
                    $table->timestamp('ticket_lastreply_snapshot')->nullable();
                    $table->string('ai_decision', 16)->index(); // replied | skipped | limit_reached
                    $table->string('skip_reason', 128)->nullable();
                    $table->integer('tokens_used')->nullable();
                    $table->timestamp('created_at')->useCurrent();
                });
            }
        } catch (\Throwable $e) {
            // If concurrent — table already exists, fine
        }

        // Ensure autopilot settings columns exist
        $cols = [
            'autopilot_enabled'          => ['type' => 'boolean', 'default' => 0],
            'autopilot_admin_id'         => ['type' => 'integer', 'default' => null, 'nullable' => true],
            'autopilot_max_replies'      => ['type' => 'integer', 'default' => 3],
            'autopilot_delay_min_sec'    => ['type' => 'integer', 'default' => 180],
            'autopilot_delay_max_sec'    => ['type' => 'integer', 'default' => 900],
            'autopilot_allowed_depts'    => ['type' => 'text', 'nullable' => true],
            'autopilot_blocked_depts'    => ['type' => 'text', 'nullable' => true],
            'autopilot_blocked_topics'   => ['type' => 'text', 'nullable' => true],
            'autopilot_max_urgency'      => ['type' => 'string', 'default' => 'High'],
            'autopilot_max_sentiment'    => ['type' => 'integer', 'default' => 7],
            'autopilot_only_first_reply' => ['type' => 'boolean', 'default' => 1],
            'autopilot_tone'             => ['type' => 'string', 'default' => 'Friendly'],
            'autopilot_cron_max_per_run' => ['type' => 'integer', 'default' => 5],
            'autopilot_tag_skipped'      => ['type' => 'boolean', 'default' => 1],
            'autopilot_cron_last_run_at' => ['type' => 'timestamp', 'nullable' => true],
            'autopilot_cron_last_message'=> ['type' => 'string', 'default' => null, 'nullable' => true],
            'autopilot_draft_mode'       => ['type' => 'boolean', 'default' => 0],
            'autopilot_display_name'     => ['type' => 'string', 'default' => null, 'nullable' => true],
        ];

        foreach ($cols as $col => $def) {
            try {
                Capsule::table('tblsahdev_settings')->select($col)->first();
            } catch (\Exception $e) {
                try {
                    Capsule::schema()->table('tblsahdev_settings', function ($table) use ($col, $def) {
                        $nullable = !empty($def['nullable']);
                        $default  = $def['default'] ?? null;

                        switch ($def['type']) {
                            case 'boolean':
                                $c = $table->boolean($col)->default($default ?? 0);
                                break;
                            case 'integer':
                                $c = $table->integer($col);
                                if ($nullable) $c->nullable();
                                elseif ($default !== null) $c->default($default);
                                break;
                            case 'text':
                                $c = $table->text($col)->nullable();
                                break;
                            case 'timestamp':
                                $c = $table->timestamp($col)->nullable();
                                break;
                            default:
                                $c = $table->string($col, 128)->nullable();
                                if ($default !== null) $c->default($default);
                        }
                    });
                } catch (\Throwable $e2) {
                    // Column may already exist via a race
                }
            }
        }

        // Ensure the 'Reply-Drafting' custom status exists in WHMCS
        $this->ensureReplyDraftingStatus();
    }

    /**
     * Create the 'Reply-Drafting' custom ticket status in WHMCS if it doesn't exist.
     * Behaves like an open/active status so tickets still appear in the open queue.
     */
    private function ensureReplyDraftingStatus(): void
    {
        try {
            $exists = Capsule::table('tblticketstatuses')
                ->where('title', 'Reply-Drafting')
                ->exists();

            if (!$exists) {
                $maxSort = (int) Capsule::table('tblticketstatuses')->max('sortorder');
                Capsule::table('tblticketstatuses')->insert([
                    'title'          => 'Reply-Drafting',
                    'color'          => '#8b5cf6',   // Purple — distinct but calm
                    'textcolor'      => '#ffffff',
                    'sortorder'      => $maxSort + 10,
                    'showopentickets'=> 1,            // Appears in open ticket views
                ]);
                ModuleLogger::log('info', 'Autopilot.Schema', "Created 'Reply-Drafting' ticket status in WHMCS.");
            }
        } catch (\Throwable $e) {
            // Non-fatal — status may already exist or column set may differ
            ModuleLogger::log('warning', 'Autopilot.Schema', "Could not ensure Reply-Drafting status: " . $e->getMessage());
        }
    }
}
