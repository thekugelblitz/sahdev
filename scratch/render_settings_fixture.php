<?php
/** Offline render of the real controller. No WHMCS bootstrap, credentials, or network. */
namespace WHMCS\Database {
    class Capsule {
        public static $preferences = null;
        public static $settings = null;
        public static $failPreferences = false;
        public static function table($name) { return new FixtureQuery($name); }
        public static function schema() { return new FixtureSchema(); }
        public static function connection() { return new self(); }
        public function transaction($callback) {
            $settings = self::$settings;
            $preferences = self::$preferences;
            try { return $callback(); }
            catch (\Throwable $error) { self::$settings = $settings; self::$preferences = $preferences; throw $error; }
        }
    }
    class FixtureSchema {
        public function hasTable($name) { return true; }
        public function hasColumn($table, $column) { return true; }
    }
    class FixtureQuery {
        private $table;
        public function __construct($table) { $this->table = $table; }
        public function __call($name, $args) { return $this; }
        public function first() {
            if ($this->table === 'tblsahdev_settings') return Capsule::$settings;
            if ($this->table === 'tblsahdev_admin_preferences' && Capsule::$preferences !== null) {
                return (object) ['preferences_json' => json_encode(Capsule::$preferences)];
            }
            return null;
        }
        public function count() { return 0; }
        public function exists() { return $this->table === 'tblsahdev_admin_preferences' && Capsule::$preferences !== null; }
        public function update($values) {
            if ($this->table === 'tblsahdev_settings' && Capsule::$settings === null) return 0;
            $this->updateOrInsert([], $values);
        }
        public function insert($values) {
            // Schema seed uses DB column defaults; the controller supplies the empty-table defaults here.
            if ($this->table !== 'tblsahdev_settings') $this->updateOrInsert([], $values);
        }
        public function updateOrInsert($key, $values) {
            if ($this->table === 'tblsahdev_settings') Capsule::$settings = (object) array_merge((array) Capsule::$settings, $key, $values);
            if ($this->table === 'tblsahdev_admin_preferences') {
                if (Capsule::$failPreferences) throw new \RuntimeException('Simulated preference write failure');
                Capsule::$preferences = json_decode($values['preferences_json'], true);
            }
        }
        public function get() {
            if ($this->table === 'tblsahdev_providers') return [(object) ['id' => 1, 'name' => 'Support model', 'model_name' => 'Primary', 'provider_type' => 'google']];
            return [];
        }
    }
}
namespace Carbon {
    class Carbon { public static function now() { return '2026-11-01 12:00:00'; } }
}
namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $_SESSION = ['adminid' => 1];
    $_POST = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    function generate_token($type) {
        return $type === 'form' ? '<input type="hidden" name="token" value="fixture-csrf">' : 'fixture-csrf';
    }
    function check_token($type) { if (($_POST['token'] ?? '') !== 'fixture-csrf') throw new \RuntimeException('Invalid fixture CSRF'); }
    require_once dirname(__DIR__) . '/controllers/AdminController.php';
    require_once dirname(__DIR__) . '/lib/PermissionService.php';
    $reflection = new \ReflectionClass(\Sahdev\Controllers\AdminController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('moduleVars');
    $property->setAccessible(true);
    $property->setValue($controller, ['modulelink' => 'addonmodules.php?module=sahdev']);
    $page = \Sahdev\Lib\AdminTheme::wrap($controller->settings(), 1);
    // Minimal host layout for local rendering; the production host supplies Bootstrap.
    $host = 'body{margin:0;background:#e9e9ec;font-family:Arial,sans-serif;padding:24px}.row{display:flex;flex-wrap:wrap;margin:0 -10px}.row>[class*=col-]{padding:0 10px;min-width:0}.col-md-6{width:50%}.col-md-4{width:33.333%}.form-control{display:block;width:100%;padding:8px 12px}label{display:inline-block;margin-bottom:6px}.btn{padding:7px 12px;cursor:pointer;border:1px solid #ccc}.text-muted{color:#666}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.alert{padding:15px;border:1px solid}.table{width:100%;border-collapse:collapse}.table td,.table th{padding:8px}.table-responsive{overflow:auto}@media(max-width:700px){body{padding:8px}.row>[class*=col-]{width:100%}}';
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sahdev settings fixture</title><style>' . $host . '</style></head><body><div id="host-control">WHMCS host remains unchanged</div>' . $page . '</body></html>';
}
