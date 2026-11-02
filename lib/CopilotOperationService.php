<?php
namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/** Saved, single-use action plans; database mutations and their journal commit together. */
final class CopilotOperationService
{
    private const PROPOSALS = 'tblsahdev_ops_proposals';
    private const EVENTS = 'tblsahdev_ops_events';
    private const JOURNAL = 'tblsahdev_ops_journal';

    public static function event(int $adminId, string $event, string $message, ?string $proposal = null, ?int $journalId = null, array $details = []): void
    {
        $details['actor_role_id'] = (int) Capsule::table('tbladmins')->where('id', $adminId)->value('roleid');
        $details['access_fingerprint'] = CopilotDatabasePolicy::fingerprint($adminId);
        Capsule::table(self::EVENTS)->insert([
            'admin_id'=>$adminId, 'event'=>$event, 'message'=>mb_substr($message, 0, 2000),
            'proposal_uuid'=>$proposal, 'journal_id'=>$journalId,
            'details_json'=>self::json($details), 'created_at'=>Carbon::now()->toDateTimeString(),
        ]);
    }

    public static function assertTransactional(array $tables): void
    {
        $connection = Capsule::connection();
        if ($connection->getDriverName() === 'sqlite') return; // Offline tests use actual SQLite transactions.
        if ($connection->getDriverName() !== 'mysql') throw new \RuntimeException('Transactional Copilot writes require MySQL/InnoDB.');
        $tables = array_values(array_unique(array_merge($tables, [self::PROPOSALS, self::EVENTS, self::JOURNAL, 'tblsahdev_settings'])));
        $rows = $connection->select('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')', $tables);
        $engines = [];
        foreach ($rows as $row) $engines[$row->table_name] = strtolower((string) $row->engine);
        foreach ($tables as $table) if (($engines[$table] ?? '') !== 'innodb') throw new \RuntimeException('Atomic writes are unavailable: ' . $table . ' must use InnoDB. Ask the database administrator to review its storage engine.');
    }

    private static function json($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) throw new \RuntimeException('Unable to encode operation evidence.');
        return $encoded;
    }

    private static function planTables(array $plan): array
    {
        $tables = array_column($plan['changes'], 'table');
        foreach ($plan['references'] as $ref) $tables[] = CopilotDatabasePolicy::resources()[$ref['resource']]['table'];
        return array_values(array_unique($tables));
    }

    private static function session(int $adminId, int $sessionId): void
    {
        if (!Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->where('admin_id', $adminId)->where('session_type', 'admin_copilot')->exists()) throw new \RuntimeException('The operation must belong to your Copilot session.');
        if (!PermissionService::hasPermission($adminId, PermissionService::PERM_OPS_EXECUTE)) throw new \RuntimeException('Operations execution permission is required.');
    }

    private static function row(string $resource, int $id, int $adminId, string $operation, bool $lock = false): array
    {
        $q = CopilotDatabasePolicy::query($adminId, $resource, $operation)->where('id', $id);
        if ($lock) $q->lockForUpdate();
        $row = $q->first();
        if (!$row) throw new \RuntimeException('Record not found in the permitted ' . $resource . ' scope.');
        return (array) $row;
    }

    private static function validateValues(string $resource, array $values, array $before): void
    {
        if (!$values) throw new \InvalidArgumentException('At least one field is required.');
        if (isset($values['status'])) {
            if ($resource === 'clients' && !in_array($values['status'], ['Active','Inactive','Closed'], true)) throw new \InvalidArgumentException('Unsupported client status.');
            if ($resource === 'tickets' && !Capsule::table('tblticketstatuses')->where('title', $values['status'])->exists()) throw new \InvalidArgumentException('Unknown WHMCS ticket status.');
            if ($resource === 'invoices' && (($before['status'] ?? '') !== 'Unpaid' || $values['status'] !== 'Cancelled')) throw new \InvalidArgumentException('Direct invoice status changes are limited to cancelling an unpaid invoice. Record payments in WHMCS.');
        }
        if (isset($values['urgency']) && !in_array($values['urgency'], ['Low','Medium','High'], true)) throw new \InvalidArgumentException('Priority must be Low, Medium, or High.');
        if (isset($values['flag']) && (!preg_match('/^(0|[1-9]\d*)$/D', (string) $values['flag']) || ((int) $values['flag'] > 0 && !Capsule::table('tbladmins')->where('id', (int) $values['flag'])->where('disabled', 0)->exists()))) throw new \InvalidArgumentException('Assigned administrator is unavailable.');
        foreach (['message','note'] as $field) if (isset($values[$field]) && trim((string) $values[$field]) === '') throw new \InvalidArgumentException('A note cannot be empty.');
        if (isset($values['sticky']) && !in_array((string) $values['sticky'], ['0','1'], true)) throw new \InvalidArgumentException('Sticky must be 0 or 1.');
    }

    /** Produce a plan from a server-owned resource definition, never arbitrary SQL/table names. */
    private static function plan(string $resource, string $operation, int $id, array $values, int $parentId, int $adminId): array
    {
        $definitions = CopilotDatabasePolicy::resources();
        $requestedValues = $values;
        CopilotDatabasePolicy::rule($adminId, $resource, $operation);
        if (!in_array($operation, ['create','update','delete'], true)) throw new \InvalidArgumentException('Choose create, update, or delete.');
        if ($operation !== 'delete') CopilotDatabasePolicy::assertFields($adminId, $resource, $operation, $values);
        $before = $operation === 'create' ? null : self::row($resource, $id, $adminId, $operation);
        self::validateValues($resource, $operation === 'delete' ? ['delete'=>true] : $values, $before ?? []);
        if ($operation === 'update' && self::matches($before, $values)) throw new \RuntimeException('The requested values already match this record; no change is needed.');
        $references = [];
        if ($before && in_array($resource, ['ticket_notes','client_notes'], true)) {
            $parentResource = $resource === 'ticket_notes' ? 'tickets' : 'clients';
            $parentRecordId = (int) $before[$definitions[$resource]['owner']];
            self::row($parentResource, $parentRecordId, $adminId, 'select');
            $references[] = ['resource'=>$parentResource, 'id'=>$parentRecordId];
        }
        if ($operation === 'create') {
            if (array_diff($definitions[$resource]['read'], CopilotDatabasePolicy::readFields($adminId, $resource))) throw new \RuntimeException('All fields of the new record must be readable for a complete creation and rollback preview.');
            if ($resource === 'ticket_notes' && empty($values['message'])) throw new \InvalidArgumentException('A note message is required.');
            if ($resource === 'client_notes' && empty($values['note'])) throw new \InvalidArgumentException('A note is required.');
            if ($parentId <= 0) throw new \InvalidArgumentException('A parent client or ticket ID is required.');
            $parentResource = $resource === 'ticket_notes' ? 'tickets' : 'clients';
            $parent = self::row($parentResource, $parentId, $adminId, 'select');
            $clientId = $resource === 'ticket_notes' ? (int) $parent['userid'] : $parentId;
            $scope = CopilotDatabasePolicy::rule($adminId, $resource, 'create')['client_ids'];
            if ($scope && !in_array($clientId, $scope, true)) throw new \RuntimeException('Parent is outside the permitted client scope.');
            $references[] = ['resource'=>$parentResource, 'id'=>$parentId];
            $now = Carbon::now()->toDateTimeString();
            if ($resource === 'ticket_notes') {
                $values = array_merge($values, ['ticketid'=>$parentId, 'admin'=>(string) Capsule::table('tbladmins')->where('id', $adminId)->value('username'), 'date'=>$now]);
            } elseif ($resource === 'client_notes') {
                $values = array_merge(['sticky'=>0], $values, ['userid'=>$parentId, 'adminid'=>$adminId, 'created'=>$now, 'modified'=>$now]);
            } elseif ($resource === 'credit_ledger') {
                if (array_diff(['credit','currency'], CopilotDatabasePolicy::readFields($adminId, 'clients'))) throw new \RuntimeException('Client credit and currency must be readable to preview a credit adjustment.');
                $amount = (string) ($values['amount'] ?? '');
                if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $amount) || (float) $amount <= 0 || empty($values['description'])) throw new \InvalidArgumentException('Credit requires a positive amount (up to two decimals) and a description.');
                $values = ['clientid'=>$parentId, 'date'=>Carbon::today()->toDateString(), 'description'=>$values['description'], 'amount'=>number_format((float) $amount, 2, '.', '')];
                $creditStep = ['kind'=>'update', 'table'=>'tblclients', 'id'=>$parentId, 'before'=>['credit'=>$parent['credit'], 'currency'=>$parent['currency']], 'after'=>['credit'=>number_format((float) $parent['credit'] + (float) $amount, 2, '.', ''), 'currency'=>$parent['currency']], 'label'=>'Client credit balance'];
            } else throw new \RuntimeException('Creating core WHMCS records requires its native workflows.');
        }
        if ($operation === 'delete') {
            if (!in_array($resource, ['ticket_notes','client_notes'], true)) throw new \RuntimeException('Deleting this resource is not supported.');
            if (array_diff(array_keys($before), CopilotDatabasePolicy::readFields($adminId, $resource))) throw new \RuntimeException('All note fields must be readable for a complete deletion preview.');
        }
        $guardFields = array_intersect(['id', $definitions[$resource]['owner'], 'status', 'domainstatus'], CopilotDatabasePolicy::readFields($adminId, $resource));
        $reviewedBefore = $operation === 'update' ? array_intersect_key($before, array_merge($values, array_flip($guardFields))) : $before;
        $step = ['kind'=>$operation, 'table'=>$definitions[$resource]['table'], 'id'=>$operation === 'create' ? null : $id,
            'before'=>$reviewedBefore,
            'after'=>$operation === 'delete' ? null : array_merge($operation === 'update' ? $reviewedBefore : [], $values), 'label'=>$definitions[$resource]['label'] . ($id ? ' #' . $id : ' (new record)')];
        $targetRow = $before ?: array_merge($values, ['id'=>$parentId]);
        return ['version'=>2, 'mode'=>'database', 'resource'=>$resource, 'operation'=>$operation, 'record_id'=>$id,
            'parent_id'=>$parentId, 'requested_values'=>$requestedValues,
            'target_url'=>$resource === 'credit_ledger' ? CopilotRecords::url('clients', ['id'=>$parentId]) : CopilotRecords::url($resource, $targetRow),
            'currency_id'=>$resource === 'credit_ledger' ? (int) $parent['currency'] : null,
            'references'=>$references, 'changes'=>isset($creditStep) ? [$creditStep, $step] : [$step]];
    }

    public static function propose(string $action, array $params, array $catalogEntry, int $adminId, int $sessionId): array
    {
        try {
            SchemaManager::ensureOpsJournalTable();
            SchemaManager::ensureCopilotOperationTables();
            self::session($adminId, $sessionId);
            $accessFingerprint = CopilotDatabasePolicy::fingerprint($adminId);
            $map = [
                'ticket_change_status'=>['tickets','update','ticket_id','status','status'],
                'ticket_change_priority'=>['tickets','update','ticket_id','urgency','priority'],
                'ticket_assign_admin'=>['tickets','update','ticket_id','flag','admin_id'],
                'ticket_add_note'=>['ticket_notes','create','ticket_id','message','note'],
                'client_toggle_status'=>['clients','update','client_id','status','status'],
                'invoice_cancel'=>['invoices','update','invoice_id','status',null],
                'client_update_notes'=>['clients','update','client_id','notes','notes'],
                'invoice_add_credit'=>['credit_ledger','create','client_id','amount','amount'],
            ];
            if ($action === 'database_plan') {
                $resource = (string) ($params['resource'] ?? '');
                $operation = (string) ($params['operation'] ?? '');
                $id = (int) ($params['record_id'] ?? 0);
                $parentId = (int) ($params['parent_id'] ?? 0);
                $values = json_decode((string) ($params['values_json'] ?? '{}'), true);
                if (!is_array($values)) throw new \InvalidArgumentException('values_json must be an object.');
            } elseif (isset($map[$action])) {
                [$resource, $operation, $idKey, $field, $input] = $map[$action];
                $id = (int) ($params[$idKey] ?? 0); $parentId = $operation === 'create' ? $id : 0;
                $values = [$field=>$input === null ? 'Cancelled' : ($params[$input] ?? '')];
                if ($action === 'invoice_add_credit') $values['description'] = (string) ($params['description'] ?? '');
                if ($action === 'client_update_notes' && ($params['mode'] ?? 'append') === 'append') {
                    $current = self::row('clients', $id, $adminId, 'update');
                    $values['notes'] = rtrim((string) $current['notes']) . "\n" . trim((string) $values['notes']);
                }
                if ($operation === 'create') $id = 0;
            } else {
                throw new \RuntimeException('This action uses payment/provisioning workflows with external side effects. Open the record in WHMCS to perform it; Copilot cannot promise an atomic rollback for it.');
            }
            $plan = self::plan($resource, $operation, $id, $values, $parentId, $adminId);
            $plan['access_fingerprint'] = $accessFingerprint;
            self::assertTransactional(self::planTables($plan));
            $uuid = 'prop_' . bin2hex(random_bytes(16));
            $expires = Carbon::now()->addMinutes(30)->toDateTimeString();
            $card = ['success'=>true, 'is_action_proposal'=>true, 'proposal_id'=>$uuid, 'action_key'=>$action, 'params'=>$params,
                'session_id'=>$sessionId, 'title'=>$catalogEntry['title'], 'description'=>$catalogEntry['description'] . ' Direct database edit; WHMCS lifecycle hooks and notifications are not run.',
                'target'=>CopilotDatabasePolicy::resources()[$resource]['label'] . ' #' . ($id ?: $parentId),
                'risk_tier'=>$operation === 'delete' ? 3 : 2, 'requires_password'=>$operation === 'delete',
                'status'=>'pending', 'expires_at'=>$expires, 'created_at'=>Carbon::now()->toDateTimeString(), 'admin_id'=>$adminId, 'rollback_mode'=>'database', 'can_execute'=>true, 'plan'=>$plan,
                'access_fingerprint'=>$accessFingerprint,
                'rollback_details'=>'All listed row changes and the journal commit in one transaction. Rollback checks the saved post-state and refuses to overwrite later changes. Created records are removed by their exact IDs; deleted notes are restored with their original IDs.'];
            Capsule::connection()->transaction(function () use ($uuid, $action, $card, $adminId, $sessionId, $expires, $accessFingerprint) {
                Capsule::table('tblsahdev_settings')->lockForUpdate()->first();
                if (!hash_equals($accessFingerprint, CopilotDatabasePolicy::fingerprint($adminId))) throw new \RuntimeException('Access rules changed while preparing the plan. Request a new preview.');
                Capsule::table(self::PROPOSALS)->insert(['proposal_uuid'=>$uuid, 'admin_id'=>$adminId, 'session_id'=>$sessionId, 'action_key'=>$action, 'card_json'=>self::json($card), 'status'=>'pending', 'journal_id'=>null, 'expires_at'=>$expires, 'created_at'=>Carbon::now(), 'updated_at'=>Carbon::now()]);
                self::event($adminId, 'proposed', 'Action plan prepared; no target records changed.', $uuid, null, $card['plan']);
            });
            return $card;
        } catch (\Throwable $error) {
            self::failure($adminId, 'proposal_rejected', $error->getMessage());
            return ['success'=>false, 'error'=>$error->getMessage()];
        }
    }

    private static function authorizePlan(array $plan, int $adminId, bool $rollback = false): void
    {
        $resource = $plan['resource']; $operation = $plan['operation'];
        CopilotDatabasePolicy::rule($adminId, $resource, $rollback ? 'rollback' : $operation);
        if ($operation !== 'delete') CopilotDatabasePolicy::assertFields($adminId, $resource, $rollback ? 'rollback' : $operation, $plan['requested_values']);
        $readable = CopilotDatabasePolicy::readFields($adminId, $resource);
        $step = end($plan['changes']);
        if ($rollback && $operation !== 'create') {
            $restoredValues = array_intersect_key($step['before'], array_flip($operation === 'update' ? array_keys($plan['requested_values']) : CopilotDatabasePolicy::resources()[$resource]['write']));
            CopilotDatabasePolicy::assertFields($adminId, $resource, 'rollback', $restoredValues);
        }
        if (array_diff(array_unique(array_merge(array_keys($step['before'] ?? []), array_keys($step['after'] ?? []))), $readable)) throw new \RuntimeException('Some reviewed fields are no longer readable under the current policy.');
        if ($resource === 'credit_ledger' && array_diff(['credit','currency'], CopilotDatabasePolicy::readFields($adminId, 'clients'))) throw new \RuntimeException('Client credit or currency is no longer readable under the current policy.');
        foreach ($plan['references'] as $ref) self::row($ref['resource'], (int) $ref['id'], $adminId, 'select', true);
        if ($plan['record_id']) {
            // Deleted rows do not exist during restore; their original ownership still must fit current policy.
            if (!$rollback || $operation !== 'delete') self::row($resource, (int) $plan['record_id'], $adminId, $rollback ? 'rollback' : $operation, true);
        }
        $rule = CopilotDatabasePolicy::rule($adminId, $resource, $rollback ? 'rollback' : $operation);
        if ($rule['client_ids']) {
            $step = end($plan['changes']); $row = $step['before'] ?? $step['after'];
            $owner = CopilotDatabasePolicy::resources()[$resource]['owner'];
            $parentId = $plan['parent_id'] ?: ($row[$owner] ?? 0);
            if ($resource === 'ticket_notes') $parentId = (int) Capsule::table('tbltickets')->where('id', $parentId)->value('userid');
            if ($operation === 'create' || ($rollback && $operation === 'delete')) {
                if (!in_array((int) $parentId, $rule['client_ids'], true)) throw new \RuntimeException('The plan is outside the current client scope.');
            }
        }
    }

    private static function matches(?array $row, ?array $expected): bool
    {
        if ($expected === null) return $row === null;
        if ($row === null) return false;
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $row) || ($value === null ? $row[$key] !== null : (string) $value !== (string) $row[$key])) return false;
        }
        return true;
    }

    private static function apply(array $changes, bool $reverse): array
    {
        // Lock and check every affected record before making any change.
        foreach ($changes as $step) {
            if ($step['id'] === null) continue;
            $row = Capsule::table($step['table'])->where('id', $step['id'])->lockForUpdate()->first();
            $expected = $reverse ? $step['after'] : $step['before'];
            if (!self::matches($row ? (array) $row : null, $expected)) throw new \RuntimeException('Record changed since the saved ' . ($reverse ? 'execution' : 'preview') . ': ' . $step['label'] . '. Review a new action plan.');
        }
        $indices = array_keys($changes);
        if ($reverse) $indices = array_reverse($indices);
        foreach ($indices as $index) {
            $step = $changes[$index];
            $desired = $reverse ? $step['before'] : $step['after'];
            $expected = $reverse ? $step['after'] : $step['before'];
            if ($desired === null) {
                Capsule::table($step['table'])->where('id', $step['id'])->delete();
            } elseif ($expected === null) {
                if ($reverse) Capsule::table($step['table'])->insert($desired);
                else {
                    $step['id'] = Capsule::table($step['table'])->insertGetId($desired);
                    $step['after'] = (array) Capsule::table($step['table'])->where('id', $step['id'])->first();
                    $changes[$index] = $step;
                }
            } else {
                Capsule::table($step['table'])->where('id', $step['id'])->update($desired);
            }
            $actual = Capsule::table($step['table'])->where('id', $step['id'])->first();
            if (!self::matches($actual ? (array) $actual : null, $desired)) throw new \RuntimeException('Database verification failed for ' . $step['label']);
        }
        return $changes;
    }

    public static function execute(string $action, array $params, int $adminId, int $sessionId, ?string $password, string $uuid): array
    {
        try {
            SchemaManager::ensureCopilotOperationTables();
            self::session($adminId, $sessionId);
            if ($uuid === '') throw new \RuntimeException('A saved proposal is required. Ask Copilot for a new action plan.');
            $preview = Capsule::table(self::PROPOSALS)->where('proposal_uuid', $uuid)->first();
            $card = json_decode((string) ($preview->card_json ?? ''), true);
            if (!$card) throw new \RuntimeException('Proposal not found. Older proposals must be regenerated.');
            self::assertTransactional(self::planTables($card['plan']));
            return Capsule::connection()->transaction(function () use ($action, $params, $adminId, $sessionId, $password, $uuid) {
                Capsule::table('tblsahdev_settings')->lockForUpdate()->first();
                PermissionService::clearAccessCache();
                self::session($adminId, $sessionId);
                $proposal = Capsule::table(self::PROPOSALS)->where('proposal_uuid', $uuid)->lockForUpdate()->first();
                if (!$proposal || (int) $proposal->admin_id !== $adminId || (int) $proposal->session_id !== $sessionId) throw new \RuntimeException('Proposal ownership does not match this admin/session.');
                $card = json_decode($proposal->card_json, true);
                if ($action !== $card['action_key'] || self::json($params) !== self::json($card['params'])) throw new \RuntimeException('The submitted operation differs from the saved preview.');
                if (!hash_equals($card['access_fingerprint'] ?? '', CopilotDatabasePolicy::fingerprint($adminId))) throw new \RuntimeException('Access rules changed since this preview. Generate a new action plan.');
                CopilotDatabasePolicy::rule($adminId, $card['plan']['resource'], $card['plan']['operation']);
                if ($proposal->status === 'executed') return ['success'=>true, 'journal_id'=>(int) $proposal->journal_id, 'can_rollback'=>true, 'message'=>'This proposal was already executed; no change was repeated.'];
                if ($proposal->status !== 'pending' || $proposal->expires_at < Carbon::now()->toDateTimeString()) throw new \RuntimeException('Proposal expired or is no longer pending. Generate a new plan.');
                self::authorizePlan($card['plan'], $adminId);
                if ($card['requires_password']) {
                    $failures = Capsule::table(self::EVENTS)->where('admin_id', $adminId)->where('event', 'password_rejected')->where('created_at', '>=', Carbon::now()->subMinutes(15)->toDateTimeString())->count();
                    if ($failures >= 5) throw new \RuntimeException('Too many incorrect deletion passwords. Try again in 15 minutes.');
                    if (!self::verifyPassword($adminId, $password ?? '')) throw new \RuntimeException('A valid administrator password is required for deletion.', 4013);
                }
                $plan = $card['plan'];
                $plan['changes'] = self::apply($plan['changes'], false);
                // Capture defaults, but never commit an unsupported schema whose full rollback evidence cannot be reviewed.
                $changedRow = end($plan['changes']);
                if (array_diff(array_keys($changedRow['after'] ?? []), CopilotDatabasePolicy::readFields($adminId, $plan['resource']))) throw new \RuntimeException('The record schema has fields outside the approved rollback preview. No changes were committed.');
                $journalId = Capsule::table(self::JOURNAL)->insertGetId([
                    'session_id'=>$sessionId, 'admin_id'=>$adminId, 'action_key'=>$action, 'target_entity_type'=>$plan['resource'],
                    'target_entity_id'=>$plan['record_id'] ?: $plan['parent_id'], 'description'=>$card['title'] . ': ' . $card['target'],
                    'parameters_json'=>self::json($card['params']), 'state_before_json'=>self::json(array_column($plan['changes'], 'before')),
                    'state_after_json'=>self::json(array_column($plan['changes'], 'after')), 'compensation_recipe_json'=>self::json($plan),
                    'risk_tier'=>$card['risk_tier'], 'status'=>'executed', 'executed_at'=>Carbon::now(), 'created_at'=>Carbon::now(), 'updated_at'=>Carbon::now(),
                ]);
                Capsule::table(self::PROPOSALS)->where('id', $proposal->id)->update(['status'=>'executed', 'journal_id'=>$journalId, 'updated_at'=>Carbon::now()]);
                self::event($adminId, 'executed', 'All row changes and journal committed atomically.', $uuid, $journalId, $plan);
                return ['success'=>true, 'journal_id'=>$journalId, 'can_rollback'=>true, 'state_before'=>array_column($plan['changes'], 'before'), 'state_after'=>array_column($plan['changes'], 'after'), 'message'=>'Database changes committed with a complete rollback journal.'];
            });
        } catch (\Throwable $error) {
            self::failure($adminId, $error->getCode() === 4013 ? 'password_rejected' : 'execution_rejected', $error->getMessage(), $uuid);
            return ['success'=>false, 'error'=>$error->getMessage()];
        }
    }

    public static function rollback(int $journalId, int $adminId): array
    {
        try {
            if (!PermissionService::hasPermission($adminId, PermissionService::PERM_OPS_ROLLBACK)) throw new \RuntimeException('Rollback permission is required.');
            $entry = Capsule::table(self::JOURNAL)->where('id', $journalId)->first();
            $plan = json_decode((string) ($entry->compensation_recipe_json ?? ''), true);
            if (($plan['version'] ?? 0) !== 2 || ($plan['mode'] ?? '') !== 'database') throw new \RuntimeException('This legacy/external operation has no verified atomic rollback plan. Review it manually in WHMCS.');
            self::assertTransactional(self::planTables($plan));
            return Capsule::connection()->transaction(function () use ($journalId, $adminId) {
                Capsule::table('tblsahdev_settings')->lockForUpdate()->first();
                PermissionService::clearAccessCache();
                if (!PermissionService::hasPermission($adminId, PermissionService::PERM_OPS_ROLLBACK)) throw new \RuntimeException('Rollback permission is required.');
                $entry = Capsule::table(self::JOURNAL)->where('id', $journalId)->lockForUpdate()->first();
                if ($entry->status === 'rolled_back') return ['success'=>true, 'journal_id'=>$journalId, 'message'=>'Already rolled back; no change was repeated.'];
                if ($entry->status !== 'executed') throw new \RuntimeException('Only executed operations can be rolled back.');
                $plan = json_decode($entry->compensation_recipe_json, true);
                self::authorizePlan($plan, $adminId, true);
                self::apply($plan['changes'], true);
                Capsule::table(self::JOURNAL)->where('id', $journalId)->update(['status'=>'rolled_back', 'rolled_back_at'=>Carbon::now(), 'rollback_admin_id'=>$adminId, 'rollback_error'=>null, 'updated_at'=>Carbon::now()]);
                Capsule::table(self::PROPOSALS)->where('journal_id', $journalId)->update(['status'=>'rolled_back', 'updated_at'=>Carbon::now()]);
                self::event($adminId, 'rolled_back', 'All saved row changes restored atomically.', null, $journalId, $plan);
                return ['success'=>true, 'journal_id'=>$journalId, 'message'=>'All saved database changes were rolled back.'];
            });
        } catch (\Throwable $error) {
            self::failure($adminId, 'rollback_rejected', $error->getMessage(), null, $journalId);
            return ['success'=>false, 'error'=>$error->getMessage()];
        }
    }

    public static function refresh(array $card): array
    {
        $row = Capsule::table(self::PROPOSALS)->where('proposal_uuid', (string) ($card['proposal_id'] ?? ''))->first();
        if (!$row) return array_merge($card, ['status'=>'legacy', 'can_execute'=>false]);
        $saved = json_decode($row->card_json, true);
        $status = $row->status === 'pending' && $row->expires_at < Carbon::now()->toDateTimeString() ? 'expired' : $row->status;
        return array_merge($saved, ['status'=>$status, 'can_execute'=>$status === 'pending', 'journal_id'=>$row->journal_id]);
    }

    private static function verifyPassword(int $adminId, string $password): bool
    {
        $hash = (string) Capsule::table('tbladmins')->where('id', $adminId)->value('password');
        if ($password === '' || $hash === '') return false;
        return password_verify($password, $hash);
    }

    private static function failure(int $adminId, string $event, string $message, ?string $uuid = null, ?int $journalId = null): void
    {
        try { self::event($adminId, $event, $message, $uuid, $journalId); }
        catch (\Throwable $error) { ModuleLogger::error('copilot_ops', $event . ': ' . $message . ' (audit storage unavailable)'); }
    }
}
