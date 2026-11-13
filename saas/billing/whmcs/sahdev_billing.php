<?php
/** Install ONLY on HostingSpell's billing WHMCS, in includes/hooks/. Never distribute its secret to customers. */
if (!defined('WHMCS')) die('Direct access is not permitted.');
use WHMCS\Database\Capsule;

function sahdev_cloud_billing_outbox_schema()
{
    if (Capsule::schema()->hasTable('mod_sahdev_billing_outbox')) return;
    Capsule::schema()->create('mod_sahdev_billing_outbox',function ($t) {
        $t->engine='InnoDB'; $t->string('event_id',120)->primary(); $t->text('payload');
        $t->integer('attempts')->default(0); $t->dateTime('next_attempt_at'); $t->dateTime('delivered_at')->nullable(); $t->dateTime('created_at');
    });
}
function sahdev_cloud_billing_enqueue($invoiceId,$kind)
{
    try {
        $invoice = localAPI('GetInvoice',['invoiceid'=>(int)$invoiceId]);
        if (($invoice['result'] ?? '') !== 'success' || !preg_match('/Sahdev order: [a-f0-9-]{36}/',$invoice['notes'] ?? '')) return;
        sahdev_cloud_billing_outbox_schema();
        $id='whmcs:'.$kind.':'.(int)$invoiceId;
        Capsule::table('mod_sahdev_billing_outbox')->insertOrIgnore(['event_id'=>$id,'payload'=>json_encode(['event_id'=>$id,'invoice_id'=>(int)$invoiceId,'kind'=>$kind],JSON_UNESCAPED_SLASHES),'attempts'=>0,'next_attempt_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) { logActivity('Sahdev billing: event enqueue failed for invoice '.(int)$invoiceId.'. Check billing reconciliation.'); }
}
add_hook('InvoicePaid',1,function ($v) { sahdev_cloud_billing_enqueue($v['invoiceid'],'paid'); });
add_hook('InvoiceRefunded',1,function ($v) { sahdev_cloud_billing_enqueue($v['invoiceid'],'reversal'); });
add_hook('InvoiceCancelled',1,function ($v) { sahdev_cloud_billing_enqueue($v['invoiceid'],'reversal'); });
add_hook('AfterCronJob',1,function () {
    // Environment variables are managed on HostingSpell's server, never through invoice notes or customer input.
    $url=rtrim((string)getenv('SAHDEV_BILLING_CLOUD_URL'),'/'); $secret=(string)getenv('SAHDEV_BILLING_WEBHOOK_SECRET');
    $parts=parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !empty($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || strlen($secret)<32) return;
    sahdev_cloud_billing_outbox_schema();
    $rows=Capsule::table('mod_sahdev_billing_outbox')->whereNull('delivered_at')->where('next_attempt_at','<=',date('Y-m-d H:i:s'))->orderBy('created_at')->limit(20)->get();
    foreach ($rows as $row) {
        // Claim by moving its due time. A killed cron leaves a retryable event, not an acknowledged payment.
        $claimed=Capsule::table('mod_sahdev_billing_outbox')->where('event_id',$row->event_id)->whereNull('delivered_at')->where('next_attempt_at','<=',date('Y-m-d H:i:s'))->update(['next_attempt_at'=>date('Y-m-d H:i:s',time()+3600),'attempts'=>$row->attempts+1]);
        if (!$claimed) continue;
        $time=(string)time(); $signature=hash_hmac('sha256',$time."\n".$row->payload,$secret);
        $ch=curl_init($url.'/api/v1/billing/events');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$row->payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-Sahdev-Time: '.$time,'X-Sahdev-Signature: '.$signature]]);
        $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($status===200 && !empty(json_decode($body ?: '',true)['received'])) Capsule::table('mod_sahdev_billing_outbox')->where('event_id',$row->event_id)->update(['delivered_at'=>date('Y-m-d H:i:s')]);
        else logActivity('Sahdev billing: delivery pending for '.$row->event_id.' (HTTP '.$status.').');
    }
});
