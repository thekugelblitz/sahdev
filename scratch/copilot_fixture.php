<?php
// Offline WHMCS boundaries for the real Copilot renderer, dispatcher and provider.
namespace WHMCS\Database {
    class Capsule {
        public static $rows = [];
        public static function table($name) { return new CopilotQuery($name); }
        public static function schema() { return new CopilotSchema(); }
        public static function connection() { return new self(); }
        public function statement($sql) { return true; }
        public function transaction($callback) { return $callback(); }
    }
    class CopilotSchema {
        public function hasTable($name) { return true; }
        public function hasColumn($table, $column) { return true; }
    }
    class CopilotCollection implements \IteratorAggregate, \Countable, \JsonSerializable {
        private $rows;
        public function __construct($rows) { $this->rows = array_values($rows); }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        public function count(): int { return count($this->rows); }
        public function isEmpty() { return !$this->rows; }
        public function reverse() { return new self(array_reverse($this->rows)); }
        public function values() { return $this; }
        public function all() { return $this->rows; }
        public function toArray() { return $this->rows; }
        public function jsonSerialize(): array { return $this->rows; }
        public function map($callback) { return new self(array_map($callback, $this->rows)); }
    }
    class CopilotQuery {
        private $table;
        private $filters = [];
        private $sort;
        private $limit;
        private $joins = [];
        public function __construct($table) { $this->table = $table; }
        public function __call($name, $args) { return $this; }
        public function where($field, $operator = null, $value = null) {
            if ($field instanceof \Closure) { $field($this); return $this; }
            if (is_array($field)) { foreach ($field as $key => $value) $this->where($key, $value); return $this; }
            if (func_num_args() === 2) { $value = $operator; $operator = '='; }
            $this->filters[] = function ($row) use ($field, $operator, $value) {
                $actual = $row[$field] ?? null;
                switch ($operator) {
                    case '>=': return $actual >= $value;
                    case '>': return $actual > $value;
                    case '<': return $actual < $value;
                    case '!=': return $actual != $value;
                    default: return $actual == $value;
                }
            };
            return $this;
        }
        public function whereNull($field) { return $this->where($field, null); }
        public function whereBetween($field, $range) { $this->where($field, '>=', $range[0]); $this->filters[] = function ($row) use ($field, $range) { return ($row[$field] ?? null) <= $range[1]; }; return $this; }
        public function whereIn($field, $values) { $this->filters[] = function ($r) use ($field, $values) { return in_array($r[$field] ?? null, $values); }; return $this; }
        public function whereNotIn($field, $values) { $this->filters[] = function ($r) use ($field, $values) { return !in_array($r[$field] ?? null, $values); }; return $this; }
        public function orderBy($field, $direction = 'asc') { $this->sort = [$field, $direction]; return $this; }
        public function limit($limit) { $this->limit = $limit; return $this; }
        public function join($table, $left, $operator, $right) { $this->joins[] = [$table, $left, $right]; return $this; }
        private function matches($row) { foreach ($this->filters as $filter) if (!$filter($row)) return false; return true; }
        private function rows() {
            $rows = [];
            foreach (Capsule::$rows[$this->table] ?? [] as $row) {
                foreach ($row as $key => $value) $row[$this->table . '.' . $key] = $value;
                $rows[] = $row;
            }
            foreach ($this->joins as [$table, $left, $right]) {
                $joined = [];
                foreach ($rows as $row) foreach (Capsule::$rows[$table] ?? [] as $other) {
                    foreach ($other as $key => $value) $other[$table . '.' . $key] = $value;
                    $combined = array_merge($other, $row);
                    if (($combined[$left] ?? null) == ($combined[$right] ?? null)) $joined[] = $combined;
                }
                $rows = $joined;
            }
            $rows = array_values(array_filter($rows, [$this, 'matches']));
            if ($this->sort) {
                [$field, $direction] = $this->sort;
                usort($rows, function ($a, $b) use ($field, $direction) { return (($a[$field] ?? null) <=> ($b[$field] ?? null)) * ($direction === 'desc' ? -1 : 1); });
            }
            return $this->limit === null ? $rows : array_slice($rows, 0, $this->limit);
        }
        public function get($columns = []) { return new CopilotCollection(array_map(function ($row) { return (object) $row; }, $this->rows())); }
        public function first($columns = []) { $rows = $this->rows(); return $rows ? (object) $rows[0] : null; }
        public function value($field) { $row = $this->first(); return $row->$field ?? null; }
        public function count() { return count($this->rows()); }
        public function sum($field) { return array_sum(array_column($this->rows(), $field)); }
        public function exists() { return $this->count() > 0; }
        public function insertGetId($row) {
            $row['id'] = count(Capsule::$rows[$this->table] ?? []) + 1;
            Capsule::$rows[$this->table][] = $row;
            return $row['id'];
        }
        public function insert($row) { $this->insertGetId($row); return true; }
        public function update($values) {
            $count = 0;
            foreach (Capsule::$rows[$this->table] ?? [] as $i => $row) {
                if (!$this->matches($row)) continue;
                Capsule::$rows[$this->table][$i] = array_merge($row, $values);
                $count++;
            }
            return $count;
        }
    }
}
namespace Carbon {
    class Carbon extends \DateTimeImmutable {
        public static function now() { return new self('2026-11-02 12:00:00'); }
        public static function today() { return new self('2026-11-02 00:00:00'); }
        public function toDateString() { return $this->format('Y-m-d'); }
        public function toDateTimeString() { return $this->format('Y-m-d H:i:s'); }
        public function __toString() { return $this->toDateTimeString(); }
        public function startOfMonth() { return $this->modify('first day of this month')->setTime(0, 0); }
        public function endOfMonth() { return $this->modify('last day of this month')->setTime(23, 59, 59); }
        public function subMonth() { return $this->modify('-1 month'); }
        public function addDay() { return $this->modify('+1 day'); }
    }
}
namespace Sahdev\Lib {
    class CopilotHttpFixture { public static $responses = []; public static $requests = []; }
    function curl_init($url) { return (object) ['url' => $url, 'options' => []]; }
    function curl_setopt($handle, $key, $value) { $handle->options[$key] = $value; return true; }
    function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
    function curl_exec($handle) {
        CopilotHttpFixture::$requests[] = $handle;
        if (!CopilotHttpFixture::$responses) throw new \RuntimeException('Unexpected provider request');
        return json_encode(array_shift(CopilotHttpFixture::$responses));
    }
    function curl_error($handle) { return ''; }
    function curl_errno($handle) { return 0; }
    function curl_getinfo($handle, $key = null) { return 200; }
    function curl_close($handle) {}
}
namespace {
    require_once dirname(__DIR__) . '/autoload.php';
    $_SESSION = ['adminid' => 1, 'adminname' => 'Fixture Admin', 'token' => 'fixture-csrf'];
    $_POST = []; $_GET = []; $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    function generate_token($type) { return $type === 'form' ? '<input name="token" value="fixture-csrf">' : 'fixture-csrf'; }
    function add_hook($name, $priority, $callback) {}
    function decrypt($value) { return $value; }
    \WHMCS\Database\Capsule::$rows = [
        'tbladmins' => [['id' => 1, 'username' => 'Fixture Admin', 'email' => 'admin@example.test', 'roleid' => 1]],
        'tblsahdev_settings' => [[
            'id' => 1, 'primary_provider_id' => 0, 'copilot_primary_provider_id' => 7,
            'copilot_enabled' => 1, 'client_chat_enabled' => 1, 'client_chat_kb_enabled' => 1,
        ]],
        'tblsahdev_providers' => [[
            'id' => 7, 'is_active' => 1, 'name' => 'Fixture Copilot', 'provider_type' => 'lmstudio',
            'model_name' => 'fixture-model', 'api_url' => 'https://provider.example.test/chat/completions', 'api_key' => '',
        ]],
        'tblsahdev_chat_sessions' => [], 'tblsahdev_chat_messages' => [],
        'tblsahdev_ops_journal' => [],
        'tbltickets' => [['id' => 12, 'tid' => '123456', 'title' => 'Fixture ticket', 'userid' => 1, 'did' => 1, 'status' => 'Open', 'urgency' => 'High', 'admin' => 'Admin', 'lastreply' => '2026-11-02 10:00:00']],
    ];
}
