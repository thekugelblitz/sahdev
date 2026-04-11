<?php
/**
 * Sahdev - AI Ticket Intelligence Assistant
 * WHMCS Addon Module
 *
 * @package    Sahdev AI
 * @author     WHMCS Addon Developer
 * @copyright  Copyright (c) WHMCS Addon Developer 2026
 * @version    2.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Define addon module configuration parameters.
 *
 * @return array
 */
function sahdev_config()
{
    return [
        // Display name for your module
        'name' => 'Sahdev AI Intelligence',
        // Description displayed within the admin interface
        'description' => 'AI Ticket Intelligence Assistant for WHMCS Support. Provides smart analysis and reply generation using LLMs.',
        // Module author name
        'author' => 'Addon Developer',
        // Default language
        'language' => 'english',
        // Version number
        'version' => '2.0.0',
        'fields' => [
            // Settings are handled in a custom admin UI built in sahdev_output,
            // but we can define standard WHMCS module settings here if we want them rendered automatically.
            // Since requirements requested a custom UI page for settings, we leave this sparse
            // and rely on our custom table tblsahdev_settings to store core configurations.
            'access_control' => [
                'FriendlyName' => 'Access Control',
                'Type' => 'text',
                'Size' => '25',
                'Default' => 'Full Administrator',
                'Description' => 'Admin Role Group Name that can configure Settings.',
            ]
        ]
    ];
}

/**
 * Activate.
 *
 * Called upon activation of the module for the first time.
 *
 * @return array Optional success/failure message
 */
function sahdev_activate()
{
    // Default user prompt template used when no custom one is configured
    $defaultUserPromptTemplate = "{{CUSTOM_INSTRUCTION_BLOCK}}=== TASK ===\nAnalyze the provided technical support ticket and output ONLY a valid JSON object matching the schema below. No extra text.\n\n=== SCHEMA ===\n{\n  \"ROOT_CAUSE\": \"string (brief technical analysis)\",\n  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n  \"CLIENT_REPLY\": \"string (reply to client in Markdown — body only, no greeting or sign-off)\"\n}\n\n=== TONE ===\nWrite CLIENT_REPLY in a {{TONE}} tone.\n\n=== TICKET DATA ===\nClient: {{CLIENT_NAME}}\nDepartment: {{DEPARTMENT}}\nSubject: {{SUBJECT}}\n{{SERVICES_BLOCK}}\n=== CONVERSATION ===\n{{MESSAGES}}\n{{ATTACHMENTS_BLOCK}}";

    try {
        // Create tblsahdev_settings
        try {
            Capsule::table('tblsahdev_settings')->first();

            // Apply migration if activating over an older version
            try {
                Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->integer('primary_provider_id')->nullable();
                    $table->integer('fallback_provider_id')->nullable();
                });
            }

            // Migrate: add user_prompt_template column if missing
            try {
                Capsule::table('tblsahdev_settings')->select('user_prompt_template')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) use ($defaultUserPromptTemplate) {
                    $table->longText('user_prompt_template')->nullable();
                });
                Capsule::table('tblsahdev_settings')->where('id', 1)->update(['user_prompt_template' => $defaultUserPromptTemplate]);
            }

            // Migrate: add auto_analyze_on_load column if missing
            try {
                Capsule::table('tblsahdev_settings')->select('auto_analyze_on_load')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->boolean('auto_analyze_on_load')->default(0);
                });
            }

            // Migrate: add v2 settings columns if missing
            try {
                Capsule::table('tblsahdev_settings')->select('summarizer_enabled')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->boolean('summarizer_enabled')->default(1);
                    $table->integer('summarizer_threshold')->default(20);
                    $table->boolean('compliance_mode')->default(0);
                    $table->boolean('pii_scrub_enabled')->default(0);
                    $table->boolean('translation_enabled')->default(0);
                    $table->boolean('auto_sentiment')->default(0);
                    $table->boolean('auto_tagging')->default(0);
                    $table->boolean('quality_scorer_enabled')->default(1);
                });
            }

            // Migrate: add custom_attachments_dir if missing
            try {
                Capsule::table('tblsahdev_settings')->select('custom_attachments_dir')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->string('custom_attachments_dir', 255)->nullable();
                });
            }

            // Migrate: add v2 columns to tblsahdev_logs if missing
            try {
                Capsule::table('tblsahdev_logs')->select('provider_used')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_logs', function ($table) {
                    $table->string('provider_used', 32)->nullable();
                    $table->boolean('used_fallback')->default(0);
                    $table->boolean('is_cached')->default(0);
                });
            }

            // Migrate: add data extraction limit columns if missing
            try {
                Capsule::table('tblsahdev_settings')->select('max_messages')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->integer('max_messages')->default(10);
                    $table->integer('max_attachment_chars')->default(5000);
                    $table->integer('max_images')->default(3);
                });
            }

            // Migrate: tools execution settings if missing
            try {
                Capsule::table('tblsahdev_settings')->select('tools_execution_enabled')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_settings', function ($table) {
                    $table->boolean('tools_execution_enabled')->default(0);
                    $table->string('tools_api_base_url', 255)->default('https://toolsapi.2hs.in');
                    $table->string('tools_openapi_url', 2048)->default('https://toolsapi.2hs.in/openapi.json');
                    $table->text('tools_api_key_encrypted')->nullable();
                    $table->integer('tools_max_tools_per_ticket')->default(50);
                    $table->integer('tools_request_timeout_sec')->default(60);
                    $table->integer('tools_request_retry_count')->default(3);
                    $table->integer('tools_cron_max_per_run')->default(10);
                    $table->string('tools_cron_statuses', 512)->nullable();
                    $table->text('tools_filter_domains')->nullable();
                    $table->text('tools_filter_ips')->nullable();
                    $table->text('tools_filter_emails')->nullable();
                    $table->boolean('tools_normalize_enabled')->default(1);
                    $table->boolean('tools_include_raw_fallback')->default(1);
                    $table->timestamp('tools_cron_last_run_at')->nullable();
                    $table->string('tools_cron_last_message', 512)->nullable();
                    $table->timestamp('tools_execution_cron_lock_until')->nullable();
                });
            }
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_settings',
                function ($table) {
                    $table->increments('id');
                    $table->integer('primary_provider_id')->nullable();
                    $table->integer('fallback_provider_id')->nullable();
                    $table->decimal('temperature', 3, 2)->default(0.70);
                    $table->integer('max_tokens')->default(2048);
                    $table->integer('max_tokens_fallback')->default(4096);
                    $table->string('tone_default')->default('Professional');
                    $table->text('system_prompt')->nullable();
                    $table->longText('user_prompt_template')->nullable();
                    $table->boolean('auto_analyze_on_load')->default(0);
                    $table->boolean('summarizer_enabled')->default(1);
                    $table->integer('summarizer_threshold')->default(20);
                    $table->boolean('compliance_mode')->default(0);
                    $table->boolean('pii_scrub_enabled')->default(0);
                    $table->boolean('translation_enabled')->default(0);
                    $table->boolean('auto_sentiment')->default(0);
                    $table->boolean('auto_tagging')->default(0);
                    $table->boolean('quality_scorer_enabled')->default(1);
                    $table->string('custom_attachments_dir', 255)->nullable();
                    $table->integer('max_messages')->default(10);
                    $table->integer('max_attachment_chars')->default(5000);
                    $table->integer('max_images')->default(3);
                    $table->boolean('cron_insights_enabled')->default(1);
                    $table->integer('cron_insights_interval_hours')->default(6);
                    $table->integer('cron_insights_max_per_run')->default(20);
                    $table->string('cron_insights_statuses', 512)->nullable();
                    $table->timestamp('insights_cron_last_run_at')->nullable();
                    $table->integer('insights_cron_last_found')->unsigned()->default(0);
                    $table->integer('insights_cron_last_analyzed')->unsigned()->default(0);
                    $table->integer('insights_cron_last_skipped')->unsigned()->default(0);
                    $table->string('insights_cron_last_message', 512)->nullable();
                    $table->string('insights_cron_http_url', 2048)->nullable();
                    $table->text('insights_cron_cli_command')->nullable();
                    $table->boolean('tools_execution_enabled')->default(0);
                    $table->string('tools_api_base_url', 255)->default('https://toolsapi.2hs.in');
                    $table->string('tools_openapi_url', 2048)->default('https://toolsapi.2hs.in/openapi.json');
                    $table->text('tools_api_key_encrypted')->nullable();
                    $table->integer('tools_max_tools_per_ticket')->default(50);
                    $table->integer('tools_request_timeout_sec')->default(60);
                    $table->integer('tools_request_retry_count')->default(3);
                    $table->integer('tools_cron_max_per_run')->default(10);
                    $table->string('tools_cron_statuses', 512)->nullable();
                    $table->text('tools_filter_domains')->nullable();
                    $table->text('tools_filter_ips')->nullable();
                    $table->text('tools_filter_emails')->nullable();
                    $table->boolean('tools_normalize_enabled')->default(1);
                    $table->boolean('tools_include_raw_fallback')->default(1);
                    $table->timestamp('tools_cron_last_run_at')->nullable();
                    $table->string('tools_cron_last_message', 512)->nullable();
                    $table->timestamp('tools_execution_cron_lock_until')->nullable();
                    $table->timestamps(); // creates created_at, updated_at
                }
            );

            // Insert default setting record
            Capsule::table('tblsahdev_settings')->insert([
                'primary_provider_id' => 1,
                'temperature' => 0.70,
                'max_tokens' => 2048,
                'max_tokens_fallback' => 4096,
                'tone_default' => 'Professional',
                'system_prompt' => "You are Sahdev, a Senior Technical Support Specialist for a premium web hosting company. Your goal is to provide elite-level support that feels empathetic, technical, and human.\n\nCORE DIRECTIVES:\n1. EMPATHY: Acknowledge the user's frustration or urgency without sounding corporate or robotic.\n2. PRECISION: If a technical issue is identified, explain it clearly and provide actionable insights.\n3. NATURAL FLOW: Use natural transitions. Avoid excessive bullet points or robotic lists.\n4. TONE: Strictly adhere to the requested Tone setting.\n\nAlways analyze the full conversation history to ensure the reply fits the current context perfectly.\n\nOutput only a valid JSON object as requested.",
                'user_prompt_template' => $defaultUserPromptTemplate,
                'auto_analyze_on_load' => 0,
                'summarizer_enabled' => 1,
                'summarizer_threshold' => 20,
                'compliance_mode' => 0,
                'pii_scrub_enabled' => 0,
                'translation_enabled' => 0,
                'auto_sentiment' => 0,
                'max_messages' => 10,
                'max_attachment_chars' => 5000,
                'max_images' => 3,
                'cron_insights_enabled' => 1,
                'cron_insights_interval_hours' => 6,
                'cron_insights_max_per_run' => 20,
                'tools_execution_enabled' => 0,
                'tools_api_base_url' => 'https://toolsapi.2hs.in',
                'tools_openapi_url' => 'https://toolsapi.2hs.in/openapi.json',
                'tools_max_tools_per_ticket' => 50,
                'tools_request_timeout_sec' => 60,
                'tools_request_retry_count' => 3,
                'tools_cron_max_per_run' => 10,
                'tools_cron_statuses' => 'Customer-Reply, Awaiting Reply, Open',
                'tools_filter_domains' => '*.nslookup.io,*.whynopadlock.com,*.google.com,*.gstatic.com',
                'tools_filter_ips' => '',
                'tools_filter_emails' => '',
                'tools_normalize_enabled' => 1,
                'tools_include_raw_fallback' => 1,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }

        // Create tblsahdev_providers
        try {
            Capsule::table('tblsahdev_providers')->first();

            // Migrate: add cost columns to tblsahdev_providers if missing
            try {
                Capsule::table('tblsahdev_providers')->select('cost_input_1m')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_providers', function ($table) {
                    $table->decimal('cost_input_1m', 10, 4)->default(0.0000);
                    $table->decimal('cost_output_1m', 10, 4)->default(0.0000);
                });
            }
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_providers',
                function ($table) {
                    $table->increments('id');
                    $table->string('name');
                    $table->string('provider_type')->default('lmstudio'); // google or lmstudio
                    $table->text('api_key')->nullable()->comment('Encrypted API Key');
                    $table->string('api_url')->nullable();
                    $table->string('model_name')->nullable();
                    $table->boolean('is_active')->default(true);
                    $table->decimal('cost_input_1m', 10, 4)->default(0.0000);
                    $table->decimal('cost_output_1m', 10, 4)->default(0.0000);
                    $table->timestamps();
                }
            );

            // Insert default Google AI Provider
            Capsule::table('tblsahdev_providers')->insert([
                'name' => 'Default Google Gemini',
                'provider_type' => 'google',
                'api_key' => '', // Needs to be set via UI
                'api_url' => '',
                'model_name' => 'models/gemini-1.5-pro',
                'cost_input_1m' => 1.25,
                'cost_output_1m' => 5.00,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);

            Capsule::table('tblsahdev_providers')->insert([
                'name' => 'Default Local AI',
                'provider_type' => 'lmstudio',
                'api_key' => '',
                'api_url' => 'http://localhost:1234/v1/chat/completions',
                'model_name' => 'local-model',
                'cost_input_1m' => 0.00,
                'cost_output_1m' => 0.00,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }

        // Create tblsahdev_logs
        try {
            Capsule::table('tblsahdev_logs')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_logs',
                function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->integer('admin_id')->unsigned()->index();
                    $table->mediumText('request_payload')->nullable();
                    $table->mediumText('response_payload')->nullable();
                    $table->integer('token_usage')->nullable();
                    $table->integer('execution_time_ms')->nullable();
                    $table->string('provider_used', 32)->nullable();
                    $table->boolean('used_fallback')->default(0);
                    $table->boolean('is_cached')->default(0);
                    $table->timestamp('created_at')->useCurrent();
                }
            );
        }

        // Create tblsahdev_cache
        try {
            Capsule::table('tblsahdev_cache')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_cache',
                function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->string('hash_signature', 128)->index();
                    $table->mediumText('ai_response');
                    $table->timestamp('created_at')->useCurrent();
                }
            );
        }

        // Create tblsahdev_client_context_cache
        try {
            Capsule::table('tblsahdev_client_context_cache')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_client_context_cache',
                function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->integer('client_id')->unsigned()->index();
                    $table->longText('historical_context');
                    $table->timestamps();
                }
            );
        }

        // Create tblsahdev_rate_limit
        try {
            Capsule::table('tblsahdev_rate_limit')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_rate_limit',
                function ($table) {
                    $table->increments('id');
                    $table->integer('admin_id')->unsigned()->unique();
                    $table->integer('requests_count')->default(0);
                    $table->timestamp('last_request_at')->nullable();
                }
            );
        }

        // Create tblsahdev_summaries
        try {
            Capsule::table('tblsahdev_summaries')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_summaries', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('admin_id')->unsigned()->index();
                $table->mediumText('summary');
                $table->timestamps();
            });
        }

        // Create tblsahdev_quality_scores
        try {
            Capsule::table('tblsahdev_quality_scores')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_quality_scores', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('admin_id')->unsigned()->index();
                $table->tinyInteger('score')->unsigned()->default(0);
                $table->tinyInteger('clarity')->unsigned()->nullable();
                $table->tinyInteger('tone_score')->unsigned()->nullable();
                $table->tinyInteger('completeness')->unsigned()->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_ai_generated')->default(1);
                $table->timestamps();
            });
        }

        // Create tblsahdev_audit_trail
        try {
            Capsule::table('tblsahdev_audit_trail')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_audit_trail', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('admin_id')->unsigned()->index();
                $table->string('action', 64)->nullable()->comment('generate_analysis, rewrite_reply, generate_summary, etc.');
                $table->string('provider_used', 64)->nullable();
                $table->longText('prompt')->nullable();
                $table->longText('response')->nullable();
                $table->integer('tokens_used')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Create tblsahdev_canned_responses
        try {
            Capsule::table('tblsahdev_canned_responses')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_canned_responses', function ($table) {
                $table->increments('id');
                $table->integer('admin_id')->unsigned()->default(1);
                $table->string('title');
                $table->text('template_text');
                $table->string('tags')->nullable();
                $table->string('category')->nullable();
                $table->boolean('is_ai_generated')->default(1);
                $table->timestamps();
            });

            // Insert matching dummy templates
            Capsule::table('tblsahdev_canned_responses')->insert([
                [
                    'admin_id' => 1,
                    'title' => 'Server Migration Delay',
                    'template_text' => 'We sincerely apologize for the delay. The migration for [Domain] is currently in progress, but we encountered an unexpected issue with the database transfer. Our senior technicians are actively resolving this to ensure no data loss occurs. We anticipate completion within the next [Timeframe]. Thank you for your continued patience.',
                    'tags' => 'migration, delay, database',
                    'category' => 'Migrations',
                    'is_ai_generated' => 1,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now(),
                ],
                [
                    'admin_id' => 1,
                    'title' => 'SSL Certificate Provisioning',
                    'template_text' => 'We are pleased to inform you that the SSL certificate for [Domain] has been successfully provisioned and installed. You may need to clear your local browser cache or flush your DNS to see the immediate changes. Please let us know if you continue to experience any insecure warnings.',
                    'tags' => 'ssl, https, provisioning',
                    'category' => 'SSL/Security',
                    'is_ai_generated' => 1,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now(),
                ],
                [
                    'admin_id' => 1,
                    'title' => 'High Resource Usage Warning',
                    'template_text' => 'Our monitoring systems indicate that your hosting account for [Domain] has been frequently hitting its allocated resource limits (specifically [Resource Type]). This can cause slow loading times or intermittent 503 errors. We recommend reviewing your recent traffic logs or considering an upgrade to a VPS for dedicated resources.',
                    'tags' => 'abuse, resources, limits',
                    'category' => 'Abuse/Compliance',
                    'is_ai_generated' => 1,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now(),
                ]
            ]);
        }

        // Create tblsahdev_sentiment
        try {
            Capsule::table('tblsahdev_sentiment')->first();

            // Migrate: add cron insight columns if missing
            try {
                Capsule::table('tblsahdev_sentiment')->select('client_tone')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->string('client_tone', 64)->nullable();
                    $table->text('ticket_summary')->nullable();
                    $table->integer('admin_reply_count')->unsigned()->default(0);
                    $table->string('last_admin_name', 128)->nullable();
                    $table->timestamp('ticket_last_reply_at')->nullable();
                    $table->timestamp('analyzed_at')->nullable();
                });
            }
            // Migrate: add ticket_last_reply_at if upgrading from an older version
            try {
                Capsule::table('tblsahdev_sentiment')->select('ticket_last_reply_at')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->timestamp('ticket_last_reply_at')->nullable();
                });
            }
            // AI tags (mirrors WHMCS Tag Cloud + list display)
            try {
                Capsule::table('tblsahdev_sentiment')->select('ai_tags_json')->first();
            } catch (\Exception $e) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->text('ai_tags_json')->nullable()->comment('JSON array of ai-* tag slugs');
                });
            }
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_sentiment', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->unique()->index();
                $table->tinyInteger('score')->unsigned()->nullable()->comment('1-10 frustration score');
                $table->string('label', 32)->nullable()->comment('Frustrated, Neutral, Satisfied');
                $table->string('urgency', 16)->nullable()->comment('Low, Medium, High, Critical');
                $table->string('client_tone', 64)->nullable()->comment('Angry, Demanding, Neutral, Polite, etc.');
                $table->text('ticket_summary')->nullable()->comment('AI-generated ticket summary');
                $table->integer('admin_reply_count')->unsigned()->default(0);
                $table->string('last_admin_name', 128)->nullable();
                $table->timestamp('ticket_last_reply_at')->nullable()->comment('tbltickets.lastreply snapshot at analysis time');
                $table->timestamp('analyzed_at')->nullable()->comment('When cron last analyzed this ticket');
                $table->text('ai_tags_json')->nullable()->comment('JSON array of ai-* tag slugs (WHMCS Tag Cloud)');
                $table->timestamps();
            });
        }

        // Migrate: add cron insight settings columns if missing
        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('cron_insights_enabled')->default(1);
                $table->integer('cron_insights_interval_hours')->default(6);
                $table->integer('cron_insights_max_per_run')->default(20);
                $table->string('cron_insights_statuses', 512)->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_statuses')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('cron_insights_statuses', 512)->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('insights_cron_last_run_at')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->timestamp('insights_cron_last_run_at')->nullable();
                $table->integer('insights_cron_last_found')->unsigned()->default(0);
                $table->integer('insights_cron_last_analyzed')->unsigned()->default(0);
                $table->integer('insights_cron_last_skipped')->unsigned()->default(0);
                $table->string('insights_cron_last_message', 512)->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('insights_cron_http_url')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('insights_cron_http_url', 2048)->nullable();
                $table->text('insights_cron_cli_command')->nullable();
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('task_provider_map')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->longText('task_provider_map')->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('max_tokens_fallback')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->integer('max_tokens_fallback')->default(4096);
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('tools_openapi_url')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('tools_openapi_url', 2048)->default('https://toolsapi.2hs.in/openapi.json');
            });
        }

        // Migrate: tools execution settings if missing (upgrade-safe)
        try {
            Capsule::table('tblsahdev_settings')->select('tools_execution_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('tools_execution_enabled')->default(0);
                $table->string('tools_api_base_url', 255)->default('https://toolsapi.2hs.in');
                $table->string('tools_openapi_url', 2048)->default('https://toolsapi.2hs.in/openapi.json');
                $table->text('tools_api_key_encrypted')->nullable();
                $table->integer('tools_max_tools_per_ticket')->default(50);
                $table->integer('tools_request_timeout_sec')->default(60);
                $table->integer('tools_request_retry_count')->default(3);
                $table->integer('tools_cron_max_per_run')->default(10);
                $table->string('tools_cron_statuses', 512)->nullable();
                $table->text('tools_filter_domains')->nullable();
                $table->text('tools_filter_ips')->nullable();
                $table->text('tools_filter_emails')->nullable();
                $table->boolean('tools_normalize_enabled')->default(1);
                $table->boolean('tools_include_raw_fallback')->default(1);
                $table->timestamp('tools_cron_last_run_at')->nullable();
                $table->string('tools_cron_last_message', 512)->nullable();
                $table->timestamp('tools_execution_cron_lock_until')->nullable();
            });
        }

        // Create tool suggestion table
        try {
            Capsule::table('tblsahdev_tool_suggestions')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_tool_suggestions', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->timestamp('ticket_last_reply_at')->nullable();
                $table->string('status', 32)->default('suggested');
                $table->string('model_name', 255)->nullable();
                $table->longText('suggestions_json')->nullable();
                $table->longText('ai_prompt_excerpt')->nullable();
                $table->timestamps();
            });
        }

        // Create tool run table
        try {
            Capsule::table('tblsahdev_tool_runs')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_tool_runs', function ($table) {
                $table->increments('id');
                $table->integer('suggestion_id')->unsigned()->index();
                $table->integer('ticket_id')->unsigned()->index();
                $table->string('method', 16);
                $table->string('path', 1024);
                $table->longText('request_query_json')->nullable();
                $table->longText('request_body_json')->nullable();
                $table->string('status', 32)->default('ok');
                $table->integer('http_status')->nullable();
                $table->longText('response_body')->nullable();
                $table->longText('normalized_summary')->nullable();
                $table->longText('normalized_json')->nullable();
                $table->string('normalization_status', 32)->nullable();
                $table->text('normalization_error')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('tools_normalize_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('tools_normalize_enabled')->default(1);
                $table->boolean('tools_include_raw_fallback')->default(1);
            });
        }
        try {
            Capsule::table('tblsahdev_tool_runs')->select('normalized_summary')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_tool_runs', function ($table) {
                $table->longText('normalized_summary')->nullable();
                $table->longText('normalized_json')->nullable();
                $table->string('normalization_status', 32)->nullable();
                $table->text('normalization_error')->nullable();
            });
        }

        // Create tblsahdev_personas
        try {
            Capsule::table('tblsahdev_personas')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_personas', function ($table) {
                $table->increments('id');
                $table->string('name', 128);
                $table->text('system_prompt');
                $table->string('tone', 64)->default('Professional');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(1);
                $table->boolean('is_default')->default(0);
                $table->timestamps();
            });
        }

        // Create tblsahdev_admin_providers (per-admin provider overrides)
        try {
            Capsule::table('tblsahdev_admin_providers')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_admin_providers', function ($table) {
                $table->increments('id');
                $table->integer('admin_id')->unsigned()->unique();
                $table->integer('primary_provider_id')->nullable();
                $table->integer('fallback_provider_id')->nullable();
                $table->timestamps();
            });
        }

        // Per-admin preferences (features, default provider, tone) — JSON in preferences_json
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_admin_preferences')) {
                Capsule::schema()->create('tblsahdev_admin_preferences', function ($table) {
                    $table->integer('admin_id')->unsigned()->primary();
                    $table->longText('preferences_json')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Exception $e) {
            // Concurrent activation or "table already exists": continue if table is present
            if (!Capsule::schema()->hasTable('tblsahdev_admin_preferences')) {
                throw $e;
            }
        }

        // Create tblsahdev_intents
        try {
            Capsule::table('tblsahdev_intents')->first();

            // If it exists but is completely empty, seed it
            if (Capsule::table('tblsahdev_intents')->count() == 0) {
                throw new \Exception("Table empty, needs seeding");
            }
        } catch (\Exception $e) {
            if (!Capsule::schema()->hasTable('tblsahdev_intents')) {
                Capsule::schema()->create('tblsahdev_intents', function ($table) {
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                    $table->increments('id');
                    $table->string('intent_key', 32)->unique();
                    $table->string('label', 64);
                    $table->text('directive');
                    $table->boolean('is_active')->default(1);
                    $table->integer('sort_order')->default(0);
                    $table->timestamps();
                });
            }

            // Seed defaults
            Capsule::table('tblsahdev_intents')->insert([
                [
                    'intent_key' => 'AUTO',
                    'label'      => 'Auto (AI Decides)',
                    'directive'  => '', // Handled by AUTO fallback
                    'is_active'  => 1,
                    'sort_order' => 10,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'RESOLVE',
                    'label'      => 'Resolved Query',
                    'directive'  => 'REPLY INTENT — RESOLVED: The admin confirms this issue has been resolved. Write CLIENT_REPLY as a confident closing message. Acknowledge what was fixed, thank the client for their patience, and advise them to reopen the ticket if the issue recurs. Do NOT ask further questions.',
                    'is_active'  => 1,
                    'sort_order' => 20,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'INVESTIGATE',
                    'label'      => 'Checking Query',
                    'directive'  => 'REPLY INTENT — INVESTIGATING: The admin is still actively investigating this issue. Write CLIENT_REPLY to acknowledge the issue empathetically, confirm the support team is actively working on it, and set realistic expectations without making firm time commitments. Keep the client reassured.',
                    'is_active'  => 1,
                    'sort_order' => 30,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'MORE_INFO',
                    'label'      => 'Need More Info',
                    'directive'  => 'REPLY INTENT — NEED MORE INFORMATION: The admin needs additional details before proceeding. Write CLIENT_REPLY to clearly and politely list exactly what specific information, logs, screenshots, credentials, or steps are required from the client. Be precise — avoid vague requests.',
                    'is_active'  => 1,
                    'sort_order' => 40,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'GUIDE',
                    'label'      => 'Guide to Solution',
                    'directive'  => 'REPLY INTENT — GUIDE TO SOLUTION: The admin wants to guide the client to self-resolve. Write CLIENT_REPLY as a clear, step-by-step guide in simple language the client can follow independently. Use numbered steps. Anticipate likely stumbling points and address them proactively.',
                    'is_active'  => 1,
                    'sort_order' => 50,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'OUT_OF_SCOPE',
                    'label'      => 'Out of Scope',
                    'directive'  => 'REPLY INTENT — OUT OF SUPPORT SCOPE: This issue falls outside the support boundaries. Write CLIENT_REPLY to clearly but respectfully explain that this specific issue is not covered under the current support scope or plan. Where applicable, point to relevant resources, documentation, or upgrade options. Be firm yet courteous — avoid leaving the client feeling dismissed.',
                    'is_active'  => 1,
                    'sort_order' => 60,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'DUPLICATE',
                    'label'      => 'Duplicate Ticket',
                    'directive'  => 'REPLY INTENT — DUPLICATE TICKET: This is a duplicate of an existing ticket. Write CLIENT_REPLY to politely inform the client that this appears to be a duplicate of an existing ticket they have already submitted. Instruct them to continue communication on the original ticket to avoid confusion and ensure continuity of support. Close this ticket gracefully.',
                    'is_active'  => 1,
                    'sort_order' => 70,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
            ]);
        }

        return [
            // Supported values here include: success, error or info
            'status' => 'success',
            'description' => 'Sahdev AI Intelligence module activated successfully. Custom tables created.',
        ];
    } catch (\Exception $e) {
        return [
            // Supported values here include: success, error or info
            'status' => 'error',
            'description' => 'Unable to create sahdev database tables: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivate.
 *
 * Called upon deactivation of the module.
 *
 * @return array Optional success/failure message
 */
function sahdev_deactivate()
{
    // We typically do NOT drop tables on deactivate to prevent accidental data loss.
    // If you wish to implement an uninstall that drops tables, it should ideally be
    // done with caution or an explicit uninstall mechanism if WHMCS offered one (v8+ has some module queue but deactivate is the primary hook).
    // For safety, we leave the tables intact.

    return [
        'status' => 'success',
        'description' => 'Sahdev AI Intelligence module deactivated. Custom tables were preserved to prevent data loss.',
    ];
}

/**
 * Upgrade.
 *
 * Called the first time the module is accessed following an update.
 *
 * @param array $vars Module configuration parameters.
 * @return void
 */
function sahdev_upgrade($vars)
{
    $version = $vars['version'];

    // Ensure new tables exist after uploading files (without requiring re-activate)
    try {
        require_once __DIR__ . '/lib/AdminPreferences.php';
        \Sahdev\Lib\AdminPreferences::ensureSchema();
    } catch (\Throwable $e) {
        // Non-fatal; ticket/ajax/admin paths also run ensureSchema
    }
}

/**
 * Admin Area Output.
 *
 * Called when the WHMCS admin user accesses the module directly via
 * Addons > Sahdev.
 *
 * @param array $vars Module configuration parameters.
 * @return string
 */
function sahdev_output($vars)
{
    // Include custom dispatcher/controller routing if necessary
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'settings';

    // Basic routing
    try {
        // Handle AJAX requests via Native WHMCS Routing
        // Use a unique parameter 'sahdev_act' to avoid collision with WHMCS/Lagom 'action' param
        if (isset($_REQUEST['sahdev_act']) && $_REQUEST['sahdev_act'] === 'ajax_handler') {
            if (ob_get_length()) ob_clean();
            require_once __DIR__ . '/ajax.php';
            exit;
        }

        // Auto-migration for overwrites without reactivation
        try {
            \WHMCS\Database\Capsule::table('tblsahdev_providers')->first();
            \WHMCS\Database\Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
            \WHMCS\Database\Capsule::table('tblsahdev_canned_responses')->first();
        } catch (\Exception $e) {
            sahdev_activate();
        }

        require_once __DIR__ . '/controllers/AdminController.php';

        $controller = new \Sahdev\Controllers\AdminController($vars);

        if (method_exists($controller, $action)) {
            echo $controller->$action();
        } else {
            echo "Invalid action.";
        }
    } catch (\Exception $e) {
        echo "<div class=\"alert alert-danger\">An error occurred: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
