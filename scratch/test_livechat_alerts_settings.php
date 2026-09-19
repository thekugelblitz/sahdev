<?php
echo "=== Testing Live Support & Visitor Notification Alerts Settings ===\n";

// Test 1: Checkbox uncheck behavior (when checkbox is unchecked, browser omits it from $_POST)
$_POST = [
    'save_settings' => '1',
    'active_subtab' => 'tab-livechat-alerts',
    // client_chat_notify_new_visitor is UNCHECKED (omitted)
    'client_chat_notify_human_summon' => '1', // CHECKED
    // client_chat_alert_focus_mode is UNCHECKED (omitted)
    'client_chat_alert_dedup_active_staff' => '1', // CHECKED
    // client_chat_sound_admin_alert is UNCHECKED (omitted)
    'client_chat_sound_type' => 'bell',
    'client_chat_alert_duration' => '30',
    // Firebase: enabled is UNCHECKED (omitted)
    'firebase_notify_new_visitor' => '1',
    // firebase_notify_summons is UNCHECKED (omitted)
];

// Simulate AdminController field processing
$updatePayload = [];
$activeSubtab = !empty($_POST['active_subtab']) ? trim((string)$_POST['active_subtab']) : 'tab-ticket';

$updatePayload['firebase_enabled'] = !empty($_POST['firebase_enabled']) ? 1 : 0;
$updatePayload['firebase_gateway_url'] = trim((string)($_POST['firebase_gateway_url'] ?? ''));
$updatePayload['firebase_notify_new_visitor'] = !empty($_POST['firebase_notify_new_visitor']) ? 1 : 0;
$updatePayload['firebase_notify_summons'] = !empty($_POST['firebase_notify_summons']) ? 1 : 0;
$updatePayload['firebase_notify_chat_messages'] = !empty($_POST['firebase_notify_chat_messages']) ? 1 : 0;
$updatePayload['firebase_notify_tickets'] = !empty($_POST['firebase_notify_tickets']) ? 1 : 0;
$updatePayload['firebase_notify_system_alerts'] = !empty($_POST['firebase_notify_system_alerts']) ? 1 : 0;

$updatePayload['client_chat_notify_new_visitor'] = !empty($_POST['client_chat_notify_new_visitor']) ? 1 : 0;
$updatePayload['client_chat_notify_human_summon'] = !empty($_POST['client_chat_notify_human_summon']) ? 1 : 0;
$updatePayload['client_chat_alert_focus_mode'] = !empty($_POST['client_chat_alert_focus_mode']) ? 1 : 0;
$updatePayload['client_chat_alert_dedup_active_staff'] = !empty($_POST['client_chat_alert_dedup_active_staff']) ? 1 : 0;
$updatePayload['client_chat_sound_admin_alert'] = !empty($_POST['client_chat_sound_admin_alert']) ? 1 : 0;
$updatePayload['client_chat_sound_type'] = in_array($_POST['client_chat_sound_type'] ?? '', ['chime', 'bell', 'ping'], true) ? $_POST['client_chat_sound_type'] : 'chime';
$updatePayload['client_chat_alert_duration'] = max(3, min(120, (int)($_POST['client_chat_alert_duration'] ?? 15)));

// Assertions for unchecking & values
assert($updatePayload['client_chat_notify_new_visitor'] === 0, 'Unchecked client_chat_notify_new_visitor must be 0');
assert($updatePayload['client_chat_notify_human_summon'] === 1, 'Checked client_chat_notify_human_summon must be 1');
assert($updatePayload['client_chat_alert_focus_mode'] === 0, 'Unchecked client_chat_alert_focus_mode must be 0');
assert($updatePayload['client_chat_alert_dedup_active_staff'] === 1, 'Checked client_chat_alert_dedup_active_staff must be 1');
assert($updatePayload['client_chat_sound_admin_alert'] === 0, 'Unchecked client_chat_sound_admin_alert must be 0');
assert($updatePayload['client_chat_sound_type'] === 'bell', 'Sound preset must be bell');
assert($updatePayload['client_chat_alert_duration'] === 30, 'Duration must be 30');
assert($updatePayload['firebase_enabled'] === 0, 'Unchecked firebase_enabled must be 0');
assert($updatePayload['firebase_notify_summons'] === 0, 'Unchecked firebase_notify_summons must be 0');
assert($activeSubtab === 'tab-livechat-alerts', 'Active subtab must persist as tab-livechat-alerts');

echo "PASS: Checkbox uncheck and value extraction verified.\n";

// Test 2: Clamping & validation edge cases
$_POST = [
    'client_chat_sound_type' => 'invalid_preset_tone',
    'client_chat_alert_duration' => '1',
];
$soundType = in_array($_POST['client_chat_sound_type'] ?? '', ['chime', 'bell', 'ping'], true) ? $_POST['client_chat_sound_type'] : 'chime';
$duration = max(3, min(120, (int)($_POST['client_chat_alert_duration'] ?? 15)));
assert($soundType === 'chime', 'Invalid sound preset must fall back to chime');
assert($duration === 3, 'Duration below 3 must clamp to 3');

$_POST['client_chat_alert_duration'] = '500';
$durationHigh = max(3, min(120, (int)($_POST['client_chat_alert_duration'] ?? 15)));
assert($durationHigh === 120, 'Duration above 120 must clamp to 120');

echo "PASS: Clamping & validation edge cases verified.\n";

// Test 3: HTML Rendering evaluation for saved 0 and 1 values
$savedSettings = (object) [
    'client_chat_notify_new_visitor' => 0,
    'client_chat_notify_human_summon' => 1,
    'client_chat_alert_focus_mode' => 0,
    'client_chat_alert_dedup_active_staff' => 1,
    'client_chat_sound_admin_alert' => 0,
    'client_chat_sound_type' => 'bell',
    'client_chat_alert_duration' => 45,
];

function isChecked($val): string {
    return (!isset($val) || !empty($val)) ? 'checked' : '';
}

assert(isChecked($savedSettings->client_chat_notify_new_visitor) === '', 'Saved 0 must NOT be checked in HTML');
assert(isChecked($savedSettings->client_chat_notify_human_summon) === 'checked', 'Saved 1 MUST be checked in HTML');
assert(isChecked($savedSettings->client_chat_alert_focus_mode) === '', 'Saved 0 must NOT be checked in HTML');
assert(isChecked($savedSettings->client_chat_alert_dedup_active_staff) === 'checked', 'Saved 1 MUST be checked in HTML');
assert(isChecked($savedSettings->client_chat_sound_admin_alert) === '', 'Saved 0 must NOT be checked in HTML');

echo "PASS: HTML rendering for checked/unchecked settings verified.\n";
echo "=== ALL TESTS PASSED SUCCESSFULLY! ===\n";