<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/**
 * Sahdev AI - AJAX Endpoint
 * Secured entry point for analyzing tickets.
 */

// Initialize WHMCS
define('ADMINAREA', true);
require_once __DIR__ . '/../../../init.php';

$whmcsAppConfig = $GLOBALS['customadminpath'] ?? 'admin';
require_once __DIR__ . '/../../../' . $whmcsAppConfig . '/bootstrap.php';

// Check if admin is logged in securely
$adminId = $_SESSION['adminid'] ?? null;
if (!$adminId) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Please login as admin.']);
    exit;
}

// Optional: Validate CSRF token
// WHMCS standard CSRF is usually validated via check_token(), but typically it targets POST endpoints via the main router.
// For custom AJAX, we rely heavily on the active admin session and matching token manually if needed.
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

header('Content-Type: application/json');

try {
    // Autoload is usually handled by WHMCS for our modules/addons/sahdev/lib/ classes
    // if we defined a PSR-4 autoloader or they are included manually.
    // If not using composer autoloader, we simply require our library files:

    require_once __DIR__ . '/lib/AIProviderInterface.php';
    require_once __DIR__ . '/lib/GoogleAIProvider.php';
    require_once __DIR__ . '/lib/TicketDataExtractor.php';
    require_once __DIR__ . '/lib/AIController.php';

    $controller = new \Sahdev\Lib\AIController($ticketId, $adminId);
    $response = $controller->getAnalysis($tone, $instruction);

    echo json_encode($response);

} catch (\Exception $e) {
    // Return friendly error
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => 'Sahdev AI Error: ' . $e->getMessage()
    ]);
}

exit;



