<?php
use WHMCS\Database\Capsule;
if (!defined('WHMCS')) die('This file cannot be accessed directly.');
require_once __DIR__.'/src/Access.php';
require_once __DIR__.'/src/Client.php';

function sahdev_cloud_config(): array
{
    return [
        'name'=>'Sahdev Cloud Connector (acceptance preview)',
        'description'=>'Connect to Sahdev Cloud for ticket drafts and optional read-only Copilot records.',
        'version'=>\SahdevConnector\Client::VERSION,'author'=>'HostingSpell','language'=>'english',
        'fields'=>[
            'copilot_records'=>[
                'FriendlyName'=>'Copilot record lookup',
                'Type'=>'yesno',
                'Description'=>'Allow approved WHMCS roles to send client names and selected invoice or service fields to Sahdev Cloud for formatting.',
            ],
        ],
    ];
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
        $status = null;
        $notice = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            \SahdevConnector\Access::csrf((string)($_POST['csrf'] ?? ''));
            try {
                if (($_POST['action'] ?? 'enroll') === 'check') {
                    $status = (new \SahdevConnector\Client())->request('GET','/api/v1/connector-status');
                } elseif (($_POST['action'] ?? 'enroll') === 'enroll') {
                    \SahdevConnector\Client::enroll(trim((string)($_POST['url'] ?? '')),trim((string)($_POST['installation_id'] ?? '')),trim((string)($_POST['token'] ?? '')));
                    $notice = 'Connector enrolled. Check the connection and open a permitted support ticket to try the draft workflows.';
                }
            } catch (RuntimeException $e) {
                $error = in_array($e->getCode(),[401,403,404,409,422,423,429,502,503],true)
                    ? $e->getMessage() : 'Connector request failed. Verify the addon settings and try again.';
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        $escape = function ($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); };
        $csrf = $escape(\SahdevConnector\Access::token());
        echo '<h2>Sahdev Cloud connector</h2><p>Preview for ticket drafting and admin record lookup. Install alongside your existing addon.</p>';
        if ($notice) echo '<div class="alert alert-success">'.$escape($notice).'</div>';
        if ($error) echo '<div class="alert alert-danger">'.$escape($error).'</div>';
        if ($status !== null) {
            echo '<div class="alert alert-info"><strong>Signed cloud connection confirmed.</strong><ul>'
                .'<li>Connector preview '.(!empty($status['preview_enabled']) ? 'enabled' : 'disabled').'</li>'
                .'<li>Brand data store '.(!empty($status['brand_database_ready']) ? 'provisioned' : 'awaiting provisioning').'</li>'
                .'<li>'.(int)($status['models'] ?? 0).' priced models available to this plan</li></ul></div>';
        }
        echo '<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><button class="btn btn-default" name="action" value="check">Check connection</button></form>';
        echo '<h3>Enroll connector</h3><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'">';
        echo '<p><label>Cloud URL <input class="form-control" type="url" name="url" value="https://sahdev.hostingspell.com" required></label></p>';
        echo '<p><label>Installation ID <input class="form-control" name="installation_id" autocomplete="off" required></label></p>';
        echo '<p><label>One-time enrollment token <input class="form-control" type="password" name="token" autocomplete="new-password" required></label></p>';
        echo '<button class="btn btn-primary" name="action" value="enroll">Enroll connector</button></form><p>Issue a token from the verified brand in your SaaS dashboard. Provider keys are configured in Command Station.</p>';
    } catch (Throwable $e) {
        $message = in_array($e->getCode(),[403,419],true) ? $e->getMessage() : 'Unable to open connector settings.';
        echo '<div class="alert alert-danger">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div>';
    }
}
