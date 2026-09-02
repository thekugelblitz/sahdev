<?php

use WHMCS\Database\Capsule;

/**
 * Sahdev AI - AJAX Endpoint
 * Secured entry point for analyzing tickets.
 */

// Initialize WHMCS securely if not already initialized
if (!defined("WHMCS")) {
    require_once dirname(__DIR__, 3) . '/init.php';
}

// Always ensure JSON output for this endpoint
header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

// Check if admin is logged in securely
$adminId = $_SESSION['adminid'] ?? null;

if (!$adminId) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Please login as admin.']);
    exit;
}

// Actions allowed via GET (no ticket/POST needed)
$getAllowedActions = ['get_analytics_period', 'get_header_server_widget'];
$isGetAllowed = in_array($_REQUEST['action'] ?? '', $getAllowedActions);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isGetAllowed) {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method. POST required.']);
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$allowedActions = [
    'get_analytics_period', 'get_open_payload', 'analyze_open_context', 'get_payload', 'save_response',
    'get_rewrite_payload', 'rewrite_reply', 'auto_analyze', 'analyze_ticket',
    'generate_summary', 'get_summary', 'delete_summary',
    'generate_historical_context', 'get_historical_context', 'delete_historical_context',
    'score_reply', 'delete_audit_entries', 'search_canned_responses', 'generate_canned_template',
    'save_canned_response', 'save_kb_article', 'get_analytics', 'get_ticket_insights',
    'trigger_cron_run', 'get_insights_queue', 'analyze_single_insight', 'test_whmcs_cron_http',
    'run_tools_for_ticket', 'get_tools_ticket_status', 'run_tools_queue', 'get_tools_operations',
    'run_manual_tool', 'autopilot_test_run', 'set_ui_theme', 'get_header_server_widget'
];
if (!in_array($action, $allowedActions, true)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isGetAllowed) {
    $requestToken = (string) ($_REQUEST['token'] ?? '');
    if ($requestToken !== '' && strpos($requestToken, '<') !== false) {
        // Some callers may accidentally pass the full generate_token("form") HTML.
        if (preg_match('/name=["\']token["\'][^>]*value=["\']([^"\']+)["\']/i', $requestToken, $m)) {
            $requestToken = (string) ($m[1] ?? '');
        } elseif (preg_match('/value=["\']([^"\']+)["\']/i', $requestToken, $m)) {
            $requestToken = (string) ($m[1] ?? '');
        }
    }
    $candidateTokens = [];
    if (!empty($_SESSION['token'])) {
        $candidateTokens[] = (string) $_SESSION['token'];
    }
    if (!empty($_SESSION['tkval'])) {
        $candidateTokens[] = (string) $_SESSION['tkval'];
    }
    if (function_exists('generate_token')) {
        try {
            $generatedToken = (string) generate_token('plain');
            if ($generatedToken !== '') {
                $candidateTokens[] = $generatedToken;
            }
        } catch (\Throwable $e) {
            // Ignore token helper failures and rely on session candidates.
        }
    }
    $candidateTokens = array_values(array_unique(array_filter($candidateTokens, static function ($v) {
        return is_string($v) && $v !== '';
    })));

    $isTokenValid = false;
    foreach ($candidateTokens as $candidate) {
        if (hash_equals($candidate, $requestToken)) {
            $isTokenValid = true;
            break;
        }
    }

    if ($requestToken === '' || !$isTokenValid) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh the page and try again.']);
        exit;
    }
}

$ticketId = (int) ($_REQUEST['ticket_id'] ?? 0);
$userId = (int) ($_POST['userid'] ?? 0);
$tone = strip_tags($_POST['tone'] ?? '');
$instruction = strip_tags($_POST['instruction'] ?? '');
$intensity = (int) ($_POST['intensity'] ?? 3);
$intent = strip_tags($_POST['intent'] ?? 'AUTO');
$technicalContext = $_POST['technical_context'] ?? '';

// Inject dive intensity context if higher than normal
if ($intensity > 3) {
    $intensityLabels = [
        4 => "Deep Dive (Be highly rigorous and exhaustive)",
        5 => "Maximum Intensity (Leave absolutely no stone unturned, hyper-detailed and rigorous analysis)"
    ];
    $label = $intensityLabels[$intensity] ?? "Deep rigorous focus";
    $intensityContext = "DIVE INTENSITY RULE: The admin has requested an intensity level of {$intensity}/5. {$label}. Please ensure your ROOT_CAUSE and INTERNAL_ACTION_PLAN are profoundly detailed and rigorous.";
    $instruction = empty($instruction) ? $intensityContext : $instruction . "\n\n" . $intensityContext;
}

    $ticketNotRequiredActions = [
        'search_canned_responses', 'generate_canned_template', 'save_canned_response', 'save_kb_article', 'delete_audit_entries',
        'get_analytics', 'get_ticket_insights', 'trigger_cron_run', 'get_insights_queue', 'analyze_single_insight',
        'test_whmcs_cron_http', 'run_tools_queue', 'get_tools_operations',
        'get_open_payload', 'analyze_open_context',
        'autopilot_test_run', 'set_ui_theme', 'get_header_server_widget',
    ];
    if (!$ticketId && !in_array($action, $ticketNotRequiredActions) && !$isGetAllowed) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['status' => 'error', 'message' => 'Missing Ticket ID.']);
        exit;
    }

    // Role-Based Access Control (RBAC) Permission Enforcement
    require_once __DIR__ . '/lib/PermissionService.php';
    \Sahdev\Lib\PermissionService::ensureSchema();

    $actionPermissionMap = [
        'get_header_server_widget'    => \Sahdev\Lib\PermissionService::PERM_TELEMETRY_VIEW,
        'analyze_ticket'              => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_payload'                 => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'auto_analyze'                => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_open_payload'            => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'analyze_open_context'        => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'save_response'               => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'score_reply'                 => \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW,
        'get_rewrite_payload'         => \Sahdev\Lib\PermissionService::PERM_REWRITE_REPLY,
        'rewrite_reply'               => \Sahdev\Lib\PermissionService::PERM_REWRITE_REPLY,
        'generate_summary'            => \Sahdev\Lib\PermissionService::PERM_SUMMARIZER,
        'get_summary'                 => \Sahdev\Lib\PermissionService::PERM_SUMMARIZER,
        'delete_summary'              => \Sahdev\Lib\PermissionService::PERM_SUMMARIZER,
        'generate_historical_context' => \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX,
        'get_historical_context'      => \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX,
        'delete_historical_context'   => \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX,
        'search_canned_responses'     => \Sahdev\Lib\PermissionService::PERM_CANNED_KB,
        'generate_canned_template'    => \Sahdev\Lib\PermissionService::PERM_KNOWLEDGE_MANAGE,
        'save_canned_response'        => \Sahdev\Lib\PermissionService::PERM_KNOWLEDGE_MANAGE,
        'save_kb_article'             => \Sahdev\Lib\PermissionService::PERM_KNOWLEDGE_MANAGE,
        'run_tools_for_ticket'        => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'get_tools_ticket_status'     => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'run_tools_queue'             => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'get_tools_operations'        => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'run_manual_tool'             => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'autopilot_test_run'          => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'trigger_cron_run'            => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'test_whmcs_cron_http'        => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'analyze_single_insight'      => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_ticket_insights'         => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_insights_queue'          => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_analytics'               => \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW,
        'get_analytics_period'        => \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW,
        'delete_audit_entries'        => \Sahdev\Lib\PermissionService::PERM_AUDIT_MANAGE,
    ];

    if (isset($actionPermissionMap[$action])) {
        $requiredPerm = $actionPermissionMap[$action];
        if (!\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, $requiredPerm)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode([
                'status' => 'error',
                'message' => "Access Denied: Your WHMCS admin role does not have permission for '{$requiredPerm}'."
            ]);
            exit;
        }
    }

try {
    require_once __DIR__ . '/lib/AIProviderInterface.php';
    require_once __DIR__ . '/lib/GoogleAIProvider.php';
    require_once __DIR__ . '/lib/LMStudioAIProvider.php';
    require_once __DIR__ . '/lib/TicketDataExtractor.php';
    require_once __DIR__ . '/lib/AdminPreferences.php';
    require_once __DIR__ . '/lib/AIController.php';
    require_once __DIR__ . '/modules/ToolsExecution/ToolsExecutionService.php';

    $forceRegenerate = !empty($_POST['force_regenerate']) && $_POST['force_regenerate'] === 'true';
    $forceFallback = !empty($_POST['force_fallback']) && $_POST['force_fallback'] === 'true';
    $useSummaryRaw = $_POST['use_summary'] ?? '1';
    $useSummary = ($useSummaryRaw === '1' || $useSummaryRaw === 'true' || $useSummaryRaw === 'on' || $useSummaryRaw === true);
    
    $includeHistoryRaw = $_POST['include_historical_context'] ?? '0';
    $includeHistory = ($includeHistoryRaw === '1' || $includeHistoryRaw === 'true' || $includeHistoryRaw === 'on' || $includeHistoryRaw === true);
    $includeToolsRaw = $_POST['include_tools_context'] ?? '1';
    $includeTools = ($includeToolsRaw === '1' || $includeToolsRaw === 'true' || $includeToolsRaw === 'on' || $includeToolsRaw === true);
    $includeAdminNotesRaw = $_POST['include_admin_notes'] ?? '0';
    $includeAdminNotes = ($includeAdminNotesRaw === '1' || $includeAdminNotesRaw === 'true' || $includeAdminNotesRaw === 'on' || $includeAdminNotesRaw === true);

    $postOverrideProviderId = (int) ($_POST['override_provider_id'] ?? 0);
    $postOverrideProviderId = $postOverrideProviderId > 0 ? $postOverrideProviderId : null;

    // Auto-migration for overwrites without reactivation, specifically for AJAX calls
    try {
        \WHMCS\Database\Capsule::table('tblsahdev_providers')->first();
        \WHMCS\Database\Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
        \WHMCS\Database\Capsule::table('tblsahdev_client_context_cache')->first();
    } catch (\Exception $e) {
        require_once __DIR__ . '/sahdev.php';
        if (function_exists('sahdev_activate')) {
            sahdev_activate();
        }
    }

    \Sahdev\Lib\AdminPreferences::ensureSchema();
    $settingsRow = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
    $settingsArray = $settingsRow ? (array) $settingsRow : [];

    $overrideProviderId = \Sahdev\Lib\AdminPreferences::mergeEffectiveOverride(
        $postOverrideProviderId,
        (int) $adminId,
        $settingsArray
    );

    $actionMap = \Sahdev\Lib\AdminPreferences::actionFeatureMap();
    if (!in_array($action, \Sahdev\Lib\AdminPreferences::unguardedActions(), true)) {
        $featureKey = $actionMap[$action] ?? \Sahdev\Lib\AdminPreferences::FEATURE_TICKET_AI;
        try {
            \Sahdev\Lib\AdminPreferences::assertFeatureOrThrow($featureKey, (int) $adminId, $settingsRow);
        } catch (\RuntimeException $e) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    $controller = new \Sahdev\Lib\AIController($ticketId, $adminId);

    if ($action === 'get_analytics_period') {
        // AJAX: return period-filtered KPI data for the analytics dashboard
        require_once __DIR__ . '/controllers/AdminController.php';
        $vars = ['modulelink' => 'addonmodules.php?module=sahdev'];
        $adminCtrl = new \Sahdev\Controllers\AdminController($vars);
        echo $adminCtrl->analytics_data();
        exit;
    } elseif ($action === 'get_open_payload') {
        if ($userId <= 0) {
            throw new \Exception('Missing or invalid userid.');
        }
        $response = $controller->getOpenContextPayload($userId, $tone, $instruction, $forceRegenerate, $intent, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'analyze_open_context') {
        if ($userId <= 0) {
            throw new \Exception('Missing or invalid userid.');
        }
        $response = $controller->getOpenContextAnalysis($userId, $tone, $instruction, $forceRegenerate, false, $intent, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'get_payload') {
        $response = $controller->getPayload($tone, $instruction, $forceRegenerate, $intent, $useSummary, $includeHistory, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'save_response') {
        $hashSignature = $_POST['hash_signature'] ?? '';
        $aiResponseRaw = $_POST['ai_response'] ?? '{}';
        $tokenUsage = (int) ($_POST['token_usage'] ?? 0);
        $execTime = (int) ($_POST['exec_time'] ?? 0);
        $tokenDetails = $_POST['token_details'] ?? [];
        if (!is_array($tokenDetails)) {
            $tokenDetails = json_decode($tokenDetails, true) ?: [];
        }

        $aiResponseStr = trim($aiResponseRaw);
        // Decode base64 if it's not starting with a JSON brace
        if (!empty($aiResponseStr) && !str_starts_with($aiResponseStr, '{') && !str_starts_with($aiResponseStr, '[')) {
            $decoded = base64_decode($aiResponseStr);
            if ($decoded !== false) {
                $aiResponseStr = $decoded;
            }
        }

        $aiResponse = json_decode($aiResponseStr, true);
        if (!$aiResponse) {
            $err = json_last_error_msg();
            throw new \Exception("Invalid JSON response payload provided. Error: {$err} | Raw: " . substr($aiResponseStr, 0, 800));
        }

        $response = $controller->saveResponse($hashSignature, $aiResponse, $tokenUsage, $execTime, $tokenDetails);
    } elseif ($action === 'get_rewrite_payload') {
        // Returns provider info + rewrite prompt so browser can call LM Studio directly
        $draftText = $_POST['draft_text'] ?? '';
        $response = $controller->getRewritePayload($draftText, $tone ?: 'Professional', $instruction);
    } elseif ($action === 'rewrite_reply') {
        // Server-side rewrite — used for Google providers only
        $draftText = $_POST['draft_text'] ?? '';
        $response = $controller->rewriteReply($draftText, $tone ?: 'Professional', $instruction);
    } elseif ($action === 'auto_analyze') {
        // Feature: Auto-load AI Snapshot on ticket page load (always cached-first, no rate limit penalty on hit)
        $response = $controller->getAnalysis($tone, $instruction, false, false, $intent, $useSummary, $includeHistory, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'generate_summary') {
        // Feature: AI Ticket Summarizer — generate and save a condensed summary
        $response = $controller->generateSummary();
    } elseif ($action === 'get_summary') {
        // Feature: AI Ticket Summarizer — fetch existing summary for a ticket
        $summary = $controller->getSummary();
        $response = $summary
            ? ['status' => 'success', 'summary' => $summary]
            : ['status' => 'not_found', 'summary' => null];
    } elseif ($action === 'delete_summary') {
        // Feature: AI Ticket Summarizer — delete saved summary
        $response = $controller->deleteSummary();
    } elseif ($action === 'generate_historical_context') {
        $limit = (int) ($_POST['limit'] ?? 7);
        $response = $controller->generateHistoricalContext($limit);
    } elseif ($action === 'get_historical_context') {
        $context = $controller->getHistoricalContext();
        $response = $context
            ? ['status' => 'success', 'historical_context' => $context]
            : ['status' => 'not_found', 'historical_context' => null];
    } elseif ($action === 'delete_historical_context') {
        $response = $controller->deleteHistoricalContext();
    } elseif ($action === 'score_reply') {
        // Feature: Response Quality Scorer
        $replyText = $_POST['reply_text'] ?? '';
        $isAiGenerated = !empty($_POST['is_ai_generated']) && $_POST['is_ai_generated'] === 'true';
        $response = $controller->scoreReply($replyText, $isAiGenerated);
    } elseif ($action === 'delete_audit_entries') {
        // Feature: AI Audit Trail — delete entries older than X days via AJAX
        $days = (int) ($_POST['days'] ?? 30);
        if ($days > 0) {
            $cutoffDate = \Carbon\Carbon::now()->subDays($days);
            $deleted = \WHMCS\Database\Capsule::table('tblsahdev_audit_trail')
                ->where('created_at', '<', $cutoffDate)
                ->delete();
            $response = ['status' => 'success', 'message' => "Deleted {$deleted} audit entries.", 'deleted_count' => $deleted];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid days parameter.'];
        }
    } elseif ($action === 'search_canned_responses') {
        // Feature: Canned Response Generator — search combinations of tables
        $query = $_POST['query'] ?? '';
        $response = $controller->searchCannedResponses($query);
    } elseif ($action === 'generate_canned_template') {
        // Feature: Canned Response Generator — turn a draft into a general template
        $draftText = $_POST['draft_text'] ?? '';
        $response = $controller->generateCannedTemplate($draftText);
    } elseif ($action === 'save_canned_response') {
        // Feature: Canned Response Generator — save generated template into the DB
        $title = $_POST['title'] ?? '';
        $templateText = $_POST['template_text'] ?? '';
        $response = $controller->saveCannedResponse($title, $templateText);
    } elseif ($action === 'save_kb_article') {
        // Feature: Canned Response Generator — save generated template as a KB article
        $title = $_POST['title'] ?? '';
        $templateText = $_POST['template_text'] ?? '';
        $response = $controller->saveKbArticle($title, $templateText);
    } elseif ($action === 'get_analytics') {
        // Feature 8: AI Performance Analytics
        $response = $controller->getAnalyticsData();
    } elseif ($action === 'get_ticket_insights') {
        // Ticket Insights: fetch bulk sentiment/urgency data for ticket list badges
        $rawIds = $_POST['ticket_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = json_decode($rawIds, true) ?: [];
        }
        $rawTids = $_POST['ticket_tids'] ?? [];
        if (!is_array($rawTids)) {
            $rawTids = json_decode($rawTids, true) ?: [];
        }

        $ticketIds = array_map('intval', array_filter($rawIds));
        $maskToId  = [];

        if (!empty($rawTids)) {
            $masks = array_unique(array_filter(array_map('trim', array_map('strval', $rawTids))));
            if (!empty($masks)) {
                $pairs = \WHMCS\Database\Capsule::table('tbltickets')
                    ->whereIn('tid', $masks)
                    ->get(['id', 'tid']);
                foreach ($pairs as $p) {
                    $maskToId[(string) $p->tid] = (int) $p->id;
                    $ticketIds[]               = (int) $p->id;
                }
            }
        }

        $ticketIds = array_values(array_unique(array_filter($ticketIds)));

        if (empty($ticketIds)) {
            $response = [
                'status'     => 'success',
                'insights'   => [],
                'mask_to_id' => new \stdClass(),
            ];
        } else {
            require_once __DIR__ . '/lib/WhmcsTicketTagHelper.php';

            $selectCols = [
                'ticket_id', 'score', 'label', 'urgency', 'client_tone',
                'ticket_summary', 'admin_reply_count', 'last_admin_name', 'analyzed_at',
            ];
            if (\WHMCS\Database\Capsule::schema()->hasColumn('tblsahdev_sentiment', 'ai_tags_json')) {
                $selectCols[] = 'ai_tags_json';
            }

            $rows = \WHMCS\Database\Capsule::table('tblsahdev_sentiment')
                ->whereIn('ticket_id', $ticketIds)
                ->get($selectCols);

            $needBulk = [];
            $insights = [];
            foreach ($rows as $row) {
                $tid = (int) $row->ticket_id;
                $tags = [];
                if (!empty($row->ai_tags_json)) {
                    $decoded = json_decode($row->ai_tags_json, true);
                    if (is_array($decoded)) {
                        $tags = array_values(array_filter($decoded));
                    }
                }
                if ($tags === []) {
                    $needBulk[] = $tid;
                }

                $insights[$tid] = [
                    'sentiment_score'    => (int) $row->score,
                    'sentiment_label'    => $row->label,
                    'urgency'            => $row->urgency,
                    'client_tone'        => $row->client_tone,
                    'ticket_summary'     => $row->ticket_summary,
                    'admin_reply_count'  => (int) $row->admin_reply_count,
                    'last_admin_name'    => $row->last_admin_name,
                    'analyzed_at'        => $row->analyzed_at,
                    'tags'               => $tags,
                ];
            }

            $needBulk = array_values(array_unique($needBulk));
            if ($needBulk !== []) {
                $bulk = \Sahdev\Lib\WhmcsTicketTagHelper::getTagsForTickets($needBulk);
                foreach ($needBulk as $tid) {
                    if (!empty($insights[$tid]['tags']) || !isset($insights[$tid])) {
                        continue;
                    }
                    if (!empty($bulk[$tid])) {
                        $insights[$tid]['tags'] = $bulk[$tid];
                    }
                }
            }
            $response = [
                'status'     => 'success',
                'insights'   => $insights,
                'mask_to_id' => empty($maskToId) ? new \stdClass() : $maskToId,
            ];
        }
    } elseif ($action === 'get_insights_queue') {
        // Returns the list of ticket IDs that need analysis (used by the incremental manual trigger)
        require_once __DIR__ . '/lib/AIProviderInterface.php';
        require_once __DIR__ . '/lib/GoogleAIProvider.php';
        require_once __DIR__ . '/lib/LMStudioAIProvider.php';
        require_once __DIR__ . '/lib/ReplicateAIProvider.php';
        require_once __DIR__ . '/lib/TicketDataExtractor.php';
        require_once __DIR__ . '/lib/AIController.php';
        require_once __DIR__ . '/lib/CronProcessor.php';

        $processor = new \Sahdev\Lib\CronProcessor();
        $queue     = $processor->getQueue();
        $response  = ['status' => 'success', 'queue' => $queue, 'total' => count($queue)];

    } elseif ($action === 'analyze_single_insight') {
        // Processes ONE ticket for insights — called repeatedly by the incremental JS loop
        $singleTicketId = (int) ($_POST['ticket_id'] ?? 0);
        if (!$singleTicketId) {
            $response = ['status' => 'error', 'message' => 'Missing ticket_id'];
        } else {
            require_once __DIR__ . '/lib/AIProviderInterface.php';
            require_once __DIR__ . '/lib/GoogleAIProvider.php';
            require_once __DIR__ . '/lib/LMStudioAIProvider.php';
            require_once __DIR__ . '/lib/ReplicateAIProvider.php';
            require_once __DIR__ . '/lib/TicketDataExtractor.php';
            require_once __DIR__ . '/lib/AIController.php';
            require_once __DIR__ . '/lib/CronProcessor.php';

            // Give a single ticket plenty of time (Replicate can be slow)
            @set_time_limit(180);
            @ignore_user_abort(true);

            $processor = new \Sahdev\Lib\CronProcessor();
            try {
                $processor->analyzeTicket($singleTicketId);
                $response = ['status' => 'success', 'ticket_id' => $singleTicketId];
            } catch (\Throwable $e) {
                $response = ['status' => 'error', 'ticket_id' => $singleTicketId, 'message' => $e->getMessage()];
            }
        }

    } elseif ($action === 'test_whmcs_cron_http') {
        // Admin-only: GET the configured WHMCS cron.php over HTTP for debugging (may run full WHMCS cron).
        require_once __DIR__ . '/lib/CronUrlHelper.php';

        $posted = trim((string) ($_POST['test_url'] ?? ''));
        $row    = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
        $saved  = trim((string) ($row->insights_cron_http_url ?? ''));
        $url    = $posted !== '' ? $posted : \Sahdev\Lib\CronUrlHelper::effectiveHttpUrl($saved ?: null);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $response = [
                'status'  => 'error',
                'message' => 'No valid HTTP(S) URL. Enter a full URL (include https:// and any token query string WHMCS requires), or save a default under Ticket Insights settings.',
            ];
        } else {
            $t0 = microtime(true);
            $body = false;
            $code = 0;
            $err  = '';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_CONNECTTIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT      => 'SahdevAddon/WHMCS-Cron-Test',
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = (string) curl_error($ch);
                curl_close($ch);
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout'         => 120,
                        'follow_location' => 1,
                        'user_agent'      => 'SahdevAddon/WHMCS-Cron-Test',
                    ],
                    'ssl'  => [
                        'verify_peer'      => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body === false) {
                    $err = 'file_get_contents failed (enable curl or check allow_url_fopen)';
                } else {
                    $code = 200;
                    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                        $code = (int) $m[1];
                    }
                }
            }

            $ms      = (int) round((microtime(true) - $t0) * 1000);
            $rawLen  = is_string($body) ? strlen($body) : 0;
            $preview = is_string($body) ? mb_substr(trim(strip_tags($body)), 0, 2000) : '';

            $response = [
                'status'       => ($err !== '' && $body === false) ? 'error' : 'success',
                'message'      => $err !== '' ? $err : 'Request finished.',
                'url_tested'   => $url,
                'http_code'    => $code,
                'duration_ms'  => $ms,
                'bytes'        => $rawLen,
                'body_preview' => $preview,
                'curl_error'   => $err,
            ];
        }

    } elseif ($action === 'trigger_cron_run') {
        // Ticket Insights: manually trigger cron analysis from admin UI
        @set_time_limit(600);
        @ignore_user_abort(true);
        require_once __DIR__ . '/lib/AIProviderInterface.php';
        require_once __DIR__ . '/lib/GoogleAIProvider.php';
        require_once __DIR__ . '/lib/LMStudioAIProvider.php';
        require_once __DIR__ . '/lib/ReplicateAIProvider.php';
        require_once __DIR__ . '/lib/TicketDataExtractor.php';
        require_once __DIR__ . '/lib/AIController.php';
        require_once __DIR__ . '/lib/CronProcessor.php';

        $processor = new \Sahdev\Lib\CronProcessor();
        $result    = $processor->run(true); // verbose=true returns diagnostic info

        // Record successful heartbeat after manual run
        try {
            \WHMCS\Database\Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'last_cron_success' => \Carbon\Carbon::now(),
                'updated_at'        => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {}

        $analyzed = $result['analyzed'] ?? 0;
        $found    = $result['tickets_found'] ?? 0;
        $errors   = $result['errors'] ?? [];

        if ($analyzed > 0) {
            $msg = "Done! Analyzed {$analyzed} of {$found} ticket(s) successfully.";
            if (!empty($errors)) {
                $msg .= ' ' . count($errors) . ' ticket(s) had errors (see Audit Trail).';
            }
            $response = ['status' => 'success', 'message' => $msg, 'result' => $result];
        } elseif (!empty($errors)) {
            // Nothing was analyzed AND there are errors — surface them
            $response = [
                'status'  => 'error',
                'message' => implode(' | ', $errors),
                'result'  => $result,
            ];
        } else {
            $response = ['status' => 'success', 'message' => "No tickets needed analysis right now (found: {$found}).", 'result' => $result];
        }
    } elseif ($action === 'run_tools_for_ticket') {
        $runTicketId = (int) ($_POST['ticket_id'] ?? 0);
        $force = !empty($_POST['force']) && $_POST['force'] === '1';
        if ($runTicketId <= 0) {
            $response = ['status' => 'error', 'message' => 'Missing ticket_id'];
        } else {
            $result = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runTicket($runTicketId, (int) $adminId, $force);
            $summary = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::getLatestRunSummary($runTicketId);
            $response = ['status' => 'success', 'result' => $result, 'summary' => $summary];
        }
    } elseif ($action === 'get_tools_ticket_status') {
        $runTicketId = (int) ($_POST['ticket_id'] ?? 0);
        if ($runTicketId <= 0) {
            $response = ['status' => 'error', 'message' => 'Missing ticket_id'];
        } else {
            $summary = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::getLatestRunSummary($runTicketId);
            $response = ['status' => 'success', 'summary' => $summary];
        }
    } elseif ($action === 'run_tools_queue') {
        $result = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runCron(true);
        $response = ['status' => 'success', 'result' => $result];
    } elseif ($action === 'get_tools_operations') {
        $ops = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::listOperations();
        $smart = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::inferSmartValues((int) $ticketId, (int) $adminId);
        $response = ['status' => 'success', 'operations' => $ops, 'smart' => $smart];
    } elseif ($action === 'run_manual_tool') {
        $runTicketId = (int) ($_POST['ticket_id'] ?? 0);
        $method = (string) ($_POST['method'] ?? 'GET');
        $path = html_entity_decode((string) ($_POST['path'] ?? ''), ENT_QUOTES);
        $pathParamsRaw = $_POST['path_params'] ?? '{}';
        if (is_string($pathParamsRaw)) $pathParamsRaw = html_entity_decode($pathParamsRaw, ENT_QUOTES);
        $queryRaw = $_POST['query'] ?? '{}';
        if (is_string($queryRaw)) $queryRaw = html_entity_decode($queryRaw, ENT_QUOTES);
        $bodyRaw = $_POST['body'] ?? '{}';
        if (is_string($bodyRaw)) $bodyRaw = html_entity_decode($bodyRaw, ENT_QUOTES);

        $pathParams = is_array($pathParamsRaw) ? $pathParamsRaw : (json_decode($pathParamsRaw, true) ?: []);
        $query = is_array($queryRaw) ? $queryRaw : (json_decode($queryRaw, true) ?: []);
        $body = is_array($bodyRaw) ? $bodyRaw : (json_decode($bodyRaw, true) ?: []);

        // Debug trace: Log what the backend received
        try {
            \WHMCS\Database\Capsule::table('tblsahdev_module_logs')->insert([
                'level' => 'debug',
                'source' => 'AJAX::run_manual_tool',
                'message' => sprintf('Manual Tool Request Trace: ticket=%d, path=%s, params=%s', $runTicketId, $path, (string)$pathParamsRaw),
                'ticket_id' => $runTicketId,
                'created_at' => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {}

        $manual = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runManualTool($runTicketId, (int) $adminId, $method, $path, $pathParams, $query, $body);
        $summary = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::getLatestRunSummary($runTicketId);
        $response = ['status' => 'success', 'result' => $manual, 'summary' => $summary];
    } elseif ($action === 'autopilot_test_run') {
        // ── Manual autopilot test for a specific ticket ──────────────────────
        require_once __DIR__ . '/lib/AutopilotProcessor.php';
        require_once __DIR__ . '/lib/WhmcsTicketTagHelper.php';
        require_once __DIR__ . '/lib/CronProcessor.php';

        $forceTicketId = (int) ($_POST['force_ticket_id'] ?? 0);
        if ($forceTicketId <= 0) {
            $response = ['status' => 'error', 'message' => 'No ticket ID provided for test run.'];
        } else {
            // Build provider the same way CronProcessor does
            $taskPrimary = \Sahdev\Lib\TaskProviderResolver::resolveProviderId(
                \Sahdev\Lib\TaskProviderResolver::TASK_AUTOPILOT,
                null,
                $settingsArray
            );
            $apProvRow = \WHMCS\Database\Capsule::table('tblsahdev_providers')
                ->where('id', $taskPrimary)
                ->where('is_active', 1)
                ->first();
            if (!$apProvRow) {
                $apProvRow = \WHMCS\Database\Capsule::table('tblsahdev_providers')
                    ->where('id', $settingsArray['primary_provider_id'])
                    ->first();
            }
            $provSettings = $apProvRow ? array_merge($settingsArray, (array) $apProvRow) : $settingsArray;
            $provType = strtolower($apProvRow->provider_type ?? 'google');
            $apiKey = !empty($apProvRow->api_key) ? decrypt($apProvRow->api_key) : '';

            switch ($provType) {
                case 'lmstudio': $prov = new \Sahdev\Lib\LMStudioAIProvider($apProvRow->api_url ?? '', $apiKey); break;
                case 'replicate': $prov = new \Sahdev\Lib\ReplicateAIProvider($apProvRow->api_url ?? '', $apiKey); break;
                default: $prov = new \Sahdev\Lib\GoogleAIProvider($apiKey);
            }

            $bypassSafety = !empty($_POST['bypass_safety']) && $_POST['bypass_safety'] === '1';
            $autopilot = new \Sahdev\Lib\AutopilotProcessor($settingsArray, $prov, null);
            $response = $autopilot->runForceTicket($forceTicketId, $bypassSafety);
        }
    } elseif ($action === 'set_ui_theme') {
        // Quick-switch UI theme preference (classic / remastered)
        $theme = strtolower(trim((string) ($_POST['theme'] ?? '')));
        $allowed = [\Sahdev\Lib\AdminPreferences::THEME_CLASSIC, \Sahdev\Lib\AdminPreferences::THEME_REMASTERED];
        if (!in_array($theme, $allowed, true)) {
            $response = ['status' => 'error', 'message' => 'Invalid theme value.'];
        } else {
            $prefs = \Sahdev\Lib\AdminPreferences::load((int) $adminId);
            $prefs['ui_theme'] = $theme;
            \Sahdev\Lib\AdminPreferences::save((int) $adminId, $prefs);
            $response = ['status' => 'success', 'theme' => $theme];
        }
    } elseif ($action === 'get_header_server_widget') {
        require_once __DIR__ . '/lib/ServerTelemetryService.php';

        $pollNow = !empty($_REQUEST['poll_now']) && $_REQUEST['poll_now'] == '1';
        if ($pollNow) {
            try {
                \Sahdev\Lib\ServerTelemetryService::pollActiveServers(true);
            } catch (\Throwable $e) {}
        }

        // Determine context server (from service_id or ticket_id)
        $contextServiceId = (int) ($_REQUEST['service_id'] ?? 0);
        $contextTicketId = (int) ($_REQUEST['ticket_id'] ?? 0);
        $contextServerId = 0;
        $contextAccountUsername = '';
        $contextAccountData = null;

        if ($contextServiceId > 0) {
            $hRow = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $contextServiceId)->first();
            if ($hRow) {
                $contextServerId = (int) ($hRow->server ?? 0);
                $contextAccountUsername = (string) ($hRow->username ?? '');
            }
        } elseif ($contextTicketId > 0) {
            $ticketRow = \WHMCS\Database\Capsule::table('tbltickets')->where('id', $contextTicketId)->first();
            if ($ticketRow && !empty($ticketRow->userid)) {
                $hRow = \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('userid', (int) $ticketRow->userid)
                    ->whereIn('domainstatus', ['Active', 'Suspended'])
                    ->orderBy('id', 'desc')
                    ->first();
                if ($hRow) {
                    $contextServerId = (int) ($hRow->server ?? 0);
                    $contextAccountUsername = (string) ($hRow->username ?? '');
                }
            }
        }

        // Fetch active incidents
        $activeIncidents = \WHMCS\Database\Capsule::table('tblsahdev_incidents')
            ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($inc) {
                return [
                    'id' => (int) $inc->id,
                    'incident_num' => (string) $inc->incident_num,
                    'title' => (string) $inc->title,
                    'severity' => (string) $inc->severity,
                    'status' => (string) $inc->status,
                    'server_id' => (int) ($inc->server_id ?? 0),
                    'server_name' => (string) ($inc->server_name ?? ''),
                    'root_cause' => (string) ($inc->root_cause_summary ?? ''),
                    'detected_at' => (string) $inc->detected_at,
                ];
            })
            ->toArray();

        // Ensure schema is fully migrated before querying
        require_once __DIR__ . '/lib/ServerTelemetryService.php';
        \Sahdev\Lib\ServerTelemetryService::ensureSchema();

        if (!empty($_GET['poll_now'])) {
            try {
                \Sahdev\Lib\ServerTelemetryService::pollActiveServers(true);
            } catch (\Throwable $e) {}
        }

        // Fetch all servers with fallback protection
        try {
            $serversDb = \WHMCS\Database\Capsule::table('tblservers as s')
                ->leftJoin('tblsahdev_server_telemetry as st', 's.id', '=', 'st.server_id')
                ->where('s.disabled', 0)
                ->select(
                    's.id as server_id',
                    's.name as server_name',
                    's.hostname',
                    's.ipaddress',
                    's.type as server_type',
                    's.username',
                    \WHMCS\Database\Capsule::raw('COALESCE(st.server_role, "auto") as server_role'),
                    \WHMCS\Database\Capsule::raw('COALESCE(st.is_monitored, 1) as is_monitored'),
                    'st.server_load',
                    'st.is_reachable',
                    'st.reachability_error',
                    'st.accounts_data_json',
                    'st.server_stats_json',
                    'st.last_polled_at'
                )
                ->orderBy('s.name', 'asc')
                ->get();
        } catch (\Throwable $dbEx) {
            \Sahdev\Lib\ServerTelemetryService::ensureSchema();
            $serversDb = \WHMCS\Database\Capsule::table('tblservers as s')
                ->leftJoin('tblsahdev_server_telemetry as st', 's.id', '=', 'st.server_id')
                ->where('s.disabled', 0)
                ->select(
                    's.id as server_id',
                    's.name as server_name',
                    's.hostname',
                    's.ipaddress',
                    's.type as server_type',
                    's.username',
                    'st.server_load',
                    'st.is_reachable',
                    'st.reachability_error',
                    'st.accounts_data_json',
                    'st.server_stats_json',
                    'st.last_polled_at'
                )
                ->orderBy('s.name', 'asc')
                ->get();
        }

        $serverList = [];
        $totalOutagesCount = 0;
        $totalWarningsCount = 0;
        $reachableCount = 0;
        $monitoredCount = 0;
        $canAccessServer = \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_SERVER_ACCESS);

        foreach ($serversDb as $srv) {
            $sId = (int) $srv->server_id;
            $isMon = !isset($srv->is_monitored) || (int) $srv->is_monitored === 1;
            $isReachable = !empty($srv->is_reachable);
            if ($isMon) $monitoredCount++;
            if ($isReachable) $reachableCount++;

            $statsPayload = !empty($srv->server_stats_json) ? json_decode($srv->server_stats_json, true) : [];
            $serviceOutages = $statsPayload['flagged_service_outages'] ?? [];
            $systemWarnings = $statsPayload['flagged_system_warnings'] ?? [];
            $accountNotices = $statsPayload['flagged_account_notices'] ?? [];

            // Backward compatibility for old payloads
            if (empty($serviceOutages) && empty($systemWarnings) && !empty($statsPayload['flagged_items'])) {
                foreach ($statsPayload['flagged_items'] as $fi) {
                    if (strpos($fi, 'Account') !== false) {
                        $accountNotices[] = $fi;
                    } elseif (strpos($fi, 'DOWN') !== false || strpos($fi, 'unreachable') !== false || strpos($fi, 'Critical') !== false) {
                        $serviceOutages[] = $fi;
                    } else {
                        $systemWarnings[] = $fi;
                    }
                }
            }

            if (!$isReachable) {
                $totalOutagesCount++;
            } else {
                $totalOutagesCount += count($serviceOutages);
                $totalWarningsCount += count($systemWarnings);
            }

            // Role detection
            $curRole = (string) ($srv->server_role ?? 'auto');
            if ($curRole === 'auto' || empty($curRole)) {
                $curRole = \Sahdev\Lib\ServerTelemetryService::detectServerRole($srv);
            }

            // Check if context account is on this server
            $accountInfo = null;
            if ($sId === $contextServerId && $contextAccountUsername !== '') {
                $accounts = !empty($srv->accounts_data_json) ? json_decode($srv->accounts_data_json, true) : [];
                if (is_array($accounts)) {
                    foreach ($accounts as $acct) {
                        if (strtolower($acct['user'] ?? '') === strtolower($contextAccountUsername)) {
                            $accountInfo = $acct;
                            break;
                        }
                    }
                }
            }

            $serverItem = [
                'server_id' => $sId,
                'server_name' => (string) ($srv->server_name ?: 'Server #' . $sId),
                'server_host' => (string) ($srv->hostname ?: $srv->ipaddress),
                'server_type' => (string) ($srv->server_type ?: 'cpanel'),
                'server_role' => $curRole,
                'is_monitored' => $isMon,
                'is_reachable' => $isReachable,
                'reachability_error' => (string) ($srv->reachability_error ?? ''),
                'server_load' => (string) ($srv->server_load ?? 'N/A'),
                'last_polled_at' => !empty($srv->last_polled_at) ? substr((string) $srv->last_polled_at, 0, 16) : 'Never',
                'service_outages' => $serviceOutages,
                'system_warnings' => $systemWarnings,
                'account_notices_count' => count($accountNotices),
                'access_url' => $canAccessServer ? \Sahdev\Lib\ServerTelemetryService::getServerAccessUrl($sId) : '',
                'is_context_pinned' => ($sId === $contextServerId),
                'context_account' => $accountInfo,
            ];

            if ($sId === $contextServerId) {
                // Pin context server to the very top
                array_unshift($serverList, $serverItem);
            } else {
                $serverList[] = $serverItem;
            }
        }

        $overallStatus = 'healthy';
        if ($totalOutagesCount > 0 || count($activeIncidents) > 0 || $reachableCount < $monitoredCount) {
            $overallStatus = 'critical';
        } elseif ($totalWarningsCount > 0) {
            $overallStatus = 'warning';
        }

        $response = [
            'status' => 'success',
            'summary' => [
                'total_servers' => count($serversDb),
                'monitored_servers' => $monitoredCount,
                'reachable_servers' => $reachableCount,
                'total_outages' => $totalOutagesCount,
                'total_warnings' => $totalWarningsCount,
                'active_incidents_count' => count($activeIncidents),
                'overall_status' => $overallStatus,
            ],
            'context_server_id' => $contextServerId,
            'servers' => $serverList,
            'active_incidents' => $activeIncidents,
        ];
    } else {
        // Default analyze_ticket (server-side generation)
        $response = $controller->getAnalysis($tone, $instruction, $forceRegenerate, $forceFallback, $intent, $useSummary, $includeHistory, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    }

    // Clean any prior output to prevent malformed JSON
    if (ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode($response);

} catch (\Throwable $e) {
    if (ob_get_length() !== false) {
        ob_clean();
    }

    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => 'Sahdev AI Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
    ]);
}

exit;
