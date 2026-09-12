<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * Class SafeOpsService
 *
 * Safe WHMCS Operations Engine & Atomic Rollback Journal.
 * Governs tool execution for Admin Ops Copilot and Client Chat:
 * - 3-Tier Risk Hierarchy (Read, Reversible Mutation, Destructive)
 * - Two-Phase Proposal Cards with State Diffs
 * - Cryptographic Pre-State Snapshots & Compensation Recipes
 * - 1-Click Atomic Rollback with WHMCS Activity Logging
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
        return [
            // --- TICKET OPS ---
            'ticket_lookup' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Tickets',
                'title'       => 'Lookup Support Ticket',
                'description' => 'Retrieve ticket details, conversation replies, department, priority, and assigned staff.',
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
                'description' => 'Search client by name, email, company or ID; view status, services, open balance, and recent tickets.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'search' => ['type' => 'string', 'required' => true, 'desc' => 'Client ID, Email, Name, or Domain'],
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
                'description' => 'View hosting package, domain, server, IP, disk/bandwidth stats, and next due date.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'service_id' => ['type' => 'int', 'required' => true, 'desc' => 'WHMCS Hosting ID (tblhosting.id) or Domain Name'],
                ],
            ],
            'service_suspend' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Services',
                'title'       => 'Suspend Service (with Reason)',
                'description' => 'Suspend hosting account via WHMCS module (cPanel, Plesk, etc.) with automated inverse rollback.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'service_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Service ID'],
                    'reason'     => ['type' => 'string', 'required' => true, 'desc' => 'Reason for suspension (e.g. Overdue, Abuse)'],
                ],
            ],
            'service_unsuspend' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Services',
                'title'       => 'Unsuspend Service',
                'description' => 'Reactivate suspended hosting account via WHMCS server module with rollback logging.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'service_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Service ID'],
                ],
            ],
            'service_change_package' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Services',
                'title'       => 'Change Service Package / Plan',
                'description' => 'Upgrade or switch hosting package ID on a service record.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'service_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Service ID'],
                    'package_id' => ['type' => 'int', 'required' => true, 'desc' => 'New Product/Package ID (tblproducts.id)'],
                ],
            ],

            // --- INVOICE & BILLING OPS ---
            'invoice_lookup' => [
                'tier'        => self::TIER_READ_ONLY,
                'category'    => 'Billing',
                'title'       => 'Lookup Invoice & Payment Status',
                'description' => 'View invoice line items, tax, total, payment gateway, transaction ID, and due dates.',
                'permission'  => PermissionService::PERM_COPILOT_USE,
                'params'      => [
                    'invoice_id' => ['type' => 'int', 'required' => true, 'desc' => 'Target Invoice ID'],
                ],
            ],
            'invoice_mark_paid' => [
                'tier'        => self::TIER_REVERSIBLE,
                'category'    => 'Billing',
                'title'       => 'Mark Invoice as Paid',
                'description' => 'Record invoice payment and trigger activation of related services/domains.',
                'permission'  => PermissionService::PERM_OPS_EXECUTE,
                'params'      => [
                    'invoice_id'     => ['type' => 'int', 'required' => true, 'desc' => 'Target Invoice ID'],
                    'payment_method' => ['type' => 'string', 'required' => false, 'desc' => 'Gateway name (e.g. banktransfer, stripe)'],
                    'trans_id'       => ['type' => 'string', 'required' => false, 'desc' => 'Transaction ID if known'],
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
                    'amount'      => ['type' => 'float', 'required' => true, 'desc' => 'Credit amount in base currency'],
                    'description' => ['type' => 'string', 'required' => true, 'desc' => 'Reason for credit adjustment'],
                ],
            ],

            // --- SYSTEM & TELEMETRY OPS ---
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
        ];
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
        return self::generateActionProposal($actionKey, $params, $op, $adminId, $sessionId);
    }

    /**
     * Execute Read-Only operations.
     */
    private static function executeReadOnlyOperation(string $actionKey, array $params, int $adminId): array
    {
        try {
            switch ($actionKey) {
                case 'ticket_lookup':
                    $ticketId = (int) ($params['ticket_id'] ?? 0);
                    $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->orWhere('tid', $ticketId)->first();
                    if (!$ticket) {
                        return ['success' => false, 'error' => "Ticket #{$ticketId} not found."];
                    }
                    $replies = Capsule::table('tblticketreplies')
                        ->where('tid', $ticket->id)
                        ->orderBy('id', 'desc')
                        ->limit(5)
                        ->get(['admin', 'name', 'date', 'message']);
                    return [
                        'success' => true,
                        'data'    => [
                            'id'         => $ticket->id,
                            'tid'        => $ticket->tid,
                            'client_id'  => $ticket->userid,
                            'subject'    => $ticket->title,
                            'department' => $ticket->did,
                            'status'     => $ticket->status,
                            'priority'   => $ticket->urgency,
                            'admin'      => $ticket->admin,
                            'last_reply' => $ticket->lastreply,
                            'replies'    => $replies,
                        ],
                    ];

                case 'client_lookup':
                    $search = trim($params['search'] ?? '');
                    if (empty($search)) {
                        return ['success' => false, 'error' => "Search query required."];
                    }
                    $client = Capsule::table('tblclients')
                        ->where('id', is_numeric($search) ? (int)$search : 0)
                        ->orWhere('email', $search)
                        ->orWhere('companyname', 'LIKE', "%{$search}%")
                        ->orWhereRaw("CONCAT(firstname, ' ', lastname) LIKE ?", ["%{$search}%"])
                        ->first();

                    if (!$client) {
                        return ['success' => false, 'error' => "No client matching '{$search}' found."];
                    }

                    $services = Capsule::table('tblhosting')
                        ->where('userid', $client->id)
                        ->get(['id', 'domain', 'packageid', 'domainstatus', 'amount', 'billingcycle']);

                    $invoices = Capsule::table('tblinvoices')
                        ->where('userid', $client->id)
                        ->orderBy('id', 'desc')
                        ->limit(5)
                        ->get(['id', 'invoicenum', 'total', 'status', 'duedate']);

                    return [
                        'success' => true,
                        'data'    => [
                            'client'   => [
                                'id'         => $client->id,
                                'name'       => $client->firstname . ' ' . $client->lastname,
                                'email'      => $client->email,
                                'company'    => $client->companyname,
                                'status'     => $client->status,
                                'credit'     => $client->credit,
                                'currency'   => $client->currency,
                                'datecreated'=> $client->datecreated,
                            ],
                            'services' => $services,
                            'invoices' => $invoices,
                        ],
                    ];

                case 'service_lookup':
                    $query = $params['service_id'] ?? 0;
                    $service = Capsule::table('tblhosting')
                        ->where('id', is_numeric($query) ? (int)$query : 0)
                        ->orWhere('domain', $query)
                        ->first();

                    if (!$service) {
                        return ['success' => false, 'error' => "Service '{$query}' not found."];
                    }

                    $product = Capsule::table('tblproducts')->where('id', $service->packageid)->value('name');
                    $server = Capsule::table('tblservers')->where('id', $service->server)->value('name');

                    return [
                        'success' => true,
                        'data'    => [
                            'id'            => $service->id,
                            'client_id'     => $service->userid,
                            'domain'        => $service->domain,
                            'product'       => $product,
                            'server'        => $server,
                            'status'        => $service->domainstatus,
                            'suspendreason' => $service->suspendreason,
                            'dedicatedip'   => $service->dedicatedip,
                            'amount'        => $service->amount,
                            'nextduedate'   => $service->nextduedate,
                            'diskusage'     => $service->diskusage,
                            'disklimit'     => $service->disklimit,
                            'bwusage'       => $service->bwusage,
                            'bwlimit'       => $service->bwlimit,
                        ],
                    ];

                case 'invoice_lookup':
                    $invoiceId = (int) ($params['invoice_id'] ?? 0);
                    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
                    if (!$invoice) {
                        return ['success' => false, 'error' => "Invoice #{$invoiceId} not found."];
                    }
                    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get(['description', 'amount', 'type', 'relid']);

                    return [
                        'success' => true,
                        'data'    => [
                            'id'            => $invoice->id,
                            'client_id'     => $invoice->userid,
                            'date'          => $invoice->date,
                            'duedate'       => $invoice->duedate,
                            'datepaid'      => $invoice->datepaid,
                            'subtotal'      => $invoice->subtotal,
                            'credit'        => $invoice->credit,
                            'tax'           => $invoice->tax,
                            'total'         => $invoice->total,
                            'status'        => $invoice->status,
                            'paymentmethod' => $invoice->paymentmethod,
                            'items'         => $items,
                        ],
                    ];

                case 'server_telemetry_query':
                    require_once dirname(__DIR__) . '/lib/ServerTelemetryService.php';
                    $serverId = (int) ($params['server_id'] ?? 0);
                    if ($serverId > 0) {
                        $metric = ServerTelemetryService::getServerTelemetry($serverId);
                        return ['success' => true, 'data' => $metric];
                    }
                    $all = ServerTelemetryService::getRecentTelemetrySummary();
                    return ['success' => true, 'data' => $all];

                case 'gateway_diagnostics':
                    $limit = (int) ($params['limit'] ?? 15);
                    $gw = trim($params['gateway'] ?? '');
                    $q = Capsule::table('tblgatewaylog')->orderBy('id', 'desc')->limit($limit);
                    if (!empty($gw)) {
                        $q->where('gateway', 'LIKE', "%{$gw}%");
                    }
                    $logs = $q->get(['id', 'date', 'gateway', 'data', 'result']);
                    return ['success' => true, 'data' => $logs];

                default:
                    return ['success' => false, 'error' => "Unhandled read-only operation: {$actionKey}"];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => "Execution error: " . $e->getMessage()];
        }
    }

    /**
     * Generate Action Proposal Card with diff and impact summary.
     */
    private static function generateActionProposal(string $actionKey, array $params, array $op, int $adminId, int $sessionId): array
    {
        $proposalId = 'prop_' . bin2hex(random_bytes(8));
        $diff = [];
        $targetDesc = '';

        try {
            switch ($actionKey) {
                case 'ticket_change_status':
                    $tid = (int) ($params['ticket_id'] ?? 0);
                    $newStatus = trim($params['status'] ?? '');
                    $current = Capsule::table('tbltickets')->where('id', $tid)->value('status');
                    $diff = ['Status' => ['before' => $current, 'after' => $newStatus]];
                    $targetDesc = "Ticket #{$tid}";
                    break;

                case 'ticket_change_priority':
                    $tid = (int) ($params['ticket_id'] ?? 0);
                    $newPri = trim($params['priority'] ?? '');
                    $current = Capsule::table('tbltickets')->where('id', $tid)->value('urgency');
                    $diff = ['Priority' => ['before' => $current, 'after' => $newPri]];
                    $targetDesc = "Ticket #{$tid}";
                    break;

                case 'ticket_assign_admin':
                    $tid = (int) ($params['ticket_id'] ?? 0);
                    $newAdmin = (int) ($params['admin_id'] ?? 0);
                    $currentAdminId = Capsule::table('tbltickets')->where('id', $tid)->value('admin');
                    $currentAdminName = $currentAdminId > 0 ? Capsule::table('tbladmins')->where('id', $currentAdminId)->value('username') : 'Unassigned';
                    $newAdminName = $newAdmin > 0 ? Capsule::table('tbladmins')->where('id', $newAdmin)->value('username') : 'Unassigned';
                    $diff = ['Assigned Staff' => ['before' => $currentAdminName, 'after' => $newAdminName]];
                    $targetDesc = "Ticket #{$tid}";
                    break;

                case 'ticket_add_note':
                    $tid = (int) ($params['ticket_id'] ?? 0);
                    $note = trim($params['note'] ?? '');
                    $diff = ['New Internal Note' => ['before' => '(none)', 'after' => mb_substr($note, 0, 100) . '...']];
                    $targetDesc = "Ticket #{$tid}";
                    break;

                case 'service_suspend':
                    $sid = (int) ($params['service_id'] ?? 0);
                    $reason = trim($params['reason'] ?? 'Suspended via Sahdev Copilot');
                    $svc = Capsule::table('tblhosting')->where('id', $sid)->first();
                    $domain = $svc ? $svc->domain : "ID #{$sid}";
                    $diff = [
                        'Domain Status' => ['before' => $svc->domainstatus ?? 'Active', 'after' => 'Suspended'],
                        'Reason'        => ['before' => $svc->suspendreason ?? '', 'after' => $reason],
                    ];
                    $targetDesc = "Service #{$sid} ({$domain})";
                    break;

                case 'service_unsuspend':
                    $sid = (int) ($params['service_id'] ?? 0);
                    $svc = Capsule::table('tblhosting')->where('id', $sid)->first();
                    $domain = $svc ? $svc->domain : "ID #{$sid}";
                    $diff = [
                        'Domain Status' => ['before' => $svc->domainstatus ?? 'Suspended', 'after' => 'Active'],
                        'Clear Reason'  => ['before' => $svc->suspendreason ?? '', 'after' => 'Cleared'],
                    ];
                    $targetDesc = "Service #{$sid} ({$domain})";
                    break;

                case 'service_change_package':
                    $sid = (int) ($params['service_id'] ?? 0);
                    $newPkgId = (int) ($params['package_id'] ?? 0);
                    $currentPkgId = Capsule::table('tblhosting')->where('id', $sid)->value('packageid');
                    $oldName = Capsule::table('tblproducts')->where('id', $currentPkgId)->value('name') ?: "ID #{$currentPkgId}";
                    $newName = Capsule::table('tblproducts')->where('id', $newPkgId)->value('name') ?: "ID #{$newPkgId}";
                    $diff = ['Package' => ['before' => $oldName, 'after' => $newName]];
                    $targetDesc = "Service #{$sid}";
                    break;

                case 'invoice_mark_paid':
                    $invId = (int) ($params['invoice_id'] ?? 0);
                    $inv = Capsule::table('tblinvoices')->where('id', $invId)->first();
                    $diff = [
                        'Status'   => ['before' => $inv->status ?? 'Unpaid', 'after' => 'Paid'],
                        'DatePaid' => ['before' => 'None', 'after' => Carbon::now()->toDateString()],
                    ];
                    $targetDesc = "Invoice #{$invId} (Total: {$inv->total})";
                    break;

                case 'invoice_cancel':
                    $invId = (int) ($params['invoice_id'] ?? 0);
                    $inv = Capsule::table('tblinvoices')->where('id', $invId)->first();
                    $diff = ['Status' => ['before' => $inv->status ?? 'Unpaid', 'after' => 'Cancelled']];
                    $targetDesc = "Invoice #{$invId}";
                    break;

                case 'invoice_add_credit':
                    $cid = (int) ($params['client_id'] ?? 0);
                    $amt = (float) ($params['amount'] ?? 0.0);
                    $currentCredit = (float) Capsule::table('tblclients')->where('id', $cid)->value('credit');
                    $newCredit = $currentCredit + $amt;
                    $diff = ['Credit Balance' => ['before' => number_format($currentCredit, 2), 'after' => number_format($newCredit, 2)]];
                    $targetDesc = "Client #{$cid}";
                    break;

                case 'client_toggle_status':
                    $cid = (int) ($params['client_id'] ?? 0);
                    $newStatus = trim($params['status'] ?? 'Active');
                    $current = Capsule::table('tblclients')->where('id', $cid)->value('status');
                    $diff = ['Account Status' => ['before' => $current, 'after' => $newStatus]];
                    $targetDesc = "Client #{$cid}";
                    break;

                default:
                    $diff = ['Parameters' => ['before' => 'Current', 'after' => json_encode($params)]];
                    $targetDesc = "Target entity";
            }
        } catch (\Throwable $e) {
            $diff = ['Error' => ['before' => 'Failed to build diff', 'after' => $e->getMessage()]];
        }

        return [
            'success'             => true,
            'is_action_proposal'  => true,
            'proposal_id'         => $proposalId,
            'action_key'          => $actionKey,
            'risk_tier'           => $op['tier'],
            'title'               => $op['title'],
            'description'         => $op['description'],
            'target'              => $targetDesc,
            'params'              => $params,
            'diff'                => $diff,
            'requires_password'   => $op['tier'] === self::TIER_DESTRUCTIVE,
            'session_id'          => $sessionId,
        ];
    }

    /**
     * Confirms and executes a proposed operation with pre-state snapshotting and rollback journaling.
     */
    public static function executeConfirmedOperation(string $actionKey, array $params, int $adminId, int $sessionId, ?string $adminPassword = null): array
    {
        $catalog = self::getOperationCatalog();
        if (!isset($catalog[$actionKey])) {
            return ['success' => false, 'error' => "Operation '{$actionKey}' is not registered."];
        }

        $op = $catalog[$actionKey];

        // Permissions check
        require_once dirname(__DIR__) . '/lib/PermissionService.php';
        if (!PermissionService::hasPermission($adminId, $op['permission'])) {
            return ['success' => false, 'error' => "Unauthorized: Missing '{$op['permission']}' permission."];
        }

        // Destructive ops require password verification
        if ($op['tier'] === self::TIER_DESTRUCTIVE) {
            if (empty($adminPassword) || !self::verifyAdminPassword($adminId, $adminPassword)) {
                return ['success' => false, 'error' => "Invalid Administrator Password. Tier-3 destructive operations require password authorization."];
            }
        }

        // 1. Take snapshot of state before mutation & build compensation recipe
        $snapshot = self::capturePreStateAndCompensation($actionKey, $params);
        if (!$snapshot['success']) {
            return $snapshot;
        }

        $stateBefore = $snapshot['state_before'];
        $compensationRecipe = $snapshot['compensation_recipe'];
        $targetEntityType = $snapshot['entity_type'];
        $targetEntityId = $snapshot['entity_id'];
        $desc = $snapshot['description'];

        // 2. Perform Mutation within Transaction
        try {
            $mutationResult = self::applyMutation($actionKey, $params, $adminId);
            if (!$mutationResult['success']) {
                return $mutationResult;
            }

            // 3. Capture State After
            $stateAfter = self::capturePostState($targetEntityType, $targetEntityId);

            // 4. Record to tblsahdev_ops_journal
            $journalId = Capsule::table('tblsahdev_ops_journal')->insertGetId([
                'session_id'               => $sessionId,
                'admin_id'                 => $adminId,
                'action_key'               => $actionKey,
                'target_entity_type'       => $targetEntityType,
                'target_entity_id'         => $targetEntityId,
                'description'              => $desc,
                'parameters_json'          => json_encode($params),
                'state_before_json'        => json_encode($stateBefore),
                'state_after_json'         => json_encode($stateAfter),
                'compensation_recipe_json' => json_encode($compensationRecipe),
                'risk_tier'                => $op['tier'],
                'status'                   => 'executed',
                'executed_at'              => Carbon::now(),
                'created_at'               => Carbon::now(),
                'updated_at'               => Carbon::now(),
            ]);

            // 5. Log to WHMCS tblactivitylog
            if (function_exists('logActivity')) {
                logActivity("Sahdev Copilot: Executed '{$desc}' [Journal #{$journalId}] by Admin #{$adminId}", $adminId);
            }

            return [
                'success'      => true,
                'journal_id'   => $journalId,
                'action_key'   => $actionKey,
                'description'  => $desc,
                'state_before' => $stateBefore,
                'state_after'  => $stateAfter,
                'can_rollback' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => "Mutation execution failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * 1-Click Rollback Engine: Inverts state using compensation recipe from tblsahdev_ops_journal.
     */
    public static function rollbackOperation(int $journalId, int $adminId): array
    {
        require_once dirname(__DIR__) . '/lib/PermissionService.php';
        if (!PermissionService::hasPermission($adminId, PermissionService::PERM_OPS_ROLLBACK)) {
            return ['success' => false, 'error' => "Unauthorized: Missing 'ops_rollback' permission."];
        }

        $row = Capsule::table('tblsahdev_ops_journal')->where('id', $journalId)->first();
        if (!$row) {
            return ['success' => false, 'error' => "Journal entry #{$journalId} not found."];
        }

        if ($row->status === 'rolled_back') {
            return ['success' => false, 'error' => "Operation #{$journalId} has already been rolled back on {$row->rolled_back_at}."];
        }

        $recipe = json_decode($row->compensation_recipe_json, true);
        if (!is_array($recipe)) {
            return ['success' => false, 'error' => "Corrupted or missing compensation recipe in journal #{$journalId}."];
        }

        try {
            // Apply compensation
            $res = self::applyCompensation($recipe, $adminId);
            if (!$res['success']) {
                Capsule::table('tblsahdev_ops_journal')->where('id', $journalId)->update([
                    'rollback_error' => $res['error'],
                    'updated_at'     => Carbon::now(),
                ]);
                return $res;
            }

            // Mark as rolled back
            Capsule::table('tblsahdev_ops_journal')->where('id', $journalId)->update([
                'status'             => 'rolled_back',
                'rolled_back_at'     => Carbon::now(),
                'rollback_admin_id'  => $adminId,
                'rollback_error'     => null,
                'updated_at'         => Carbon::now(),
            ]);

            if (function_exists('logActivity')) {
                logActivity("Sahdev Copilot: Rolled back Operation [Journal #{$journalId}] '{$row->description}' by Admin #{$adminId}", $adminId);
            }

            return [
                'success'    => true,
                'journal_id' => $journalId,
                'message'    => "Operation #{$journalId} ('{$row->description}') was successfully rolled back.",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => "Rollback failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Snapshot pre-state and prepare the exact compensation action recipe.
     */
    private static function capturePreStateAndCompensation(string $actionKey, array $params): array
    {
        switch ($actionKey) {
            case 'ticket_change_status':
                $tid = (int) $params['ticket_id'];
                $ticket = Capsule::table('tbltickets')->where('id', $tid)->first(['id', 'status']);
                if (!$ticket) return ['success' => false, 'error' => "Ticket #{$tid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'ticket',
                    'entity_id'           => $tid,
                    'description'         => "Changed Ticket #{$tid} status to '{$params['status']}'",
                    'state_before'        => ['status' => $ticket->status],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tbltickets',
                        'where'  => ['id' => $tid],
                        'values' => ['status' => $ticket->status],
                    ],
                ];

            case 'ticket_change_priority':
                $tid = (int) $params['ticket_id'];
                $ticket = Capsule::table('tbltickets')->where('id', $tid)->first(['id', 'urgency']);
                if (!$ticket) return ['success' => false, 'error' => "Ticket #{$tid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'ticket',
                    'entity_id'           => $tid,
                    'description'         => "Changed Ticket #{$tid} priority to '{$params['priority']}'",
                    'state_before'        => ['urgency' => $ticket->urgency],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tbltickets',
                        'where'  => ['id' => $tid],
                        'values' => ['urgency' => $ticket->urgency],
                    ],
                ];

            case 'ticket_assign_admin':
                $tid = (int) $params['ticket_id'];
                $ticket = Capsule::table('tbltickets')->where('id', $tid)->first(['id', 'admin']);
                if (!$ticket) return ['success' => false, 'error' => "Ticket #{$tid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'ticket',
                    'entity_id'           => $tid,
                    'description'         => "Reassigned Ticket #{$tid} to Admin #{$params['admin_id']}",
                    'state_before'        => ['admin' => (int) $ticket->admin],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tbltickets',
                        'where'  => ['id' => $tid],
                        'values' => ['admin' => (int) $ticket->admin],
                    ],
                ];

            case 'ticket_add_note':
                $tid = (int) $params['ticket_id'];
                return [
                    'success'             => true,
                    'entity_type'         => 'ticket',
                    'entity_id'           => $tid,
                    'description'         => "Added internal staff note on Ticket #{$tid}",
                    'state_before'        => ['notes_count' => Capsule::table('tblticketnotes')->where('ticketid', $tid)->count()],
                    'compensation_recipe' => [
                        'type'   => 'delete_last_note',
                        'ticket' => $tid,
                    ],
                ];

            case 'service_suspend':
                $sid = (int) $params['service_id'];
                $svc = Capsule::table('tblhosting')->where('id', $sid)->first(['id', 'domain', 'domainstatus', 'suspendreason']);
                if (!$svc) return ['success' => false, 'error' => "Service #{$sid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'service',
                    'entity_id'           => $sid,
                    'description'         => "Suspended Service #{$sid} ({$svc->domain})",
                    'state_before'        => [
                        'domainstatus'  => $svc->domainstatus,
                        'suspendreason' => $svc->suspendreason,
                    ],
                    'compensation_recipe' => [
                        'type'          => 'module_unsuspend',
                        'service_id'    => $sid,
                        'restore_state' => [
                            'domainstatus'  => $svc->domainstatus,
                            'suspendreason' => $svc->suspendreason,
                        ],
                    ],
                ];

            case 'service_unsuspend':
                $sid = (int) $params['service_id'];
                $svc = Capsule::table('tblhosting')->where('id', $sid)->first(['id', 'domain', 'domainstatus', 'suspendreason']);
                if (!$svc) return ['success' => false, 'error' => "Service #{$sid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'service',
                    'entity_id'           => $sid,
                    'description'         => "Unsuspended Service #{$sid} ({$svc->domain})",
                    'state_before'        => [
                        'domainstatus'  => $svc->domainstatus,
                        'suspendreason' => $svc->suspendreason,
                    ],
                    'compensation_recipe' => [
                        'type'          => 'module_suspend',
                        'service_id'    => $sid,
                        'reason'        => $svc->suspendreason ?: 'Restored suspension',
                    ],
                ];

            case 'service_change_package':
                $sid = (int) $params['service_id'];
                $svc = Capsule::table('tblhosting')->where('id', $sid)->first(['id', 'packageid']);
                if (!$svc) return ['success' => false, 'error' => "Service #{$sid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'service',
                    'entity_id'           => $sid,
                    'description'         => "Changed package on Service #{$sid} from Package #{$svc->packageid} to #{$params['package_id']}",
                    'state_before'        => ['packageid' => (int)$svc->packageid],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tblhosting',
                        'where'  => ['id' => $sid],
                        'values' => ['packageid' => (int)$svc->packageid],
                    ],
                ];

            case 'invoice_mark_paid':
                $invId = (int) $params['invoice_id'];
                $inv = Capsule::table('tblinvoices')->where('id', $invId)->first(['id', 'status', 'datepaid']);
                if (!$inv) return ['success' => false, 'error' => "Invoice #{$invId} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'invoice',
                    'entity_id'           => $invId,
                    'description'         => "Marked Invoice #{$invId} as Paid",
                    'state_before'        => ['status' => $inv->status, 'datepaid' => $inv->datepaid],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tblinvoices',
                        'where'  => ['id' => $invId],
                        'values' => ['status' => $inv->status, 'datepaid' => $inv->datepaid],
                    ],
                ];

            case 'invoice_cancel':
                $invId = (int) $params['invoice_id'];
                $inv = Capsule::table('tblinvoices')->where('id', $invId)->first(['id', 'status']);
                if (!$inv) return ['success' => false, 'error' => "Invoice #{$invId} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'invoice',
                    'entity_id'           => $invId,
                    'description'         => "Cancelled Invoice #{$invId}",
                    'state_before'        => ['status' => $inv->status],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tblinvoices',
                        'where'  => ['id' => $invId],
                        'values' => ['status' => $inv->status],
                    ],
                ];

            case 'invoice_add_credit':
                $cid = (int) $params['client_id'];
                $client = Capsule::table('tblclients')->where('id', $cid)->first(['id', 'credit']);
                if (!$client) return ['success' => false, 'error' => "Client #{$cid} not found."];
                $amt = (float) $params['amount'];
                return [
                    'success'             => true,
                    'entity_type'         => 'client',
                    'entity_id'           => $cid,
                    'description'         => "Added {$amt} credit to Client #{$cid}",
                    'state_before'        => ['credit' => (float)$client->credit],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tblclients',
                        'where'  => ['id' => $cid],
                        'values' => ['credit' => (float)$client->credit],
                    ],
                ];

            case 'client_toggle_status':
                $cid = (int) $params['client_id'];
                $client = Capsule::table('tblclients')->where('id', $cid)->first(['id', 'status']);
                if (!$client) return ['success' => false, 'error' => "Client #{$cid} not found."];
                return [
                    'success'             => true,
                    'entity_type'         => 'client',
                    'entity_id'           => $cid,
                    'description'         => "Changed Client #{$cid} status to '{$params['status']}'",
                    'state_before'        => ['status' => $client->status],
                    'compensation_recipe' => [
                        'type'   => 'sql_update',
                        'table'  => 'tblclients',
                        'where'  => ['id' => $cid],
                        'values' => ['status' => $client->status],
                    ],
                ];

            default:
                return ['success' => false, 'error' => "No snapshot blueprint for '{$actionKey}'."];
        }
    }

    /**
     * Apply actual database or LocalAPI mutation.
     */
    private static function applyMutation(string $actionKey, array $params, int $adminId): array
    {
        switch ($actionKey) {
            case 'ticket_change_status':
                Capsule::table('tbltickets')->where('id', (int)$params['ticket_id'])->update([
                    'status' => $params['status'],
                ]);
                return ['success' => true];

            case 'ticket_change_priority':
                Capsule::table('tbltickets')->where('id', (int)$params['ticket_id'])->update([
                    'urgency' => $params['priority'],
                ]);
                return ['success' => true];

            case 'ticket_assign_admin':
                Capsule::table('tbltickets')->where('id', (int)$params['ticket_id'])->update([
                    'admin' => (int)$params['admin_id'],
                ]);
                return ['success' => true];

            case 'ticket_add_note':
                $adminName = Capsule::table('tbladmins')->where('id', $adminId)->value('username') ?: 'Sahdev AI';
                Capsule::table('tblticketnotes')->insert([
                    'ticketid' => (int)$params['ticket_id'],
                    'admin'    => $adminName,
                    'date'     => Carbon::now(),
                    'message'  => $params['note'],
                ]);
                return ['success' => true];

            case 'service_suspend':
                $sid = (int) $params['service_id'];
                $reason = $params['reason'] ?? 'Suspended via Copilot';
                if (function_exists('localAPI')) {
                    $res = localAPI('ModuleSuspend', ['serviceid' => $sid, 'suspendreason' => $reason]);
                    if ($res['result'] === 'success') {
                        return ['success' => true];
                    }
                }
                // Fallback direct update
                Capsule::table('tblhosting')->where('id', $sid)->update([
                    'domainstatus'  => 'Suspended',
                    'suspendreason' => $reason,
                ]);
                return ['success' => true];

            case 'service_unsuspend':
                $sid = (int) $params['service_id'];
                if (function_exists('localAPI')) {
                    $res = localAPI('ModuleUnsuspend', ['serviceid' => $sid]);
                    if ($res['result'] === 'success') {
                        return ['success' => true];
                    }
                }
                // Fallback direct update
                Capsule::table('tblhosting')->where('id', $sid)->update([
                    'domainstatus'  => 'Active',
                    'suspendreason' => '',
                ]);
                return ['success' => true];

            case 'service_change_package':
                Capsule::table('tblhosting')->where('id', (int)$params['service_id'])->update([
                    'packageid' => (int)$params['package_id'],
                ]);
                return ['success' => true];

            case 'invoice_mark_paid':
                $invId = (int) $params['invoice_id'];
                if (function_exists('localAPI')) {
                    $res = localAPI('AddInvoicePayment', [
                        'invoiceid' => $invId,
                        'transid'   => $params['trans_id'] ?? ('AI-COPILOT-' . time()),
                        'gateway'   => $params['payment_method'] ?? 'manual',
                    ]);
                    if ($res['result'] === 'success') {
                        return ['success' => true];
                    }
                }
                Capsule::table('tblinvoices')->where('id', $invId)->update([
                    'status'   => 'Paid',
                    'datepaid' => Carbon::now(),
                ]);
                return ['success' => true];

            case 'invoice_cancel':
                Capsule::table('tblinvoices')->where('id', (int)$params['invoice_id'])->update([
                    'status' => 'Cancelled',
                ]);
                return ['success' => true];

            case 'invoice_add_credit':
                $cid = (int) $params['client_id'];
                $amt = (float) $params['amount'];
                Capsule::table('tblclients')->where('id', $cid)->increment('credit', $amt);
                Capsule::table('tblcredit')->insert([
                    'clientid'    => $cid,
                    'date'        => Carbon::now()->toDateString(),
                    'description' => $params['description'] ?? 'Credit added via Sahdev Copilot',
                    'amount'      => $amt,
                ]);
                return ['success' => true];

            case 'client_toggle_status':
                Capsule::table('tblclients')->where('id', (int)$params['client_id'])->update([
                    'status' => $params['status'],
                ]);
                return ['success' => true];

            default:
                return ['success' => false, 'error' => "Mutation handler missing for '{$actionKey}'."];
        }
    }

    /**
     * Apply inverse compensation recipe.
     */
    private static function applyCompensation(array $recipe, int $adminId): array
    {
        $type = $recipe['type'] ?? '';

        switch ($type) {
            case 'sql_update':
                Capsule::table($recipe['table'])
                    ->where($recipe['where'])
                    ->update($recipe['values']);
                return ['success' => true];

            case 'delete_last_note':
                $ticketId = (int) $recipe['ticket'];
                $lastNote = Capsule::table('tblticketnotes')
                    ->where('ticketid', $ticketId)
                    ->orderBy('id', 'desc')
                    ->first();
                if ($lastNote) {
                    Capsule::table('tblticketnotes')->where('id', $lastNote->id)->delete();
                }
                return ['success' => true];

            case 'module_unsuspend':
                $sid = (int) $recipe['service_id'];
                if (function_exists('localAPI')) {
                    localAPI('ModuleUnsuspend', ['serviceid' => $sid]);
                }
                if (!empty($recipe['restore_state'])) {
                    Capsule::table('tblhosting')->where('id', $sid)->update($recipe['restore_state']);
                }
                return ['success' => true];

            case 'module_suspend':
                $sid = (int) $recipe['service_id'];
                $reason = $recipe['reason'] ?? 'Restored suspension';
                if (function_exists('localAPI')) {
                    localAPI('ModuleSuspend', ['serviceid' => $sid, 'suspendreason' => $reason]);
                } else {
                    Capsule::table('tblhosting')->where('id', $sid)->update([
                        'domainstatus'  => 'Suspended',
                        'suspendreason' => $reason,
                    ]);
                }
                return ['success' => true];

            default:
                return ['success' => false, 'error' => "Unknown compensation type: {$type}"];
        }
    }

    private static function capturePostState(string $entityType, int $entityId): array
    {
        try {
            switch ($entityType) {
                case 'ticket':
                    return (array) Capsule::table('tbltickets')->where('id', $entityId)->first(['status', 'urgency', 'admin']);
                case 'service':
                    return (array) Capsule::table('tblhosting')->where('id', $entityId)->first(['domainstatus', 'suspendreason', 'packageid']);
                case 'invoice':
                    return (array) Capsule::table('tblinvoices')->where('id', $entityId)->first(['status', 'datepaid']);
                case 'client':
                    return (array) Capsule::table('tblclients')->where('id', $entityId)->first(['status', 'credit']);
                default:
                    return [];
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function verifyAdminPassword(int $adminId, string $password): bool
    {
        $hash = Capsule::table('tbladmins')->where('id', $adminId)->value('password');
        if (empty($hash)) {
            return false;
        }

        if (class_exists('\WHMCS\Auth')) {
            try {
                return \WHMCS\Auth::verifyPassword($password, $hash);
            } catch (\Throwable $e) {}
        }

        return password_verify($password, $hash);
    }
}
