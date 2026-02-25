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

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$tone = strip_tags($_POST['tone'] ?? '');
$instruction = strip_tags($_POST['instruction'] ?? '');
$intensity = (int) ($_POST['intensity'] ?? 3);
$intent = strip_tags($_POST['intent'] ?? 'AUTO');

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

if (!$ticketId) {
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

    $action = $_POST['action'] ?? '';
    $forceRegenerate = !empty($_POST['force_regenerate']) && $_POST['force_regenerate'] === 'true';
    $forceFallback = !empty($_POST['force_fallback']) && $_POST['force_fallback'] === 'true';
    $useSummary = !isset($_POST['use_summary']) || $_POST['use_summary'] == '1';

    // Auto-migration for overwrites without reactivation, specifically for AJAX calls
    try {
        \WHMCS\Database\Capsule::table('tblsahdev_providers')->first();
        \WHMCS\Database\Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
    } catch (\Exception $e) {
        require_once __DIR__ . '/sahdev.php';
        if (function_exists('sahdev_activate')) {
            sahdev_activate();
        }
    }

    $controller = new \Sahdev\Lib\AIController($ticketId, $adminId);

    if ($action === 'get_payload') {
        $response = $controller->getPayload($tone, $instruction, $forceRegenerate, $intent, $useSummary);
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
        $response = $controller->getAnalysis($tone, $instruction, false, false, $intent, $useSummary);
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
    } else {
        // Default analyze_ticket (server-side generation)
        $response = $controller->getAnalysis($tone, $instruction, $forceRegenerate, $forceFallback, $intent, $useSummary);
    }

    // Clean any prior output to prevent malformed JSON
    if (ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode($response);

} catch (\Exception $e) {
    if (ob_get_length() !== false) {
        ob_clean();
    }

    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => 'Sahdev AI Error: ' . $e->getMessage()
    ]);
}

exit;
