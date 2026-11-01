<?php
// Offline integration harness: real SQLite queries for tenant filters and evidence persistence.
namespace WHMCS\Database {
    class Capsule {
        public static $db;
        public static $denyLock = false;
        public static function table($name) { return new Query($name); }
        public static function schema() { return new class { public function hasTable($name) { return (bool) Capsule::$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . Capsule::$db->quote($name))->fetchColumn(); } }; }
        public static function connection() { return new class { public function selectOne($sql, $args) { return (object) ['acquired'=>Capsule::$denyLock ? 0 : 1,'released'=>1]; } }; }
    }
    class Rows extends \ArrayIterator { public function toArray() { return $this->getArrayCopy(); } }
    class Query {
        private $table; private $filters=[]; private $params=[]; private $joins=''; private $limit=1000; private $order='';
        public function __construct($name) { $this->table=$name; }
        public function where($column,$operator,$value=null) { if (func_num_args()===2) { $value=$operator; $operator='='; } $this->filters[]="$column $operator ?"; $this->params[]=$value; return $this; }
        public function whereIn($column,$values) { $this->filters[]=$column.' IN ('.implode(',',array_fill(0,count($values),'?')).')'; $this->params=array_merge($this->params,$values); return $this; }
        public function leftJoin($table,$a,$op,$b) { $this->joins.=" LEFT JOIN $table ON $a $op $b"; return $this; }
        public function limit($n) { $this->limit=$n; return $this; }
        public function orderBy($column,$direction='asc') { $this->order=" ORDER BY $column $direction"; return $this; }
        private function clause() { return $this->filters ? ' WHERE '.implode(' AND ',$this->filters) : ''; }
        public function get($fields=['*']) { $q=Capsule::$db->prepare('SELECT '.implode(',',$fields).' FROM '.$this->table.$this->joins.$this->clause().$this->order.' LIMIT '.$this->limit); $q->execute($this->params); return new Rows($q->fetchAll(\PDO::FETCH_OBJ)); }
        public function first($fields=['*']) { return $this->limit(1)->get($fields)->getArrayCopy()[0] ?? null; }
        public function value($field) { $row=$this->first([$field]); return $row->$field ?? null; }
        public function exists() { return $this->first() !== null; }
        public function insert($values) { $q=Capsule::$db->prepare('INSERT INTO '.$this->table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')'); $q->execute(array_values($values)); }
        public function update($values) { $q=Capsule::$db->prepare('UPDATE '.$this->table.' SET '.implode(',',array_map(static function($f){return $f.'=?';},array_keys($values))).$this->clause()); $q->execute(array_merge(array_values($values),$this->params)); }
        public function pluck($field) { return new Rows(array_map(static function($r)use($field){return $r->$field;},$this->get([$field])->getArrayCopy())); }
        public function updateOrInsert($keys,$values) { $data=array_merge($keys,$values); $fields=array_keys($data); $sql='INSERT INTO '.$this->table.' ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).') ON CONFLICT('.implode(',',array_keys($keys)).') DO UPDATE SET '.implode(',',array_map(static function($f){return $f.'=excluded.'.$f;},array_keys($values))); $q=Capsule::$db->prepare($sql); $q->execute(array_values($data)); }
        public function delete() { $q=Capsule::$db->prepare('DELETE FROM '.$this->table.$this->clause()); $q->execute($this->params); return $q->rowCount(); }
    }
}
namespace Sahdev\Lib {
    class ModuleLogger { public static function info(...$args) {} public static function warning(...$args) {} }
    // Planner fixture never contacts an AI provider.
    class ChatService {
        public static $calls=0;
        public static $reply='invalid planner JSON';
        public static function instantiateProviderFromRecord($row) { return new class { public function generateChat($messages,$tools,$settings) { ChatService::$calls++; return ['content'=>ChatService::$reply]; } public function getLastTokenDetails() { return ['input'=>10,'output'=>3]; } }; }
    }
}
namespace {
    require __DIR__ . '/../lib/DiagnosticWorkflow.php';
    use WHMCS\Database\Capsule as DB;
    use Sahdev\Lib\DiagnosticWorkflow as W;
    use Sahdev\Lib\DiagnosticPolicy as P;
    use Sahdev\Lib\LiveChatToolService as T;
    DB::$db=new PDO('sqlite::memory:'); DB::$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    DB::$db->exec('CREATE TABLE tblsahdev_settings (diagnostic_policy_json TEXT,client_chat_tools_enabled INTEGER,tools_execution_enabled INTEGER,client_chat_tool_max_executions INTEGER)');
    DB::$db->exec('INSERT INTO tblsahdev_settings VALUES (NULL,1,1,2)');
    DB::$db->exec('CREATE TABLE tblsahdev_module_logs (id INTEGER PRIMARY KEY AUTOINCREMENT,source TEXT,level TEXT,message TEXT,ticket_id INTEGER,created_at TEXT)');
    foreach (['tblsahdev_diagnostic_state','tblsahdev_diagnostic_runs'] as $table) DB::$db->exec("CREATE TABLE $table (record_key TEXT PRIMARY KEY,payload TEXT,expires_at TEXT,created_at TEXT,updated_at TEXT)");
    DB::$db->exec('CREATE TABLE tblservers (id INTEGER PRIMARY KEY,hostname TEXT,ipaddress TEXT,nameserver1 TEXT,nameserver2 TEXT)');
    DB::$db->exec("INSERT INTO tblservers VALUES (1,'hosting.example.net','8.8.8.8','ns1.example.net','ns2.example.net')");
    DB::$db->exec('CREATE TABLE tblhosting (id INTEGER,userid INTEGER,domain TEXT,domainstatus TEXT,dedicatedip TEXT,assignedips TEXT,server INTEGER)');
    DB::$db->exec("INSERT INTO tblhosting VALUES (10,1,'example.com','Active','1.1.1.1','',1),(20,2,'secret.example.net','Active','8.8.4.4','',1)");
    DB::$db->exec('CREATE TABLE tblsahdev_providers (id INTEGER,is_active INTEGER,model_name TEXT)');
    DB::$db->exec("INSERT INTO tblsahdev_providers VALUES (1,1,'fixture'),(2,1,'fixture fallback')");
    $policy=P::defaults(); $policy['telemetry']=false; $policy['disabled_tools']=array_keys(T::getToolRegistry());
    function policy($p) { $q=DB::$db->prepare('UPDATE tblsahdev_settings SET diagnostic_policy_json=?');$q->execute([json_encode($p)]); }
    policy($policy); T::setEnabledOverride(true);
    $count=0;
    function expect($ok,$name) { global $count; $count++; if (!$ok) throw new RuntimeException($name); }
    $r=W::run('My website is not working','livechat',1,1,'message1');
    expect($r['status']==='clarification','Missing target asks clarification without AI');
    $r=W::run('example.com','livechat',1,1,'message2');
    expect($r['category']==='web' && $r['target']['host']==='example.com','Target-only follow-up retains issue');
    expect($r['configured_context']['services'][0]['service_id']===10,'Own service matched');
    expect($r['status']==='partial' && count($r['checks'])===2,'Disabled tools return unavailable without HTTP');
    expect(W::run('example.com','livechat',1,1,'message2')===$r,'Idempotent evidence replay');
    $follow=W::run('What about its SSL certificate?','livechat',1,1,'message2b');
    expect($follow['category']==='ssl' && $follow['target']['host']==='example.com','Changing check type retains explicit conversation target');
    expect(W::run('A different website is down','livechat',1,1,'message2c')['status']==='clarification','Different service does not inherit prior target');
    W::run('Check example.com','livechat',1,1,'message2d');
    expect(W::serviceContext('secret.example.net',1,false)['services']===[],'Other customer service not disclosed');
    expect(count(W::serviceContext('8.8.8.8',1,false)['services'])===1,'Shared server IP only matches authenticated customer services');
    DB::$db->exec('CREATE TABLE tbldomains (id INTEGER,userid INTEGER,domain TEXT,status TEXT,expirydate TEXT)');
    DB::$db->exec("INSERT INTO tbldomains VALUES (1,1,'registered.example.org','Active','2027-01-01'),(2,2,'private.example.org','Active','2027-01-01')");
    expect(count(W::serviceContext('registered.example.org',1,false)['registered_domains'])===1,'Owned registration without hosting is recognized');
    expect(W::serviceContext('private.example.org',1,false)['registered_domains']===[],'Other customer registration is private');
    expect(W::serviceContext('example.com',1,false,false,false)['services']===[],'Disabled service source suppresses enrichment');
    expect(P::isExcluded('example.com','Email support@example.com',(object)['tools_filter_emails'=>'support@*']),'Configured email exclusions are enforced locally');
    expect(P::isExcluded('sub.example.com','',(object)['tools_filter_domains'=>'*.example.com']),'Configured wildcard domain exclusion is retained');
    expect(W::run('example.com','livechat',2,1,'message1')===[],'Conversation state does not leak between sessions');
    $ip=W::run('8.8.8.8 is down','livechat',3,1,'message1');
    expect($ip['category']==='network' && $ip['checks'][0]['tool']==='toolsapi_ping','Bare IP outage uses reachability instead of website DNS');
    expect(W::run('Invoice for example.com','livechat',1,1,'message3')===[],'Billing message does not inherit diagnostics');
    $r=W::run('Check example.com and another.example.com','livechat',1,1,'message4');
    expect($r['status']==='clarification','Multiple targets require selection');
    $r=W::run('SSL check example.com','tickets',10,1,'reply1');
    expect($r['category']==='ssl' && count($r['checks'])===1,'Ticket workflow uses same recipes');
    $policy['emergency_stop']=true;policy($policy);
    expect(W::run('Check example.com','livechat',1,1,'message5')===[],'Emergency stop precedes cache and work');
    expect(!T::executeToolCall('toolsapi_ping',['host'=>'8.8.8.8'],1)['success'],'Executor independently enforces emergency stop');
    $policy['emergency_stop']=false;$policy['categories']=[];policy($policy);
    expect(W::run('SSL example.com','livechat',1,1,'message6')['status']==='disabled','Category disable enforced');
    $policy['categories']=P::defaults()['categories'];$policy['planner']='always';$policy['planner_provider']=1;$policy['planner_fallback']=2;policy($policy);
    $r=W::run('Check SSL example.com','livechat',1,1,'message7');
    expect(isset($r['planner_status']) && count($r['usage'])===2,'Malformed planners fall back to local recipe and retain token counts');
    expect(\Sahdev\Lib\ChatService::$calls===2,'Planner attempts bounded to primary and configured fallback');
    DB::$denyLock=true;
    expect(W::run('Check example.com','livechat',1,1,'message8')['status']==='pending','Concurrent request does not execute');
    DB::$denyLock=false;
    $match=T::matchOperation('GET','/dns/example.com');
    expect($match['tool']==='toolsapi_dns_lookup' && $match['args']['domain']==='example.com','Manual concrete paths resolve to shared tool permissions');
    expect(T::matchOperation('POST','/dns/example.com')===null,'Wrong method cannot match shared GET tool');
    W::saveTurn('test-turn',['body_hash'=>'body','response'=>['success'=>true,'reply'=>'fixture']]);
    expect(W::getTurn('test-turn')['response']['reply']==='fixture','Request replay persists completed response');
    require __DIR__.'/../lib/TaskProviderResolver.php';
    require __DIR__.'/../lib/DiagnosticSettings.php';
    DB::$db->exec('ALTER TABLE tblsahdev_settings ADD COLUMN id INTEGER DEFAULT 1');
    DB::$db->exec("ALTER TABLE tblsahdev_providers ADD COLUMN name TEXT DEFAULT 'Fixture'");
    DB::$db->exec("ALTER TABLE tblsahdev_providers ADD COLUMN provider_type TEXT DEFAULT 'generic'");
    ob_start(); \Sahdev\Lib\DiagnosticSettings::render(); $html=ob_get_clean();
    expect(strpos($html,'diagnostics[emergency_stop]')!==false && strpos($html,'diagnostics[planner_provider]')!==false,'Diagnostic settings render operational switches and provider routing');
    \Sahdev\Lib\DiagnosticSettings::save(['diagnostic_policy_present'=>1,'diagnostics'=>['planner_provider'=>1]]);
    expect(!P::get()['enabled'] && P::get()['categories']===[] && P::get()['planner_provider']===1,'Unchecked switches and categories persist as disabled');
    try { \Sahdev\Lib\DiagnosticSettings::save(['diagnostic_policy_present'=>1,'diagnostics'=>['planner_provider'=>999]]); throw new RuntimeException('Expected invalid provider rejection'); }
    catch (RuntimeException $e) { expect(P::get()['planner_provider']===1,'Invalid provider cannot overwrite saved route'); }
    $policy=P::defaults(); $policy['disabled_tools']=array_keys(T::getToolRegistry());policy($policy);
    $r=W::run('indiaremediesoverseas.com is not loading','livechat',100,1,'1');
    expect($r['category']==='web' && $r['checks'][0]['tool']==='toolsapi_dns_lookup','Reported not-loading wording starts DNS and web checks');
    $r=W::run('Can you check where it is pointing right now with A record IP share it','livechat',100,1,'2');
    expect($r['category']==='dns' && $r['target']['host']==='indiaremediesoverseas.com' && count($r['checks'])===1,'Reported follow-up keeps domain and selects only DNS');
    DB::$db->exec('UPDATE tblsahdev_settings SET client_chat_tools_enabled=0');
    W::run('Check example.com','livechat',100,1,'3');
    $last=json_decode(DB::$db->query('SELECT message FROM tblsahdev_module_logs ORDER BY id DESC LIMIT 1')->fetchColumn(),true);
    expect($last['reason']==='client_chat_tools_enabled_off','Disabled legacy switch logs its exact blocker');
    $blocks=\Sahdev\Lib\DiagnosticSettings::readiness(DB::table('tblsahdev_settings')->first(),$policy);
    expect(in_array('Enable Diagnostic Tool Calling in Live Chat is off',$blocks['livechat'],true),'Readiness exposes separate chat execution gate');
    $policy['logs_enabled']=false;policy($policy);
    $before=DB::$db->query('SELECT COUNT(*) FROM tblsahdev_module_logs')->fetchColumn();
    W::run('Check example.com','livechat',100,1,'4');
    T::executeToolCall('toolsapi_dns_lookup',['domain'=>'example.com'],100);
    expect(DB::$db->query('SELECT COUNT(*) FROM tblsahdev_module_logs')->fetchColumn()===$before,'Logging off suppresses both routing and executor events');
    $policy['logs_enabled']=true;policy($policy);
    \Sahdev\Lib\DiagnosticTrace::record('privacy_test',['api_key'=>'SECRET','message'=>'PRIVATE','body'=>'RAW','reason'=>'safe']);
    $last=DB::$db->query('SELECT message FROM tblsahdev_module_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    expect(strpos($last,'SECRET')===false && strpos($last,'PRIVATE')===false && strpos($last,'RAW')===false,'Trace excludes unknown secret-bearing fields');
    DB::$db->exec("INSERT INTO tblsahdev_module_logs (source,message,created_at) VALUES ('diagnostic_workflow','expired','2000-01-01'),('diagnostic_usage','expired usage','2000-01-01'),('billing','keep','2000-01-01')");
    $policy['logs_enabled']=false; $policy['logs_auto_cleanup']=false;policy($policy);
    expect(\Sahdev\Lib\DiagnosticTrace::automaticCleanup()===0,'Automatic cleanup can be disabled independently');
    expect(\Sahdev\Lib\DiagnosticTrace::cleanup(false,1)===1,'Cleanup honors batch cap while recording is off');
    $policy['logs_auto_cleanup']=true;policy($policy);
    expect(\Sahdev\Lib\DiagnosticTrace::automaticCleanup()===1,'Automatic retention still works with recording off');
    expect(DB::$db->query("SELECT COUNT(*) FROM tblsahdev_module_logs WHERE source='billing'")->fetchColumn()==1,'Retention preserves unrelated logs');
    expect(\Sahdev\Lib\DiagnosticTrace::cleanup(true,5000)>0,'Manual clear removes recent diagnostic rows');
    expect(DB::$db->query('SELECT COUNT(*) FROM tblsahdev_module_logs')->fetchColumn()==1,'Manual clear preserves unrelated logs');
    expect(P::normalize(['log_retention_days'=>1000])['log_retention_days']===90,'Retention is bounded');
    \Sahdev\Lib\DiagnosticSettings::save(['diagnostic_policy_present'=>1,'diagnostic_livechat_gate_present'=>1,'client_chat_tools_enabled'=>1,
        'diagnostics'=>['enabled'=>1,'livechat'=>1,'logs_enabled'=>1,'logs_auto_cleanup'=>1,'log_retention_days'=>14]]);
    expect(DB::table('tblsahdev_settings')->value('client_chat_tools_enabled')==1,'Tools page explicitly enables the canonical live-chat gate');
    expect(P::get()['logs_enabled'] && P::get()['logs_auto_cleanup'] && P::get()['log_retention_days']===14,'Log recording and retention persist through real settings save');
    \Sahdev\Lib\DiagnosticSettings::save(['diagnostic_policy_present'=>1,'diagnostic_livechat_gate_present'=>1,'diagnostics'=>['log_retention_days'=>14]]);
    expect(!P::get()['logs_enabled'] && !P::get()['logs_auto_cleanup'] && DB::table('tblsahdev_settings')->value('client_chat_tools_enabled')==0,'Unchecked log and execution switches persist as off');
    ob_start();\Sahdev\Lib\DiagnosticSettings::render();$html=ob_get_clean();
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    expect($xpath->query('//button[@name="diagnostic_log_cleanup"]')->length===2 && $xpath->query('//input[@name="diagnostics[logs_enabled]"]')->length===1,'Rendered controls expose both cleanup actions and log toggle');
    echo "Diagnostic workflow: $count checks passed\n";
}
