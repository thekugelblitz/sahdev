<?php
use WHMCS\Database\Capsule;
if (!defined('WHMCS')) die('This file cannot be accessed directly.');
require_once __DIR__.'/src/Access.php';
require_once __DIR__.'/src/Client.php';

function sahdev_cloud_config(): array
{
    return ['name'=>'Sahdev Cloud Connector (acceptance preview)','description'=>'Thin bridge for cloud workflows. Do not replace your existing Sahdev installation until migration acceptance is complete.','version'=>'0.2.0-dev','author'=>'HostingSpell','language'=>'english','fields'=>[]];
}
function sahdev_cloud_activate(): array
{
    try {
        if (!Capsule::schema()->hasTable('mod_sahdev_cloud_requests')) Capsule::schema()->create('mod_sahdev_cloud_requests',function ($table) {
            $table->engine = 'InnoDB'; $table->string('fingerprint',64)->primary(); $table->unsignedInteger('admin_id'); $table->unsignedInteger('ticket_id');
            $table->mediumText('payload'); $table->string('remote_id',36)->nullable()->index(); $table->string('state',30); $table->dateTime('created_at');
        });
        return ['status'=>'success','description'=>'Connector activated. Assign permitted roles and enroll a staging brand.'];
    } catch (Throwable $e) { return ['status'=>'error','description'=>'Unable to create the connector request journal. Check database permissions.']; }
}
function sahdev_cloud_deactivate(): array
{
    Capsule::table('tbladdonmodules')->where('module','sahdev_cloud')->whereIn('setting',['connection','pending_connection'])->delete();
    // Keep unknown-request references for reconciliation. Revocation in SaaS is independent.
    return ['status'=>'success','description'=>'Local credentials removed. Revoke the installation in SaaS. The local request journal is retained.'];
}
function sahdev_cloud_output(array $vars): void
{
    try {
        $admin = \SahdevConnector\Access::admin();
        // WHMCS settings permission is required in addition to addon role access.
        \SahdevConnector\Access::permission($admin,'Configure Addon Modules');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            \SahdevConnector\Access::csrf((string)($_POST['csrf'] ?? ''));
            \SahdevConnector\Client::enroll(trim((string)($_POST['url'] ?? '')),trim((string)($_POST['installation_id'] ?? '')),trim((string)($_POST['token'] ?? '')));
            echo '<div class="alert alert-success">Connector enrolled. Open a permitted staging support ticket to test cloud workflows.</div>';
        }
        $escape = function ($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); };
        echo '<h2>Sahdev Cloud connector</h2><p>Acceptance preview. Core prompts and response processing run in Sahdev Cloud. This build does not yet replace all standard Sahdev features.</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="'.$escape(\SahdevConnector\Access::token()).'">';
        echo '<p><label>Cloud URL <input class="form-control" type="url" name="url" value="https://sahdev.hostingspell.com" required></label></p>';
        echo '<p><label>Installation ID <input class="form-control" name="installation_id" autocomplete="off" required></label></p>';
        echo '<p><label>One-time enrollment token <input class="form-control" type="password" name="token" autocomplete="new-password" required></label></p>';
        echo '<button class="btn btn-primary">Enroll connector</button></form><p>Issue a token from the verified brand in your SaaS dashboard. No AI provider keys belong in WHMCS.</p>';
    } catch (Throwable $e) { echo '<div class="alert alert-danger">'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</div>'; }
}
