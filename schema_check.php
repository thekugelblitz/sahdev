<?php
require_once dirname(__DIR__, 3) . '/init.php';
header('Content-Type: application/json');

use WHMCS\Database\Capsule;

$kb_cols = Capsule::schema()->getColumnListing('tblknowledgebase');
$kbcats_cols = Capsule::schema()->getColumnListing('tblknowledgebasecats');
$kblinks_cols = Capsule::schema()->getColumnListing('tblknowledgebaselinks');

echo json_encode([
    'tblknowledgebase' => $kb_cols,
    'tblknowledgebasecats' => $kbcats_cols,
    'tblknowledgebaselinks' => $kblinks_cols,
]);
