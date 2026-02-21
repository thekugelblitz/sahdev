<?php

/**
 * Sahdev AI - AJAX Endpoint
 * Secured entry point for analyzing tickets.
 */

// Initialize WHMCS securely
require_once dirname(__DIR__, 3) . '/init.php';

// Ensure it returns JSON and blocks WHMCS output buffering warnings
header('Content-Type: application/json');

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

    $controller = new \Sahdev\Lib\AIController($ticketId, $adminId);

    if ($action === 'get_payload') {
        $response = $controller->getPayload($tone, $instruction);
    } elseif ($action === 'save_response') {
        $hashSignature = $_POST['hash_signature'] ?? '';
        $aiResponseStr = $_POST['ai_response'] ?? '{}';
        $tokenUsage = (int) ($_POST['token_usage'] ?? 0);
        $execTime = (int) ($_POST['exec_time'] ?? 0);
        
        $aiResponse = json_decode($aiResponseStr, true);
        if (!$aiResponse) {
            throw new \Exception("Invalid JSON response payload provided.");
        }
        
        $response = $controller->saveResponse($hashSignature, $aiResponse, $tokenUsage, $execTime);
    } else {
        // Default analyze_ticket (server-side generation)
        $response = $controller->getAnalysis($tone, $instruction);
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
