<?php
// Isolated SQLite fixture: no WHMCS installation or production connection.
namespace WHMCS\Database {
    class Capsule {
        public static $db;
        public static function table($name){return new Query($name);}
        public static function schema(){return new class {public function hasTable($name){return true;}};}
        public static function connection(){return new class {public function transaction($fn){Capsule::$db->beginTransaction();try{$r=$fn();Capsule::$db->commit();return $r;}catch(\Throwable $e){Capsule::$db->rollBack();throw $e;}}};}
    }
    class Rows implements \IteratorAggregate {
        private $rows;public function __construct($r){$this->rows=$r;}public function all(){return $this->rows;}public function getIterator():\Traversable{return new \ArrayIterator($this->rows);}
    }
    class Query {
        private $name,$w=[],$b=[],$sort=[],$n=1000;
        public function __construct($n){$this->name=$n;}
        public function where($k,$op,$v=null){if(func_num_args()===2){$v=$op;$op='=';}$this->w[]="$k $op ?";$this->b[]=$v;return $this;}
        public function whereIn($k,$v){$this->w[]=$k.' IN ('.implode(',',array_fill(0,count($v),'?')).')';$this->b=array_merge($this->b,$v);return $this;}
        public function orderBy($k,$d='asc'){$this->sort[]="$k $d";return $this;}
        public function limit($n){$this->n=$n;return $this;}
        private function suffix(){return $this->w?' WHERE '.implode(' AND ',$this->w):'';}
        private function run($s,$b){$q=Capsule::$db->prepare($s);$q->execute($b);return $q;}
        public function get($cols=['*']){return new Rows($this->run('SELECT '.implode(',',$cols).' FROM '.$this->name.$this->suffix().($this->sort?' ORDER BY '.implode(',',$this->sort):'').' LIMIT '.$this->n,$this->b)->fetchAll(\PDO::FETCH_OBJ));}
        public function first($cols=['*']){$this->n=1;$rows=$this->get($cols)->all();return $rows[0]??null;}
        public function exists(){return $this->first()!==null;}
        public function pluck($col){return new Rows(array_map(function($r)use($col){return $r->$col;},$this->get([$col])->all()));}
        public function insertOrIgnore($data){$this->run('INSERT OR IGNORE INTO '.$this->name.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));}
        public function insert($data){$this->run('INSERT INTO '.$this->name.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));}
        public function update($data){return $this->run('UPDATE '.$this->name.' SET '.implode(',',array_map(function($k){return $k.'=?';},array_keys($data))).$this->suffix(),array_merge(array_values($data),$this->b))->rowCount();}
        public function delete(){return $this->run('DELETE FROM '.$this->name.$this->suffix(),$this->b)->rowCount();}
    }
}
namespace Sahdev\Lib {
    class PermissionService {const PERM_SETTINGS_MANAGE='settings';public static $allowed=true;public static function resolveCurrentAdminId(){return 1;}public static function hasPermission($a,$p){return self::$allowed;}}
}
namespace {
    require dirname(__DIR__).'/autoload.php';
    use WHMCS\Database\Capsule as DB;
    use Sahdev\Lib\CaseTimeline as Timeline;
    function check_token($context){if(($_POST['token']??'')!=='fixture')throw new RuntimeException('CSRF');}
    function generate_token($type){return '<input name="token" value="fixture">';}
    function expectCase($ok,$message){if(!$ok)throw new RuntimeException($message);}
    DB::$db=new PDO('sqlite::memory:');DB::$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    foreach([
        'tblsahdev_case_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,active INTEGER,policy TEXT)',
        'tblsahdev_case_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,ticket_id INTEGER,source TEXT,event_key TEXT UNIQUE,payload TEXT,observed_at TEXT)',
        'tbltickets (id INTEGER,userid INTEGER,date TEXT,status TEXT,urgency TEXT,service TEXT,message TEXT)',
        'tblticketreplies (id INTEGER,tid INTEGER,date TEXT,admin TEXT,message TEXT)',
        'tblhosting (id INTEGER,userid INTEGER,domain TEXT,domainstatus TEXT,server INTEGER,dedicatedip TEXT,password TEXT)',
        'tblservers (id INTEGER,name TEXT,hostname TEXT,ipaddress TEXT,nameserver1 TEXT,nameserver2 TEXT,nameserver3 TEXT,nameserver4 TEXT,password TEXT)',
        'tbldomains (id INTEGER,userid INTEGER,domain TEXT,status TEXT,expirydate TEXT)',
    ] as $sql)DB::$db->exec('CREATE TABLE '.$sql);
    $policy=Timeline::validate(['enabled'=>1,'services'=>1,'servers'=>1,'domains'=>1,'tools'=>1,'messages'=>1,'ai'=>1]);
    DB::table('tblsahdev_case_profiles')->insert(['name'=>'Test','active'=>1,'policy'=>json_encode($policy)]);
    $at=date('Y-m-d H:i:s',time()-3600);
    DB::table('tbltickets')->insert(['id'=>1,'userid'=>7,'date'=>$at,'status'=>'Open','urgency'=>'High','service'=>'S10','message'=>'Site is down. password=hidden']);
    DB::table('tblticketreplies')->insert(['id'=>2,'tid'=>1,'date'=>$at,'admin'=>'','message'=>'Still down']);
    DB::table('tblhosting')->insert(['id'=>10,'userid'=>7,'domain'=>'example.test','domainstatus'=>'Active','server'=>3,'dedicatedip'=>'192.0.2.1','password'=>'must-not-leak']);
    DB::table('tblservers')->insert(['id'=>3,'name'=>'Server','hostname'=>'srv.example.test','ipaddress'=>'192.0.2.1','nameserver1'=>'ns.example.test','password'=>'server-secret']);
    Timeline::capture(1,'diagnostics','run1',['status'=>'observed','summary'=>'A: 192.0.2.1','observed_at'=>$at]);
    Timeline::capture(1,'diagnostics','run1',['status'=>'observed','summary'=>'Changed']);
    $data=Timeline::read(1);$snap=DB::table('tblsahdev_case_snapshots')->first();
    expectCase($snap->observed_at===$at&&count(DB::table('tblsahdev_case_snapshots')->get()->all())===1,'Deduplication/timestamps');
    expectCase($data['context']['server']['hostname']==='srv.example.test','Allowlisted server data');
    expectCase(strpos(json_encode($data),'must-not-leak')===false&&strpos(json_encode($data),'server-secret')===false&&strpos(json_encode($data),'password=hidden')===false,'Secret exclusions');
    DB::table('tblhosting')->where('id',10)->update(['userid'=>999]);
    expectCase(!isset(Timeline::read(1)['context']['hosting']),'Cross-client service rejected');
    expectCase(strpos(Timeline::promptBlock(1,false),'A: 192.0.2.1')===false,'Per-generation tool opt-out');
    DB::table('tbldomains')->insert(['id'=>55,'userid'=>7,'domain'=>'domain-only.test','status'=>'Active','expirydate'=>'2027-01-01']);
    DB::table('tbltickets')->where('id',1)->update(['service'=>'D55']);
    expectCase(Timeline::read(1)['context']['domain']['domain']==='domain-only.test','Domain-only ticket context');
    DB::table('tbltickets')->where('id',1)->update(['service'=>'S10']);
    DB::table('tblhosting')->where('id',10)->update(['userid'=>7]);
    DB::$db->exec('DROP TABLE tblservers');
    expectCase(count(array_filter(Timeline::read(1)['events'],function($e){return $e['kind']==='tool';}))===1,'Account failure must preserve tool evidence');
    Timeline::capture(1,'diagnostics','old',['summary'=>'Expired','observed_at'=>date('Y-m-d H:i:s',time()-100*86400)]);
    expectCase(count(DB::table('tblsahdev_case_snapshots')->get()->all())===1,'Expired data not reimported');
    DB::table('tblsahdev_case_snapshots')->insert(['ticket_id'=>1,'source'=>'fixture','event_key'=>'expired','payload'=>'{}','observed_at'=>date('Y-m-d H:i:s',time()-100*86400)]);
    expectCase(Timeline::prune(14)===1,'Explicit cleanup removes only expired snapshots');
    for($i=0;$i<25;$i++)Timeline::capture(1,'diagnostics','budget'.$i,['summary'=>str_repeat('x',1500)]);
    $block=Timeline::promptBlock(1);expectCase(strlen($block)<=12000&&is_array(json_decode($block,true)),'Bounded complete JSON');
    $secret=Timeline::clean('password="two words" Authorization: Bearer abc123 https://user:pass@host/');
    expectCase(strpos($secret,'two words')===false&&strpos($secret,'abc123')===false&&strpos($secret,'user:pass')===false,'Credential redaction');
    expectCase(Timeline::validate(['enabled'=>'false','limit'=>999,'days'=>0])['enabled']===false,'Boolean false respected');
    expectCase(Timeline::validate(['limit'=>999,'days'=>0])['limit']===100,'Limits clamped');
    $report=Sahdev\Lib\CaseTimelineInterpreter::message("Hi Ashish,\n\nThank you for your patience while we review your request. We are revalidating all commission entries. If you choose credit balance instead of withdrawal, we offer a 30% bonus. Please let us know if you prefer credit or standard withdrawal.\n\n---\nBest Regards!\nSupport Engineer\nOur ChatGPT Support Assistant: https://example.test",true);
    expectCase($report['title']==='Options & next steps','Decision event classification');
    expectCase(strpos($report['summary'],'30% bonus')!==false&&strpos($report['summary'],'prefer credit')!==false,'Offer and next decision retained');
    expectCase(strpos(json_encode($report),'Support Engineer')===false&&strpos(json_encode($report),'ChatGPT Support')===false,'Signature and promotional footer removed');
    $negative=Sahdev\Lib\CaseTimelineInterpreter::message('We have not escalated the issue yet. Please provide the error first.',true);
    expectCase(strpos($negative['summary'],'not escalated')!==false,'Negation survives extraction');
    $tool=Sahdev\Lib\CaseTimelineInterpreter::tool(json_encode(['tool'=>'toolsapi_dns_lookup','target'=>'example.test','facts'=>['records'=>[['value'=>'192.0.2.8']]]]),'observed');
    expectCase(strpos($tool['summary'],'192.0.2.8')!==false&&strpos($tool['summary'],'{')===false,'Structured evidence becomes readable facts');
    $largeTool=Sahdev\Lib\CaseTimelineInterpreter::tool(json_encode(['facts'=>array_fill(0,20,str_repeat('測',1500))]),'observed');
    expectCase(strlen(json_encode($largeTool))<32000,'Presentation stays within snapshot storage budget for multibyte evidence');
    foreach(Timeline::layouts() as $id=>$name)expectCase(Sahdev\Lib\AdminPreferences::normalizeArray(['ui_theme'=>$id])['ui_theme']===$id,'Layout preference persisted');
    $_GET=[];$_SERVER['REQUEST_METHOD']='POST';$_POST=['token'=>'fixture','save_profile'=>1,'name'=>'New','active'=>1,'enabled'=>1,'tools'=>1];
    Sahdev\Lib\CaseTimelineSettings::render();expectCase(DB::table('tblsahdev_case_profiles')->where('active',1)->first()->name==='New','Create and activate policy');
    $_POST=['token'=>'fixture','save_profile'=>1,'profile_id'=>2,'name'=>'Updated','active'=>1,'enabled'=>1];Sahdev\Lib\CaseTimelineSettings::render();
    expectCase(!Timeline::policy()['tools'],'Source disable saved');
    $_POST=['token'=>'bad','delete_profile'=>1,'profile_id'=>2];try{Sahdev\Lib\CaseTimelineSettings::render();throw new RuntimeException('CSRF not checked');}catch(RuntimeException $e){expectCase($e->getMessage()==='CSRF','CSRF enforcement');}
    $_POST=['token'=>'fixture','delete_profile'=>1,'profile_id'=>2];Sahdev\Lib\CaseTimelineSettings::render();expectCase(!Timeline::policy()['enabled'],'Deleting active profile fails closed');
    $before=count(DB::table('tblsahdev_case_snapshots')->get()->all());Timeline::capture(1,'diagnostics','disabled',[]);expectCase($before===count(DB::table('tblsahdev_case_snapshots')->get()->all()),'Disabled collection writes nothing');
    Sahdev\Lib\PermissionService::$allowed=false;expectCase(strpos(Sahdev\Lib\CaseTimelineSettings::render(),'permission required')!==false,'Settings authorization');
    echo "PASS: snapshot deduplication, timestamps, tenant scope, opt-outs, failure isolation, retention, JSON budget, redaction, nine preferences, policy CRUD, CSRF and permissions.\n";
}
