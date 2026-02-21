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

        <div class="sahdev-settings-container"
            style="max-width: 800px; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2 style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Sahdev AI Intelligence -
                Settings</h2>

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
}

