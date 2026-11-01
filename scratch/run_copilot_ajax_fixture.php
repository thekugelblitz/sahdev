<?php
// A fresh process is required because the production endpoint exits after its JSON response.
require __DIR__ . '/copilot_fixture.php';
define('WHMCS', true);
$mode = $argv[1] ?? 'message';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'copilot_send_message', 'token' => 'fixture-csrf', 'message' => 'Hello'];
\Sahdev\Lib\CopilotHttpFixture::$responses = [['choices' => [['message' => ['content' => 'Copilot works without Ticket AI', 'tool_calls' => []]]]]];
if ($mode === 'missing_token') unset($_POST['token']);
if ($mode === 'invalid_token') $_POST['token'] = 'wrong';
if ($mode === 'forbidden') $_SESSION['adminid'] = 2;
if ($mode === 'empty_message') $_POST['message'] = '';
if ($mode === 'execute' || $mode === 'rollback') {
    $session = \Sahdev\Lib\ChatService::getOrCreateAdminSession(1);
    $_POST['action'] = 'copilot_execute_op';
    $_POST['action_key'] = 'ticket_change_status';
    $_POST['session_id'] = $session['id'];
    $_POST['params'] = json_encode(['ticket_id' => 12, 'status' => 'Closed']);
    if ($mode === 'rollback') {
        $result = \Sahdev\Lib\SafeOpsService::executeConfirmedOperation('ticket_change_status', ['ticket_id' => 12, 'status' => 'Closed'], 1, $session['id']);
        $_POST['action'] = 'copilot_rollback_op';
        $_POST['journal_id'] = $result['journal_id'];
    }
}
$_REQUEST = $_POST;
require dirname(__DIR__) . '/ajax.php';
