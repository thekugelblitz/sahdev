<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/**
 * Role-Based Access Control (RBAC) Service for Sahdev AI & Telemetry Features.
 *
 * Controls access to Server Telemetry, WHM SSO Access, Ticket AI, Autopilot,
 * Tools Execution, Incidents, Knowledge Base, and Admin Settings based on
 * WHMCS Admin Roles (tbladminroles / tbladmins.roleid).
 */
class PermissionService
{
    // Permission Keys
    public const PERM_TELEMETRY_VIEW    = 'telemetry_view';
    public const PERM_SERVER_ACCESS     = 'server_access';
    public const PERM_TICKET_AI         = 'ticket_ai';
    public const PERM_REWRITE_REPLY     = 'rewrite_reply';
    public const PERM_SUMMARIZER        = 'summarizer';
    public const PERM_HISTORICAL_CTX    = 'historical_context';
    public const PERM_CANNED_KB         = 'canned_kb';
    public const PERM_TOOLS_EXECUTE     = 'tools_execute';
    public const PERM_INCIDENTS_MANAGE  = 'incidents_manage';
    public const PERM_KNOWLEDGE_MANAGE  = 'knowledge_manage';
    public const PERM_ANALYTICS_VIEW    = 'analytics_view';
    public const PERM_AUDIT_MANAGE      = 'audit_manage';
    public const PERM_SETTINGS_MANAGE   = 'settings_manage';

    /**
     * Cache for resolved permissions within the same request lifecycle.
     */
    private static array $permissionCache = [];
    private static array $adminRoleCache = [];

    /**
     * Ensure tblsahdev_role_permissions table exists in the database.
     */
    public static function ensureSchema(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_role_permissions')) {
                Capsule::schema()->create('tblsahdev_role_permissions', function ($table) {
                    $table->increments('id');
                    $table->integer('role_id')->unsigned()->unique();
                    $table->longText('permissions_json')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Benign race or existing table
        }
    }

    /**
     * Return all permission definitions with categories and labels for UI display.
     */
    public static function getPermissionDefinitions(): array
    {
        return [
            'telemetry' => [
                'category_label' => 'Server Telemetry & Infrastructure',
                'category_icon'  => 'fa-satellite-dish',
                'permissions'    => [
                    self::PERM_TELEMETRY_VIEW => [
                        'label'       => 'View Server Telemetry & Health',
                        'description' => 'View server loads, daemon statuses, account disk quotas, header server widget, and client service health card.',
                        'risk'        => 'medium',
                    ],
                    self::PERM_SERVER_ACCESS => [
                        'label'       => 'Access WHM / Control Panel SSO Links',
                        'description' => 'Single-sign-on 1-click access buttons to server control panels (doserverlogon.php?id=XX).',
                        'risk'        => 'high',
                    ],
                ],
            ],
            'ticket_ai' => [
                'category_label' => 'Ticket AI & Assistance',
                'category_icon'  => 'fa-robot',
                'permissions'    => [
                    self::PERM_TICKET_AI => [
                        'label'       => 'Ticket AI Generation & Auto-Analysis',
                        'description' => 'Generate AI responses, inspect ticket root-cause, and view suggested replies in ticket panel.',
                        'risk'        => 'low',
                    ],
                    self::PERM_REWRITE_REPLY => [
                        'label'       => 'AI Response Rewriter & Tone Polishing',
                        'description' => 'Rewrite or rephrase admin draft messages in different professional tones.',
                        'risk'        => 'low',
                    ],
                    self::PERM_SUMMARIZER => [
                        'label'       => 'AI Ticket Summarizer',
                        'description' => 'Generate and view concise AI conversation summaries.',
                        'risk'        => 'low',
                    ],
                    self::PERM_HISTORICAL_CTX => [
                        'label'       => 'Historical Customer Intelligence',
                        'description' => 'View AI-aggregated past tickets, SLA metrics, and customer sentiment context.',
                        'risk'        => 'low',
                    ],
                    self::PERM_CANNED_KB => [
                        'label'       => 'Search & Insert Canned / KB Templates',
                        'description' => 'Search and paste canned responses & KB articles into ticket replies.',
                        'risk'        => 'low',
                    ],
                ],
            ],
            'operations' => [
                'category_label' => 'Operations & Incident Management',
                'category_icon'  => 'fa-tools',
                'permissions'    => [
                    self::PERM_TOOLS_EXECUTE => [
                        'label'       => 'Execute Diagnostics & Server Tools',
                        'description' => 'Execute live server diagnostic tools, cPanel/WHM repair tasks, and mail/DNS operations.',
                        'risk'        => 'high',
                    ],
                    self::PERM_INCIDENTS_MANAGE => [
                        'label'       => 'Incident Center & Broadcast Mass Replies',
                        'description' => 'View outage surges, manage incident status, and post 1-click broadcast replies to clustered tickets.',
                        'risk'        => 'high',
                    ],
                ],
            ],
            'administration' => [
                'category_label' => 'Knowledge & Module Administration',
                'category_icon'  => 'fa-shield-alt',
                'permissions'    => [
                    self::PERM_KNOWLEDGE_MANAGE => [
                        'label'       => 'Manage RAG Knowledge Base & Canned Content',
                        'description' => 'Upload documents, sync WHMCS KB, and create/edit organizational canned response templates.',
                        'risk'        => 'medium',
                    ],
                    self::PERM_ANALYTICS_VIEW => [
                        'label'       => 'View Analytics & Token ROI Reports',
                        'description' => 'Access AI cost analytics, quality scores, and operator performance reports.',
                        'risk'        => 'low',
                    ],
                    self::PERM_AUDIT_MANAGE => [
                        'label'       => 'Audit Trail & Compliance Log Management',
                        'description' => 'View AI prompt/response audit records and delete audit entries.',
                        'risk'        => 'medium',
                    ],
                    self::PERM_SETTINGS_MANAGE => [
                        'label'       => 'Full Module Settings & Permission Management',
                        'description' => 'Configure AI providers, API keys, system prompts, autopilot settings, and role permissions.',
                        'risk'        => 'critical',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get all active WHMCS Admin Roles.
     */
    public static function getAllRoles(): array
    {
        try {
            $roles = Capsule::table('tbladminroles')->orderBy('id', 'asc')->get();
            $result = [];
            foreach ($roles as $r) {
                $adminCount = Capsule::table('tbladmins')->where('roleid', $r->id)->where('disabled', 0)->count();
                $result[] = [
                    'id'          => (int) $r->id,
                    'name'        => (string) $r->name,
                    'admin_count' => $adminCount,
                ];
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get admin's WHMCS Role ID.
     */
    public static function getAdminRoleId(int $adminId): int
    {
        if ($adminId <= 0) {
            return 0;
        }
        if (isset(self::$adminRoleCache[$adminId])) {
            return self::$adminRoleCache[$adminId]['role_id'];
        }

        try {
            $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
            if ($admin) {
                $roleId = (int) $admin->roleid;
                $roleName = (string) (Capsule::table('tbladminroles')->where('id', $roleId)->value('name') ?: '');
                self::$adminRoleCache[$adminId] = [
                    'role_id'   => $roleId,
                    'role_name' => $roleName,
                ];
                return $roleId;
            }
        } catch (\Throwable $e) {}

        return 0;
    }

    /**
     * Get admin's WHMCS Role Name.
     */
    public static function getAdminRoleName(int $adminId): string
    {
        if ($adminId <= 0) {
            return '';
        }
        if (isset(self::$adminRoleCache[$adminId])) {
            return self::$adminRoleCache[$adminId]['role_name'];
        }
        self::getAdminRoleId($adminId);
        return self::$adminRoleCache[$adminId]['role_name'] ?? '';
    }

    /**
     * Determine if admin is a Super/Full Administrator.
     */
    public static function isSuperAdmin(int $adminId): bool
    {
        $roleId = self::getAdminRoleId($adminId);
        if ($roleId === 1) {
            return true;
        }
        $roleName = strtolower(self::getAdminRoleName($adminId));
        return (strpos($roleName, 'admin') !== false || strpos($roleName, 'super') !== false || strpos($roleName, 'owner') !== false);
    }

    /**
     * Generate safe default permissions for a role based on role name heuristics.
     */
    public static function getDefaultPermissionsForRole(int $roleId, string $roleName = ''): array
    {
        $isSuper = ($roleId === 1 || stripos($roleName, 'admin') !== false || stripos($roleName, 'owner') !== false);
        $isSupport = (stripos($roleName, 'support') !== false || stripos($roleName, 'tech') !== false || stripos($roleName, 'engineer') !== false);
        $isSalesOrBilling = (stripos($roleName, 'sale') !== false || stripos($roleName, 'bill') !== false || stripos($roleName, 'account') !== false);

        if ($isSuper) {
            return [
                self::PERM_TELEMETRY_VIEW   => true,
                self::PERM_SERVER_ACCESS    => true,
                self::PERM_TICKET_AI        => true,
                self::PERM_REWRITE_REPLY    => true,
                self::PERM_SUMMARIZER       => true,
                self::PERM_HISTORICAL_CTX   => true,
                self::PERM_CANNED_KB        => true,
                self::PERM_TOOLS_EXECUTE    => true,
                self::PERM_INCIDENTS_MANAGE => true,
                self::PERM_KNOWLEDGE_MANAGE => true,
                self::PERM_ANALYTICS_VIEW   => true,
                self::PERM_AUDIT_MANAGE     => true,
                self::PERM_SETTINGS_MANAGE  => true,
            ];
        }

        if ($isSupport) {
            return [
                self::PERM_TELEMETRY_VIEW   => true,
                self::PERM_SERVER_ACCESS    => true,
                self::PERM_TICKET_AI        => true,
                self::PERM_REWRITE_REPLY    => true,
                self::PERM_SUMMARIZER       => true,
                self::PERM_HISTORICAL_CTX   => true,
                self::PERM_CANNED_KB        => true,
                self::PERM_TOOLS_EXECUTE    => true,
                self::PERM_INCIDENTS_MANAGE => true,
                self::PERM_KNOWLEDGE_MANAGE => false,
                self::PERM_ANALYTICS_VIEW   => false,
                self::PERM_AUDIT_MANAGE     => false,
                self::PERM_SETTINGS_MANAGE  => false,
            ];
        }

        if ($isSalesOrBilling) {
            // Strict security: Sales & Billing operators have NO server telemetry, NO server access, NO tools execution
            return [
                self::PERM_TELEMETRY_VIEW   => false,
                self::PERM_SERVER_ACCESS    => false,
                self::PERM_TICKET_AI        => true,
                self::PERM_REWRITE_REPLY    => true,
                self::PERM_SUMMARIZER       => true,
                self::PERM_HISTORICAL_CTX   => true,
                self::PERM_CANNED_KB        => true,
                self::PERM_TOOLS_EXECUTE    => false,
                self::PERM_INCIDENTS_MANAGE => false,
                self::PERM_KNOWLEDGE_MANAGE => false,
                self::PERM_ANALYTICS_VIEW   => false,
                self::PERM_AUDIT_MANAGE     => false,
                self::PERM_SETTINGS_MANAGE  => false,
            ];
        }

        // Generic custom role defaults
        return [
            self::PERM_TELEMETRY_VIEW   => false,
            self::PERM_SERVER_ACCESS    => false,
            self::PERM_TICKET_AI        => true,
            self::PERM_REWRITE_REPLY    => true,
            self::PERM_SUMMARIZER       => false,
            self::PERM_HISTORICAL_CTX   => false,
            self::PERM_CANNED_KB        => true,
            self::PERM_TOOLS_EXECUTE    => false,
            self::PERM_INCIDENTS_MANAGE => false,
            self::PERM_KNOWLEDGE_MANAGE => false,
            self::PERM_ANALYTICS_VIEW   => false,
            self::PERM_AUDIT_MANAGE     => false,
            self::PERM_SETTINGS_MANAGE  => false,
        ];
    }

    /**
     * Get stored permissions for a given role ID.
     */
    public static function getRolePermissions(int $roleId): array
    {
        self::ensureSchema();
        $roleName = '';
        try {
            $roleName = (string) (Capsule::table('tbladminroles')->where('id', $roleId)->value('name') ?: '');
        } catch (\Throwable $e) {}

        $defaults = self::getDefaultPermissionsForRole($roleId, $roleName);

        try {
            $row = Capsule::table('tblsahdev_role_permissions')->where('role_id', $roleId)->first();
            if ($row && !empty($row->permissions_json)) {
                $saved = json_decode($row->permissions_json, true);
                if (is_array($saved)) {
                    return array_merge($defaults, $saved);
                }
            }
        } catch (\Throwable $e) {}

        return $defaults;
    }

    /**
     * Save permissions for a given role ID.
     */
    public static function saveRolePermissions(int $roleId, array $permissions): bool
    {
        self::ensureSchema();
        if ($roleId <= 0) {
            return false;
        }

        try {
            $now = \Carbon\Carbon::now();
            Capsule::table('tblsahdev_role_permissions')->updateOrInsert(
                ['role_id' => $roleId],
                [
                    'permissions_json' => json_encode($permissions),
                    'updated_at'       => $now,
                ]
            );

            // Invalidate cache
            self::$permissionCache = [];
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a specific admin user has permission for a feature.
     *
     * @param int $adminId WHMCS Admin ID
     * @param string $permissionKey One of PermissionService::PERM_*
     * @return bool True if authorized, False otherwise
     */
    public static function hasPermission(int $adminId, string $permissionKey): bool
    {
        if ($adminId <= 0) {
            return false;
        }

        $cacheKey = "{$adminId}:{$permissionKey}";
        if (isset(self::$permissionCache[$cacheKey])) {
            return self::$permissionCache[$cacheKey];
        }

        $roleId = self::getAdminRoleId($adminId);
        if ($roleId <= 0) {
            self::$permissionCache[$cacheKey] = false;
            return false;
        }

        // Full Super Admin always retains settings_manage
        if ($roleId === 1 && $permissionKey === self::PERM_SETTINGS_MANAGE) {
            self::$permissionCache[$cacheKey] = true;
            return true;
        }

        $rolePerms = self::getRolePermissions($roleId);
        $allowed = !empty($rolePerms[$permissionKey]);

        // Global master switch override (if org disabled telemetry completely, deny telemetry)
        if ($permissionKey === self::PERM_TELEMETRY_VIEW || $permissionKey === self::PERM_SERVER_ACCESS) {
            try {
                $settings = Capsule::table('tblsahdev_settings')->where('id', 1)->first();
                if ($settings && isset($settings->telemetry_enabled) && (int) $settings->telemetry_enabled === 0) {
                    $allowed = false;
                }
            } catch (\Throwable $e) {}
        }

        self::$permissionCache[$cacheKey] = $allowed;
        return $allowed;
    }

    /**
     * Enforce permission in controllers/endpoints; throws exception if denied.
     */
    public static function enforce(int $adminId, string $permissionKey): void
    {
        if (!self::hasPermission($adminId, $permissionKey)) {
            throw new \Exception("Access Denied: Your admin role does not have permission for '" . htmlspecialchars($permissionKey) . "'.");
        }
    }
}
