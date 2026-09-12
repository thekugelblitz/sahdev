<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/**
 * SchemaManager
 *
 * Manages database migrations and self-healing table definitions for Sahdev AI 3.x
 * including Chat Copilot, Ops Journal, Atomic Rollbacks, MetricsCube-style aggregations,
 * and OpenRouter/Chat AI Providers.
 */
class SchemaManager
{
    /**
     * Ensure all tables, columns, and indexes exist. Safe to run repeatedly.
     */
    public static function ensureAll(): void
    {
        self::ensureProviderColumns();
        self::ensureSettingsColumns();
        self::ensureChatSessionsTable();
        self::ensureChatMessagesTable();
        self::ensureOpsJournalTable();
        self::ensureMetricsTables();
        self::ensureVisitorTable();
    }

    /**
     * Extend tblsahdev_providers with purpose ('ticket', 'chat', 'both') and custom headers.
     */
    public static function ensureProviderColumns(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_providers')) {
                return;
            }

            if (!Capsule::schema()->hasColumn('tblsahdev_providers', 'purpose')) {
                Capsule::schema()->table('tblsahdev_providers', function ($table) {
                    $table->string('purpose', 32)->default('both')->after('provider_type');
                });
            }

            if (!Capsule::schema()->hasColumn('tblsahdev_providers', 'custom_headers')) {
                Capsule::schema()->table('tblsahdev_providers', function ($table) {
                    $table->text('custom_headers')->nullable()->after('model_name');
                });
            }

            if (!Capsule::schema()->hasColumn('tblsahdev_providers', 'context_window')) {
                Capsule::schema()->table('tblsahdev_providers', function ($table) {
                    $table->integer('context_window')->default(32768)->after('model_name');
                });
            }
        } catch (\Throwable $e) {
            // Ignore benign duplicate or existing column
        }
    }

    /**
     * Extend tblsahdev_settings with copilot and client chat configuration.
     */
    public static function ensureSettingsColumns(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_settings')) {
                return;
            }

            $columns = [
                // Admin Ops Copilot
                'copilot_enabled'              => ['type' => 'boolean', 'default' => 1],
                'copilot_primary_provider_id'  => ['type' => 'integer', 'default' => 0],
                'copilot_fallback_provider_id' => ['type' => 'integer', 'default' => 0],
                'copilot_system_prompt'        => ['type' => 'longtext'],
                'copilot_temperature'          => ['type' => 'decimal', 'precision' => 3, 'scale' => 2, 'default' => 0.70],
                'copilot_max_tokens'           => ['type' => 'integer', 'default' => 2048],
                'copilot_stream_enabled'       => ['type' => 'boolean', 'default' => 1],
                'copilot_shortcut_key'         => ['type' => 'string', 'length' => 32, 'default' => 'Ctrl+Space'],

                // Client Live Chat
                'client_chat_enabled'          => ['type' => 'boolean', 'default' => 0],
                'client_chat_provider_id'      => ['type' => 'integer', 'default' => 0],
                'client_chat_title'            => ['type' => 'string', 'length' => 128, 'default' => 'Hosting Support Assistant'],
                'client_chat_brand_color'      => ['type' => 'string', 'length' => 32, 'default' => '#0d6efd'],
                'client_chat_position'         => ['type' => 'string', 'length' => 32, 'default' => 'bottom-right'],
                'client_chat_welcome_message'  => ['type' => 'text'],
                'client_chat_require_prechat'  => ['type' => 'boolean', 'default' => 0],
                'client_chat_proactive_delay'  => ['type' => 'integer', 'default' => 15],
                'client_chat_kb_enabled'       => ['type' => 'boolean', 'default' => 1],
                'client_chat_system_prompt'    => ['type' => 'longtext'],

                // Safe Ops & Rollback Governance
                'ops_journal_retention_days'   => ['type' => 'integer', 'default' => 90],
                'ops_require_password_tier3'   => ['type' => 'boolean', 'default' => 1],
            ];

            foreach ($columns as $name => $spec) {
                if (!Capsule::schema()->hasColumn('tblsahdev_settings', $name)) {
                    Capsule::schema()->table('tblsahdev_settings', function ($table) use ($name, $spec) {
                        switch ($spec['type']) {
                            case 'boolean':
                                $table->boolean($name)->default($spec['default'] ?? 0);
                                break;
                            case 'integer':
                                $table->integer($name)->default($spec['default'] ?? 0);
                                break;
                            case 'decimal':
                                $table->decimal($name, $spec['precision'], $spec['scale'])->default($spec['default'] ?? 0.0);
                                break;
                            case 'text':
                                $table->text($name)->nullable();
                                break;
                            case 'longtext':
                                $table->longText($name)->nullable();
                                break;
                            case 'string':
                            default:
                                $table->string($name, $spec['length'] ?? 255)->default($spec['default'] ?? '');
                                break;
                        }
                    });
                }
            }
        } catch (\Throwable $e) {
            // Benign column migration
        }
    }

    /**
     * Chat Sessions: covers both Admin Ops Copilot threads and Visitor/Client Live Chats.
     */
    public static function ensureChatSessionsTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_chat_sessions')) {
                Capsule::schema()->create('tblsahdev_chat_sessions', function ($table) {
                    $table->increments('id');
                    $table->string('session_uuid', 64)->unique();
                    $table->string('session_type', 32)->index(); // admin_copilot, client_livechat
                    $table->integer('admin_id')->unsigned()->default(0)->index();
                    $table->integer('client_id')->unsigned()->default(0)->index();
                    $table->string('visitor_token', 64)->nullable()->index();
                    $table->string('status', 32)->default('active')->index(); // active, taken_over, escalated_ticket, closed
                    $table->string('title', 255)->nullable();
                    $table->integer('assigned_admin_id')->unsigned()->default(0)->index();
                    $table->longText('metadata_json')->nullable();
                    $table->timestamp('last_message_at')->nullable()->index();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Benign table creation
        }
    }

    /**
     * Chat Messages: stored conversation turn, action cards, tool calls.
     */
    public static function ensureChatMessagesTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_chat_messages')) {
                Capsule::schema()->create('tblsahdev_chat_messages', function ($table) {
                    $table->increments('id');
                    $table->integer('session_id')->unsigned()->index();
                    $table->string('sender_type', 32)->index(); // user, assistant, system, staff
                    $table->integer('sender_id')->unsigned()->default(0);
                    $table->string('sender_name', 128)->nullable();
                    $table->longText('message_text')->nullable();
                    $table->longText('action_card_json')->nullable();
                    $table->longText('tool_calls_json')->nullable();
                    $table->integer('tokens_used')->default(0);
                    $table->timestamp('created_at')->useCurrent()->index();
                });
            }
        } catch (\Throwable $e) {
            // Benign table creation
        }
    }

    /**
     * Ops Journal: Immutable 90-day execution log with pre-state snapshots and 1-click rollback.
     */
    public static function ensureOpsJournalTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_ops_journal')) {
                Capsule::schema()->create('tblsahdev_ops_journal', function ($table) {
                    $table->increments('id');
                    $table->integer('session_id')->unsigned()->default(0)->index();
                    $table->integer('admin_id')->unsigned()->index();
                    $table->string('action_key', 64)->index();
                    $table->string('target_entity_type', 64)->index(); // client, service, invoice, ticket, domain, server, system
                    $table->integer('target_entity_id')->unsigned()->default(0)->index();
                    $table->string('description', 255);
                    $table->longText('parameters_json')->nullable();
                    $table->longText('state_before_json')->nullable();
                    $table->longText('state_after_json')->nullable();
                    $table->longText('compensation_recipe_json')->nullable();
                    $table->tinyInteger('risk_tier')->unsigned()->default(2)->index(); // 1 = read, 2 = reversible, 3 = destructive
                    $table->string('status', 32)->default('executed')->index(); // executed, rolled_back, failed
                    $table->timestamp('executed_at')->useCurrent()->index();
                    $table->timestamp('rolled_back_at')->nullable()->index();
                    $table->integer('rollback_admin_id')->unsigned()->default(0);
                    $table->text('rollback_error')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Benign table creation
        }
    }

    /**
     * MetricsCube-Style Daily Rollups & AI Anomaly Tables.
     */
    public static function ensureMetricsTables(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_metrics_daily')) {
                Capsule::schema()->create('tblsahdev_metrics_daily', function ($table) {
                    $table->increments('id');
                    $table->date('metric_date')->index();
                    $table->string('metric_category', 32)->index(); // financial, clients, support, gateways, servers
                    $table->string('metric_key', 64)->index();
                    $table->decimal('metric_value', 14, 4)->default(0.0000);
                    $table->longText('metadata_json')->nullable();
                    $table->timestamps();
                    $table->unique(['metric_date', 'metric_category', 'metric_key'], 'metric_daily_unique');
                });
            }

            if (!Capsule::schema()->hasTable('tblsahdev_metrics_anomalies')) {
                Capsule::schema()->create('tblsahdev_metrics_anomalies', function ($table) {
                    $table->increments('id');
                    $table->timestamp('detected_at')->useCurrent()->index();
                    $table->string('category', 32)->index();
                    $table->string('severity', 16)->default('warning')->index(); // info, warning, critical
                    $table->string('title', 255);
                    $table->text('description');
                    $table->string('suggested_action_key', 64)->nullable();
                    $table->longText('suggested_action_payload')->nullable();
                    $table->string('status', 32)->default('active')->index(); // active, acknowledged, resolved, dismissed
                    $table->timestamp('resolved_at')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Benign table creation
        }
    }

    /**
     * Client Chat Visitors: presence heartbeat, page tracking, and proactive invitations.
     */
    public static function ensureVisitorTable(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_client_chat_visitors')) {
                Capsule::schema()->create('tblsahdev_client_chat_visitors', function ($table) {
                    $table->increments('id');
                    $table->string('visitor_token', 64)->unique();
                    $table->integer('client_id')->unsigned()->default(0)->index();
                    $table->string('visitor_name', 128)->nullable();
                    $table->string('visitor_email', 128)->nullable();
                    $table->string('ip_address', 45)->nullable();
                    $table->text('user_agent')->nullable();
                    $table->string('current_page', 512)->nullable();
                    $table->string('status', 32)->default('browsing')->index(); // browsing, chatting, invited
                    $table->timestamp('last_seen_at')->nullable()->index();
                    $table->timestamps();
                });
            }
        } catch (\Throwable $e) {
            // Benign table creation
        }
    }
}
