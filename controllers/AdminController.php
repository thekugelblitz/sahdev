<?php

namespace Sahdev\Controllers;

use WHMCS\Database\Capsule;

class AdminController
{
    protected $moduleVars;

    public function __construct($vars)
    {
        $this->moduleVars = $vars;
        $this->ensureSchemaIntegrity();
    }

    /**
     * Hot-fixes schema for users updating the module without deactivating/reactivating.
     */
    private function ensureSchemaIntegrity()
    {
        // 1. Ensure Summaries Table Exists
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

        // 2. Ensure Audit Trail Table Exists with corrected schema
        try {
            Capsule::table('tblsahdev_audit_trail')->first();
        } catch (\Exception $e) {
            $errMessage = $e->getMessage();
            if (strpos($errMessage, 'Base table or view not found') !== false || strpos($errMessage, 'doesn\'t exist') !== false) {
                Capsule::schema()->create('tblsahdev_audit_trail', function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->integer('admin_id')->unsigned()->index();
                    $table->string('action_type', 64)->nullable();
                    $table->string('provider_used', 64)->nullable();
                    $table->longText('prompt_text')->nullable();
                    $table->longText('response_text')->nullable();
                    $table->integer('tokens_used')->nullable();
                    $table->integer('execution_time_ms')->nullable();
                    $table->timestamp('created_at')->useCurrent();
                });
            }
        }

        // 3. Ensure Quality Scores Table Exists
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
        
        // Ensure execution_time_ms exists in case of an older schema
        try {
            Capsule::table('tblsahdev_audit_trail')->select('execution_time_ms')->first();
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                Capsule::schema()->table('tblsahdev_audit_trail', function ($table) {
                    $table->integer('execution_time_ms')->nullable();
                });
                
                // Safe raw-SQL renames for WHMCS environments lacking Doctrine/DBAL
                try {
                    Capsule::statement("ALTER TABLE tblsahdev_audit_trail CHANGE `action` `action_type` VARCHAR(64) NULL");
                    Capsule::statement("ALTER TABLE tblsahdev_audit_trail CHANGE `prompt` `prompt_text` LONGTEXT NULL");
                    Capsule::statement("ALTER TABLE tblsahdev_audit_trail CHANGE `response` `response_text` LONGTEXT NULL");
                } catch (\Exception $renameEx) {
                    // Ignore if already renamed or other constraint
                }
            }
        }
    }

    /**
     * Generates a standardized, beautifully styled navigation menu for the Admin UI.
     * 
     * @param string $activeTab The key of the currently active tab.
     * @return string
     */
    private function getNavigationMarkup(string $activeTab = 'settings'): string
    {
        $base = htmlspecialchars($this->moduleVars['modulelink']);
        $tabs = [
            'settings' => ['label' => '<i class="fas fa-cog"></i> General Settings', 'url' => $base],
            'providers' => ['label' => '<i class="fas fa-microchip"></i> AI Providers', 'url' => $base . '&action=providers'],
            'knowledgebase' => ['label' => '<i class="fas fa-book"></i> Knowledgebase', 'url' => $base . '&action=knowledgebase'],
            'prompt_manager' => ['label' => '<i class="fas fa-magic"></i> Prompt Manager', 'url' => $base . '&action=prompt_manager'],
            'summaries' => ['label' => '<i class="fas fa-file-alt"></i> Ticket Summaries', 'url' => $base . '&action=summaries'],
            'canned_responses' => ['label' => '<i class="fas fa-save"></i> Canned Responses', 'url' => $base . '&action=canned_responses'],
            'analytics' => ['label' => '<i class="fas fa-chart-line"></i> Analytics', 'url' => $base . '&action=analytics'],
            'audit_trail' => ['label' => '<i class="fas fa-history"></i> Audit Trail', 'url' => $base . '&action=audit_trail'],
        ];

        $html = '<style>
            .sahdev-nav-wrapper { 
                background: #fff; 
                padding: 15px 20px; 
                border-radius: 8px; 
                box-shadow: 0 2px 8px rgba(0,0,0,0.04); 
                margin-bottom: 25px; 
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                border-left: 4px solid #0d6efd;
            }
            .sahdev-nav-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 500;
                color: #495057;
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 6px;
                text-decoration: none !important;
                transition: all 0.2s ease-in-out;
            }
            .sahdev-nav-btn:hover {
                background: #e9ecef;
                color: #0d6efd;
                transform: translateY(-1px);
            }
            .sahdev-nav-btn.active {
                background: #0d6efd;
                color: #fff;
                border-color: #0d6efd;
                box-shadow: 0 4px 10px rgba(13, 110, 253, 0.2);
            }
            .sahdev-nav-btn i { font-size: 13px; }
            .sahdev-page-container {
                max-width: 1100px;
                padding: 25px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            }
        </style>';

        $html .= '<div class="sahdev-nav-wrapper">';
        foreach ($tabs as $key => $tab) {
            $activeClass = ($key === $activeTab) ? ' active' : '';
            $html .= sprintf('<a href="%s" class="sahdev-nav-btn%s">%s</a>', $tab['url'], $activeClass, $tab['label']);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Default view for the Addon settings.
     *
     * @return string
     */
    public function settings()
    {
        // Inline migration: ensure auto_analyze_on_load column exists on older installs
        try {
            Capsule::table('tblsahdev_settings')->select('auto_analyze_on_load')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('auto_analyze_on_load')->default(0);
            });
        }
        
        // Inline migration: ensure summarizer columns exist
        try {
            Capsule::table('tblsahdev_settings')->select('summarizer_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('summarizer_enabled')->default(0);
                $table->integer('summarizer_threshold')->default(15);
            });
        }
        
        // Inline migration: ensure compliance_mode column exists
        try {
            Capsule::table('tblsahdev_settings')->select('compliance_mode')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('compliance_mode')->default(0);
                $table->boolean('scrub_emails')->default(1);
                $table->boolean('scrub_cc')->default(1);
                $table->boolean('scrub_ips')->default(1);
                $table->boolean('scrub_passwords')->default(1);
            });
        }

        // Granular columns inline check for existing installs
        try {
            Capsule::table('tblsahdev_settings')->select('scrub_emails')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('scrub_emails')->default(1);
                $table->boolean('scrub_cc')->default(1);
                $table->boolean('scrub_ips')->default(1);
                $table->boolean('scrub_passwords')->default(1);
            });
        }

        // Inline migration: ensure quality_scorer_enabled column exists
        try {
            Capsule::table('tblsahdev_settings')->select('quality_scorer_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('quality_scorer_enabled')->default(1);
            });
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
            check_token("WHMCS.admin.default"); // Verify CSRF

            $primaryProviderId = (int) ($_POST['primary_provider_id'] ?? 1);
            $fallbackProviderId = (int) ($_POST['fallback_provider_id'] ?? 0);
            $temperature = (float) ($_POST['temperature'] ?? 0.70);
            $maxTokens = (int) ($_POST['max_tokens'] ?? 2048);
            $toneDefault = $_POST['tone_default'] ?? 'Professional';
            $systemPrompt = $_POST['system_prompt'] ?? '';
            $autoAnalyzeOnLoad = !empty($_POST['auto_analyze_on_load']) ? 1 : 0;
            
            $summarizerEnabled = !empty($_POST['summarizer_enabled']) ? 1 : 0;
            $summarizerThreshold = (int) ($_POST['summarizer_threshold'] ?? 15);
            if ($summarizerThreshold < 3) $summarizerThreshold = 3; 
            
            $complianceMode = !empty($_POST['compliance_mode']) ? 1 : 0;
            $scrubEmails = !empty($_POST['scrub_emails']) ? 1 : 0;
            $scrubCc = !empty($_POST['scrub_cc']) ? 1 : 0;
            $scrubIps = !empty($_POST['scrub_ips']) ? 1 : 0;
            $scrubPasswords = !empty($_POST['scrub_passwords']) ? 1 : 0;
            $qualityScorerEnabled = !empty($_POST['quality_scorer_enabled']) ? 1 : 0;

            // Ensure valid bounds
            if ($temperature < 0 || $temperature > 1) {
                $temperature = 0.70;
            }

            Capsule::table('tblsahdev_settings')->updateOrInsert(
                ['id' => 1],
                [
                    'primary_provider_id' => $primaryProviderId ?: null,
                    'fallback_provider_id' => $fallbackProviderId ?: null,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'tone_default' => $toneDefault,
                    'system_prompt' => $systemPrompt,
                    'auto_analyze_on_load' => $autoAnalyzeOnLoad,
                    'summarizer_enabled' => $summarizerEnabled,
                    'summarizer_threshold' => $summarizerThreshold,
                    'compliance_mode' => $complianceMode,
                    'scrub_emails' => $scrubEmails,
                    'scrub_cc' => $scrubCc,
                    'scrub_ips' => $scrubIps,
                    'scrub_passwords' => $scrubPasswords,
                    'quality_scorer_enabled' => $qualityScorerEnabled,
                    'updated_at' => \Carbon\Carbon::now(),
                ]
            );

            $successMessage = "Settings saved successfully.";
        }

        // Fetch current settings
        $settings = Capsule::table('tblsahdev_settings')->first();
        if (!$settings) {
            $settings = (object) [
                'primary_provider_id' => 1,
                'fallback_provider_id' => null,
                'temperature' => 0.70,
                'max_tokens' => 2048,
                'tone_default' => 'Professional',
                'system_prompt' => '',
                'auto_analyze_on_load' => 0,
                'summarizer_enabled' => 0,
                'summarizer_threshold' => 15,
                'compliance_mode' => 0,
                'scrub_emails' => 1,
                'scrub_cc' => 1,
                'scrub_ips' => 1,
                'scrub_passwords' => 1,
                'quality_scorer_enabled' => 1,
            ];
        }

        // Fetch all active providers
        $providers = Capsule::table('tblsahdev_providers')->where('is_active', 1)->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        

        <?php echo $this->getNavigationMarkup('settings'); ?>
        <div class="sahdev-page-container">

            <h2 style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Sahdev AI Intelligence - Settings</h2>

            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <input type="hidden" name="save_settings" value="1">

                <div class="row" style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Primary AI Provider 🌟</label>
                        <select name="primary_provider_id" class="form-control">
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider->id; ?>" <?php echo ($settings->primary_provider_id == $provider->id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($provider->name . ' (' . ucfirst($provider->provider_type) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">The main AI model used for ticket analysis.</small>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Fallback AI Provider 🛡️
                            (Optional)</label>
                        <select name="fallback_provider_id" class="form-control">
                            <option value="0">-- None (Don't use fallback) --</option>
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider->id; ?>" <?php echo ($settings->fallback_provider_id == $provider->id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($provider->name . ' (' . ucfirst($provider->provider_type) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Used automatically if the Primary AI fails to respond or is offline.</small>
                    </div>
                </div>

                <div class="row" style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Temperature</label>
                        <input type="number" step="0.01" min="0" max="1" name="temperature" class="form-control"
                            value="<?php echo htmlspecialchars($settings->temperature); ?>">
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Max Tokens</label>
                        <input type="number" step="1" min="1" name="max_tokens" class="form-control"
                            value="<?php echo htmlspecialchars($settings->max_tokens); ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">Default Tone</label>
                    <select name="tone_default" class="form-control" style="width: 100%; max-width: 300px;">
                        <?php
                        $tones = ['Professional', 'Technical', 'Friendly', 'Strict', 'Custom'];
                        foreach ($tones as $tone) {
                            $selected = ($settings->tone_default === $tone) ? 'selected' : '';
                            echo "<option value=\"$tone\" $selected>$tone</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">System Prompt</label>
                    <textarea name="system_prompt" class="form-control" rows="6"
                        style="width: 100%; resize: vertical;"><?php echo htmlspecialchars($settings->system_prompt); ?></textarea>
                    <small class="text-muted">Instructions for the AI on how to interpret support tickets and format its
                        response.</small>
                </div>

                <!-- AI Snapshot Feature Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #0d6efd;">
                    <div class="panel-heading" style="background: #f0f5ff;">
                        <h4 style="margin: 0; font-size: 15px;"><i class="fas fa-bolt"></i> AI Snapshot on Page Load <span class="label label-primary" style="font-size: 11px; vertical-align: middle; margin-left: 6px;">Feature Toggle</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="auto_analyze_on_load" value="1" <?php echo !empty($settings->auto_analyze_on_load) ? 'checked' : ''; ?>>
                                &nbsp;Enable automatic AI analysis when a ticket page loads
                            </label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0;">
                            When <strong>ON</strong>: Sahdev will silently fetch the AI-generated <strong>Summary, Root Cause, Responsibility, Risk Level, and Action Plan</strong> as soon as a ticket page opens.<br>
                            <em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Note: This uses one AI call per ticket page load. Uses cache when available so repeat views are free.</em>
                        </p>
                    </div>
                </div>

                <!-- AI Summarizer Feature Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #6f42c1;">
                    <div class="panel-heading" style="background: #fdfbff;">
                        <h4 style="margin: 0; font-size: 15px; color:#4b2d8a;"><i class="fas fa-compress-alt"></i> Adaptive Ticket Summarizer <span class="label label-success" style="font-size: 11px; vertical-align: middle; margin-left: 6px; background:#6f42c1;">Saves Tokens</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="summarizer_enabled" value="1" <?php echo !empty($settings->summarizer_enabled) ? 'checked' : ''; ?>>
                                &nbsp;Enable Adaptive Ticket Summarizer
                            </label>
                        </div>
                        <div style="margin-top: 10px; display:flex; align-items:center; gap:10px;">
                            <label style="margin:0; font-weight:600;">Message Threshold:</label>
                            <input type="number" name="summarizer_threshold" value="<?php echo htmlspecialchars($settings->summarizer_threshold ?? 15); ?>" class="form-control" style="width: 80px; display:inline-block;" min="3">
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When a ticket reaches the threshold number of replies, the AI will use the generated summary of the conversation instead of the full raw message history. This drastically reduces input token usage for long tickets. Admins can manage these in the ticket sidebar.
                        </p>
                    </div>
                </div>

                <!-- Response Quality Scorer Feature Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #fd7e14;">
                    <div class="panel-heading" style="background: #fff8f3;">
                        <h4 style="margin: 0; font-size: 15px; color:#d35400;"><i class="fas fa-bullseye"></i> AI Response Quality Scorer <span class="label label-success" style="font-size: 11px; vertical-align: middle; margin-left: 6px; background:#fd7e14;">New</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="quality_scorer_enabled" value="1" <?php echo !empty($settings->quality_scorer_enabled) ? 'checked' : ''; ?>>
                                &nbsp;Enable Auto-Scoring for AI Generated Replies
                            </label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When enabled, Sahdev will automatically evaluate the clarity, tone, and completeness of its own generated replies and display a confidence score badge in the ticket panel in a single combined AI call. Manual draft scoring remains available regardless of this setting.
                        </p>
                    </div>
                </div>

                <!-- Compliance Mode Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #198754;">
                    <div class="panel-heading" style="background: #f8fff9;">
                        <h4 style="margin: 0; font-size: 15px; color:#146c43;"><i class="fas fa-user-shield"></i> Compliance Mode (PII Scrubber) <span class="label label-success" style="font-size: 11px; vertical-align: middle; margin-left: 6px; background:#198754;">Privacy</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="compliance_mode" value="1" <?php echo !empty($settings->compliance_mode) ? 'checked' : ''; ?>>
                                &nbsp;Enable PII Scrubber
                            </label>
                        </div>
                        <div style="margin-top: 10px; display:flex; gap:15px; flex-wrap: wrap;">
                            <label><input type="checkbox" name="scrub_emails" value="1" <?php echo (!isset($settings->scrub_emails) || !empty($settings->scrub_emails)) ? 'checked' : ''; ?>> <i class="fas fa-envelope text-muted"></i> Emails</label>
                            <label><input type="checkbox" name="scrub_cc" value="1" <?php echo (!isset($settings->scrub_cc) || !empty($settings->scrub_cc)) ? 'checked' : ''; ?>> <i class="fas fa-credit-card text-muted"></i> Credit Cards</label>
                            <label><input type="checkbox" name="scrub_ips" value="1" <?php echo (!isset($settings->scrub_ips) || !empty($settings->scrub_ips)) ? 'checked' : ''; ?>> <i class="fas fa-network-wired text-muted"></i> IPv4</label>
                            <label><input type="checkbox" name="scrub_passwords" value="1" <?php echo (!isset($settings->scrub_passwords) || !empty($settings->scrub_passwords)) ? 'checked' : ''; ?>> <i class="fas fa-key text-muted"></i> Passwords</label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When enabled, Sahdev will automatically use regex to strip out selected sensitive information before sending the ticket context to the AI model. Essential context structure remains intact.
                        </p>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600;">
                    <i class="fas fa-save" style="margin-right: 5px;"></i> Save General Settings
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AI Providers Manager View
     *
     * @return string
     */
    public function providers()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['provider_action'] ?? '';

            if ($action === 'create' || $action === 'update') {
                $id = (int) ($_POST['provider_id'] ?? 0);
                $name = trim($_POST['provider_name'] ?? '');
                $type = $_POST['provider_type'] ?? 'lmstudio';
                $apiKey = $_POST['api_key'] ?? '';
                $apiUrl = $_POST['api_url'] ?? '';
                $modelName = $_POST['model_name'] ?? '';

                if (empty($name)) {
                    $errorMessage = "Provider name is required.";
                } else {
                    $data = [
                        'name' => $name,
                        'provider_type' => $type,
                        'api_url' => $apiUrl,
                        'model_name' => $modelName,
                        'updated_at' => \Carbon\Carbon::now(),
                    ];

                    if (!empty($apiKey)) {
                        $data['api_key'] = encrypt($apiKey);
                    }

                    if ($action === 'create') {
                        $data['created_at'] = \Carbon\Carbon::now();
                        Capsule::table('tblsahdev_providers')->insert($data);
                        $successMessage = "Provider created successfully.";
                    } else {
                        Capsule::table('tblsahdev_providers')->where('id', $id)->update($data);
                        $successMessage = "Provider updated successfully.";
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int) $_POST['provider_id'];

                // Check if it's currently assigned
                $inUse = Capsule::table('tblsahdev_settings')
                    ->where('primary_provider_id', $id)
                    ->orWhere('fallback_provider_id', $id)
                    ->exists();

                if ($inUse) {
                    $errorMessage = "Cannot delete provider while it is assigned as Primary or Fallback in General Settings.";
                } else {
                    Capsule::table('tblsahdev_providers')->where('id', $id)->delete();
                    $successMessage = "Provider deleted.";
                }
            }
        }

        $providers = Capsule::table('tblsahdev_providers')->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=providers';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);
        $kbUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=knowledgebase';

        ob_start();
        ?>
        <style>

            .provider-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 15px;
                background: #fafafa;
            }

            .provider-header {
                margin-bottom: 15px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
        </style>

        <?php echo $this->getNavigationMarkup('providers'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom: 10px;">AI Providers Manager</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create and manage connections to various LLM APIs (OpenAI, Local
                LM Studio, Ollama, Google GenAI, Replicate). You can assign these as Primary or Fallback in General Settings.
            </p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New Form -->
            <div class="provider-card" style="border-left: 4px solid #198754; background: #f8fff9;">
                <div class="provider-header">
                    <h4 style="margin:0;"><i class="fas fa-plus-circle"></i> Add New AI Provider</h4>
                </div>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="provider_action" value="create">

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-6">
                            <label>Display Name</label>
                            <input type="text" name="provider_name" class="form-control" placeholder="e.g. My Secure Local AI"
                                required>
                        </div>
                        <div class="col-md-6">
                            <label>API Format Type</label>
                            <select name="provider_type" class="form-control">
                                <option value="lmstudio">OpenAI Compatible (LM Studio / Ollama / OpenAI)</option>
                                <option value="google">Google GenAI (Gemini)</option>
                                <option value="replicate">Replicate</option>
                            </select>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-12 mb-2">
                            <label>API Key</label>
                            <input type="password" name="api_key" class="form-control"
                                placeholder="Leave blank if local without auth">
                        </div>
                        <div class="col-md-12 mb-2">
                            <label>API URL Endpoint</label>
                            <input type="text" name="api_url" class="form-control"
                                placeholder="e.g. http://localhost:1234/v1/chat/completions">
                        </div>
                        <div class="col-md-12 mb-2">
                            <label>Model Name</label>
                            <input type="text" name="model_name" class="form-control"
                                placeholder="e.g. gpt-4, gemma-7b, models/gemini-pro">
                        </div>
                    </div>

                    <div style="text-align: right;">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Add Provider</button>
                    </div>
                </form>
            </div>

            <hr style="margin: 30px 0;">
            <h4 style="margin-bottom: 15px;">Existing Providers</h4>

            <?php foreach ($providers as $p): ?>
                <div class="provider-card">
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="provider_id" value="<?php echo $p->id; ?>">

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-6">
                                <label>Display Name</label>
                                <input type="text" name="provider_name" class="form-control"
                                    value="<?php echo htmlspecialchars($p->name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>API Format Type</label>
                                <select name="provider_type" class="form-control">
                                    <option value="lmstudio" <?php echo ($p->provider_type == 'lmstudio') ? 'selected' : ''; ?>>OpenAI
                                        Compatible</option>
                                    <option value="google" <?php echo ($p->provider_type == 'google') ? 'selected' : ''; ?>>Google
                                        GenAI</option>
                                    <option value="replicate" <?php echo ($p->provider_type == 'replicate') ? 'selected' : ''; ?>>
                                        Replicate</option>
                                </select>
                            </div>
                        </div>

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-12 mb-2">
                                <label>API Key</label>
                                <input type="password" name="api_key" class="form-control"
                                    placeholder="Hidden. Enter a new key to update.">
                            </div>
                            <div class="col-md-12 mb-2">
                                <label>API URL Endpoint</label>
                                <input type="text" name="api_url" class="form-control"
                                    value="<?php echo htmlspecialchars($p->api_url ?? ''); ?>">
                            </div>
                            <div class="col-md-12 mb-2">
                                <label>Model Name</label>
                                <input type="text" name="model_name" class="form-control"
                                    value="<?php echo htmlspecialchars($p->model_name ?? ''); ?>">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                            <button type="submit" name="provider_action" value="delete" class="btn btn-sm btn-danger"
                                onclick="return confirm('WARNING: Are you sure you want to delete this provider?');"><i
                                    class="fas fa-trash"></i> Delete</button>
                            <button type="submit" name="provider_action" value="update" class="btn btn-sm btn-primary"><i
                                    class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Knowledgebase & Rules Manager View
     *
     * @return string
     */
    public function knowledgebase()
    {
        $kbPath = dirname(__DIR__) . '/knowledgebase';
        if (!is_dir($kbPath)) {
            mkdir($kbPath, 0755, true);
        }

        $successMessage = '';
        $errorMessage = '';

        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['kb_action'] ?? '';
            $filename = $_POST['filename'] ?? '';
            $content = $_POST['file_content'] ?? '';

            // Basic sanitization
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);

            if (!empty($action) && !empty($filename)) {
                $filePath = $kbPath . '/' . $filename . '.txt';

                if ($action === 'save') {
                    if (file_put_contents($filePath, $content) !== false) {
                        $successMessage = "File '$filename.txt' saved successfully.";
                    } else {
                        $errorMessage = "Failed to save file. Check directory permissions.";
                    }
                } elseif ($action === 'delete') {
                    if (file_exists($filePath) && unlink($filePath)) {
                        $successMessage = "File '$filename.txt' deleted successfully.";
                    } else {
                        $errorMessage = "Failed to delete file.";
                    }
                }
            } else {
                if ($action === 'save')
                    $errorMessage = "Filename is required.";
            }
        }

        // List files
        $files = [];
        $dir = new \DirectoryIterator($kbPath);
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->getExtension() === 'txt') {
                $files[$fileinfo->getFilename()] = file_get_contents($fileinfo->getPathname());
            }
        }
        ksort($files);

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=knowledgebase';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>

            .kb-file-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 15px;
                background: #fafafa;
            }

            .kb-file-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }
        </style>

        <?php echo $this->getNavigationMarkup('knowledgebase'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom: 10px;">Knowledgebase & AI Rules</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create text files below containing context, rules, and facts you
                want Sahdev AI to always know about when replying to users. It reads all <code>.txt</code> files here
                automatically.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New File Form -->
            <div class="kb-file-card" style="border-left: 4px solid #198754; background: #f8fff9;">
                <h4 style="margin-top:0;"><i class="fas fa-plus-circle"></i> Create New Rule / File</h4>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="kb_action" value="save">
                    <div class="row">
                        <div class="col-md-3">
                            <label>Filename (No spaces, no extension)</label>
                            <input type="text" name="filename" class="form-control" placeholder="e.g. migration_rules" required
                                pattern="[a-zA-Z0-9_-]+">
                        </div>
                        <div class="col-md-9">
                            <label>File Content (Rules, Context, Fact Sheet)</label>
                            <textarea name="file_content" class="form-control" rows="4" required
                                placeholder="Enter instructions like 'If a user asks about migration, tell them it costs $50...'"></textarea>
                        </div>
                    </div>
                    <div style="margin-top: 10px; text-align: right;">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Save File</button>
                    </div>
                </form>
            </div>

            <hr style="margin: 30px 0;">

            <!-- List Existing Files -->
            <h4 style="margin-bottom: 15px;">Existing Knowledgebase Files</h4>
            <?php if (empty($files)): ?>
                <div class="alert alert-info">No knowledgebase files found. Create one above!</div>
            <?php else: ?>
                <?php foreach ($files as $fullname => $content): ?>
                    <?php $basename = pathinfo($fullname, PATHINFO_FILENAME); ?>
                    <div class="kb-file-card">
                        <form method="post" action="<?php echo $actionUrl; ?>">
                            <?php echo $csrfToken; ?>
                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($basename); ?>">

                            <div class="kb-file-header">
                                <strong><i class="far fa-file-alt"></i> <?php echo htmlspecialchars($fullname); ?></strong>
                                <div>
                                    <button type="submit" name="kb_action" value="save" class="btn btn-xs btn-primary"><i
                                            class="fas fa-save"></i> Update</button>
                                    <button type="submit" name="kb_action" value="delete" class="btn btn-xs btn-danger"
                                        onclick="return confirm('Delete <?php echo htmlspecialchars($fullname); ?>?');"><i
                                            class="fas fa-trash"></i> Delete</button>
                                </div>
                            </div>
                            <textarea name="file_content" class="form-control"
                                rows="4"><?php echo htmlspecialchars($content); ?></textarea>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
    /**
     * Prompt Manager View — allows admins to edit the user prompt template
     * sent to the AI on every ticket analysis.
     *
     * @return string
     */
    public function prompt_manager()
    {
        $defaultTemplate = "{{CUSTOM_INSTRUCTION_BLOCK}}=== TASK ===\nAnalyze the provided technical support ticket and output ONLY a valid JSON object matching the schema below. No extra text.\n\n=== SCHEMA ===\n{\n  \"ROOT_CAUSE\": \"string (brief technical analysis)\",\n  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n  \"CLIENT_REPLY\": \"string (reply to client in Markdown — body only, no greeting or sign-off)\"\n}\n\n=== TONE ===\nWrite CLIENT_REPLY in a {{TONE}} tone.\n\n=== TICKET DATA ===\nClient: {{CLIENT_NAME}}\nDepartment: {{DEPARTMENT}}\nSubject: {{SUBJECT}}\n{{SERVICES_BLOCK}}\n=== CONVERSATION ===\n{{MESSAGES}}\n{{ATTACHMENTS_BLOCK}}";

        $settings = Capsule::table('tblsahdev_settings')->first();
        $currentTemplate = $settings->user_prompt_template ?? $defaultTemplate;

        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['prompt_action'] ?? '';

            if ($action === 'save') {
                $newTemplate = $_POST['user_prompt_template'] ?? '';
                Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                    'user_prompt_template' => $newTemplate,
                    'updated_at' => \Carbon\Carbon::now(),
                ]);
                $currentTemplate = $newTemplate;
                $successMessage = 'Prompt template saved successfully.';
            } elseif ($action === 'reset') {
                Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                    'user_prompt_template' => $defaultTemplate,
                    'updated_at' => \Carbon\Carbon::now(),
                ]);
                $currentTemplate = $defaultTemplate;
                $successMessage = 'Prompt template reset to default.';
            }
        }

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=prompt_manager';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>

            .prompt-template-area {
                font-family: 'SFMono-Regular', Consolas, monospace;
                font-size: 13px;
                background: #1e1e2e;
                color: #cdd6f4;
                border: 1px solid #444;
                border-radius: 6px;
            }

            .placeholder-tag {
                display: inline-block;
                background: #313244;
                color: #89dceb;
                padding: 2px 7px;
                border-radius: 4px;
                font-size: 12px;
                font-family: monospace;
                margin: 2px;
            }
        </style>
        <?php echo $this->getNavigationMarkup('prompt_manager'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom:5px;">🔬 Prompt Manager</h2>
            <p class="text-muted" style="margin-bottom:20px;">Edit the exact <strong>user-prompt template</strong> sent to the
                AI on every ticket analysis. The <strong>System Prompt</strong> (AI persona / identity) is still managed in <a
                    href="<?php echo $settingsUrl; ?>">General Settings</a>.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-bottom:20px;">
                <strong>Available Placeholders:</strong><br>
                <span class="placeholder-tag">{{CUSTOM_INSTRUCTION_BLOCK}}</span> Auto-injected admin instruction (supreme
                priority) — keep at the very top<br>
                <span class="placeholder-tag">{{TONE}}</span> Tone selected in ticket panel &nbsp;
                <span class="placeholder-tag">{{CLIENT_NAME}}</span> &nbsp;
                <span class="placeholder-tag">{{DEPARTMENT}}</span> &nbsp;
                <span class="placeholder-tag">{{SUBJECT}}</span><br>
                <span class="placeholder-tag">{{SERVICES_BLOCK}}</span> Client active services &nbsp;
                <span class="placeholder-tag">{{MESSAGES}}</span> Full conversation history &nbsp;
                <span class="placeholder-tag">{{ATTACHMENTS_BLOCK}}</span>
            </div>

            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="font-weight:600; margin-bottom:5px; display:block;">User Prompt Template</label>
                    <textarea name="user_prompt_template" class="form-control prompt-template-area" rows="28"
                        style="width:100%; resize:vertical;"><?php echo htmlspecialchars($currentTemplate); ?></textarea>
                    <small class="text-muted">This is the full prompt body sent to the AI (not the system persona). Placeholders
                        are replaced with live ticket data at runtime.</small>
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="submit" name="prompt_action" value="reset" class="btn btn-default"
                        onclick="return confirm('Reset the prompt template to the built-in default?');">
                        <i class="fas fa-undo"></i> Reset to Default
                    </button>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Ticket Summaries Manager View
     */
    public function summaries()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['summary_action'] ?? '';

            if ($action === 'delete') {
                $id = (int) $_POST['summary_id'];
                Capsule::table('tblsahdev_summaries')->where('id', $id)->delete();
                $successMessage = "Summary deleted successfully.";
            }
        }

        // Fetch summaries with ticket and admin info
        $summaries = Capsule::table('tblsahdev_summaries')
            ->leftJoin('tbltickets', 'tblsahdev_summaries.ticket_id', '=', 'tbltickets.id')
            ->leftJoin('tbladmins', 'tblsahdev_summaries.admin_id', '=', 'tbladmins.id')
            ->select(
                'tblsahdev_summaries.*',
                'tbltickets.tid as ticket_mask',
                'tbltickets.title as ticket_title',
                'tbladmins.username as admin_username'
            )
            ->orderBy('tblsahdev_summaries.id', 'desc')
            ->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=summaries';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        
        <?php echo $this->getNavigationMarkup('summaries'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom:5px;">✨ Adaptive Ticket Summaries</h2>
            <p class="text-muted" style="margin-bottom:20px;">Manage AI-generated conversation summaries. These summaries replace the full message history in AI prompts for long tickets, saving massive amounts of input tokens.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            
            <table class="table table-striped table-bordered text-center" style="font-size:13px;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th width="80" class="text-center">ID</th>
                        <th width="120" class="text-center">Ticket</th>
                        <th>AI Condensed Summary</th>
                        <th width="150" class="text-center">Generated By</th>
                        <th width="150" class="text-center">Date Date</th>
                        <th width="100" class="text-center">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($summaries->isEmpty()): ?>
                        <tr><td colspan="6" class="text-muted py-4">No AI summaries have been generated yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($summaries as $s): ?>
                            <tr>
                                <td><?php echo $s->id; ?></td>
                                <td>
                                    <a href="supporttickets.php?action=view&id=<?php echo $s->ticket_id; ?>" target="_blank">
                                        #<?php echo htmlspecialchars($s->ticket_mask ?? $s->ticket_id); ?>
                                    </a>
                                </td>
                                <td class="text-left">
                                    <div style="max-height:80px; overflow-y:auto; font-size:12px; white-space:pre-wrap; color:#333;"><?php echo htmlspecialchars($s->summary); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($s->admin_username ?? 'System'); ?></td>
                                <td><span class="text-muted" style="font-size:11px;"><?php echo $s->updated_at; ?></span></td>
                                <td>
                                    <form method="post" action="<?php echo $actionUrl; ?>" style="display:inline;">
                                        <?php echo $csrfToken; ?>
                                        <input type="hidden" name="summary_action" value="delete">
                                        <input type="hidden" name="summary_id" value="<?php echo $s->id; ?>">
                                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Delete this summary?');"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AI Audit Trail Manager View
     */
    public function audit_trail()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['audit_action'] ?? '';

            if ($action === 'delete_range') {
                $days = (int) $_POST['delete_days'];
                if ($days > 0) {
                    $cutoffDate = \Carbon\Carbon::now()->subDays($days);
                    $deleted = Capsule::table('tblsahdev_audit_trail')
                        ->where('created_at', '<', $cutoffDate)
                        ->delete();
                    $successMessage = "Successfully deleted {$deleted} audit log entries older than {$days} days.";
                }
            } elseif ($action === 'delete_single') {
                $id = (int) $_POST['log_id'];
                Capsule::table('tblsahdev_audit_trail')->where('id', $id)->delete();
                $successMessage = "Audit log entry deleted.";
            }
        }

        // Fetch recent audit logs
        $logs = Capsule::table('tblsahdev_audit_trail')
            ->leftJoin('tbltickets', 'tblsahdev_audit_trail.ticket_id', '=', 'tbltickets.id')
            ->leftJoin('tbladmins', 'tblsahdev_audit_trail.admin_id', '=', 'tbladmins.id')
            ->select(
                'tblsahdev_audit_trail.*',
                'tbltickets.tid as ticket_mask',
                'tbltickets.title as ticket_title',
                'tbladmins.username as admin_username'
            )
            ->orderBy('tblsahdev_audit_trail.id', 'desc')
            ->limit(500) // Hard limit to prevent memory exhaustion, should implement real pagination later
            ->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=audit_trail';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>
            .audit-code-block { background: #1e1e2e; color: #cdd6f4; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin: 0; }
        </style>
        <?php echo $this->getNavigationMarkup('audit_trail'); ?>
        <div class="sahdev-page-container">

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <div>
                    <h2 style="margin-bottom:5px; margin-top:0;">🕵️ AI Audit Trail</h2>
                    <p class="text-muted" style="margin-bottom:0px;">Complete record of what is sent to and received from the AI models. Showing last 500 entries.</p>
                </div>
                <div style="background:#f8d7da; padding:10px 15px; border-radius:6px; border:1px solid #f5c6cb;">
                    <form method="post" action="<?php echo $actionUrl; ?>" class="form-inline" style="margin:0;" onsubmit="return confirm('WARNING: This will permanently delete old audit logs. Proceed?');">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="audit_action" value="delete_range">
                        <label style="color:#721c24; margin-right:10px; font-weight:600;"><i class="fas fa-trash-alt"></i> Auto-Prune Logs:</label>
                        <select name="delete_days" class="form-control input-sm" style="margin-right:10px;">
                            <option value="7">Older than 7 days</option>
                            <option value="15">Older than 15 days</option>
                            <option value="30" selected>Older than 30 days</option>
                            <option value="90">Older than 90 days</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-danger">Prune Now</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            
            <table class="table table-bordered table-striped" style="font-size:12px; table-layout: fixed;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th width="80" class="text-center">ID / Date</th>
                        <th width="120" class="text-center">Context</th>
                        <th width="35%">Raw Prompt Sent to AI</th>
                        <th width="35%">Raw AI Response</th>
                        <th width="120" class="text-center">Action / Metrics</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs->isEmpty()): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No audit trail logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-center">
                                    <strong>#<?php echo $log->id; ?></strong><br>
                                    <span class="text-muted" style="font-size:10px;"><?php echo $log->created_at; ?></span>
                                </td>
                                <td>
                                    <?php if ($log->ticket_id): ?>
                                        <a href="supporttickets.php?action=view&id=<?php echo $log->ticket_id; ?>" target="_blank" style="font-weight:600;">
                                            Ticket #<?php echo htmlspecialchars($log->ticket_mask ?? $log->ticket_id); ?>
                                        </a><br>
                                    <?php else: ?>
                                        <span class="text-muted">No Ticket</span><br>
                                    <?php endif; ?>
                                    <span style="font-size:11px;">Admin: <?php echo htmlspecialchars($log->admin_username ?? 'System'); ?></span>
                                </td>
                                <td>
                                    <pre class="audit-code-block"><?php echo htmlspecialchars($log->prompt_text ?? ''); ?></pre>
                                </td>
                                <td>
                                    <pre class="audit-code-block" style="background:#1e3a2e;"><?php echo htmlspecialchars($log->response_text ?? ''); ?></pre>
                                </td>
                                <td class="text-center">
                                    <span class="label label-primary" style="display:block; margin-bottom:4px;"><?php echo strtoupper($log->action_type); ?></span>
                                    <span class="label label-info" style="display:block; margin-bottom:4px;"><?php echo htmlspecialchars($log->provider_used ?? 'Unknown'); ?></span>
                                    <div style="font-size:10px; margin-top:6px; color:#555;">
                                        Tokens: <?php echo number_format($log->tokens_used); ?><br>
                                        Time: <?php echo number_format($log->execution_time_ms); ?>ms
                                    </div>
                                    <form method="post" action="<?php echo $actionUrl; ?>" style="margin-top:8px;">
                                        <?php echo $csrfToken; ?>
                                        <input type="hidden" name="audit_action" value="delete_single">
                                        <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                        <button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Delete this specific log entry?');"><i class="fas fa-trash text-danger"></i> Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Canned Responses Manager View
     *
     * @return string
     */
    public function canned_responses()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['canned_action'] ?? '';

            if ($action === 'create' || $action === 'update') {
                $id = (int) ($_POST['canned_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $templateText = trim($_POST['template_text'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $tags = trim($_POST['tags'] ?? '');
                $adminId = $_SESSION['adminid'] ?? 1;

                if (empty($title) || empty($templateText)) {
                    $errorMessage = "Title and Template text are required.";
                } else {
                    $data = [
                        'title' => $title,
                        'template_text' => $templateText,
                        'category' => $category,
                        'tags' => $tags,
                        'updated_at' => \Carbon\Carbon::now(),
                    ];

                    if ($action === 'create') {
                        $data['admin_id'] = $adminId;
                        $data['created_at'] = \Carbon\Carbon::now();
                        Capsule::table('tblsahdev_canned_responses')->insert($data);
                        $successMessage = "Canned response created successfully.";
                    } else {
                        Capsule::table('tblsahdev_canned_responses')->where('id', $id)->update($data);
                        $successMessage = "Canned response updated successfully.";
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int) $_POST['canned_id'];
                Capsule::table('tblsahdev_canned_responses')->where('id', $id)->delete();
                $successMessage = "Canned response deleted.";
            }
        }

        $responses = Capsule::table('tblsahdev_canned_responses')
            ->orderBy('id', 'desc')
            ->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=canned_responses';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>
            .canned-card { border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-bottom: 15px; background: #fafafa; }
            .canned-header { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        </style>
        <?php echo $this->getNavigationMarkup('canned_responses'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom:10px;">💾 Canned Responses Manager</h2>
            <p class="text-muted" style="margin-bottom:25px;">Manage AI-generated or manual canned response templates. These are immediately searchable in the ticket view panel.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New Form -->
            <div class="canned-card" style="border-left: 4px solid #f0ad4e; background: #fffdfa;">
                <div class="canned-header">
                    <h4 style="margin:0;"><i class="fas fa-plus-circle"></i> Add New Response</h4>
                </div>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="canned_action" value="create">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label>Title</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g. Server Restart Instructions" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label>Category</label>
                                <input type="text" name="category" class="form-control" placeholder="e.g. Sales, Technical, Billing">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label>Tags (Comma separated)</label>
                                <input type="text" name="tags" class="form-control" placeholder="e.g. restart, down, emergency">
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label>Template Text / HTML</label>
                        <textarea name="template_text" class="form-control" rows="5" placeholder="Insert your generalized response here... HTML is allowed." required></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-save"></i> Save Template</button>
                    </div>
                </form>
            </div>

            <hr style="margin:30px 0;">
            <h4 style="margin-bottom:15px;">Existing Responses</h4>

            <?php foreach ($responses as $r): ?>
                <div class="canned-card">
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="canned_id" value="<?php echo $r->id; ?>">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-2">
                                    <label>Title</label>
                                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($r->title); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-2">
                                    <label>Category</label>
                                    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($r->category ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-2">
                                    <label>Tags (Comma separated)</label>
                                    <input type="text" name="tags" class="form-control" value="<?php echo htmlspecialchars($r->tags ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-2">
                            <label>Template Text / HTML</label>
                            <textarea name="template_text" class="form-control" rows="5" required><?php echo htmlspecialchars($r->template_text); ?></textarea>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                            <button type="submit" name="canned_action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Delete this canned response?');"><i class="fas fa-trash"></i> Delete</button>
                            <button type="submit" name="canned_action" value="update" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Dashboard view for AI Performance Analytics (Feature 8).
     *
     * @return string
     */
    public function analytics()
    {
        ob_start();
        
        // Fetch real analytics data
        $aiController = new \Sahdev\Lib\AIController(0, $_SESSION['adminid'] ?? 1);
        $analytics = $aiController->getAnalyticsData();
        
        $totalActions = $analytics['total_actions'] ?? 0;
        $totalTokens = $analytics['total_tokens'] ?? 0;
        $avgExecMs = $analytics['avg_exec_time'] ?? 0;
        
        $actionsByType = $analytics['action_breakdown'] ?? [];
        $scores = $analytics['average_scores'] ?? [];
        ?>
        <?php echo $this->getNavigationMarkup('analytics'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom:10px;">📊 AI Performance Analytics</h2>
            <p class="text-muted" style="margin-bottom:25px;">
                Review the performance, ROI, and quality metrics of your AI deployment.
            </p>

            <div class="row" style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div class="col-md-4" style="flex: 1; min-width: 250px;">
                    <div style="background: #eef2ff; border-left: 4px solid #4f46e5; padding: 20px; border-radius: 8px;">
                        <span style="display:block; font-size: 13px; font-weight: 600; color: #4338ca; text-transform: uppercase;">Total Actions</span>
                        <div style="font-size: 32px; font-weight: 700; color: #1e1b4b; margin-top: 5px;"><?php echo number_format($totalActions); ?></div>
                    </div>
                </div>
                
                <div class="col-md-4" style="flex: 1; min-width: 250px;">
                    <div style="background: #fdf4ff; border-left: 4px solid #c026d3; padding: 20px; border-radius: 8px;">
                        <span style="display:block; font-size: 13px; font-weight: 600; color: #a21caf; text-transform: uppercase;">Tokens Used</span>
                        <div style="font-size: 32px; font-weight: 700; color: #4a044e; margin-top: 5px;"><?php echo number_format($totalTokens); ?></div>
                    </div>
                </div>

                <div class="col-md-4" style="flex: 1; min-width: 250px;">
                    <div style="background: #f0fdf4; border-left: 4px solid #16a34a; padding: 20px; border-radius: 8px;">
                        <span style="display:block; font-size: 13px; font-weight: 600; color: #15803d; text-transform: uppercase;">Avg Generation Speed</span>
                        <div style="font-size: 32px; font-weight: 700; color: #14532d; margin-top: 5px;"><?php echo number_format($avgExecMs); ?> ms</div>
                    </div>
                </div>
            </div>

            <hr style="margin: 30px 0;">
            
            <h4 style="margin-bottom: 20px;">AI Action Breakdown</h4>
            <div style="display:flex; gap:10px; flex-wrap: wrap;">
                <?php foreach ($actionsByType as $type => $count): ?>
                    <span style="background: #f1f5f9; padding: 8px 16px; border-radius: 20px; font-weight: 500; color: #334155;">
                        <strong style="color: #0f172a; margin-right: 5px;"><?php echo ucwords(str_replace('_', ' ', $type)); ?>:</strong> <?php echo number_format($count); ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <hr style="margin: 30px 0;">

            <div class="row">
                <div class="col-md-6">
                    <h4 style="margin-bottom: 20px;">Quality Bar Chart</h4>
                    <?php foreach ($scores as $cat => $val): 
                        if ($cat == 'total_scored') continue;
                        $pct = ($val / 10) * 100;
                    ?>
                        <div style="margin-bottom: 15px;">
                            <div style="display:flex; justify-content: space-between; margin-bottom: 5px; font-size: 13px; font-weight: 600;">
                                <span><?php echo ucwords(str_replace('_', ' ', $cat)); ?></span>
                                <span><?php echo number_format($val, 1); ?>/10</span>
                            </div>
                            <div style="background: #e2e8f0; height: 10px; border-radius: 5px; overflow: hidden;">
                                <div style="background: <?php echo $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#f59e0b' : '#ef4444'); ?>; width: <?php echo $pct; ?>%; height: 100%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}