<?php
namespace SahdevConnector;

final class CopilotPanel
{
    public static function render(string $systemUrl, string $token, bool $invoices, bool $services): string
    {
        if (!$invoices && !$services) return '';
        $base = htmlspecialchars(rtrim($systemUrl,'/').'/modules/addons/sahdev_cloud',ENT_QUOTES,'UTF-8');
        $csrf = htmlspecialchars($token,ENT_QUOTES,'UTF-8');
        $options = ($invoices ? '<option value="invoices">Invoices</option>' : '')
            .($services ? '<option value="services">Services</option>' : '');
        return '<link rel="stylesheet" href="'.$base.'/panel.css">'
            .'<details class="sd-cloud sd-copilot" data-sd-copilot data-endpoint="'.$base.'/bridge.php" data-csrf="'.$csrf.'">'
            .'<summary>Sahdev Cloud Copilot</summary><div class="sd-copilot-body">'
            .'<p>Look up a client and open related records in WHMCS.</p>'
            .'<form data-sd-search><label>Client ID, full name or exact company name'
            .'<input name="search" required maxlength="201" autocomplete="off"></label>'
            .'<button type="submit">Find client</button></form>'
            .'<form data-sd-records hidden><label><span id="sd-copilot-client-label">Client</span>'
            .'<select name="client" aria-labelledby="sd-copilot-client-label" required></select></label>'
            .'<label><span id="sd-copilot-records-label">Records</span>'
            .'<select name="resource" aria-labelledby="sd-copilot-records-label">'.$options.'</select></label>'
            .'<button type="submit">Show records</button></form>'
            .'<p data-sd-status role="status"></p><div data-sd-card></div></div></details>'
            .'<script src="'.$base.'/copilot.js" defer></script>';
    }
}
