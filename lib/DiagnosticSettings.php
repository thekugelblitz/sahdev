<?php
namespace Sahdev\Lib;
use WHMCS\Database\Capsule;
require_once __DIR__ . '/DiagnosticPolicy.php';

class DiagnosticSettings
{
    public static function save(array $post): void
    {
        if (!isset($post['diagnostic_policy_present'])) return;
        $p = (array) ($post['diagnostics'] ?? []);
        foreach (['enabled', 'emergency_stop', 'tickets', 'livechat', 'enrichment', 'telemetry'] as $k) $p[$k] = !empty($p[$k]);
        $p['categories'] = (array) ($p['categories'] ?? []);
        $p['disabled_tools'] = (array) ($p['disabled_tools'] ?? []);
        $p = DiagnosticPolicy::normalize($p);
        foreach (['planner_provider', 'planner_fallback'] as $k) {
            if ($p[$k] && !TaskProviderResolver::isValidActiveProviderId($p[$k])) throw new \RuntimeException('Select an active diagnostic provider.');
        }
        Capsule::table('tblsahdev_settings')->where('id', 1)->update(['diagnostic_policy_json' => json_encode($p)]);
    }
    public static function render(): void
    {
        $p = DiagnosticPolicy::get();
        $escape = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>v5.0 Diagnostic workflow</strong></div><div class="panel-body">';
        echo '<input type="hidden" name="diagnostic_policy_present" value="1"><p>Shared evidence for tickets and live chat. Existing channel enable switches also apply. Manual ticket tools remain available when automation is off.</p>';
        foreach (['enabled'=>'Enable shared diagnostic automation', 'emergency_stop'=>'Emergency stop: block ALL diagnostic execution, including manual tools', 'tickets'=>'Automatic ticket diagnostics', 'livechat'=>'Automatic live-chat diagnostics', 'enrichment'=>'Include matched customer service configuration', 'telemetry'=>'Include matched server telemetry snapshots'] as $key => $label) {
            echo '<div class="checkbox"><label><input type="checkbox" name="diagnostics[' . $key . ']" value="1" ' . ($p[$key] ? 'checked' : '') . '> ' . $escape($label) . '</label></div>';
        }
        echo '<div class="form-group"><label>Reconnaissance mode</label><select class="form-control" name="diagnostics[planner]">';
        foreach (['auto'=>'Auto: use the planner for complex requests', 'always'=>'Always: use the selected planner', 'off'=>'Off: local diagnostic recipes only'] as $key => $label) echo '<option value="' . $key . '" ' . ($p['planner'] === $key ? 'selected' : '') . '>' . $escape($label) . '</option>';
        echo '</select></div>';
        $providers = TaskProviderResolver::listActiveProvidersForRouting();
        foreach (['planner_provider'=>'Reconnaissance provider', 'planner_fallback'=>'Reconnaissance fallback provider'] as $key => $label) {
            echo '<div class="form-group"><label>' . $label . '</label><select class="form-control" name="diagnostics[' . $key . ']"><option value="0">None — use local recipes</option>';
            foreach ($providers as $provider) echo '<option value="' . (int) $provider['id'] . '" ' . ($p[$key] === $provider['id'] ? 'selected' : '') . '>' . $escape($provider['name'] . ' / ' . $provider['model_name']) . '</option>';
            echo '</select></div>';
        }
        echo '<p class="help-block">Final replies use the existing Ticket Reply task provider or the dedicated Live Chat provider. Edit diagnostic instructions in Prompt Manager. Credentials and execution permissions never come from prompts.</p>';
        foreach (['max_checks'=>['Maximum checks per request',1,5], 'deadline_seconds'=>['Total diagnostic deadline (seconds, including planner)',5,30]] as $key => $def) echo '<div class="form-group"><label>' . $def[0] . '</label><input class="form-control" type="number" name="diagnostics[' . $key . ']" min="' . $def[1] . '" max="' . $def[2] . '" value="' . (int) $p[$key] . '"></div>';
        echo '<label>Automatic diagnostic categories</label>';
        foreach (DiagnosticPolicy::defaults()['categories'] as $category) echo '<label class="checkbox-inline"><input type="checkbox" name="diagnostics[categories][]" value="' . $category . '" ' . (in_array($category, $p['categories'], true) ? 'checked' : '') . '> ' . $escape(ucfirst($category)) . '</label>';
        echo '<div class="form-group" style="margin-top:16px"><label>Disabled individual tools</label><select multiple size="8" class="form-control" name="diagnostics[disabled_tools][]">';
        foreach (LiveChatToolService::getToolRegistry() as $name => $tool) echo '<option value="' . $escape($name) . '" ' . (in_array($name, $p['disabled_tools'], true) ? 'selected' : '') . '>' . $escape($name) . '</option>';
        echo '</select><small>Select tools to block in both automatic and matching manual requests.</small></div></div></div>';
    }
}
