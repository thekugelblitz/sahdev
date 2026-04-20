<?php
$paths = [
    __DIR__ . '/init.php',
    dirname(__DIR__) . '/init.php',
    dirname(__DIR__, 2) . '/init.php',
    dirname(__DIR__, 3) . '/init.php',
    'e:/MainStream/Code/sahdev-main/init.php',
    'e:/MainStream/Code/init.php',
    'e:/MainStream/init.php',
];
foreach($paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $logs = \WHMCS\Database\Capsule::table('tblsahdev_module_logs')
            ->where('source', 'like', '%Manual%')
            ->orWhere('source', 'like', '%AJAX%')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();
        foreach ($logs as $l) {
            echo $l->created_at . " | " . $l->source . " | " . $l->message . "\n";
        }
        exit;
    }
}
echo "Could not find init.php";
