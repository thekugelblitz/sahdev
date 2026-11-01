<?php
require __DIR__ . '/copilot_fixture.php';
use WHMCS\Database\Capsule;
use Sahdev\Lib\ChatService;
use Sahdev\Lib\SafeOpsService;
use Sahdev\Lib\CopilotHttpFixture as Http;

error_reporting(E_ALL);
set_error_handler(function ($level, $message, $file, $line) {
    if ($level & error_reporting()) throw new ErrorException($message, 0, $level, $file, $line);
});
$checks = 0;
function expect($condition, $message) { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function reply($content, $calls = []) { return ['choices' => [['message' => ['content' => $content, 'tool_calls' => $calls]]]]; }
function tool($name, $params) { return ['id' => 'call_' . $name, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($params)]]; }

Http::$responses = [reply('First response')];
$first = ChatService::handleAdminMessage(1, '', 'Hello');
expect($first['success'] && strpos($first['session_uuid'], 'copilot_') === 0, 'Server returns canonical session identity');
Http::$responses = [reply('Second response')];
$second = ChatService::handleAdminMessage(1, $first['session_uuid'], 'Follow up');
expect($first['session_id'] === $second['session_id'] && count(Capsule::$rows['tblsahdev_chat_sessions']) === 1, 'Consecutive messages stay in one session');
$request = json_decode(end(Http::$requests)->options[CURLOPT_POSTFIELDS], true);
expect(count($request['messages']) === 4 && $request['messages'][2]['content'] === 'First response', 'Provider receives the previous turn');
$other = ChatService::getOrCreateAdminSession(2, $first['session_uuid']);
expect($other['id'] !== $first['session_id'], 'A different admin cannot resume the original session');

Http::$responses = [reply('', [tool('ticket_change_status', ['ticket_id' => 12, 'status' => 'Closed'])])];
$proposal = ChatService::handleAdminMessage(1, $first['session_uuid'], 'Close ticket 12');
expect($proposal['success'] && count($proposal['action_cards']) === 1, 'Mutations produce confirmation cards');
expect($proposal['action_cards'][0]['session_id'] === $first['session_id'], 'Proposal is bound to the current session');
expect(Capsule::$rows['tbltickets'][0]['status'] === 'Open' && empty(Capsule::$rows['tblsahdev_ops_journal']), 'Proposal does not execute a mutation');
$executed = SafeOpsService::executeConfirmedOperation('ticket_change_status', ['ticket_id' => 12, 'status' => 'Closed'], 1, $first['session_id']);
expect($executed['success'] && Capsule::$rows['tbltickets'][0]['status'] === 'Closed', 'Explicit confirmation executes the operation');
expect(Capsule::$rows['tblsahdev_ops_journal'][0]['session_id'] === $first['session_id'], 'Journal retains session association');
$rollback = SafeOpsService::rollbackOperation($executed['journal_id'], 1);
expect($rollback['success'] && Capsule::$rows['tbltickets'][0]['status'] === 'Open', 'Rollback restores the ticket status');
$destructive = SafeOpsService::executeConfirmedOperation('invoice_mark_paid', ['invoice_id' => 12], 1, $first['session_id']);
expect(!$destructive['success'], 'Destructive operation refuses an absent admin password');

Capsule::$rows['tblcurrencies'] = [['id' => 1, 'code' => 'USD', 'default' => 1], ['id' => 2, 'code' => 'INR', 'default' => 0]];
Capsule::$rows['tblclients'] = [['id' => 1, 'currency' => 1], ['id' => 2, 'currency' => 2]];
Capsule::$rows['tblhosting'] = [
    ['id' => 1, 'userid' => 1, 'domainstatus' => 'Active', 'amount' => 120, 'billingcycle' => 'Annually'],
    ['id' => 2, 'userid' => 1, 'domainstatus' => 'Active', 'amount' => 30, 'billingcycle' => 'Monthly'],
    ['id' => 3, 'userid' => 2, 'domainstatus' => 'Active', 'amount' => 10000, 'billingcycle' => 'Monthly'],
    ['id' => 4, 'userid' => 1, 'domainstatus' => 'Suspended', 'amount' => 100, 'billingcycle' => 'Monthly'],
];
Capsule::$rows['tblinvoices'] = [
    ['id' => 1, 'userid' => 1, 'status' => 'Paid', 'total' => 25, 'datepaid' => '2026-11-02 08:00:00'],
    ['id' => 2, 'userid' => 2, 'status' => 'Paid', 'total' => 9999, 'datepaid' => '2026-11-02 08:00:00'],
    ['id' => 3, 'userid' => 1, 'status' => 'Unpaid', 'total' => 15, 'datepaid' => '0000-00-00 00:00:00'],
    ['id' => 4, 'userid' => 1, 'status' => 'Paid', 'total' => 5, 'datepaid' => '2026-11-01 08:00:00'],
    ['id' => 5, 'userid' => 1, 'status' => 'Paid', 'total' => 20, 'datepaid' => '2026-10-01 08:00:00'],
];
$metrics = SafeOpsService::handleOperationRequest('financial_metrics', [], 1, $first['session_id']);
expect($metrics['success'] && $metrics['data']['currency'] === 'USD', 'Metrics use the configured default currency');
expect($metrics['data']['metrics']['mrr'] === 40.0, 'MRR normalizes billing cycles and excludes other currencies/inactive hosting');
expect($metrics['data']['metrics']['today_paid'] === 25.0 && $metrics['data']['metrics']['this_month_paid'] === 30.0, 'Today/month totals use distinct date windows');
expect($metrics['data']['metrics']['last_month_paid'] === 20.0 && $metrics['data']['metrics']['unpaid_amount'] === 15.0, 'Previous month and unpaid amounts remain currency isolated');
$missing = SafeOpsService::handleOperationRequest('financial_metrics', ['currency_id' => 99], 1, $first['session_id']);
expect(!$missing['success'], 'Unknown currency returns an actionable error');
$denied = SafeOpsService::handleOperationRequest('financial_metrics', [], 2, $other['id']);
expect(!$denied['success'], 'Metrics tool enforces metrics_view permission');

Http::$responses = [reply('', [tool('financial_metrics', [])]), reply('MRR is USD 40; invoices paid today total USD 25.')];
$answer = ChatService::handleAdminMessage(1, $first['session_uuid'], "Show today's MRR and revenue status");
expect($answer['success'] && strpos($answer['reply'], 'USD 40') !== false, 'MRR request completes the provider/tool/synthesis flow');
$request = json_decode(end(Http::$requests)->options[CURLOPT_POSTFIELDS], true);
$evidence = json_decode(end($request['messages'])['content'], true);
expect($evidence['metrics']['mrr'] == 40 && $evidence['currency'] === 'USD', 'Model receives measured financial evidence');

Http::$responses = [reply('', [tool('open_tickets', ['limit' => 20])]), reply('One open ticket: 12')];
$tickets = ChatService::handleAdminMessage(1, $first['session_uuid'], 'Show open tickets');
expect($tickets['success'] && strpos($tickets['reply'], 'ticket: 12') !== false, 'Open Tickets shortcut has a working read tool');
Http::$responses = [reply('')];
$empty = ChatService::handleAdminMessage(1, $first['session_uuid'], 'Empty response');
expect(!$empty['success'] && strpos($empty['error'], 'empty reply') !== false && $empty['session_uuid'] === $first['session_uuid'], 'Empty provider response is an explicit error without losing the thread');
echo "PASS: $checks Copilot backend checks (history, isolation, tool execution, confirmation, rollback, metrics, permissions, provider errors).\n";
