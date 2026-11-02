<?php
namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

final class CopilotAdminPages
{
    private static function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

    public static function access(string $moduleLink, int $adminId): string
    {
        if (!PermissionService::hasPermission($adminId, PermissionService::PERM_SETTINGS_MANAGE)) return '<div class="alert alert-danger">Settings management permission is required.</div>';
        SchemaManager::ensureSettingsColumns();
        $roles = PermissionService::getAllRoles();
        $roleId = (int) ($_POST['role_id'] ?? $_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
        $message = ''; $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token('form');
            try {
                CopilotDatabasePolicy::saveRole($adminId, $roleId, !empty($_POST['database_enabled']), (array) ($_POST['rules'] ?? []), (string) ($_POST['revision'] ?? ''));
                $message = 'Database policy saved and audited. Pending proposals will be checked against these rules when executed.';
            } catch (\Throwable $e) { $error = $e->getMessage(); }
        }
        $settings = Capsule::table('tblsahdev_settings')->first();
        $policy = CopilotDatabasePolicy::load();
        $url = self::e($moduleLink . '&action=copilot_access');
        ob_start();
        echo CopilotPresentation::assets();
        ?>
        <div class="sahdev-page-container sdv-copilot-policy">
            <h2>Copilot Database Access</h2>
            <p>Grant each WHMCS admin role access to specific resources, operations, fields and client IDs. All roles, including Full Administrator, start with no database grants. The role also needs Copilot access, execution and rollback permissions in <a href="<?php echo self::e($moduleLink); ?>&action=permissions">Role Permissions</a>.</p>
            <p>Every write requires approval of a saved plan. Deleting notes requires the operator’s password. Writes and rollback require InnoDB tables and stop when a reviewed record has changed.</p>
            <p>Core client, invoice, service and product creation/deletion, payments, refunds, provisioning and package changes stay in WHMCS’s native workflows. Direct edits do not run WHMCS lifecycle hooks or send notifications. Secrets, login credentials and arbitrary SQL are never exposed to Copilot.</p>
            <?php if ($message): ?><div class="alert alert-success"><?php echo self::e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?php echo self::e($error); ?></div><?php endif; ?>
            <nav aria-label="Select admin role">
                <?php foreach ($roles as $role): ?>
                    <a class="btn btn-<?php echo (int) $role['id'] === $roleId ? 'primary' : 'default'; ?>" href="<?php echo $url; ?>&role_id=<?php echo (int) $role['id']; ?>"><?php echo self::e($role['name']); ?></a>
                <?php endforeach; ?>
            </nav>
            <form method="post" action="<?php echo $url; ?>">
                <?php echo generate_token('form'); ?>
                <input type="hidden" name="role_id" value="<?php echo $roleId; ?>">
                <input type="hidden" name="revision" value="<?php echo hash('sha256', (string) ($settings->copilot_database_policy_json ?? '')); ?>">
                <p style="margin-top:16px"><label><input type="checkbox" name="database_enabled" value="1" <?php echo !empty($policy['enabled']) ? 'checked' : ''; ?>> Enable database access for configured roles</label></p>
                <p>Turning this off stops all Copilot database reads, writes and rollback. It does not remove rules or logs. Re-enable it to use approved rollback permissions.</p>
                <?php foreach (CopilotDatabasePolicy::resources() as $name => $definition):
                    $rule = $policy['roles'][$roleId][$name] ?? [];
                    $base = 'rules[' . $name . ']'; ?>
                    <fieldset><legend><?php echo self::e($definition['label']); ?></legend>
                        <div>
                            <?php foreach ($definition['ops'] as $op): ?>
                                <label><input type="checkbox" name="<?php echo $base; ?>[operations][<?php echo $op; ?>]" value="1" <?php echo !empty($rule['operations'][$op]) ? 'checked' : ''; ?>> <?php echo ucfirst($op); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="sdv-policy-fields">
                            <label>Readable fields (ID is always included)<br>
                                <select multiple class="form-control" name="<?php echo $base; ?>[read_fields][]" aria-label="<?php echo self::e($definition['label']); ?> readable fields">
                                    <?php foreach ($definition['read'] as $field): ?><option value="<?php echo $field; ?>" <?php echo in_array($field, $rule['read_fields'] ?? [], true) ? 'selected' : ''; ?>><?php echo $field; ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <?php if ($definition['write']): ?>
                                <label>Writable fields (must also be readable)<br>
                                    <select multiple class="form-control" name="<?php echo $base; ?>[write_fields][]" aria-label="<?php echo self::e($definition['label']); ?> writable fields">
                                        <?php foreach ($definition['write'] as $field): ?><option value="<?php echo $field; ?>" <?php echo in_array($field, $rule['write_fields'] ?? [], true) ? 'selected' : ''; ?>><?php echo $field; ?></option><?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <div>
                                <?php if ($definition['owner']): ?>
                                    <label>Allowed client IDs (blank means all clients)<br><input class="form-control" name="<?php echo $base; ?>[client_ids]" value="<?php echo self::e(implode(',', $rule['client_ids'] ?? [])); ?>" placeholder="e.g. 12,34"></label><br>
                                <?php endif; ?>
                                <label>Maximum records per page<br><input class="form-control" type="number" min="1" max="50" name="<?php echo $base; ?>[limit]" value="<?php echo (int) ($rule['limit'] ?? 20); ?>"></label>
                            </div>
                        </div>
                        <?php if ($definition['write']): ?>
                            <label style="display:block;margin-top:12px">Allowed values by field (JSON; blank means built-in validation only)
                                <textarea class="form-control" name="<?php echo $base; ?>[allowed_values]" rows="2" placeholder='{"status":["Active","Inactive"]}'><?php echo !empty($rule['allowed_values']) ? self::e(json_encode($rule['allowed_values'], JSON_PRETTY_PRINT)) : ''; ?></textarea>
                            </label>
                        <?php endif; ?>
                        <?php if ($name === 'invoices'): ?><p>Invoice status edits only cancel an unpaid invoice. Payment status changes require the WHMCS payment workflow.</p><?php endif; ?>
                        <?php if ($name === 'credit_ledger'): ?><p>Creating credit atomically changes the client balance and inserts its ledger record. Read access to the parent client’s credit and currency is also required.</p><?php endif; ?>
                        <?php if (in_array($name, ['client_notes','ticket_notes'], true)): ?><p>Creating notes also requires read access to the parent client or ticket. Creation and deletion require all note fields to be readable for a complete preview.</p><?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>
                <button class="btn btn-primary" type="submit">Save role policy</button>
                <a class="btn btn-default" href="<?php echo self::e($moduleLink); ?>&action=admin_copilot&subtab=journal">View action and policy log</a>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function audit(string $moduleLink, int $adminId): string
    {
        if (!PermissionService::hasPermission($adminId, PermissionService::PERM_COPILOT_USE)) return '<div class="alert alert-danger">Copilot access is required.</div>';
        SchemaManager::ensureCopilotOperationTables();
        $all = PermissionService::hasPermission($adminId, PermissionService::PERM_AUDIT_MANAGE) || PermissionService::hasPermission($adminId, PermissionService::PERM_SETTINGS_MANAGE);
        $fingerprint = CopilotDatabasePolicy::fingerprint($adminId);
        $page = max(1, (int) ($_GET['log_page'] ?? 1));
        $journalPage = max(1, (int) ($_GET['journal_page'] ?? 1));
        $events = Capsule::table('tblsahdev_ops_events');
        $journal = Capsule::table('tblsahdev_ops_journal');
        if (!$all) { $events->where('admin_id', $adminId); $journal->where('admin_id', $adminId); }
        $eventCount = (clone $events)->count(); $journalCount = (clone $journal)->count();
        $events = $events->orderBy('id', 'desc')->offset(($page-1)*50)->limit(50)->get();
        $journal = $journal->orderBy('id', 'desc')->offset(($journalPage-1)*25)->limit(25)->get();
        $url = self::e($moduleLink . '&action=admin_copilot&subtab=journal');
        ob_start(); ?>
        <section class="panel panel-default" style="padding:20px">
            <h3>Database action journal</h3>
            <p><?php echo $all ? 'All administrators' : 'Your operations'; ?> · <?php echo $journalCount; ?> operations. Expand an operation to inspect exact row snapshots, parameters and rollback outcome. Older operations without a verified transactional plan require manual review.</p>
            <?php foreach ($journal as $entry): $plan = json_decode((string) $entry->compensation_recipe_json, true); $visible = $all || hash_equals($fingerprint, (string) ($plan['access_fingerprint'] ?? '')); ?>
                <details style="margin:12px 0;padding:12px;border:1px solid var(--sdv-border,#64748b);border-radius:6px">
                    <summary>#<?php echo (int) $entry->id; ?> · <?php echo $visible ? self::e($entry->description) : 'Evidence withheld after access change'; ?> · <?php echo self::e($entry->status); ?> · Admin #<?php echo (int) $entry->admin_id; ?> · <?php echo self::e($entry->executed_at); ?></summary>
                    <?php if ($visible): ?>
                    <p>Rolled back by: <?php echo (int) ($entry->rollback_admin_id ?? 0); ?> · <?php echo self::e($entry->rolled_back_at ?? '—'); ?></p>
                    <p><?php echo self::e($entry->rollback_error ?? ''); ?></p>
                    <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo self::e(json_encode(['parameters'=>json_decode($entry->parameters_json, true), 'plan'=>$plan, 'before'=>json_decode($entry->state_before_json, true), 'after'=>json_decode($entry->state_after_json, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    <?php if ($entry->status === 'executed' && ($plan['version'] ?? 0) === 2 && PermissionService::hasPermission($adminId, PermissionService::PERM_OPS_ROLLBACK)): ?>
                        <button type="button" class="btn btn-danger btn-sm" onclick="triggerRollback(<?php echo (int) $entry->id; ?>, this)">Rollback</button>
                    <?php endif; ?>
                    <?php else: ?><p>Your access rules have changed. An administrator with audit or settings access can review the complete evidence.</p><?php endif; ?>
                </details>
            <?php endforeach; ?>
            <p>Journal page <?php echo $journalPage; ?>
                <?php if ($journalPage > 1): ?><a href="<?php echo $url; ?>&journal_page=<?php echo $journalPage-1; ?>&log_page=<?php echo $page; ?>">Previous</a><?php endif; ?>
                <?php if ($journalPage*25 < $journalCount): ?><a href="<?php echo $url; ?>&journal_page=<?php echo $journalPage+1; ?>&log_page=<?php echo $page; ?>">Next</a><?php endif; ?>
            </p>
            <h3>Append-only activity log</h3>
            <p><?php echo $eventCount; ?> events · Queries, proposed plans, approvals, rejected/failed attempts, rollback and policy changes. Stored events cannot be edited or removed through Copilot.</p>
            <?php foreach ($events as $event): $details = json_decode((string) $event->details_json, true); $visible = $all || hash_equals($fingerprint, (string) ($details['access_fingerprint'] ?? '')); ?>
                <details style="margin:10px 0">
                    <summary>#<?php echo (int) $event->id; ?> · <?php echo self::e($event->created_at); ?> · Admin #<?php echo (int) $event->admin_id; ?> · <?php echo self::e($event->event); ?> · <?php echo $visible ? self::e($event->message) : 'Evidence withheld after access change'; ?></summary>
                    <p>Proposal: <?php echo self::e($event->proposal_uuid ?? '—'); ?> · Journal: <?php echo (int) ($event->journal_id ?? 0); ?></p>
                    <?php if ($visible): ?><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo self::e(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre><?php endif; ?>
                </details>
            <?php endforeach; ?>
            <p>Log page <?php echo $page; ?>
                <?php if ($page > 1): ?><a href="<?php echo $url; ?>&log_page=<?php echo $page-1; ?>&journal_page=<?php echo $journalPage; ?>">Previous</a><?php endif; ?>
                <?php if ($page*50 < $eventCount): ?><a href="<?php echo $url; ?>&log_page=<?php echo $page+1; ?>&journal_page=<?php echo $journalPage; ?>">Next</a><?php endif; ?>
            </p>
        </section>
        <?php return ob_get_clean();
    }
}
