<?php
// Real SQLite transactions for database-policy and atomic-operation regression tests.
namespace WHMCS\Database {
    class Capsule {
        public static $db;
        public static $driver = 'sqlite';
        public static function table($table) { return new Query($table); }
        public static function connection() { return new self(); }
        public static function schema() { return new Schema(); }
        public function getDriverName() { return self::$driver; }
        public function transaction($callback) {
            self::$db->beginTransaction();
            try { $result = $callback(); self::$db->commit(); return $result; }
            catch (\Throwable $e) { if (self::$db->inTransaction()) self::$db->rollBack(); throw $e; }
        }
        public function select($sql, $bindings) { if (self::$db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== self::$driver) return []; $q=self::$db->prepare($sql);$q->execute($bindings);return $q->fetchAll(\PDO::FETCH_OBJ); }
        public function statement($sql) { return true; }
    }
    class Schema {
        public function hasTable($table) { if (Capsule::$driver === 'mysql') { $q=Capsule::$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (bool)$q->fetchColumn(); } $q=Capsule::$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn(); }
        public function hasColumn($table, $column) { return true; }
    }
    class Collection implements \IteratorAggregate, \Countable, \JsonSerializable {
        private $rows;
        public function __construct($rows) { $this->rows=$rows; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        public function count(): int { return count($this->rows); }
        public function jsonSerialize(): array { return $this->rows; }
        public function toArray() { return $this->rows; }
        public function all() { return $this->rows; }
        public function isEmpty() { return !$this->rows; }
        public function values() { return $this; }
        public function reverse() { return new self(array_reverse($this->rows)); }
        public function map($fn) { return new self(array_map($fn,$this->rows)); }
    }
    class Query {
        private $table; private $conditions=[]; private $bindings=[]; private $sort=''; private $limit; private $offset=0; private $columns=['*']; private $locked=false;
        public function __construct($table) { $this->table=$table; }
        public function from($table) { $this->table=$table;return $this; }
        public function select($columns) { $this->columns=(array)$columns;return $this; }
        public function lockForUpdate() { $this->locked=true; return $this; }
        public function where($field,$operator=null,$value=null,$join='AND') {
            if ($field instanceof \Closure) {
                $sub=new self($this->table);$field($sub);$this->conditions[]=[$join,'('.$sub->conditionSql().')'];$this->bindings=array_merge($this->bindings,$sub->bindings);return $this;
            }
            if (is_array($field)) { foreach ($field as $key=>$v) $this->where($key,$v);return $this; }
            if (func_num_args()===2) { $value=$operator;$operator='='; }
            $this->conditions[]=[$join,$field.' '.$operator.' ?'];$this->bindings[]=$value;return $this;
        }
        public function orWhere($field,$operator=null,$value=null) {
            if (func_num_args()===2) { $value=$operator;$operator='='; }
            return $this->where($field,$operator,$value,'OR');
        }
        public function whereIn($field,$values) {
            if ($values instanceof \Closure) { $q=new self('');$values($q);$sql=$q->selectSql();$values=$q->bindings; }
            else $sql=$values ? implode(',',array_fill(0,count($values),'?')) : 'NULL';
            $this->conditions[]=['AND',$field.' IN ('.$sql.')'];$this->bindings=array_merge($this->bindings,$values);return $this;
        }
        private function conditionSql() { $sql='';foreach($this->conditions as [$join,$clause]) $sql.=($sql ? ' '.$join.' ' : '').$clause;return $sql; }
        private function suffix() { return $this->conditions ? ' WHERE '.$this->conditionSql() : ''; }
        private function selectSql() { return 'SELECT '.implode(',',$this->columns).' FROM '.$this->table.$this->suffix().$this->sort.($this->limit===null?'':' LIMIT '.(int)$this->limit.' OFFSET '.(int)$this->offset).($this->locked && Capsule::$driver==='mysql' ? ' FOR UPDATE' : ''); }
        private function run($sql,$values) { $q=Capsule::$db->prepare($sql);$q->execute(array_map(function($v){return is_object($v)?(string)$v:$v;},$values));return $q; }
        public function orderBy($field,$order='asc') { $this->sort=' ORDER BY '.$field.' '.$order;return $this; }
        public function limit($n) { $this->limit=$n;return $this; }
        public function offset($n) { $this->offset=$n;return $this; }
        public function get($fields=null) { if($fields)$this->columns=$fields;return new Collection($this->run($this->selectSql(),$this->bindings)->fetchAll(\PDO::FETCH_OBJ)); }
        public function first($fields=null) { $this->limit=1;$rows=$this->get($fields)->all();return $rows[0]??null; }
        public function value($field) { $r=$this->first([$field]);return $r->$field??null; }
        public function exists() { return $this->first()!==null; }
        public function count() { return (int)$this->run('SELECT COUNT(*) FROM '.$this->table.$this->suffix(),$this->bindings)->fetchColumn(); }
        public function insertGetId($values) { $this->insert($values);return (int)Capsule::$db->lastInsertId(); }
        public function insert($values) { $this->run('INSERT INTO '.$this->table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));return true; }
        public function update($values) { return $this->run('UPDATE '.$this->table.' SET '.implode(',',array_map(function($key){return $key.'=?';},array_keys($values))).$this->suffix(),array_merge(array_values($values),$this->bindings))->rowCount(); }
        public function delete() { return $this->run('DELETE FROM '.$this->table.$this->suffix(),$this->bindings)->rowCount(); }
    }
}
namespace Carbon {
    class Carbon extends \DateTimeImmutable {
        public static function now() { return new self('2026-11-02 12:00:00'); }
        public static function today() { return new self('2026-11-02 00:00:00'); }
        public function toDateTimeString() { return $this->format('Y-m-d H:i:s'); }
        public function toDateString() { return $this->format('Y-m-d'); }
        public function subMinutes($n) { return $this->modify('-'.$n.' minutes'); }
        public function addMinutes($n) { return $this->modify('+'.$n.' minutes'); }
        public function __toString() { return $this->toDateTimeString(); }
    }
}
namespace {
    require dirname(__DIR__).'/autoload.php';
    use WHMCS\Database\Capsule as DB;
    if (getenv('SAHDEV_COPILOT_MYSQL_DSN') && !preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=sahdev_copilot_test(?:_\w+)?$/D', getenv('SAHDEV_COPILOT_MYSQL_DSN'))) throw new RuntimeException('Only an explicitly named disposable Copilot test database on localhost is allowed.');
    DB::$db=new PDO(getenv('SAHDEV_COPILOT_MYSQL_DSN') ?: 'sqlite::memory:', getenv('SAHDEV_COPILOT_MYSQL_USER') ?: null, getenv('SAHDEV_COPILOT_MYSQL_PASSWORD') ?: null);
    DB::$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    DB::$driver=DB::$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    function copilotDdl($sql) {
        if (DB::$driver==='mysql') {
            $sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
            $sql=str_replace('proposal_uuid TEXT UNIQUE','proposal_uuid VARCHAR(64) UNIQUE',$sql);
            if (strpos($sql,'CREATE TABLE ')===0) $sql.=' ENGINE=InnoDB';
        }
        DB::$db->exec($sql);
    }
    if (getenv('SAHDEV_COPILOT_DATABASE_SKIP_SEED')) return;
    // Every supported resource gets its actual exposed columns; sensitive columns prove projection.
    foreach (\Sahdev\Lib\CopilotDatabasePolicy::resources() as $resource=>$definition) {
        $columns=array_values(array_diff($definition['read'],['id']));
        copilotDdl('CREATE TABLE '.$definition['table'].' (id INTEGER PRIMARY KEY AUTOINCREMENT, '.implode(' TEXT, ',$columns).' TEXT)');
    }
    copilotDdl('ALTER TABLE tblclients ADD COLUMN password TEXT');
    copilotDdl('CREATE TABLE tbladmins (id INTEGER PRIMARY KEY,roleid INTEGER,username TEXT,disabled INTEGER DEFAULT 0,password TEXT)');
    copilotDdl('CREATE TABLE tbladminroles (id INTEGER PRIMARY KEY,name TEXT)');
    copilotDdl('CREATE TABLE tblsahdev_role_permissions (id INTEGER PRIMARY KEY,role_id INTEGER,permissions_json TEXT,updated_at TEXT)');
    copilotDdl('CREATE TABLE tblsahdev_settings (id INTEGER PRIMARY KEY,copilot_database_policy_json TEXT)');
    copilotDdl('CREATE TABLE tblticketstatuses (id INTEGER PRIMARY KEY,title TEXT)');
    copilotDdl('CREATE TABLE tblsahdev_chat_sessions (id INTEGER PRIMARY KEY,admin_id INTEGER,session_type TEXT)');
    copilotDdl('CREATE TABLE tblsahdev_ops_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT,proposal_uuid TEXT UNIQUE,admin_id INTEGER,session_id INTEGER,action_key TEXT,card_json TEXT,status TEXT,journal_id INTEGER,expires_at TEXT,created_at TEXT,updated_at TEXT)');
    copilotDdl('CREATE TABLE tblsahdev_ops_events (id INTEGER PRIMARY KEY AUTOINCREMENT,proposal_uuid TEXT,journal_id INTEGER,admin_id INTEGER,event TEXT,message TEXT,details_json TEXT,created_at TEXT)');
    copilotDdl('CREATE TABLE tblsahdev_ops_journal (id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER,admin_id INTEGER,action_key TEXT,target_entity_type TEXT,target_entity_id INTEGER,description TEXT,parameters_json TEXT,state_before_json TEXT,state_after_json TEXT,compensation_recipe_json TEXT,risk_tier INTEGER,status TEXT,executed_at TEXT,rolled_back_at TEXT,rollback_admin_id INTEGER,rollback_error TEXT,created_at TEXT,updated_at TEXT)');
    DB::table('tbladminroles')->insert(['id'=>1,'name'=>'Full Administrator']);
    DB::table('tbladminroles')->insert(['id'=>2,'name'=>'Limited Administrator']);
    DB::table('tbladmins')->insert(['id'=>1,'roleid'=>1,'username'=>'Root','password'=>password_hash('test-secret',PASSWORD_DEFAULT)]);
    DB::table('tbladmins')->insert(['id'=>2,'roleid'=>2,'username'=>'Scoped','password'=>password_hash('staff-secret',PASSWORD_DEFAULT)]);
    DB::table('tblsahdev_chat_sessions')->insert(['id'=>1,'admin_id'=>1,'session_type'=>'admin_copilot']);
    DB::table('tblsahdev_chat_sessions')->insert(['id'=>2,'admin_id'=>2,'session_type'=>'admin_copilot']);
    DB::table('tblclients')->insert(['id'=>12,'firstname'=>'Dhruv','lastname'=>'Joshi','email'=>'dhruv@example.test','status'=>'Active','credit'=>'5.00','currency'=>1,'notes'=>'original','password'=>'never-expose']);
    DB::table('tblclients')->insert(['id'=>34,'firstname'=>'Other','lastname'=>'Client','email'=>'hidden@example.test','status'=>'Active','credit'=>'0.00','currency'=>1]);
    DB::table('tblticketstatuses')->insert(['id'=>1,'title'=>'Open']);DB::table('tblticketstatuses')->insert(['id'=>2,'title'=>'Closed']);
    DB::table('tbltickets')->insert(['id'=>5,'userid'=>12,'status'=>'Open','title'=>'Test','urgency'=>'Low','flag'=>0]);
    DB::table('tbltickets')->insert(['id'=>6,'userid'=>34,'status'=>'Open','title'=>'Hidden','urgency'=>'Low','flag'=>0]);
    DB::table('tblproducts')->insert(['id'=>9,'name'=>'Starter']);
    DB::table('tblhosting')->insert(['id'=>8,'userid'=>12,'packageid'=>9,'domain'=>'site.example.test','domainstatus'=>'Active','notes'=>'']);
    foreach (range(1,7) as $id) DB::table('tblinvoices')->insert(['id'=>$id,'userid'=>12,'status'=>'Unpaid','total'=>'2.65','duedate'=>'2026-11-19','notes'=>'']);
    DB::table('tblinvoices')->insert(['id'=>99,'userid'=>34,'status'=>'Paid','total'=>'999.00']);
    DB::table('tblsahdev_settings')->insert(['id'=>1,'copilot_database_policy_json'=>'']);
    $_SESSION=['adminid'=>1];$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
    function generate_token($kind) { return $kind==='form'?'<input name="token" value="test-csrf">':'test-csrf'; }
    function check_token($kind) { if(($_POST['token']??'')!=='test-csrf')throw new RuntimeException('Invalid CSRF'); }
}
