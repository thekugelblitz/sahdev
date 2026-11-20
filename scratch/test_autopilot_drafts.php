<?php
// Isolated SQLite records and WHMCS permission doubles; never connects to WHMCS.
namespace WHMCS\Database {
    class Capsule {
        public static $db;
        public static function table($name) { return new Query($name); }
        public static function connection() { return new class {
            public function transaction($callback) {
                Capsule::$db->beginTransaction();
                try { $result = $callback(); Capsule::$db->commit(); return $result; }
                catch (\Throwable $e) { Capsule::$db->rollBack(); throw $e; }
            }
        }; }
    }
    class Query {
        private $table, $where = [], $bindings = [];
        public function __construct($table) { $this->table = $table; }
        public function where($column, $value) { $this->where[] = "$column = ?"; $this->bindings[] = $value; return $this; }
        public function lockForUpdate() { return $this; }
        private function suffix() { return $this->where ? ' WHERE ' . implode(' AND ', $this->where) : ''; }
        public function first($columns = ['*']) {
            $q = Capsule::$db->prepare('SELECT ' . implode(',', $columns) . ' FROM ' . $this->table . $this->suffix());
            $q->execute($this->bindings); return $q->fetch(\PDO::FETCH_OBJ) ?: null;
        }
        public function update($values) {
            $q = Capsule::$db->prepare('UPDATE ' . $this->table . ' SET '
                . implode(',', array_map(function ($key) { return "$key = ?"; }, array_keys($values))) . $this->suffix());
            $q->execute(array_merge(array_values($values), $this->bindings)); return $q->rowCount();
        }
    }
}
namespace WHMCS\User {
    class Admin {
        public static $current;
        public $isDisabled = false, $permissions = ['View Support Ticket'], $departments = [2];
        public static function find($id) { return $id === 7 ? self::$current : null; }
        public function hasPermission($name) { return in_array($name, $this->permissions, true); }
        public function getSupportDepartmentIds() { return $this->departments; }
    }
}
namespace Sahdev\Lib {
    class PermissionService {
        const PERM_TICKET_AI = 'ticket_ai';
        public static $allowed = true;
        public static function hasPermission($id, $permission) { return self::$allowed; }
    }
}
namespace Michelf {
    if (getenv('SAHDEV_MARKDOWN_DIR')) {
        require getenv('SAHDEV_MARKDOWN_DIR') . '/MarkdownInterface.php';
        require getenv('SAHDEV_MARKDOWN_DIR') . '/Markdown.php';
        require getenv('SAHDEV_MARKDOWN_DIR') . '/MarkdownExtra.php';
    } else {
        // The persistence suite does not test WHMCS's bundled Markdown parser.
        class MarkdownExtra {
            public $no_markup;
            public function transform($text) { return '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>'; }
        }
    }
}
namespace {
    require dirname(__DIR__) . '/lib/AutopilotDrafts.php';
    use WHMCS\Database\Capsule as DB;
    use WHMCS\User\Admin;
    use Sahdev\Lib\AutopilotDrafts as Drafts;
    use Sahdev\Lib\PermissionService;
    $activity = [];
    function logActivity($message) { $GLOBALS['activity'][] = $message; }
    function expectDraft($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    function rejectsDraft($code, $callback) {
        try { $callback(); } catch (Throwable $e) { expectDraft($e->getCode() === $code, $e->getMessage()); return; }
        throw new RuntimeException('Expected error ' . $code);
    }
    DB::$db = new PDO('sqlite::memory:'); DB::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    DB::$db->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, did INTEGER, flag INTEGER)');
    DB::$db->exec('CREATE TABLE tblticketnotes (id INTEGER PRIMARY KEY, ticketid INTEGER, message TEXT, editor TEXT, admin TEXT, date TEXT, attachments TEXT)');
    DB::$db->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, message TEXT)');
    DB::$db->exec("INSERT INTO tbltickets VALUES (1,2,0),(2,3,0),(3,3,7)");
    DB::$db->exec("INSERT INTO tblticketreplies VALUES (10,'Public reply must not change')");
    $markdown = "Hello **client**,\n\n## Findings\n\n- `dig example.test`\n- [Details](https://example.test)\n\n```sh\necho '<tag>'\n```";
    $insert = DB::$db->prepare("INSERT INTO tblticketnotes VALUES (?,?,?,'markdown','Sahdev AI','2026-11-20','file.pdf')");
    $insert->execute([10,1,Drafts::PREFIX . $markdown]);
    $insert->execute([11,1,'Ordinary private note']);
    $insert->execute([12,2,Drafts::PREFIX . 'Other department']);
    $insert->execute([13,3,Drafts::PREFIX . 'Assigned ticket']);
    Admin::$current = new Admin();
    $draft = Drafts::read(1,10,7);
    expectDraft($draft['body'] === $markdown, 'Raw Markdown must survive reads exactly');
    expectDraft(Drafts::body(str_replace("\n", "\r\n", Drafts::PREFIX . 'Text')) === 'Text', 'CRLF marker support');
    expectDraft(Drafts::body('Quoted ' . Drafts::PREFIX . 'text') === null, 'Marker must be at start');
    if (getenv('SAHDEV_MARKDOWN_DIR')) {
        expectDraft(strpos($draft['html'], '<strong>client</strong>') !== false, 'WHMCS parser preserves formatting');
        expectDraft(strpos($draft['html'], '<code>') !== false, 'WHMCS parser preserves code');
    }
    rejectsDraft(404, function () { Drafts::read(1,12,7); });
    rejectsDraft(404, function () { Drafts::read(1,11,7); });
    rejectsDraft(403, function () { Drafts::read(2,12,7); });
    rejectsDraft(403, function () { Drafts::read(1,10,0); });
    Admin::$current->isDisabled = true;
    rejectsDraft(403, function () { Drafts::read(1,10,7); }); Admin::$current->isDisabled = false;
    Admin::$current->permissions = [];
    rejectsDraft(403, function () { Drafts::read(1,10,7); }); Admin::$current->permissions = ['View Support Ticket'];
    PermissionService::$allowed = false;
    rejectsDraft(403, function () { Drafts::read(1,10,7); }); PermissionService::$allowed = true;
    rejectsDraft(403, function () { Drafts::read(3,13,7); });
    Admin::$current->permissions[] = 'View Flagged Tickets';
    expectDraft(Drafts::read(3,13,7)['body'] === 'Assigned ticket', 'Assigned ticket access');
    rejectsDraft(422, function () use ($draft) { Drafts::save(1,10,7,' ', $draft['revision']); });
    rejectsDraft(422, function () use ($draft) { Drafts::save(1,10,7,str_repeat('x',65536), $draft['revision']); });
    rejectsDraft(404, function () use ($draft) { Drafts::save(1,11,7,'No', $draft['revision']); });
    rejectsDraft(404, function () use ($draft) { Drafts::save(1,12,7,'No', $draft['revision']); });
    PermissionService::$allowed = false;
    rejectsDraft(403, function () use ($draft) { Drafts::save(1,10,7,'No', $draft['revision']); }); PermissionService::$allowed = true;
    $edited = $markdown . "\n\nEdited ✓";
    $saved = Drafts::save(1,10,7,$edited,$draft['revision']);
    expectDraft(Drafts::read(1,10,7)['body'] === $edited, 'Edit survives reload');
    $note = DB::table('tblticketnotes')->where('id',10)->first();
    expectDraft($note->admin === 'Sahdev AI' && $note->date === '2026-11-20' && $note->attachments === 'file.pdf', 'Author, date and attachments preserved');
    expectDraft($note->message === Drafts::PREFIX . $edited && $note->editor === 'markdown', 'Draft stays a marked private Markdown note');
    expectDraft(DB::table('tblticketreplies')->where('id',10)->first()->message === 'Public reply must not change', 'Matching reply ID never modified');
    rejectsDraft(409, function () use ($draft) { Drafts::save(1,10,7,'Stale edit',$draft['revision']); });
    expectDraft(Drafts::read(1,10,7)['body'] === $edited, 'Stale save preserves latest edit');
    expectDraft(count($activity) === 1 && strpos($activity[0], $edited) === false, 'Audit logs IDs without draft content');
    DB::$db->exec('DELETE FROM tblticketnotes WHERE id = 10');
    rejectsDraft(404, function () use ($saved) { Drafts::save(1,10,7,'Deleted note',$saved['revision']); });
    echo "PASS: Markdown round trip, private-note isolation, permissions, department access, stale/deleted notes, size limits and audit logging.\n";
}
