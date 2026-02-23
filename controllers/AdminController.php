<?php

namespace Sahdev\Controllers;

use WHMCS\Database\Capsule;

class AdminController
{
    protected $moduleVars;

    public function __construct($vars)
    {
        $this->moduleVars = $vars;
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
        <style>
            .sahdev-container {
                max-width: 900px;
                padding: 20px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .sahdev-nav {
                margin-bottom: 20px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }

            .sahdev-nav a {
                margin-right: 15px;
                font-weight: 600;
                text-decoration: none;
                padding: 5px 10px;
                border-radius: 4px;
            }

            .sahdev-nav a.active {
                background: #0d6efd;
                color: white;
            }

            .sahdev-nav a:not(.active) {
                color: #0d6efd;
                background: #f8f9fa;
            }
        </style>

        <div class="sahdev-container">

            <div class="sahdev-nav">
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>" class="active">General Settings</a>
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&action=providers">AI Providers
                    Manager</a>
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&action=knowledgebase">Knowledgebase
                    Engine</a>
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&action=prompt_manager">Prompt Manager 🔬</a>
            </div>

            <h2 style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Sahdev AI Intelligence -
                Settings</h2>

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
                            When <strong>ON</strong>: Sahdev will silently fetch the AI-generated <strong>Summary, Root Cause, Responsibility, Risk Level, and Action Plan</strong> as soon as a ticket page opens — before the admin clicks any button. Results appear in a dedicated "AI Snapshot" panel below the main Sahdev panel.<br>
                            When <strong>OFF</strong>: Normal behaviour — analysis only runs when the admin clicks "Analyze &amp; Generate Reply".<br>
                            <em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Note: This uses one AI call per ticket page load. Uses cache when available so repeat views are free.</em>
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
            .sahdev-container {
                max-width: 1000px;
                padding: 20px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .sahdev-nav {
                margin-bottom: 20px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }

            .sahdev-nav a {
                margin-right: 15px;
                font-weight: 600;
                text-decoration: none;
                padding: 5px 10px;
                border-radius: 4px;
            }

            .sahdev-nav a.active {
                background: #0d6efd;
                color: white;
            }

            .sahdev-nav a:not(.active) {
                color: #0d6efd;
                background: #f8f9fa;
            }

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

        <div class="sahdev-container">
            <div class="sahdev-nav">
                <a href="<?php echo $settingsUrl; ?>">General Settings</a>
                <a href="<?php echo $actionUrl; ?>" class="active">AI Providers Manager</a>
                <a href="<?php echo $kbUrl; ?>">Knowledgebase Engine</a>
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&action=prompt_manager">Prompt Manager 🔬</a>
            </div>

            <h2 style="margin-bottom: 10px;">AI Providers Manager</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create and manage connections to various LLM APIs (OpenAI, Local
                LM Studio, Ollama, Google GenAI). You can assign these as Primary or Fallback in General Settings.</p>

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
            .sahdev-container {
                max-width: 1000px;
                padding: 20px;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }

            .sahdev-nav {
                margin-bottom: 20px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }

            .sahdev-nav a {
                margin-right: 15px;
                font-weight: 600;
                text-decoration: none;
                padding: 5px 10px;
                border-radius: 4px;
            }

            .sahdev-nav a.active {
                background: #0d6efd;
                color: white;
            }

            .sahdev-nav a:not(.active) {
                color: #0d6efd;
                background: #f8f9fa;
            }

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

        <div class="sahdev-container">
            <div class="sahdev-nav">
                <a href="<?php echo $settingsUrl; ?>">General Settings</a>
                <a href="<?php echo $actionUrl; ?>" class="active">Knowledgebase Engine Rules</a>
            </div>

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
            .sahdev-container { max-width: 1100px; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
            .sahdev-nav { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            .sahdev-nav a { margin-right: 15px; font-weight: 600; text-decoration: none; padding: 5px 10px; border-radius: 4px; }
            .sahdev-nav a.active { background: #0d6efd; color: white; }
            .sahdev-nav a:not(.active) { color: #0d6efd; background: #f8f9fa; }
            .prompt-template-area { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 13px; background: #1e1e2e; color: #cdd6f4; border: 1px solid #444; border-radius: 6px; }
            .placeholder-tag { display: inline-block; background: #313244; color: #89dceb; padding: 2px 7px; border-radius: 4px; font-size: 12px; font-family: monospace; margin: 2px; }
        </style>
        <div class="sahdev-container">
            <div class="sahdev-nav">
                <a href="<?php echo $settingsUrl; ?>">General Settings</a>
                <a href="<?php echo $settingsUrl; ?>&action=providers">AI Providers Manager</a>
                <a href="<?php echo $settingsUrl; ?>&action=knowledgebase">Knowledgebase Engine</a>
                <a href="<?php echo $actionUrl; ?>" class="active">Prompt Manager 🔬</a>
            </div>

            <h2 style="margin-bottom:5px;">🔬 Prompt Manager</h2>
            <p class="text-muted" style="margin-bottom:20px;">Edit the exact <strong>user-prompt template</strong> sent to the AI on every ticket analysis. The <strong>System Prompt</strong> (AI persona / identity) is still managed in <a href="<?php echo $settingsUrl; ?>">General Settings</a>.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-bottom:20px;">
                <strong>Available Placeholders:</strong><br>
                <span class="placeholder-tag">{{CUSTOM_INSTRUCTION_BLOCK}}</span> Auto-injected admin instruction (supreme priority) — keep at the very top<br>
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
                    <small class="text-muted">This is the full prompt body sent to the AI (not the system persona). Placeholders are replaced with live ticket data at runtime.</small>
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="submit" name="prompt_action" value="reset" class="btn btn-default"
                        onclick="return confirm('Reset the prompt template to the built-in default?');">
                        <i class="fas fa-undo"></i> Reset to Default
                    </button>
                    <button type="submit" name="prompt_action" value="save" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Prompt Template
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

