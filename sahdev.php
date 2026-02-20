<?php
/**
 * Sahdev - AI Ticket Intelligence Assistant
 * WHMCS Addon Module
 *
 * @package    Sahdev AI
 * @author     WHMCS Addon Developer
 * @copyright  Copyright (c) WHMCS Addon Developer 2026
 * @version    1.0.0
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
        'version' => '1.0.0',
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
    try {
        // Create tblsahdev_settings
        if (!Capsule::schema()->hasTable('tblsahdev_settings')) {
            Capsule::schema()->create(
                'tblsahdev_settings',
                function ($table) {
                    $table->increments('id');
                    $table->string('ai_provider')->default('google');
                    $table->text('api_key')->nullable()->comment('Encrypted API Key');
                    $table->string('model_name')->default('models/gemini-1.5-pro');
                    $table->decimal('temperature', 3, 2)->default(0.70);
                    $table->integer('max_tokens')->default(2048);
                    $table->string('tone_default')->default('Professional');
                    $table->text('system_prompt')->nullable();
                    $table->timestamps(); // creates created_at, updated_at
                }
            );

            // Insert default setting record
            Capsule::table('tblsahdev_settings')->insert([
                'ai_provider' => 'google',
                'temperature' => 0.70,
                'max_tokens' => 2048,
                'tone_default' => 'Professional',
                'system_prompt' => "You are Sahdev, an expert web hosting support engineer. You provide highly accurate and helpful solutions.\nAlways respond strictly with the JSON format requested.",
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }

        // Create tblsahdev_logs
        if (!Capsule::schema()->hasTable('tblsahdev_logs')) {
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
        if (!Capsule::schema()->hasTable('tblsahdev_cache')) {
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
        if (!Capsule::schema()->hasTable('tblsahdev_rate_limit')) {
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
        // We will delegate this to our controller logic, but for simplicity we can include the file
        // Or inline the controller instantiation.
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
