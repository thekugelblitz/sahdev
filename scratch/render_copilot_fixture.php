<?php
require __DIR__ . '/copilot_fixture.php';
require dirname(__DIR__) . '/hooks.php';
require dirname(__DIR__) . '/controllers/AdminController.php';
$_GET['subtab'] = $argv[1] ?? 'console';
$mode = $_GET['subtab'];
if ($mode === 'history') {
    \WHMCS\Database\Capsule::$rows['tblclients'] = [['id'=>12,'firstname'=>'Dhruv','lastname'=>'Joshi','status'=>'Active','currency'=>1,'credit'=>'5.00']];
    \WHMCS\Database\Capsule::$rows['tblinvoices'] = [
        ['id'=>75992,'userid'=>12,'invoicenum'=>'INV-75992','status'=>'Paid','total'=>'0.00','duedate'=>'2026-11-19','notes'=>'Full invoice details'],
        ['id'=>70049,'userid'=>12,'invoicenum'=>'INV-70049','status'=>'Unpaid','total'=>'2.65','duedate'=>'2026-03-05'],
    ];
    $session = \Sahdev\Lib\ChatService::getOrCreateAdminSession(1);
    $_GET['session'] = $session['session_uuid']; $_GET['subtab'] = 'console';
    $records = \Sahdev\Lib\SafeOpsService::handleOperationRequest('client_invoices', ['client_id'=>12], 1, $session['id']);
    $done = \Sahdev\Lib\SafeOpsService::handleOperationRequest('ticket_change_status', ['ticket_id'=>12,'status'=>'Closed'], 1, $session['id']);
    \Sahdev\Lib\SafeOpsService::executeConfirmedOperation($done['action_key'], $done['params'], 1, $session['id'], null, $done['proposal_id']);
    $expired = \Sahdev\Lib\SafeOpsService::handleOperationRequest('ticket_change_status', ['ticket_id'=>12,'status'=>'Open'], 1, $session['id']);
    \WHMCS\Database\Capsule::table('tblsahdev_ops_proposals')->where('proposal_uuid', $expired['proposal_id'])->update(['expires_at'=>'2020-01-01 00:00:00']);
    \WHMCS\Database\Capsule::table('tblsahdev_chat_messages')->insert([
        'session_id'=>$session['id'], 'sender_type'=>'assistant','sender_name'=>'Copilot',
        'message_text'=>"## Invoices — Dhruv Joshi\n\n**Two invoices** are shown below. Open each invoice or the client profile using its link.\n\n- Review the unpaid balance.\n- Inspect complete details in WHMCS.\n\n| Status | Total |\n|---|---|\n| **Unpaid** | 2.65 |\n\n<script>window.bad=true</script> [Unsafe](javascript:alert)",
        'result_cards_json'=>json_encode($records['result_cards']), 'action_card_json'=>json_encode([$done, $expired]),
        'copilot_access_fingerprint'=>\Sahdev\Lib\CopilotDatabasePolicy::fingerprint(1), 'created_at'=>'2026-11-02 12:00:00',
    ]);
}
$reflection = new ReflectionClass(\Sahdev\Controllers\AdminController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('moduleVars');
$property->setAccessible(true);
$property->setValue($controller, ['modulelink' => 'addonmodules.php?module=sahdev']);
echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';
if ($mode === 'access') echo \Sahdev\Lib\AdminTheme::wrap($controller->copilot_access(), 1);
elseif ($mode !== 'drawer') echo \Sahdev\Lib\AdminTheme::wrap($controller->admin_copilot(), 1);
echo sahdev_render_admin_copilot_drawer();
echo '</body></html>';
