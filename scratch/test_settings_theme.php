<?php
// Run: php scratch/test_settings_theme.php
ob_start();
require __DIR__ . '/render_settings_fixture.php';
$html = ob_get_clean();
use WHMCS\Database\Capsule;
use Sahdev\Lib\AdminPreferences;
function expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
expect(substr_count($html, 'class="sdv-settings-pane ') === 8, 'All eight sections render');
expect(strpos($html, 'data-sahdev-token="fixture-csrf"') !== false, 'Theme requests have CSRF token');
expect(AdminPreferences::normalizeArray([])['theme_mode'] === null, 'New admins inherit');
expect(AdminPreferences::normalizeArray(['theme_mode' => 'invalid'])['theme_mode'] === null, 'Invalid preferences inherit');
Capsule::$settings = (object) ['ui_theme_mode' => 'white'];
expect(AdminPreferences::getThemeMode(1) === 'white', 'Global white is inherited');
Capsule::$preferences = ['theme_mode' => 'dark'];
expect(AdminPreferences::getThemeMode(1) === 'dark', 'Explicit admin choice wins');
Capsule::$preferences = ['theme_mode' => null];
expect(AdminPreferences::getThemeMode(1) === 'white', 'Reset preference inherits');
Capsule::$settings = null;
Capsule::$preferences = null;
// Submit real field names through the production controller, with DB boundaries stubbed.
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$_POST = [];
foreach ($xpath->query('//form[@id="sdvSettingsForm"]//*[@name]') as $field) {
    $name = $field->getAttribute('name');
    $type = $field->getAttribute('type');
    if (in_array($type, ['checkbox', 'radio']) && !$field->hasAttribute('checked')) continue;
    $value = $field->getAttribute('value');
    if ($field->tagName === 'textarea') $value = $field->textContent;
    if ($field->tagName === 'select') {
        $options = $field->getElementsByTagName('option');
        $value = $options->item(0)->getAttribute('value');
        foreach ($options as $option) if ($option->hasAttribute('selected')) $value = $option->getAttribute('value');
    }
    $_POST[$name] = $value;
}
$_POST['ui_theme_mode'] = 'white';
$_POST['active_subtab'] = 'not-a-real-section';
$_POST['temperature'] = '0.2';
unset($_POST['client_chat_notify_new_visitor']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$saved = $controller->settings();
expect(Capsule::$settings->ui_theme_mode === 'white', 'Workspace theme saved');
expect(Capsule::$preferences['theme_mode'] === 'white', 'Admin theme saved');
expect((float) Capsule::$settings->temperature === 0.2, 'Generation field saved');
expect(Capsule::$settings->client_chat_notify_new_visitor === 0, 'Unchecked hidden-section checkbox persists');
expect(strpos($saved, 'value="tab-appearance"') !== false, 'Invalid section safely falls back');
expect(strpos($saved, 'Settings saved successfully.') !== false, 'Save acknowledgement returned');
Capsule::$failPreferences = true;
$_POST['ui_theme_mode'] = 'black';
try { $controller->settings(); throw new RuntimeException('Expected persistence failure'); }
catch (RuntimeException $e) { expect($e->getMessage() === 'Simulated preference write failure', 'Write error is not swallowed'); }
expect(Capsule::$settings->ui_theme_mode === 'white', 'Failed preference write rolls back settings');
echo "PASS: rendered sections, theme inheritance, real settings save, hidden checkbox, invalid tab, transaction rollback.\n";
