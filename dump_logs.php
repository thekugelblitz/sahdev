<?php
require __DIR__ . '/init.php';
$logs = \WHMCS\Database\Capsule::table('tblsahdev_module_logs')
    ->where('source', 'like', '%Manual%')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();
$out = "";
foreach ($logs as $l) {
    $out .= $l->created_at . " | " . $l->source . " | " . $l->message . "\n";
}
file_put_contents(__DIR__ . '/debug_logs.txt', $out);
echo "Dumped logs to debug_logs.txt";
