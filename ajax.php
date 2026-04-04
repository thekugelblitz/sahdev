<?php

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method. POST required.']);
    exit;
}

$action = $_POST['action'] ?? '';
$ticketId = (int) ($_POST['ticket_id'] ?? 0);
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
    'get_analytics', 'get_ticket_insights', 'trigger_cron_run'
];
if (!$ticketId && !in_array($action, $ticketNotRequiredActions)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['status' => 'error', 'message' => 'Missing Ticket ID.']);
    exit;
}

try {
    require_once __DIR__ . '/lib/AIProviderInterface.php';
    require_once __DIR__ . '/lib/GoogleAIProvider.php';
    require_once __DIR__ . '/lib/LMStudioAIProvider.php';
    require_once __DIR__ . '/lib/TicketDataExtractor.php';
    require_once __DIR__ . '/lib/AIController.php';

    $forceRegenerate = !empty($_POST['force_regenerate']) && $_POST['force_regenerate'] === 'true';
    $forceFallback = !empty($_POST['force_fallback']) && $_POST['force_fallback'] === 'true';
    $useSummaryRaw = $_POST['use_summary'] ?? '1';
    $useSummary = ($useSummaryRaw === '1' || $useSummaryRaw === 'true' || $useSummaryRaw === 'on' || $useSummaryRaw === true);
    
    $includeHistoryRaw = $_POST['include_historical_context'] ?? '0';
    $includeHistory = ($includeHistoryRaw === '1' || $includeHistoryRaw === 'true' || $includeHistoryRaw === 'on' || $includeHistoryRaw === true);

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

    $controller = new \Sahdev\Lib\AIController($ticketId, $adminId);

    if ($action === 'get_payload') {
        $response = $controller->getPayload($tone, $instruction, $forceRegenerate, $intent, $useSummary, $includeHistory, $technicalContext);
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
        $response = $controller->getAnalysis($tone, $instruction, false, false, $intent, $useSummary, $includeHistory, $technicalContext);
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
            $deleted = Capsule::table('tblsahdev_audit_trail')
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
        $ticketIds = array_map('intval', array_filter($rawIds));

        if (empty($ticketIds)) {
            $response = ['status' => 'success', 'insights' => []];
        } else {
            $rows = \WHMCS\Database\Capsule::table('tblsahdev_sentiment')
                ->whereIn('ticket_id', $ticketIds)
                ->get([
                    'ticket_id', 'score', 'label', 'urgency', 'client_tone',
                    'ticket_summary', 'admin_reply_count', 'last_admin_name', 'analyzed_at'
                ]);

            $insights = [];
            foreach ($rows as $row) {
                $insights[(int)$row->ticket_id] = [
                    'sentiment_score'    => (int)$row->score,
                    'sentiment_label'    => $row->label,
                    'urgency'            => $row->urgency,
                    'client_tone'        => $row->client_tone,
                    'ticket_summary'     => $row->ticket_summary,
                    'admin_reply_count'  => (int)$row->admin_reply_count,
                    'last_admin_name'    => $row->last_admin_name,
                    'analyzed_at'        => $row->analyzed_at,
                ];
            }
            $response = ['status' => 'success', 'insights' => $insights];
        }
    } elseif ($action === 'trigger_cron_run') {
        // Ticket Insights: manually trigger cron analysis from admin UI
        require_once __DIR__ . '/lib/AIProviderInterface.php';
        require_once __DIR__ . '/lib/GoogleAIProvider.php';
        require_once __DIR__ . '/lib/LMStudioAIProvider.php';
        require_once __DIR__ . '/lib/ReplicateAIProvider.php';
        require_once __DIR__ . '/lib/TicketDataExtractor.php';
        require_once __DIR__ . '/lib/AIController.php';
        require_once __DIR__ . '/lib/CronProcessor.php';

        $processor = new \Sahdev\Lib\CronProcessor();
        $processor->run();
        $response = ['status' => 'success', 'message' => 'Cron analysis triggered successfully. Check Ticket Insights for results.'];
    } else {
        // Default analyze_ticket (server-side generation)
        $response = $controller->getAnalysis($tone, $instruction, $forceRegenerate, $forceFallback, $intent, $useSummary, $includeHistory, $technicalContext);
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
