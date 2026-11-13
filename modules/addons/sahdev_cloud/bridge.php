<?php
require_once dirname(__DIR__,3).'/init.php';
require_once __DIR__.'/src/Access.php';
require_once __DIR__.'/src/Client.php';
require_once __DIR__.'/src/Tickets.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Use POST.',405);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>32768) throw new RuntimeException('Request too large.',413);
    $admin = \SahdevConnector\Access::admin();
    $input = json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Invalid request.',422);
    \SahdevConnector\Access::csrf((string)($input['csrf'] ?? ''));
    $ticket = \SahdevConnector\Access::ticket((int)($input['ticket_id'] ?? 0),$admin);
    $client = new \SahdevConnector\Client();
    switch ($input['action'] ?? '') {
        case 'models': $result = $client->request('GET','/api/v1/models'); break;
        case 'submit': $result = \SahdevConnector\Tickets::submit($admin,$ticket,$input,$client); break;
        case 'result': $result = \SahdevConnector\Tickets::result($admin,$ticket,(string)($input['id'] ?? ''),$client); break;
        default: throw new RuntimeException('Unsupported bridge action.',422);
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $status = in_array($error->getCode(),[401,402,403,404,405,409,413,419,422,423,429,502,503],true) ? $error->getCode() : 500;
    http_response_code($status);
    echo json_encode(['error'=>$status===500 ? 'Connector request failed. Ask your WHMCS administrator to check the installation.' : $error->getMessage()]);
}
