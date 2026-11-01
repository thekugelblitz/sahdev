<?php
namespace Sahdev\Lib {
    /** HTTP/DNS boundary fixtures; the production router, validation and normalization still run. */
    class ExchangeHttp { public static $calls=[]; public static $mode='success'; }
    function gethostbynamel($host) { return ['93.184.216.34']; }
    function dns_get_record($host,$type) { return []; }
    function curl_init($url) { return (object) ['url'=>$url,'options'=>[]]; }
    function curl_setopt_array($handle,$options) { $handle->options=$options; return true; }
    function curl_setopt($handle,$key,$value) { $handle->options[$key]=$value; return true; }
    function curl_exec($handle) {
        ExchangeHttp::$calls[]=$handle;
        $meta=['query'=>'indiaremediesoverseas.com','timestamp'=>gmdate('c'),'duration_ms'=>1,'cached'=>false];
        $payload=strpos($handle->url,'/dns/')!==false
            ? ['metadata'=>$meta,'records'=>[['type'=>'A','name'=>'indiaremediesoverseas.com','value'=>'93.184.216.34','ttl'=>60]]]
            : ['metadata'=>$meta,'target'=>'indiaremediesoverseas.com','reachable'=>true,'status_code'=>503,'headers'=>['server'=>'fixture']];
        if (ExchangeHttp::$mode==='schema') $payload=['unexpected'=>'shape'];
        ($handle->options[CURLOPT_WRITEFUNCTION])($handle,json_encode($payload));
        return true;
    }
    function curl_getinfo($handle,$key) { return ExchangeHttp::$mode==='auth' ? 401 : 200; }
    function curl_error($handle) { return ExchangeHttp::$mode==='timeout' ? 'fixture timeout' : ''; }
    function curl_errno($handle) { return ExchangeHttp::$mode==='timeout' ? 28 : 0; }
    function curl_close($handle) {}
}
namespace {
    function decrypt($value) { return 'fixture-secret'; }
    require __DIR__.'/test_diagnostic_workflow.php';
    use WHMCS\Database\Capsule as DB;
    use Sahdev\Lib\DiagnosticWorkflow as W;
    use Sahdev\Lib\DiagnosticPolicy as P;
    use Sahdev\Lib\LiveChatToolService as T;
    use Sahdev\Lib\ExchangeHttp as HTTP;
    $count=0;
    set_error_handler(static function($severity,$message,$file,$line) { if ($severity & error_reporting()) throw new ErrorException($message,0,$severity,$file,$line); });
    DB::$db->exec("ALTER TABLE tblsahdev_settings ADD COLUMN tools_api_key_encrypted TEXT DEFAULT 'fixture-encrypted'");
    DB::$db->exec("ALTER TABLE tblsahdev_settings ADD COLUMN tools_api_base_url TEXT DEFAULT 'https://toolsapi.2hs.in'");
    DB::$db->exec('ALTER TABLE tblsahdev_settings ADD COLUMN tools_request_retry_count INTEGER DEFAULT 0');
    DB::$db->exec('UPDATE tblsahdev_settings SET client_chat_tools_enabled=1');
    $policy=P::defaults();$policy['planner']='off';policy($policy);T::setEnabledOverride(null);
    $first=W::run('indiaremediesoverseas.com is not loading','livechat',200,1,'1');
    expect($first['status']==='complete' && count(HTTP::$calls)===2,'Exact initial report invokes real executor for DNS and headers');
    expect($first['checks'][0]['success'] && $first['checks'][1]['success'],'Both HTTP contracts validated successfully');
    expect(json_decode($first['checks'][1]['evidence'],true)['facts']['status_code']===503,'Website 503 preserved rather than reported healthy');
    $follow=W::run('Can you check where it is pointing right now with A record IP share it','livechat',200,1,'2');
    expect(count(HTTP::$calls)===3 && count($follow['checks'])===1,'Follow-up invokes exactly one DNS check');
    expect(strpos(HTTP::$calls[2]->url,'/dns/indiaremediesoverseas.com?type=A')!==false,'Follow-up sends correct target and explicit A query');
    expect(json_decode($follow['checks'][0]['evidence'],true)['facts']['records'][0]['value']==='93.184.216.34','A-record IP reaches main reply evidence');
    expect(strpos(W::promptBlock($follow),'93.184.216.34')!==false,'Main prompt includes observed IP');
    $events=DB::$db->query("SELECT message FROM tblsahdev_module_logs WHERE source='diagnostic_workflow'")->fetchAll(PDO::FETCH_COLUMN);
    $joined=implode('\n',$events);
    expect(strpos($joined,'tool_result')!==false && strpos($joined,'fixture-secret')===false,'Tool outcomes logged without HTTP credentials');
    $before=count(HTTP::$calls);W::run('Can you check where it is pointing right now with A record IP share it','livechat',200,1,'2');
    expect(count(HTTP::$calls)===$before,'Exact turn replay does not repeat HTTP calls');
    DB::$db->exec('CREATE TABLE tblsahdev_chat_sessions (id INTEGER,client_id INTEGER,session_type TEXT)');
    DB::$db->exec('CREATE TABLE tblsahdev_chat_messages (id INTEGER,session_id INTEGER,sender_type TEXT,message_text TEXT,created_at TEXT)');
    DB::table('tblsahdev_chat_sessions')->insert(['id'=>201,'client_id'=>1,'session_type'=>'client_livechat']);
    DB::table('tblsahdev_chat_messages')->insert(['id'=>10,'session_id'=>201,'sender_type'=>'user','message_text'=>'indiaremediesoverseas.com is not loading','created_at'=>date('Y-m-d H:i:s')]);
    DB::table('tblsahdev_chat_messages')->insert(['id'=>11,'session_id'=>201,'sender_type'=>'assistant','message_text'=>'Use dnschecker.org','created_at'=>date('Y-m-d H:i:s')]);
    $restored=W::run('Check its A record','livechat',201,1,'12');
    expect($restored['target']['host']==='indiaremediesoverseas.com','Old sessions recover customer target without taking assistant links');
    expect(W::run('Check its A record','livechat',201,2,'12')['status']==='clarification','History recovery cannot cross customer ownership');
    foreach (['auth'=>'authentication_failed','schema'=>'invalid_response','timeout'=>'timeout'] as $mode=>$code) {
        HTTP::$mode=$mode;
        $r=T::executeToolCall('toolsapi_dns_lookup',['domain'=>'indiaremediesoverseas.com','type'=>'A'],200);
        expect(!$r['success'] && $r['error']===$code,'Failed API mode '.$mode.' has actionable code');
        $event=json_decode(DB::$db->query('SELECT message FROM tblsahdev_module_logs ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
        expect($event['event']==='tool_result' && $event['error_code']===$code,'Failed API mode '.$mode.' is logged');
    }
    HTTP::$mode='success';
    $policy['logs_enabled']=false;policy($policy);
    $before=DB::$db->query('SELECT COUNT(*) FROM tblsahdev_module_logs')->fetchColumn();
    expect(W::run('Check A record for indiaremediesoverseas.com','livechat',200,1,'silent')['checks'][0]['success'],'Turning logging off does not disable DNS execution');
    expect(DB::$db->query('SELECT COUNT(*) FROM tblsahdev_module_logs')->fetchColumn()===$before,'Successful workflow and tools stay silent with logging off');
    $policy['logs_enabled']=true;$policy['planner']='always';$policy['planner_provider']=1;policy($policy);
    \Sahdev\Lib\ChatService::$reply='{"checks":[]}';
    expect(W::run('Check A record for indiaremediesoverseas.com','livechat',200,1,'empty-plan')['checks'][0]['success'],'Empty planner response cannot suppress an explicit DNS check');
    \Sahdev\Lib\ChatService::$reply='{"checks":[{"tool":"toolsapi_dns_lookup","args":{"domain":"indiaremediesoverseas.com","type":"MX"}}]}';
    W::run('Check A record for indiaremediesoverseas.com','livechat',200,1,'wrong-type');
    expect(strpos(end(HTTP::$calls)->url,'type=A')!==false,'Planner cannot replace an explicitly requested A record with MX');
    echo "Diagnostic exchange: $count checks passed\n";
}
