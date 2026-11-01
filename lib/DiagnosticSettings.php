<?php
namespace Sahdev\Lib;
use WHMCS\Database\Capsule;
require_once __DIR__ . '/DiagnosticPolicy.php';
require_once __DIR__ . '/DiagnosticTrace.php';

class DiagnosticSettings
{
    public static function save(array $post): void
    {
        if (!isset($post['diagnostic_policy_present'])) return;
        $p = (array) ($post['diagnostics'] ?? []);
        foreach (['enabled', 'emergency_stop', 'tickets', 'livechat', 'enrichment', 'telemetry', 'logs_enabled', 'logs_auto_cleanup'] as $k) $p[$k] = !empty($p[$k]);
        $p['categories'] = (array) ($p['categories'] ?? []);
        $p['disabled_tools'] = (array) ($p['disabled_tools'] ?? []);
        $p = DiagnosticPolicy::normalize($p);
        foreach (['planner_provider', 'planner_fallback'] as $k) {
            if ($p[$k] && !TaskProviderResolver::isValidActiveProviderId($p[$k])) throw new \RuntimeException('Select an active diagnostic provider.');
        }
        $update = ['diagnostic_policy_json' => json_encode($p)];
        if (isset($post['diagnostic_livechat_gate_present'])) $update['client_chat_tools_enabled'] = !empty($post['client_chat_tools_enabled']) ? 1 : 0;
        Capsule::table('tblsahdev_settings')->where('id', 1)->update($update);
    }
    public static function readiness($settings, array $policy): array
    {
        $common = [];
        if (!$policy['enabled']) $common[] = 'Shared diagnostic automation is off';
        if ($policy['emergency_stop']) $common[] = 'Emergency stop is enabled';
        if (empty($settings->tools_api_key_encrypted)) $common[] = 'ToolsAPI key is missing';
        if (!$policy['categories']) $common[] = 'All automatic categories are disabled';
        foreach (['tblsahdev_diagnostic_state', 'tblsahdev_diagnostic_runs'] as $table) {
            if (!Capsule::schema()->hasTable($table)) $common[] = 'Diagnostic storage is missing; run the module upgrade';
        }
        $result = [];
        foreach (['livechat'=>'client_chat_tools_enabled', 'tickets'=>'tools_execution_enabled'] as $channel=>$field) {
            $blockers = $common;
            if (!$policy[$channel]) $blockers[] = ucfirst($channel) . ' automation is off';
            if (empty($settings->$field)) $blockers[] = $channel === 'livechat' ? 'Enable Diagnostic Tool Calling in Live Chat is off' : 'Tools execution module is off';
            $result[$channel] = array_values(array_unique($blockers));
        }
        return $result;
    }
    public static function render(string $moduleLink = 'addonmodules.php?module=sahdev'): void
    {
        $p = DiagnosticPolicy::get();
        $escape = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $settings = Capsule::table('tblsahdev_settings')->first();
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Diagnostic workflow v' . DiagnosticPolicy::VERSION . '</strong></div><div class="panel-body">';
        echo '<input type="hidden" name="diagnostic_policy_present" value="1"><p>Shared evidence for tickets and live chat. Existing channel enable switches also apply. Manual ticket tools remain available when automation is off.</p>';
        foreach (self::readiness($settings, $p) as $channel=>$blockers) {
            echo '<div class="alert ' . ($blockers ? 'alert-warning' : 'alert-success') . '"><strong>' . ($channel === 'livechat' ? 'Live chat' : 'Tickets') . ':</strong> ' . $escape($blockers ? implode('; ', $blockers) : 'Configuration ready — credentials and connectivity are verified when a check runs.') . '</div>';
        }
        echo '<input type="hidden" name="diagnostic_livechat_gate_present" value="1"><div class="checkbox"><label><input type="checkbox" name="client_chat_tools_enabled" value="1" ' . (!empty($settings->client_chat_tools_enabled) ? 'checked' : '') . '> Enable Diagnostic Tool Calling in Live Chat</label></div><p class="help-block">This is the same switch shown in Live Chat settings. Choosing an AI model does not enable external checks. Basic DNS checks run locally selected recipes without a reconnaissance model call.</p>';
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
        echo '</select><small>Select tools to block in both automatic and matching manual requests.</small></div>';
        echo '<hr><h4>Diagnostic logging and cleanup</h4>';
        foreach (['logs_enabled'=>'Record diagnostic decisions and tool outcomes', 'logs_auto_cleanup'=>'Automatically clean expired diagnostic logs during Sahdev cron'] as $key=>$label) {
            echo '<div class="checkbox"><label><input type="checkbox" name="diagnostics[' . $key . ']" value="1" ' . ($p[$key] ? 'checked' : '') . '> ' . $escape($label) . '</label></div>';
        }
        echo '<div class="form-group"><label>Keep diagnostic logs for days</label><input type="number" min="1" max="90" class="form-control" name="diagnostics[log_retention_days]" value="' . (int) $p['log_retention_days'] . '"></div>';
        echo '<p class="help-block">Logs contain routing reasons, trace/session IDs, target hosts, HTTP status and timings. No full messages, prompts, API keys or response bodies. These controls leave other module logs and debug-payload settings unchanged. Cleanup uses saved settings and still works when recording is off. Automatic cleanup removes up to 1,000 rows per cron run.</p>';
        echo '<p><a class="btn btn-default" href="' . $escape($moduleLink . '&action=module_logs&diagnostics_only=1') . '">View diagnostic logs</a> <button type="submit" class="btn btn-default" name="diagnostic_log_cleanup" value="expired">Clean expired logs (up to 5,000)</button> <button type="submit" class="btn btn-warning" name="diagnostic_log_cleanup" value="all" onclick="return confirm(\'Delete up to 5,000 diagnostic logs, including recent entries? Other module logs are kept.\')">Clear diagnostic logs (up to 5,000)</button></p>';
        echo '</div></div>';
    }
}
