<?php
require_once __DIR__ . '/../../../init.php';

use Illuminate\Database\Capsule\Manager as Capsule;

try {
    $intents = Capsule::table('tblsahdev_intents')->get();
    echo "Intents count: " . count($intents) . "\n";
    foreach ($intents as $intent) {
        echo "- {$intent->intent_key}: {$intent->label} (Active: {$intent->is_active})\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
