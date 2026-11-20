<?php
if (!defined('WHMCS')) die('This file cannot be accessed directly.');
require_once __DIR__.'/src/Access.php';
require_once __DIR__.'/src/Records.php';
require_once __DIR__.'/src/CopilotPanel.php';
add_hook('AdminAreaViewTicketPage',1,function ($vars) {
    try {
        $admin = \SahdevConnector\Access::admin();
        $ticket = \SahdevConnector\Access::ticket((int)($vars['ticketid'] ?? 0),$admin);
        $base = rtrim((string)\WHMCS\Database\Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value'),'/').'/modules/addons/sahdev_cloud';
        $escape = function ($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); };
        return '<link rel="stylesheet" href="'.$escape($base).'/panel.css"><section class="sd-cloud" data-cloud-panel data-endpoint="'.$escape($base).'/bridge.php" data-ticket="'.(int)$ticket->id.'" data-csrf="'.$escape(\SahdevConnector\Access::token()).'">'
            .'<header><strong>Sahdev Cloud</strong><span>Acceptance preview · Drafts require your review</span></header>'
            .'<form><div class="sd-fields"><label>Workflow<select name="workflow"><option value="ticket.analyze">Analyze ticket</option><option value="ticket.summarize">Summarize</option><option value="ticket.rewrite">Rewrite draft</option><option value="ticket.score">Score draft</option><option value="ticket.canned">Create canned draft</option></select></label>'
            .'<label>Cloud model<select name="model_id" required><option value="">Load models first</option></select></label><label>Tone<select name="tone"><option>Professional</option><option>Friendly</option><option>Concise</option><option>Empathetic</option></select></label>'
            .'<label>Maximum output tokens<input type="number" name="max_output_tokens" value="1024" min="64" max="4096" required></label></div>'
            .'<label>Draft to rewrite, score or reuse<textarea name="draft" maxlength="12000" rows="3"></textarea></label><label>Editing instruction<input name="instruction" maxlength="2000"></label>'
            .'<div class="sd-actions"><button type="button" data-load-models>Load models</button><button type="submit">Run workflow</button><button type="button" data-check-result hidden>Check request</button><span data-status role="status"></span></div></form><div data-result aria-live="polite"></div></section><script src="'.$escape($base).'/panel.js" defer></script>';
    } catch (Throwable $e) { return ''; }
});

add_hook('AdminAreaFooterOutput',1,function () {
    try {
        $admin = \SahdevConnector\Access::admin();
        \SahdevConnector\Records::access($admin);
        $url = (string)\WHMCS\Database\Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value');
        $invoices = \SahdevConnector\Access::allows($admin,'View Invoice');
        $services = \SahdevConnector\Access::allows($admin,'View Clients Products/Services');
        return \SahdevConnector\CopilotPanel::render($url,\SahdevConnector\Access::token(),$invoices,$services);
    } catch (Throwable $error) {
        return '';
    }
});
