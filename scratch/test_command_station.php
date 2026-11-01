<?php
require_once dirname(__DIR__) . '/autoload.php';

use Sahdev\Lib\AdminPreferences;
use Sahdev\Lib\DisplayText;

function expectStation($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

foreach (['focus', 'canvas', 'console'] as $layout) {
    $prefs = AdminPreferences::normalizeArray([
        'ui_theme' => $layout,
        'panel_density' => 'featured',
        'panel_accent' => 'teal',
        'panel_show_server' => false,
        'panel_show_appearance' => false,
    ]);
    expectStation($prefs['ui_theme'] === $layout, 'Layout lost: ' . $layout);
    expectStation($prefs['panel_density'] === 'featured' && $prefs['panel_accent'] === 'teal', 'Variants lost');
    expectStation(!$prefs['panel_show_server'] && !$prefs['panel_show_appearance'], 'Personal visibility lost');
}
$invalid = AdminPreferences::normalizeArray(['ui_theme'=>'<script>', 'panel_density'=>'wide', 'panel_accent'=>'red']);
expectStation($invalid['ui_theme'] === 'remastered' && $invalid['panel_density'] === 'comfortable' && $invalid['panel_accent'] === 'indigo', 'Invalid preference fallback');
expectStation(DisplayText::label('Sales &amp; General Help') === 'Sales &amp; General Help', 'Entity decoding');
expectStation(DisplayText::label('Sales &amp;amp; General Help') === 'Sales &amp; General Help', 'Double entity decoding');
expectStation(DisplayText::label('&lt;script&gt;alert(1)&lt;/script&gt;') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'Escaping after decode');
echo "PASS: three layouts, personal variants, invalid values, and safe label rendering.\n";
