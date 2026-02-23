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
        } catch (\Exception $e) {
            Capsule::schema()->create(
                'tblsahdev_settings',
                function ($table) {
                    $table->increments('id');
                    $table->integer('primary_provider_id')->nullable();
                    $table->integer('fallback_provider_id')->nullable();
                    $table->decimal('temperature', 3, 2)->default(0.70);
                    $table->integer('max_tokens')->default(2048);
                    $table->string('tone_default')->default('Professional');
                    $table->text('system_prompt')->nullable();
                    $table->longText('user_prompt_template')->nullable();
                    $table->boolean('auto_analyze_on_load')->default(0);
                    $table->timestamps(); // creates created_at, updated_at
                }
            );

            // Insert default setting record
            Capsule::table('tblsahdev_settings')->insert([
                'primary_provider_id' => 1,
                'temperature' => 0.70,
                'max_tokens' => 2048,
                'tone_default' => 'Professional',
                'system_prompt' => "You are Sahdev, a Senior Technical Support Specialist for a premium web hosting company. Your goal is to provide elite-level support that feels empathetic, technical, and human.\n\nCORE DIRECTIVES:\n1. EMPATHY: Acknowledge the user's frustration or urgency without sounding corporate or robotic.\n2. PRECISION: If a technical issue is identified, explain it clearly and provide actionable insights.\n3. NATURAL FLOW: Use natural transitions. Avoid excessive bullet points or robotic lists.\n4. TONE: Strictly adhere to the requested Tone setting.\n\nAlways analyze the full conversation history to ensure the reply fits the current context perfectly.\n\nOutput only a valid JSON object as requested.",
                'user_prompt_template' => $defaultUserPromptTemplate,
                'auto_analyze_on_load' => 0,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }

        // Create tblsahdev_providers
        try {
            Capsule::table('tblsahdev_providers')->first();
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
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);

            // Insert default LM Studio Provider
            Capsule::table('tblsahdev_providers')->insert([
                'name' => 'Default Local AI',
                'provider_type' => 'lmstudio',
                'api_key' => '',
                'api_url' => 'http://localhost:1234/v1/chat/completions',
                'model_name' => 'local-model',
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

    // Run SQL updates for different versions...
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
