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
        // Dynamically add api_url field if it does not exist
        if (!Capsule::schema()->hasColumn('tblsahdev_settings', 'api_url')) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('api_url')->nullable()->after('api_key');
            });
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
            check_token("WHMCS.admin.default"); // Verify CSRF

            $aiProvider = $_POST['ai_provider'] ?? 'google';
            $apiKey = $_POST['api_key'] ?? '';
            $apiUrl = $_POST['api_url'] ?? '';
            $modelName = $_POST['model_name'] ?? 'models/gemini-1.5-pro';
            $temperature = (float) ($_POST['temperature'] ?? 0.70);
            $maxTokens = (int) ($_POST['max_tokens'] ?? 2048);
            $toneDefault = $_POST['tone_default'] ?? 'Professional';
            $systemPrompt = $_POST['system_prompt'] ?? '';

            // Ensure valid bounds
            if ($temperature < 0 || $temperature > 1) {
                $temperature = 0.70;
            }

            // Encrypt API Key if provided, else keep existing if empty string submitted (or handle how you prefer)
            if (!empty($apiKey)) {
                $encryptedApiKey = encrypt($apiKey);

                Capsule::table('tblsahdev_settings')->updateOrInsert(
                    ['id' => 1], // Always ID 1 since we only have one row of settings
                    [
                        'ai_provider' => $aiProvider,
                        'api_key' => $encryptedApiKey,
                        'api_url' => $apiUrl,
                        'model_name' => $modelName,
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                        'tone_default' => $toneDefault,
                        'system_prompt' => $systemPrompt,
                        'updated_at' => \Carbon\Carbon::now(),
                    ]
                );
            } else {
                // Update everything except API key
                Capsule::table('tblsahdev_settings')->updateOrInsert(
                    ['id' => 1],
                    [
                        'ai_provider' => $aiProvider,
                        'api_url' => $apiUrl,
                        'model_name' => $modelName,
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                        'tone_default' => $toneDefault,
                        'system_prompt' => $systemPrompt,
                        'updated_at' => \Carbon\Carbon::now(),
                    ]
                );
            }

            $successMessage = "Settings saved successfully.";
        }

        // Fetch current settings
        $settings = Capsule::table('tblsahdev_settings')->first();
        if (!$settings) {
            // Fallback object to avoid errors if the table wasn't seeded correctly
            $settings = (object) [
                'ai_provider' => 'google',
                'api_url' => '',
                'model_name' => 'models/gemini-1.5-pro',
                'temperature' => 0.70,
                'max_tokens' => 2048,
                'tone_default' => 'Professional',
                'system_prompt' => '',
            ];
        } else if (!isset($settings->api_url)) {
            $settings->api_url = '';
        }

        // Output HTML using heredoc, embedding variables securely
        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']);

        // We use ob_start to buffer a template block
        ob_start();
        ?>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        <style>
            .sahdev-nav { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            .sahdev-nav a { margin-right: 15px; font-weight: 600; text-decoration: none; padding: 5px 10px; border-radius: 4px; }
            .sahdev-nav a.active { background: #0d6efd; color: white; }
            .sahdev-nav a:not(.active) { color: #0d6efd; background: #f8f9fa; }
        </style>
        
        <div class="sahdev-settings-container"
            style="max-width: 800px; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            
            <div class="sahdev-nav">
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>" class="active">General Settings</a>
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&action=knowledgebase">Knowledgebase Engine Rules</a>
            </div>

            <h2 style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Sahdev AI Intelligence - Settings</h2>

            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <input type="hidden" name="save_settings" value="1">

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">AI Provider</label>
                    <select name="ai_provider" class="form-control" style="width: 100%; max-width: 300px;">
                        <option value="google" <?php echo ($settings->ai_provider === 'google') ? 'selected' : ''; ?>>Google AI
                            (Gemini)</option>
                        <option value="lmstudio" <?php echo ($settings->ai_provider === 'lmstudio') ? 'selected' : ''; ?>>LM Studio / Local AI</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">API Key (Google AI / OpenAI)</label>
                    <input type="password" name="api_key" class="form-control"
                        placeholder="Enter new API key to update. Leave blank to keep existing." style="width: 100%;">
                    <small class="text-muted">Stored encrypted using WHMCS core helpers.</small>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">API URL (LM Studio/Local API Only)</label>
                    <input type="text" name="api_url" class="form-control"
                        value="<?php echo htmlspecialchars($settings->api_url); ?>" placeholder="e.g. http://192.168.1.67:1234/v1/chat/completions" style="width: 100%;">
                    <small class="text-muted">Full endpoint URL including /v1/chat/completions or /api/v1/chat</small>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">Model Name</label>
                    <input type="text" name="model_name" class="form-control"
                        value="<?php echo htmlspecialchars($settings->model_name); ?>" style="width: 100%; max-width: 300px;">
                    <small class="text-muted">Google: e.g. models/gemini-1.5-pro | LM Studio: e.g. google/gemma-3n-e4b</small>
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

                <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600;">
                    <i class="fas fa-save" style="margin-right: 5px;"></i> Save Settings
                </button>
            </form>
        </div>

        <?php
        $html = ob_get_clean();
        return $html;
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
                if ($action === 'save') $errorMessage = "Filename is required.";
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
            .sahdev-container { max-width: 1000px; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .sahdev-nav { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            .sahdev-nav a { margin-right: 15px; font-weight: 600; text-decoration: none; padding: 5px 10px; border-radius: 4px; }
            .sahdev-nav a.active { background: #0d6efd; color: white; }
            .sahdev-nav a:not(.active) { color: #0d6efd; background: #f8f9fa; }
            .kb-file-card { border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-bottom: 15px; background: #fafafa; }
            .kb-file-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        </style>

        <div class="sahdev-container">
            <div class="sahdev-nav">
                <a href="<?php echo $settingsUrl; ?>">General Settings</a>
                <a href="<?php echo $actionUrl; ?>" class="active">Knowledgebase Engine Rules</a>
            </div>

            <h2 style="margin-bottom: 10px;">Knowledgebase & AI Rules</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create text files below containing context, rules, and facts you want Sahdev AI to always know about when replying to users. It reads all <code>.txt</code> files here automatically.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
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
                            <input type="text" name="filename" class="form-control" placeholder="e.g. migration_rules" required pattern="[a-zA-Z0-9_-]+">
                        </div>
                        <div class="col-md-9">
                            <label>File Content (Rules, Context, Fact Sheet)</label>
                            <textarea name="file_content" class="form-control" rows="4" required placeholder="Enter instructions like 'If a user asks about migration, tell them it costs $50...'"></textarea>
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
                                    <button type="submit" name="kb_action" value="save" class="btn btn-xs btn-primary"><i class="fas fa-save"></i> Update</button>
                                    <button type="submit" name="kb_action" value="delete" class="btn btn-xs btn-danger" onclick="return confirm('Delete <?php echo htmlspecialchars($fullname); ?>?');"><i class="fas fa-trash"></i> Delete</button>
                                </div>
                            </div>
                            <textarea name="file_content" class="form-control" rows="4"><?php echo htmlspecialchars($content); ?></textarea>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
}

