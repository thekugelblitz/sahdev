<?php
require 'init.php';
$logs = \WHMCS\Database\Capsule::table('tblsahdev_module_logs')
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();
echo "ID | Source | Message\n";
foreach ($logs as $l) {
    echo $l->id . " | " . $l->source . " | " . $l->message . "\n";
}
