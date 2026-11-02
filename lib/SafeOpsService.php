<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * Class SafeOpsService
 *
 * Safe WHMCS Operations Engine & Atomic Rollback Journal.
 * Governs tool execution for Admin Ops Copilot:
 * - 3-Tier Risk Hierarchy (Read, Reversible Mutation, Destructive)
 * - Two-Phase Proposal Cards with State Diffs
 * - Saved action plans, transactional row snapshots, and conflict-safe rollback
 * - Atomic row rollback with an append-only operation log
 */
class SafeOpsService
{
    public const TIER_READ_ONLY   = 1;
    public const TIER_REVERSIBLE  = 2;
    public const TIER_DESTRUCTIVE = 3;

    /**
     * Complete registry of supported WHMCS Ops.
     */
    public static function getOperationCatalog(): array
    {
        return array_merge(self::databaseCatalog(), [
            // --- TICKET OPS ---
            'ticket_lookup' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Tickets',
                'title'       => 'Lookup Support Ticket',
                'description' => 'Retrieve permitted ticket fields, status, priority, assigned staff, and related client link.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'ticket_id' => ['type' => 'int', 'required' => true, 'desc' => 'WHMCS Ticket ID or Ticket Mask (#123456)'],
                ],
            ],
            'ticket_change_status' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Tickets',
                'title'       => 'Change Ticket Status',
                'description' => 'Update status (e.g. Answered, In Progress, Customer-Reply, Closed, On Hold).',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'ticket_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Ticket ID'],
                    'status'    => ['type' => 'string', 'required' => true, 'desc' => 'New status string'],
                ],
            ],
            'ticket_change_priority' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Tickets',
                'title'       => 'Change Ticket Priority',
                'description' => 'Change ticket priority level (Low, Medium, High).',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'ticket_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Ticket ID'],
                    'priority'  => ['type' => 'string', 'required' => true, 'desc' => 'Priority: Low, Medium, or High'],
                ],
            ],
            'ticket_assign_admin' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Tickets',
                'title'       => 'Assign Ticket to Admin',
                'description' => 'Assign or reassign ticket to a specific WHMCS administrator.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'ticket_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Ticket ID'],
                    'admin_id'  => ['type' => 'int', 'required' => true, 'desc' => 'WHMCS Admin ID (0 to unassign)'],
                ],
            ],
            'ticket_add_note' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Tickets',
                'title'       => 'Add Internal Staff Note',
                'description' => 'Post a private internal note visible only to support staff.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'ticket_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Ticket ID'],
                    'note'      => ['type' => 'string', 'required' => true, 'desc' => 'Internal note text'],
                ],
            ],

            // --- CLIENT OPS ---
            'client_lookup' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Clients',
                'title'       => 'Client 360° Profile Lookup',
                'description' => 'Find permitted client profiles by name, email, company or ID. Use client_invoices and client_services for related records.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'search' => ['type' => 'string', 'required' => true, 'desc' => 'Client ID, Email, Name, or Company'],
                    'page' => ['type' => 'int', 'required' => false, 'desc' => 'Result page, starting at 1.'],
                ],
            ],
            'client_update_notes' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Clients',
                'title'       => 'Update Client Admin Notes',
                'description' => 'Append or update internal admin notes on client profile.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'client_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Client ID'],
                    'notes'     => ['type' => 'string', 'required' => true, 'desc' => 'Note content to append or set'],
                    'mode'      => ['type' => 'string', 'required' => false, 'desc' => '"append" or "replace" (default append)'],
                ],
            ],
            'client_toggle_status' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Clients',
                'title'       => 'Change Client Account Status',
                'description' => 'Set client status to Active, Inactive, or Closed.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'client_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Client ID'],
                    'status'    => ['type' => 'string', 'required' => true, 'desc' => 'Active, Inactive, or Closed'],
                ],
            ],

            // --- SERVICE / HOSTING OPS ---
            'service_lookup' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Services',
                'title'       => 'Lookup Hosting / Service',
                'description' => 'View permitted service fields, product and client relationships, status, and next due date.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'service_id' => ['type' => 'int', 'required' => true, 'desc' => 'WHMCS Hosting ID (tblhosting.id)'],
                ],
            ],

            // --- INVOICE & BILLING OPS ---
            'invoice_lookup' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Billing',
                'title'       => 'Lookup Invoice & Payment Status',
                'description' => 'View permitted invoice totals, tax, status, payment method, dates, and client relationship.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'invoice_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Invoice ID'],
                ],
            ],
            'invoice_cancel' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Billing',
                'title'       => 'Cancel Unpaid Invoice',
                'description' => 'Mark unpaid invoice as Cancelled with full rollback ability.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'invoice_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Invoice ID'],
                ],
            ],
            'invoice_add_credit' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Billing',
                'title'       => 'Add Credit to Client Account',
                'description' => 'Credit client account balance with audit description and compensation rollback.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'client_id'   => ['type' => 'int', 'required' => true, 'desc' => 'Target Client ID'],
                    'amount'      => ['type' => 'float', 'required' => true, 'desc' => 'Credit amount in the client account currency'],
                    'description' => ['type' => 'string', 'required' => true, 'desc' => 'Reason for credit adjustment'],
                ],
            ],

            // --- SYSTEM & TELEMETRY OPS ---
            'financial_metrics' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Metrics',
                'title'       => 'MRR and Revenue Status',
                'description' => 'Current hosting MRR/ARR, invoices paid today and this month, growth, and unpaid balances in one currency. Defaults to the WHMCS default currency.',
                'permission'  => PermissionService::PERM_METRICS_VIEW,
                'params'      => [
                    'currency_id' => ['type' => 'int', 'required' => false, 'desc' => 'WHMCS currency ID; omit for the default currency. Amounts are never combined across currencies.'],
                ],
            ],
            'open_tickets' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Tickets',
                'title'       => 'List Open Support Tickets',
                'description' => 'List recent tickets awaiting a reply or in progress, with status, priority, and last reply time.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'limit' => ['type' => 'int', 'required' => false, 'desc' => 'Maximum tickets to return (1-50, default 20).'],
                    'page' => ['type' => 'int', 'required' => false, 'desc' => 'Result page, starting at 1.'],
                ],
            ],
            'server_telemetry_query' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'System',
                'title'       => 'Check Server Telemetry & Load',
                'description' => 'Query live server CPU load, RAM usage, disk health, and daemon statuses.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'server_id' => ['type' => 'int', 'required' => false, 'desc' => 'Optional server ID (0 for all)'],
                ],
            ],
            'gateway_diagnostics' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'System',
                'title'       => 'Inspect Payment Gateway Logs',
                'description' => 'Analyze recent gateway webhook failures, card declines, or timeout errors.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'gateway' => ['type' => 'string', 'required' => false, 'desc' => 'Gateway name or filter (e.g. stripe, paypal)'],
                    'limit'   => ['type' => 'int', 'required' => false, 'desc' => 'Number of recent log lines (default 20)'],
                ],
            ],
        ]);
    }

    /**
     * Returns OpenAI-standard tool definitions format for all catalog operations.
     */
    public static function getOpenAIToolsDefinition(): array
    {
        $catalog = self::getOperationCatalog();
        $tools = [];

        foreach ($catalog as $key => $op) {
            $properties = [];
            $required = [];

            foreach ($op['params'] as $pName => $pDef) {
                $pType = $pDef['type'] === 'int' ? 'integer' : ($pDef['type'] === 'float' ? 'number' : 'string');
                $properties[$pName] = [
                    'type'        => $pType,
                    'description' => $pDef['desc'],
                ];
                if (!empty($pDef['required'])) {
                    $required[] = $pName;
                }
            }

            $tools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $key,
                    'description' => $op['title'] . ' — ' . $op['description'],
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => $properties,
                        'required'   => $required,
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }

        return $tools;
    }

    /**
     * Dispatch an operation call:
     * - If Tier 1 (Read-Only), executes immediately and returns data.
     * - If Tier 2 or 3 (Mutating), returns an Action Proposal Card with diff preview.
     */
    public static function handleOperationRequest(string $actionKey, array $params, int $adminId, int $sessionId): array
    {
        $catalog = self::getOperationCatalog();
        if (!isset($catalog[$actionKey])) {
            return [
                'success' => false,
                'error'   => "Unknown WHMCS operation: {$actionKey}",
            ];
        }

        $op = $catalog[$actionKey];

        try {
            if (array_diff(array_keys($params), array_keys($op['params']))) throw new \InvalidArgumentException('Unsupported parameters for this operation.');
            foreach ($op['params'] as $name => $definition) {
                if (!array_key_exists($name, $params)) {
                    if (!empty($definition['required'])) throw new \InvalidArgumentException('Missing required parameter: ' . $name);
                    continue;
                }
                $value = $params[$name];
                if ($definition['type'] === 'int' && (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false)) throw new \InvalidArgumentException('Parameter must be an integer: ' . $name);
                if ($definition['type'] === 'float' && (!is_numeric($value) || !is_finite((float) $value))) throw new \InvalidArgumentException('Parameter must be a finite number: ' . $name);
                if ($definition['type'] === 'string' && (!is_string($value) || strlen($value) > 25000)) throw new \InvalidArgumentException('Parameter must be text up to 25,000 bytes: ' . $name);
            }
        } catch (\Throwable $error) {
            try { CopilotOperationService::event($adminId, 'request_rejected', $error->getMessage(), null, null, ['action'=>$actionKey]); } catch (\Throwable $ignored) {}
            return ['success'=>false, 'error'=>$error->getMessage()];
        }

        // RBAC check
        require_once dirname(__DIR__) . '/lib/PermissionService.php';
        if (!PermissionService::hasPermission($adminId, $op['permission'])) {
            return [
                'success' => false,
                'error'   => "Access Denied: You lack the '{$op['permission']}' permission for {$op['title']}.",
            ];
        }

        // Tier 1: Read-Only (Immediate Execution)
        if ($op['tier'] === self::TIER_READ_ONLY) {
            return self::executeReadOnlyOperation($actionKey, $params, $adminId);
        }

        // Tier 2 or 3: Mutating Operation -> Generate Action Proposal Card
        return CopilotOperationService::propose($actionKey, $params, $op, $adminId, $sessionId);
    }

    /**
     * Execute Read-Only operations.
     */
    private static function executeReadOnlyOperation(string $actionKey, array $params, int $adminId): array
    {
        try {
            SchemaManager::ensureCopilotOperationTables();
            if ($actionKey === 'financial_metrics') {
                foreach (['clients'=>['currency'], 'services'=>['domainstatus','amount','billingcycle'], 'invoices'=>['status','total','datepaid']] as $resource => $required) {
                    $rule = CopilotDatabasePolicy::rule($adminId, $resource, 'select');
                    if ($rule['client_ids'] || array_diff($required, CopilotDatabasePolicy::readFields($adminId, $resource))) throw new \RuntimeException('Financial aggregates require unrestricted read scope and the relevant financial fields. Use scoped record queries for this role.');
                }
                $currencyId = (int) ($params['currency_id'] ?? 0);
                $currencyQuery = Capsule::table('tblcurrencies');
                $currency = $currencyId > 0 ? $currencyQuery->where('id', $currencyId)->first() : $currencyQuery->where('default', 1)->first();
                if (!$currency) throw new \RuntimeException('The requested WHMCS currency was not found.');
                $result = ['success'=>true, 'data'=>[
                    'currency'=>$currency->code, 'currency_id'=>(int) $currency->id, 'as_of'=>Carbon::now()->toDateTimeString(),
                    'scope'=>'Active hosting service MRR; paid invoice totals include tax and are not net cash receipts. Amounts are limited to clients using this currency, without conversion.',
                    'metrics'=>MetricsIntelligenceService::getFinancialMetrics((int) $currency->id),
                ]];
            } elseif ($actionKey === 'server_telemetry_query') {
                if (!PermissionService::hasPermission($adminId, PermissionService::PERM_TELEMETRY_VIEW)) throw new \RuntimeException('Server telemetry permission is required.');
                $serverId = (int) ($params['server_id'] ?? 0);
                $result = ['success'=>true, 'data'=>$serverId > 0 ? ServerTelemetryService::getServerTelemetry($serverId) : ServerTelemetryService::getRecentTelemetrySummary()];
            } else {
                $result = CopilotRecords::read($actionKey, $params, $adminId);
            }
            CopilotOperationService::event($adminId, 'read', 'Read-only query: ' . $actionKey, null, null, ['parameters'=>$params, 'resource'=>$result['data']['resource'] ?? null, 'total'=>$result['data']['total'] ?? null, 'record_ids'=>array_map(function ($row) { return (int) ((array) $row)['id']; }, $result['data']['rows'] ?? [])]);
            return $result;
        } catch (\Throwable $e) {
            try { CopilotOperationService::event($adminId, 'read_rejected', $e->getMessage(), null, null, ['action'=>$actionKey]); } catch (\Throwable $ignored) {}
            return ['success'=>false, 'error'=>$e->getMessage()];
        }
    }

    public static function executeConfirmedOperation(string $actionKey, array $params, int $adminId, int $sessionId, ?string $adminPassword = null, string $proposalId = ''): array
    {
        return CopilotOperationService::execute($actionKey, $params, $adminId, $sessionId, $adminPassword, $proposalId);
    }

    public static function rollbackOperation(int $journalId, int $adminId): array
    {
        return CopilotOperationService::rollback($journalId, $adminId);
    }

    private static function databaseCatalog(): array
    {
        $resources = implode(', ', array_keys(CopilotDatabasePolicy::resources()));
        $string = function ($desc, $required = true) { return ['type'=>'string','required'=>$required,'desc'=>$desc]; };
        $integer = function ($desc, $required = true) { return ['type'=>'int','required'=>$required,'desc'=>$desc]; };
        $base = ['tier'=>self::TIER_READ_ONLY, 'category'=>'Database', 'permission'=>PermissionService::PERM_COPILOT_USE];
        return [
            'database_capabilities'=>$base + ['title'=>'View my database permissions', 'description'=>'Get server-enforced readable/writable fields, allowed values, operations and client scopes. Use before planning database operations. Empty means the role has no configured access.', 'params'=>[]],
            'database_select'=>$base + ['title'=>'Query permitted records', 'description'=>'Read a single allowed resource with bounded pagination and parameterized equality filters. Never raw SQL. Resources: ' . $resources, 'params'=>['resource'=>$string('Resource name'), 'filters_json'=>$string('JSON object of readable-field equality filters, e.g. {"userid":123}.', false), 'page'=>$integer('Page, starting at 1.', false)]],
            'database_plan'=>['tier'=>self::TIER_REVERSIBLE, 'category'=>'Database', 'permission'=>PermissionService::PERM_OPS_EXECUTE, 'title'=>'Review database changes', 'description'=>'Prepare one exact saved action plan. Supports update on granted safe fields, create/delete of notes, and credit creation. No execution until the administrator approves the card. Payment/provisioning/core-record creation/deletion use native WHMCS workflows. Resources: ' . $resources, 'params'=>['resource'=>$string('Allowed resource'), 'operation'=>$string('create, update, or delete'), 'record_id'=>$integer('Existing record ID for update/delete.', false), 'parent_id'=>$integer('Ticket ID for ticket notes; client ID for client notes/credits.', false), 'values_json'=>$string('JSON object of allowed fields and new values; empty object for delete.', false)]],
            'client_invoices'=>$base + ['title'=>'List client invoices', 'description'=>'Count and list invoices for a known client ID. Returns clickable invoices and client profile with explicit pagination. Do not describe a single page as all invoices when has_more is true.', 'params'=>['client_id'=>$integer('Verified client ID'), 'status'=>$string('Optional exact invoice status', false), 'page'=>$integer('Page, starting at 1', false)]],
            'client_services'=>$base + ['title'=>'List client services', 'description'=>'Count and list services with client and product relationships.', 'params'=>['client_id'=>$integer('Verified client ID'), 'status'=>$string('Optional exact service status', false), 'page'=>$integer('Page, starting at 1', false)]],
            'product_lookup'=>$base + ['title'=>'Look up product', 'description'=>'Read an allowed product and open its configuration.', 'params'=>['product_id'=>$integer('Product ID')]],
        ];
    }
}
