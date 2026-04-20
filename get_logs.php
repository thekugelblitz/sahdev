<?php
require_once dirname(__DIR__, 2) . '/init.php';
$logs = \WHMCS\Database\Capsule::table('tblsahdev_module_logs')
    ->where('source', 'like', '%Manual%')
    ->orWhere('source', 'like', '%AJAX%')
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();
foreach ($logs as $l) {
    echo $l->created_at . " | " . $l->source . " | " . $l->message . "\n";
}
