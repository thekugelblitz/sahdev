<?php
namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/** Server-enforced allowlist. Prompts and model-supplied SQL cannot extend this policy. */
final class CopilotDatabasePolicy
{
    public static function resources(): array
    {
        return [
            'clients' => ['table'=>'tblclients', 'label'=>'Clients', 'owner'=>'id', 'read'=>['id','firstname','lastname','companyname','email','status','credit','currency','notes'], 'write'=>['firstname','lastname','companyname','notes','status'], 'ops'=>['select','update','rollback']],
            'invoices' => ['table'=>'tblinvoices', 'label'=>'Invoices', 'owner'=>'userid', 'read'=>['id','userid','invoicenum','date','duedate','datepaid','subtotal','tax','tax2','credit','total','status','paymentmethod','notes'], 'write'=>['notes','status'], 'ops'=>['select','update','rollback']],
            'services' => ['table'=>'tblhosting', 'label'=>'Services', 'owner'=>'userid', 'read'=>['id','userid','domain','packageid','server','domainstatus','suspendreason','amount','billingcycle','nextduedate','notes'], 'write'=>['notes'], 'ops'=>['select','update','rollback']],
            'products' => ['table'=>'tblproducts', 'label'=>'Products', 'owner'=>null, 'read'=>['id','name','type','description','hidden'], 'write'=>[], 'ops'=>['select']],
            'tickets' => ['table'=>'tbltickets', 'label'=>'Tickets', 'owner'=>'userid', 'read'=>['id','tid','userid','title','did','status','urgency','flag','lastreply'], 'write'=>['status','urgency','flag'], 'ops'=>['select','update','rollback']],
            'ticket_notes' => ['table'=>'tblticketnotes', 'label'=>'Ticket notes', 'owner'=>'ticketid', 'read'=>['id','ticketid','admin','date','message'], 'write'=>['message'], 'ops'=>['select','create','update','delete','rollback']],
            'client_notes' => ['table'=>'tblnotes', 'label'=>'Client notes', 'owner'=>'userid', 'read'=>['id','userid','adminid','created','modified','note','sticky'], 'write'=>['note','sticky'], 'ops'=>['select','create','update','delete','rollback']],
            'credit_ledger' => ['table'=>'tblcredit', 'label'=>'Client credit adjustments', 'owner'=>'clientid', 'read'=>['id','clientid','date','description','amount'], 'write'=>['description','amount'], 'ops'=>['select','create','rollback']],
            'gateway_logs' => ['table'=>'tblgatewaylog', 'label'=>'Gateway log summaries', 'owner'=>null, 'read'=>['id','date','gateway','result'], 'write'=>[], 'ops'=>['select']],
        ];
    }

    public static function load(): array
    {
        $row = Capsule::table('tblsahdev_settings')->first();
        $policy = json_decode((string) ($row->copilot_database_policy_json ?? ''), true);
        return is_array($policy) ? $policy : ['enabled'=>false, 'roles'=>[]];
    }

    public static function rule(int $adminId, string $resource, string $operation): array
    {
        if ($adminId <= 0 || !PermissionService::hasPermission($adminId, PermissionService::PERM_COPILOT_USE)) {
            throw new \RuntimeException('Copilot access is not permitted.');
        }
        $catalog = self::resources();
        if (!isset($catalog[$resource]) || !in_array($operation, $catalog[$resource]['ops'], true)) {
            throw new \RuntimeException('This database resource or operation is not supported.');
        }
        // No super-admin bypass: even role 1 needs an explicit database grant.
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
        $policy = self::load();
        $rule = $policy['roles'][(int) ($admin->roleid ?? 0)][$resource] ?? [];
        if (!$admin || !empty($admin->disabled) || empty($policy['enabled']) || empty($rule['operations'][$operation])) {
            throw new \RuntimeException('Database access denied for ' . $resource . ' / ' . $operation . '. Configure Copilot Database Access for this admin role.');
        }
        return self::normalizeRule($resource, $rule);
    }

    public static function query(int $adminId, string $resource, string $operation = 'select')
    {
        $rule = self::rule($adminId, $resource, $operation);
        $definition = self::resources()[$resource];
        $query = Capsule::table($definition['table']);
        $ids = $rule['client_ids'];
        if ($ids) {
            if (!$definition['owner']) throw new \RuntimeException('Client scope is not supported for this resource.');
            if ($resource === 'ticket_notes') {
                $query->whereIn('ticketid', function ($q) use ($ids) { $q->select('id')->from('tbltickets')->whereIn('userid', $ids); });
            } else {
                $query->whereIn($definition['owner'], $ids);
            }
        }
        return $query;
    }

    public static function readFields(int $adminId, string $resource): array
    {
        $rule = self::rule($adminId, $resource, 'select');
        return array_values(array_unique(array_merge(['id'], array_intersect(self::resources()[$resource]['read'], $rule['read_fields']))));
    }

    public static function assertFields(int $adminId, string $resource, string $operation, array $values): void
    {
        $rule = self::rule($adminId, $resource, $operation);
        $allowed = array_intersect(self::resources()[$resource]['write'], $rule['write_fields'], self::readFields($adminId, $resource));
        foreach ($values as $field => $value) {
            if (!in_array($field, $allowed, true) || !is_scalar($value)) throw new \RuntimeException('Field is not writable: ' . $field);
            if (strlen((string) $value) > 20000) throw new \RuntimeException('Field value exceeds 20,000 bytes.');
            if (isset($rule['allowed_values'][$field]) && !in_array((string) $value, array_map('strval', $rule['allowed_values'][$field]), true)) {
                throw new \RuntimeException('Value is outside the configured rule for ' . $field . '.');
            }
        }
    }

    public static function normalizeRule(string $resource, array $input): array
    {
        $definition = self::resources()[$resource];
        $ops = [];
        foreach ($definition['ops'] as $op) $ops[$op] = !empty($input['operations'][$op]);
        $allowedValues = $input['allowed_values'] ?? [];
        if (is_string($allowedValues)) {
            $allowedValues = trim($allowedValues) === '' ? [] : json_decode($allowedValues, true);
        }
        if (!is_array($allowedValues)) throw new \InvalidArgumentException('Allowed values must be a JSON object of field-to-value lists.');
        foreach ($allowedValues as $field => $values) {
            if (!in_array($field, $definition['write'], true) || !is_array($values) || !$values) throw new \InvalidArgumentException('Invalid allowed-values rule for ' . $resource . '.');
            foreach ($values as $value) if (!is_scalar($value)) throw new \InvalidArgumentException('Allowed values must be simple values.');
        }
        $ids = $input['client_ids'] ?? [];
        if (is_string($ids)) {
            if (trim($ids) !== '' && !preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/D', $ids)) throw new \InvalidArgumentException('Client scope must be comma-separated positive IDs.');
            $ids = trim($ids) === '' ? [] : explode(',', $ids);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids && (!$definition['owner'] || min($ids) <= 0 || count($ids) > 200)) throw new \InvalidArgumentException('Invalid client scope for ' . $resource . '.');
        $readFields = array_values(array_intersect($definition['read'], (array) ($input['read_fields'] ?? [])));
        $writeFields = array_values(array_intersect($definition['write'], (array) ($input['write_fields'] ?? [])));
        if (array_filter(array_diff_key($ops, ['select'=>true])) && empty($ops['select'])) throw new \InvalidArgumentException('Select access is required to review changes for ' . $resource . '.');
        if (array_diff($writeFields, $readFields)) throw new \InvalidArgumentException('Writable fields must also be readable for ' . $resource . '.');
        if ((!empty($ops['create']) || !empty($ops['delete'])) && array_diff($definition['read'], array_merge(['id'], $readFields))) throw new \InvalidArgumentException('Creation/deletion requires all record fields to be readable for ' . $resource . '.');
        return [
            'operations'=>$ops,
            'read_fields'=>$readFields,
            'write_fields'=>$writeFields,
            'client_ids'=>$ids, 'allowed_values'=>$allowedValues,
            'limit'=>max(1, min(50, (int) ($input['limit'] ?? 20))),
        ];
    }

    public static function select(int $adminId, string $resource, array $filters, int $page = 1): array
    {
        $rule = self::rule($adminId, $resource, 'select');
        $fields = self::readFields($adminId, $resource);
        $query = self::query($adminId, $resource);
        if (count($filters) > 8) throw new \InvalidArgumentException('At most eight equality filters are supported.');
        foreach ($filters as $field => $value) {
            if (!in_array($field, $fields, true) || !is_scalar($value)) throw new \InvalidArgumentException('Filter field is not readable: ' . $field);
            $query->where($field, '=', $value);
        }
        $page = max(1, min(10000, $page));
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')->offset(($page - 1) * $rule['limit'])->limit($rule['limit'])->get($fields)->toArray();
        return ['resource'=>$resource, 'rows'=>$rows, 'page'=>$page, 'page_size'=>$rule['limit'], 'total'=>$total, 'has_more'=>$page * $rule['limit'] < $total];
    }

    public static function capabilities(int $adminId): array
    {
        $out = [];
        foreach (self::resources() as $name => $definition) {
            try { $out[$name] = self::rule($adminId, $name, 'select'); } catch (\Throwable $denied) {}
        }
        return $out;
    }

    /** Bind reviewed evidence to the operator's effective access policy, not just their identity. */
    public static function fingerprint(int $adminId): string
    {
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
        $role = (int) ($admin->roleid ?? 0);
        $policy = self::load();
        return hash('sha256', json_encode([
            'admin_id'=>$adminId, 'role_id'=>$role, 'disabled'=>!$admin || !empty($admin->disabled),
            'enabled'=>!empty($policy['enabled']), 'rules'=>$policy['roles'][$role] ?? [],
            'permissions'=>PermissionService::getRolePermissions($role),
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function saveRole(int $adminId, int $roleId, bool $enabled, array $input, string $revision): void
    {
        if (!PermissionService::hasPermission($adminId, PermissionService::PERM_SETTINGS_MANAGE)) throw new \RuntimeException('Settings permission is required.');
        if (!Capsule::table('tbladminroles')->where('id', $roleId)->exists()) throw new \InvalidArgumentException('Unknown administrator role.');
        $rules = [];
        foreach (self::resources() as $resource => $definition) $rules[$resource] = self::normalizeRule($resource, (array) ($input[$resource] ?? []));
        SchemaManager::ensureCopilotOperationTables();
        SchemaManager::ensureOpsJournalTable();
        CopilotOperationService::assertTransactional([]);
        Capsule::connection()->transaction(function () use ($adminId, $roleId, $enabled, $rules, $revision) {
            $settings = Capsule::table('tblsahdev_settings')->lockForUpdate()->first();
            PermissionService::clearAccessCache();
            if (!PermissionService::hasPermission($adminId, PermissionService::PERM_SETTINGS_MANAGE)) throw new \RuntimeException('Settings permission is required.');
            $raw = (string) ($settings->copilot_database_policy_json ?? '');
            if (!hash_equals(hash('sha256', $raw), $revision)) throw new \RuntimeException('Another administrator changed this policy. Reload before saving.');
            $before = self::load();
            $after = $before;
            $after['enabled'] = $enabled;
            $after['roles'][$roleId] = $rules;
            Capsule::table('tblsahdev_settings')->where('id', $settings->id)->update(['copilot_database_policy_json'=>json_encode($after)]);
            CopilotOperationService::event($adminId, 'policy_changed', 'Database access policy changed for role #' . $roleId, null, null, ['role_id'=>$roleId, 'before'=>$before, 'after'=>$after]);
        });
    }
}
