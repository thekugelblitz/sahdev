<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/autoload.php';

function sahdev_inject_ticket_panel($vars)
{
    $ticketId = (int) ($vars['ticketid'] ?? 0);
    $contextUserId = (int) ($vars['userid'] ?? ($_GET['userid'] ?? 0));
    if ($ticketId <= 0 && $contextUserId <= 0) {
        return '';
    }
    $isOpenContextMode = $ticketId <= 0 ? 'true' : 'false';

    // Verify admin isLoggedIn via WHMCS helper, strictly
    $adminId = $_SESSION['adminid'] ?? null;
    if (!$adminId)
        return '';

    // Generate CSRF form token securely
    $csrfToken = generate_token("form");

    // We use a unique parameter 'sahdev_act' to avoid collision with WHMCS/Theme 'action' parameter
    // We also add a cache buster v= timestamp to ensure the latest hook version is used
    $versionBuster = time();
    $ajaxUrl = "addonmodules.php?module=sahdev&sahdev_act=ajax_handler&v={$versionBuster}";

    // Output HTML Panel (collapsible using WHMCS bootstrap structure)
    // Needs to append into the "viewticket" page typically above replies or side sidebar
    // AdminAreaViewTicketPage hook outputs raw HTML onto the ticket view

    // Load Tone Defaults from DB + per-admin preferences
    $settings = Capsule::table('tblsahdev_settings')->first();
    require_once __DIR__ . '/lib/AdminPreferences.php';
    require_once __DIR__ . '/lib/PermissionService.php';
    \Sahdev\Lib\AdminPreferences::ensureSchema();
    \Sahdev\Lib\PermissionService::ensureSchema();

    // RBAC: Check if this admin's WHMCS role allows Ticket AI
    if (!\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TICKET_AI)) {
        return '';
    }

    $adminPrefs = \Sahdev\Lib\AdminPreferences::load((int) $adminId);
    $uiTheme = $adminPrefs['ui_theme'] ?? \Sahdev\Lib\AdminPreferences::THEME_REMASTERED;

    $defaultTone = 'Professional';
    if ($adminPrefs['tone_default'] !== null && $adminPrefs['tone_default'] !== '') {
        $defaultTone = $adminPrefs['tone_default'];
    } elseif ($settings && !empty($settings->tone_default)) {
        $defaultTone = (string) $settings->tone_default;
    }

    // Combine per-admin preference + global setting + WHMCS Role Permission
    $featTicketAi = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_TICKET_AI,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TICKET_AI);

    $featTools = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_TOOLS,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE);

    $featSummarizer = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_SUMMARIZER,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_SUMMARIZER);

    $featCannedKb = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_CANNED_KB,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_CANNED_KB);

    $featHistorical = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_HISTORICAL_CONTEXT,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX);

    $featRewrite = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_REWRITE,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_REWRITE_REPLY);

    $featQuality = \Sahdev\Lib\AdminPreferences::isFeatureEnabledForUi(
        \Sahdev\Lib\AdminPreferences::FEATURE_QUALITY_SCORE,
        (int) $adminId,
        $settings
    ) && \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW);

    $coreAiWrapStyle = $featTicketAi ? '' : 'display:none !important;';
    $toolsSectionStyle = $featTools ? '' : 'display:none !important;';
    $summarizerOuterStyle = $featSummarizer ? '' : 'display:none !important;';
    $cannedOuterStyle = $featCannedKb ? '' : 'display:none !important;';
    $historyOuterStyle = $featHistorical ? '' : 'display:none !important;';
    $rewriteBtnStyle = $featRewrite ? '' : 'display:none !important;';
    $cannedKbBtnsStyle = $featCannedKb ? '' : 'display:none !important;';
    $snapshotOuterExtraStyle = $featTicketAi ? '' : 'display:none !important;';

    // Inline migration: ensure auto_analyze_on_load column exists on older installs
    try {
        Capsule::table('tblsahdev_settings')->select('auto_analyze_on_load')->first();
    } catch (\Exception $e) {
        Capsule::schema()->table('tblsahdev_settings', function ($table) {
            $table->boolean('auto_analyze_on_load')->default(0);
        });
        // Re-fetch settings now column exists
        $settings = Capsule::table('tblsahdev_settings')->first();
    }

    $autoAnalyzeEnabled = ($settings && !empty($settings->auto_analyze_on_load) && $featTicketAi) ? 'true' : 'false';
    $qualityScorerEnabled = ($settings && !empty($settings->quality_scorer_enabled) && $featQuality) ? 'true' : 'false';

    $routingProviders = [];
    try {
        $routingProviders = Capsule::table('tblsahdev_providers')->where('is_active', 1)->orderBy('name')->get();
    } catch (\Exception $e) {
        $routingProviders = [];
    }

    require_once __DIR__ . '/lib/TaskProviderResolver.php';
    $defaultTicketOptionLabel = 'Default (routing)';
    if ($settings) {
        $effId = \Sahdev\Lib\TaskProviderResolver::resolveProviderId(
            \Sahdev\Lib\TaskProviderResolver::TASK_TICKET_REPLY,
            null,
            (array) $settings
        );
        $defRow = Capsule::table('tblsahdev_providers')->where('id', $effId)->first();
        if ($defRow) {
            $mn = trim((string) ($defRow->model_name ?? ''));
            $defaultTicketOptionLabel = 'Default: ' . $defRow->name . ($mn !== '' ? ' — ' . $mn : '');
        }
    }
    $defaultTicketOptionLabelEsc = htmlspecialchars($defaultTicketOptionLabel, ENT_QUOTES, 'UTF-8');

    $adminDefaultPid = (int) ($adminPrefs['default_provider_id'] ?? 0);
    $providerOptionsHtml = '';
    foreach ($routingProviders as $rp) {
        $rid    = (int) $rp->id;
        $rlabel = htmlspecialchars($rp->name . ' — ' . ($rp->model_name ?? ''), ENT_QUOTES, 'UTF-8');
        $optSel = ($adminDefaultPid > 0 && $rid === $adminDefaultPid) ? ' selected' : '';
        $providerOptionsHtml .= '<option value="' . $rid . '"' . $optSel . '>' . $rlabel . '</option>';
    }
    $emptyRoutingSel = ($adminDefaultPid <= 0) ? ' selected' : '';

    $isSel = function ($val, $current) {
        return $val === $current ? 'selected' : '';
    };

    $toneSelProfessional = $isSel('Professional', $defaultTone);
    $toneSelTechnical    = $isSel('Technical', $defaultTone);
    $toneSelFriendly     = $isSel('Friendly', $defaultTone);
    $toneSelStrict       = $isSel('Strict', $defaultTone);
    $toneSelCustom       = $isSel('Custom', $defaultTone);

    $modelSelectHtml = '<select id="sahdev_override_provider" class="form-control" style="max-width: 520px; color: #212529; background-color: #fff;">'
        . '<option value=""' . $emptyRoutingSel . '>' . $defaultTicketOptionLabelEsc . '</option>'
        . $providerOptionsHtml
        . '</select>';

    $scoreBtnStyle = ($qualityScorerEnabled === 'true') ? '' : 'display: none;';

    // Hardcode emojis for well known intents, since some DBs don't support utf8mb4 emojis natively
    $intentIconMap = [
        'AUTO'         => '🤖 ',
        'RESOLVE'      => '✅ ',
        'INVESTIGATE'  => '🧐 ',
        'MORE_INFO'    => '❓ ',
        'GUIDE'        => '🗺️ ',
        'OUT_OF_SCOPE' => '🚫 ',
        'DUPLICATE'    => '🔁 ',
        'ABUSE_REPORT' => '🛡️ ',
    ];

    // Load active intents from DB, ordered by sort_order
    $intentsList = [];
    try {
        // Backward-compatible migration: ensure ABUSE_REPORT exists on older installs
        if (Capsule::schema()->hasTable('tblsahdev_intents')) {
            $abuseExists = Capsule::table('tblsahdev_intents')
                ->where('intent_key', 'ABUSE_REPORT')
                ->exists();
            if (!$abuseExists) {
                Capsule::table('tblsahdev_intents')->insert([
                    'intent_key' => 'ABUSE_REPORT',
                    'label' => 'Abuse Report',
                    'directive' => 'REPLY INTENT — ABUSE REPORT: The admin is handling abuse, phishing, spam, malware, copyright, or policy reports. Write CLIENT_REPLY as a calm, human, policy-aware message that acknowledges the report, requests missing evidence when needed, outlines next review steps, and sets realistic follow-up expectations. Continue the conversation naturally and avoid abrupt closure.',
                    'is_active' => 1,
                    'sort_order' => 80,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now(),
                ]);
            }
        }

        $intentsList = Capsule::table('tblsahdev_intents')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'asc')
            ->get();
    } catch (\Exception $e) {
        // Fallback or ignore if DB not ready
    }
    
    // Generate Intent Buttons HTML
    $intentButtonsHtml = '';
    $jsIntentLabels = []; // To power the javascript badge label logic
    if (count($intentsList) > 0) {
        foreach ($intentsList as $inc => $intent) {
            $isActiveClass = ($inc === 0) ? ' sahdev-intent-active' : '';
            
            // Prepend known emoji if exists
            $emojiPrefix = isset($intentIconMap[$intent->intent_key]) ? $intentIconMap[$intent->intent_key] : '';
            $displayLabel = $emojiPrefix . $intent->label;

            $intentButtonsHtml .= '<button type="button" class="btn btn-xs sahdev-intent-btn' . $isActiveClass . '" data-intent="' . htmlspecialchars($intent->intent_key) . '" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">' . htmlspecialchars($displayLabel) . '</button> ';
            $jsIntentLabels[$intent->intent_key] = $displayLabel;
        }
        $defaultIntentVal = htmlspecialchars($intentsList[0]->intent_key);
    } else {
        // Ultimate fallback
        $intentButtonsHtml = '<button type="button" class="btn btn-xs sahdev-intent-btn sahdev-intent-active" data-intent="AUTO" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">🤖 Auto (AI Decides)</button>';
        $jsIntentLabels['AUTO'] = '🤖 Auto (AI Decides)';
        $defaultIntentVal = 'AUTO';
    }
    
    $jsIntentLabelsJson = json_encode($jsIntentLabels);

    // Active Incident Alert Banner (Only if role has incidents_manage or telemetry_view permission)
    $incidentAlertBanner = '';
    if (\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_INCIDENTS_MANAGE) || \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TELEMETRY_VIEW)) {
        try {
            require_once __DIR__ . '/lib/IncidentDetectionService.php';
            $activeInc = \Sahdev\Lib\IncidentDetectionService::getActiveIncidentForTicket($ticketId);
            if ($activeInc) {
                $incNum = htmlspecialchars($activeInc['incident_num']);
                $incTitle = htmlspecialchars($activeInc['title']);
                $incSummary = htmlspecialchars($activeInc['root_cause_summary']);
                $incidentAlertBanner = <<<INC_HTML
<div class="alert alert-danger" style="margin-top: 15px; border-left: 5px solid #c53030; background: #fff5f5; color: #742a2a; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
        <div>
            <strong style="font-size: 14px;"><i class="fas fa-exclamation-triangle"></i> ACTIVE OUTAGE / INCIDENT: {$incNum} — {$incTitle}</strong>
            <p style="margin: 4px 0 0 0; font-size: 12px; color: #4a5568;">{$incSummary}</p>
        </div>
        <a href="addonmodules.php?module=sahdev&amp;action=incidents" class="btn btn-xs btn-danger" target="_blank"><i class="fas fa-satellite-dish"></i> Incident Center</a>
    </div>
</div>
INC_HTML;
            }
        } catch (\Throwable $e) {}
    }

    // Live Server Health Badge (Single Sign-On to server control panel on click)
    $serverHealthBadge = '';
    if (\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TELEMETRY_VIEW)) {
        try {
            require_once __DIR__ . '/lib/ServerTelemetryService.php';
            $ticketRow = \WHMCS\Database\Capsule::table('tbltickets')->where('id', $ticketId)->first();
            if ($ticketRow && !empty($ticketRow->userid)) {
                $hRow = \WHMCS\Database\Capsule::table('tblhosting')->where('userid', (int)$ticketRow->userid)->whereIn('domainstatus', ['Active', 'Suspended'])->orderBy('id', 'desc')->first();
                if ($hRow && !empty($hRow->server)) {
                    $serverId = (int) $hRow->server;
                    $srvHealth = \Sahdev\Lib\ServerTelemetryService::getServerHealth($serverId);
                    if ($srvHealth) {
                        $srvName = htmlspecialchars($srvHealth['server_name'] ?: 'Server #' . $serverId);
                        $srvLoad = htmlspecialchars($srvHealth['server_load'] ?: '');
                        $loadPill = $srvLoad !== '' ? " | Load: {$srvLoad}" : '';
                        $isOnline = !empty($srvHealth['is_reachable']);
                        $badgeColor = $isOnline ? '#38a169' : '#e53e3e';
                        $badgeIcon = $isOnline ? 'fa-server' : 'fa-exclamation-circle';
                        
                        $canAccessServer = \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_SERVER_ACCESS);
                        $ssoUrl = $canAccessServer ? \Sahdev\Lib\ServerTelemetryService::getServerAccessUrl($serverId) : 'javascript:void(0);';
                        $targetAttr = $canAccessServer ? ' target="_blank"' : '';
                        $titleAttr = $canAccessServer ? ' title="Click to Log in to WHM / Server Control Panel (Single Sign-On)"' : ' title="Server Telemetry Snapshot"';
                        $ssoIcon = $canAccessServer ? ' <i class="fas fa-external-link-alt" style="font-size:9px; opacity:0.85; margin-left:3px;"></i>' : '';

                        $serverHealthBadge = '<a href="' . $ssoUrl . '"' . $targetAttr . $titleAttr . ' class="badge" style="background:' . $badgeColor . '; color:#ffffff !important; text-decoration:none !important; font-size: 11px; margin-left: 8px; vertical-align: middle; font-weight: 600; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; transition: opacity 0.2s; cursor: pointer;" onclick="event.stopPropagation();" onmouseover="this.style.opacity=\'0.85\';" onmouseout="this.style.opacity=\'1\';"><i class="fas ' . $badgeIcon . '"></i> ' . $srvName . $loadPill . $ssoIcon . '</a>';
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    // Server-side inline styles for each theme — eliminates flash entirely
    $isRemastered = ($uiTheme === \Sahdev\Lib\AdminPreferences::THEME_REMASTERED);
    $panelStyle   = $isRemastered
        ? 'margin-top:14px;border:none;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);'
        : 'margin-top: 20px; border-color: #0d6efd;';
    $panelClass   = $isRemastered ? 'panel panel-info sahdev-remastered' : 'panel panel-info';
    $headingStyle = $isRemastered
        ? 'background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:#e2e8f0;padding:10px 16px;border:none;display:flex;justify-content:space-between;align-items:center;cursor:pointer;'
        : 'background-color: #0d6efd; color: white; display: flex; justify-content: space-between; align-items: center; cursor: pointer;';
    $titleStyle   = $isRemastered
        ? 'display:flex;align-items:center;flex-wrap:wrap;gap:8px;font-size:14px;font-weight:700;letter-spacing:0.3px;'
        : 'display:flex;align-items:center;flex-wrap:wrap;gap:8px;';
    $robotColor   = $isRemastered ? 'color:#818cf8;' : '';
    $linkStyle    = $isRemastered
        ? 'font-size:11px;color:#94a3b8;font-weight:500;'
        : 'font-size:12px;color:#e7f1ff;font-weight:500;';
    $bodyStyle    = $isRemastered
        ? 'display:none;visibility:hidden;'
        : 'display: none; background: #f8f9fa;';

    $htmlPanel = <<<HTML
{$incidentAlertBanner}
<div class="{$panelClass}" id="sahdev-ai-panel" style="{$panelStyle}">
    <div class="panel-heading" style="{$headingStyle}" onclick="$('#sahdev-ai-body').slideToggle();">
        <h3 class="panel-title" style="{$titleStyle}"><i class="fas fa-robot" style="{$robotColor}"></i> Sahdev AI Ticket Intelligence {$serverHealthBadge}
            <a href="addonmodules.php?module=sahdev&amp;action=my_preferences" style="{$linkStyle}" onclick="event.stopPropagation();">My preferences</a>
        </h3>
        <i class="fas fa-chevron-down"></i>
    </div>
    <div class="panel-body" id="sahdev-ai-body" style="{$bodyStyle}">
        
        <form id="sahdev-ai-form">
            {$csrfToken}
            <input type="hidden" id="sahdev_ticket_id" value="{$ticketId}">
            <input type="hidden" id="sahdev_context_user_id" value="{$contextUserId}">
            <input type="hidden" id="sahdev_is_open_context_mode" value="{$isOpenContextMode}">
            <input type="hidden" id="sahdev_intent" value="{$defaultIntentVal}">

            <div id="sahdev-core-ai-wrap" style="{$coreAiWrapStyle}">
            <!-- Intent Selector -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;"><i class="fas fa-bullseye"></i> Reply Intent</label>
                <div id="sahdev-intent-btns" style="display: flex; flex-wrap: wrap; gap: 6px;">
                    {$intentButtonsHtml}
                </div>
            </div>
            <style>
                .sahdev-intent-btn { background: #f0f0f0; color: #555; border: 1px solid #ccc; transition: all 0.15s ease; }
                .sahdev-intent-btn:hover { background: #dbe4ff; color: #3a56c9; border-color: #3a56c9; }
                .sahdev-intent-active { background: #0d6efd !important; color: #fff !important; border-color: #0d6efd !important; }
                #sahdev_override_provider, #sahdev_override_provider option { color: #212529; background-color: #fff; }
            </style>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>AI Tone</label>
                        <select id="sahdev_tone" class="form-control">
                            <option value="Professional" {$toneSelProfessional}>Professional</option>
                            <option value="Technical" {$toneSelTechnical}>Technical</option>
                            <option value="Friendly" {$toneSelFriendly}>Friendly</option>
                            <option value="Strict" {$toneSelStrict}>Strict</option>
                            <option value="Custom" {$toneSelCustom}>Custom</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Dive Intensity: <span id="sahdev_intensity_val">3</span>/5</label>
                        <input type="range" id="sahdev_intensity" class="form-control" style="padding: 0; box-shadow: none;" min="1" max="5" value="3" oninput="document.getElementById('sahdev_intensity_val').innerText = this.value;">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Custom Instruction (Optional)</label>
                        <input type="text" id="sahdev_instruction" class="form-control" placeholder="e.g. 'Ask for server credentials in the reply' or 'Explain why the load is high'">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="font-weight: 600;">Model for this generation</label>
                        {$modelSelectHtml}
                        <small class="text-muted">Optional override for this ticket’s analysis and reply generation only. Admins only.</small>
                    </div>
                </div>
            </div>

            <!-- Technical Context Input -->
            <div class="row">
                <div class="col-md-12">
                    <div class="form-group" style="margin-bottom: 5px;">
                        <label style="display: flex; justify-content: space-between; align-items: center;">
                            <span><i class="fas fa-terminal" style="color:#6c757d;"></i> Technical Context (Optional)</span>
                            <button type="button" id="btn-sahdev-paste-tech" class="btn btn-xs btn-default" style="font-size: 11px;">
                                <i class="fas fa-paste"></i> Fetch from Clipboard
                            </button>
                        </label>
                        <textarea id="sahdev_technical_context" class="form-control" rows="2" placeholder="Paste JSON API response, DNS output, server logs, or any raw technical data here to feed the AI..."></textarea>
                        <div id="sahdev_tech_context_info" class="text-muted" style="font-size: 11px; margin-top: 4px; display: none;"></div>
                    </div>
                </div>
            </div>

            <div class="form-group text-right" style="margin-top: 10px; display: flex; justify-content: flex-end; align-items: center; gap: 15px;">
                <label style="font-weight: 600; font-size: 13px; margin: 0; cursor: pointer; color: #6f42c1;" title="If checked, the AI will use the condensed ticket summary instead of reading the full message history (if a summary exists).">
                    <input type="checkbox" id="sahdev_use_summary" value="1" checked style="vertical-align: middle; margin: 0 4px 0 0;"> Feed Summary (if available)
                </label>
                <label style="font-weight: 600; font-size: 13px; margin: 0; cursor: pointer; color: #e83e8c;" title="If checked, private ticket notes will be included in the AI context.">
                    <input type="checkbox" id="sahdev_include_admin_notes" value="1" checked style="vertical-align: middle; margin: 0 4px 0 0;"> Include Ticket Notes
                </label>
                <label style="font-weight: 600; font-size: 13px; margin: 0; cursor: pointer; color: #0d6efd;" title="If checked, latest tool execution evidence will be added to AI prompt context for this generation.">
                    <input type="checkbox" id="sahdev_include_tools_context" value="1" checked style="vertical-align: middle; margin: 0 4px 0 0;"> Include Tool Evidence
                </label>
                <div>
                    <button type="button" id="btn-sahdev-analyze" class="btn btn-primary" style="font-weight: 600;">
                        <i class="fas fa-magic"></i> Analyze & Generate Reply
                    </button>
                    <button type="button" id="btn-sahdev-regenerate" class="btn btn-warning" style="font-weight: 600; display: none; margin-left: 5px;" data-force="true">
                        <i class="fas fa-sync"></i> Regenerate Reply
                    </button>
                </div>
            </div>
            </div>
            <div class="form-group" style="margin-top:8px;{$toolsSectionStyle}">
                <button type="button" id="btn-sahdev-run-tools" class="btn btn-default btn-sm">
                    <i class="fas fa-tools"></i> Run Tool Execution
                </button>
                <button type="button" id="btn-sahdev-rerun-tools" class="btn btn-warning btn-sm" style="margin-left:6px;">
                    <i class="fas fa-sync"></i> Re-run Tool Execution
                </button>
                <div id="sahdev-tools-status" class="text-muted" style="font-size:12px; margin-top:6px;"></div>
                <div id="sahdev-tools-panel" style="margin-top:8px; border:1px solid #e5e7eb; border-radius:6px; padding:10px; background:#fafafa;">
                    <label style="font-weight:600; margin-bottom:4px; display:block;">Latest Tool Evidence (editable before AI run)</label>
                    <div style="margin-bottom:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <label for="sahdev-tools-view-mode" style="font-size:12px; margin:0;">View</label>
                        <select id="sahdev-tools-view-mode" class="form-control input-sm" style="max-width:180px;">
                            <option value="readable" selected>Readable</option>
                            <option value="raw">Raw</option>
                            <option value="both">Readable + Raw</option>
                        </select>
                    </div>
                    <textarea id="sahdev-tools-output-edit" class="form-control" rows="6" placeholder="Tool output will appear here..."></textarea>
                    <div style="margin-top:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <button type="button" id="btn-sahdev-append-tools-context" class="btn btn-default btn-xs">
                            <i class="fas fa-plus-circle"></i> Append output to Technical Context
                        </button>
                        <span id="sahdev-tools-meta" class="text-muted" style="font-size:11px;"></span>
                    </div>
                    <hr style="margin:10px 0;">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:10px; align-items:end;">
                        <div style="grid-column: span 2;">
                            <label style="font-size:11px; color:#666; margin-bottom:2px; display:block;">Select Diagnostic Tool</label>
                            <select id="sahdev-manual-tool-op" class="form-control input-sm">
                                <option value="">Select Tool Operation...</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px; color:#666; margin-bottom:2px; display:block;">Domain / Target</label>
                            <input type="text" id="sahdev-tool-domain" class="form-control input-sm" placeholder="example.com">
                        </div>
                        <div>
                            <label style="font-size:11px; color:#666; margin-bottom:2px; display:block;">IP Address</label>
                            <input type="text" id="sahdev-tool-ip" class="form-control input-sm" placeholder="1.2.3.4">
                        </div>
                        <div>
                            <label style="font-size:11px; color:#666; margin-bottom:2px; display:block;">Email / Account</label>
                            <input type="text" id="sahdev-tool-email" class="form-control input-sm" placeholder="user@email.com">
                        </div>
                        <div>
                            <label style="font-size:11px; color:#666; margin-bottom:2px; display:block;">Extra / Type</label>
                            <input type="text" id="sahdev-tool-extra" class="form-control input-sm" placeholder="A, MX, etc.">
                        </div>
                        <div>
                            <button type="button" id="btn-sahdev-manual-tool-run" class="btn btn-info btn-sm btn-block" style="font-weight:600; padding:5px;">
                                <i class="fas fa-play"></i> RUN TOOL
                            </button>
                        </div>
                    </div>
                    <div class="text-muted" style="font-size:10px; margin-top:6px; display:flex; justify-content:space-between;">
                        <span><i class="fas fa-magic"></i> Auto-filled from ticket context.</span>
                        <span><a href="#" id="sahdev-manual-json-toggle" style="color:#aaa; text-decoration:none;">Advanced JSON</a></span>
                    </div>
                    <div id="sahdev-manual-json-wrap" style="display:none; margin-top:8px;">
                        <div style="display:flex; gap:6px;">
                            <input type="text" id="sahdev-manual-path-params" class="form-control input-sm sahdev-json-input" placeholder='Path Params JSON'>
                            <input type="text" id="sahdev-manual-query" class="form-control input-sm sahdev-json-input" placeholder='Query JSON'>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Rewrite It: Expand Admin Draft from Editor -->
        <div style="border-top: 2px dashed #c0d9f5; margin-top: 12px; padding-top: 12px;">
            <label style="font-weight: 700; font-size: 13px; margin-bottom: 6px; display: block;"><i class="fas fa-pen-nib" style="color:#17a2b8;"></i> ✍️ Expand &amp; Polish My Draft Reply</label>
            <p class="text-muted" style="font-size: 12px; margin-bottom: 8px;">Write a short rough reply in the editor below first, then click <strong>Rewrite It</strong> — Sahdev will expand it into a complete, professional reply and put it right back in the editor.</p>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="btn-sahdev-rewrite" class="btn btn-info btn-sm" style="font-weight: 600;{$rewriteBtnStyle}">
                    <i class="fas fa-pen-nib"></i> Rewrite It
                </button>
                <button type="button" id="btn-sahdev-score-draft" class="btn btn-default btn-sm" style="font-weight: 600; {$scoreBtnStyle}" title="Get AI feedback on your manual draft before sending">
                    <i class="fas fa-tachometer-alt"></i> Score Admin Draft
                </button>
                <button type="button" id="btn-sahdev-save-canned" class="btn btn-warning btn-sm" style="font-weight: 600; margin-left: auto;{$cannedKbBtnsStyle}" title="Save your draft as a reusable Canned Response">
                    <i class="fas fa-save"></i> Save as Canned
                </button>
                <button type="button" id="btn-sahdev-save-kb" class="btn btn-primary btn-sm" style="font-weight: 600;{$cannedKbBtnsStyle}" title="Stores a KB-style draft in Sahdev (tblsahdev_canned_responses only). Does not write to WHMCS core tables.">
                    <i class="fas fa-book"></i> Save KB draft
                </button>
                <span id="sahdev-rewrite-status" class="label label-default" style="display: none; font-size: 12px; cursor: help; padding: 5px 8px;"></span>
            </div>
            <div id="sahdev-rewrite-loading" style="display: none; margin-top: 8px; font-size: 13px; color: #17a2b8;">
                <i class="fas fa-spinner fa-spin"></i> Sahdev is polishing your draft...
            </div>
            <div id="sahdev-canned-loading" style="display: none; margin-top: 8px; font-size: 13px; color: #f0ad4e;">
                <i class="fas fa-spinner fa-spin"></i> Sahdev is generalizing your draft into a template...
            </div>
            <div id="sahdev-rewrite-error" class="alert alert-danger" style="display: none; margin-top: 8px; font-size: 13px; padding: 8px 12px;"></div>
        </div>

        <!-- Loading Indicator -->
        <div id="sahdev-loading" style="display: none; text-align: center; padding: 20px;{$coreAiWrapStyle}">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p style="margin-top: 10px;">Sahdev AI is analyzing ticket data securely...</p>
        </div>

        <!-- Output sections -->
        <div id="sahdev-results" style="display: none; margin-top: 20px;{$coreAiWrapStyle}">
            
            <div class="row">
                <!-- Left Column: internal analysis -->
                <div class="col-md-6">
                    <div class="well well-sm" style="background: #fff; border-left: 4px solid #d9534f;">
                        <strong><i class="fas fa-search"></i> Root Cause:</strong>
                        <p id="sahdev-out-cause" class="text-danger" style="margin-bottom:0;"></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="well well-sm" style="background: #fff; border-left: 4px solid #f0ad4e;">
                                <strong><i class="fas fa-users"></i> Responsibility:</strong>
                                <p id="sahdev-out-resp" class="text-warning" style="margin-bottom:0;"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="well well-sm" style="background: #fff; border-left: 4px solid #5bc0de;">
                                <strong><i class="fas fa-exclamation-triangle"></i> Risk Level:</strong>
                                <p id="sahdev-out-risk" class="text-info" style="margin-bottom:0;"></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="well well-sm" style="background: #fff; border-left: 4px solid #5cb85c;">
                        <strong><i class="fas fa-clipboard-list"></i> Action Plan (Internal):</strong>
                        <p id="sahdev-out-plan" class="text-success" style="margin-bottom:0; white-space: pre-wrap;"></p>
                    </div>
                </div>

                <!-- Right Column: Proposed Client Reply -->
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong>Proposed Client Reply</strong>
                                <span id="sahdev-reply-score-badge" class="label" style="display:none; margin-left:8px; font-size: 11px; cursor: help;"></span>
                            </div>
                            <div style="display: flex; gap: 5px;">
                                <button type="button" class="btn btn-xs btn-default" onclick="copySahdevReply()"><i class="fas fa-copy"></i> Copy</button>
                                <button type="button" class="btn btn-xs btn-success" onclick="insertSahdevToTinyMce()"><i class="fas fa-arrow-down"></i> Insert</button>
                            </div>
                        </div>
                        <div class="panel-body" style="max-height: 250px; overflow-y: auto;">
                            <!-- The reply is often HTML formatted -->
                            <div id="sahdev-out-reply"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-muted small text-right">
                <span id="sahdev-intent-display"></span> &nbsp;|&nbsp; <span id="sahdev-token-usage"></span> • <span id="sahdev-exec-time"></span>
            </div>
        </div>

        <div id="sahdev-error" class="alert alert-danger" style="display: none; margin-top: 20px;{$coreAiWrapStyle}"></div>

    </div>
</div>

<!-- AI Snapshot Panel: Auto-loads analysis on page open (controlled by backend setting) -->
<div id="sahdev-snapshot-outer" style="margin-top: 15px; display: none;{$snapshotOuterExtraStyle}">
    <div class="panel panel-default" id="sahdev-snapshot-panel" style="border-color: #17a2b8;">
        <div class="panel-heading" style="background: #e8f7fa; color: #0d7490; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="$('#sahdev-snapshot-body').slideToggle();">
            <h4 class="panel-title" style="margin: 0; font-size: 14px; font-weight: 700;">
                <i class="fas fa-bolt" style="margin-right: 6px;"></i>AI Snapshot <span class="label label-info" style="font-size: 10px; vertical-align: middle; margin-left: 6px;">Auto-loaded</span>
            </h4>
            <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
        </div>
        <div class="panel-body" id="sahdev-snapshot-body" style="background: #f8feff; padding: 15px;">

            <!-- Sleeping / Error state -->
            <div id="sahdev-snapshot-sleeping" style="display: none; text-align: center; padding: 20px 10px;">
                <div style="font-size: 48px; margin-bottom: 8px;">😴</div>
                <h4 style="color: #5a6875; margin-bottom: 4px;">Sahdev is sleeping right now! 😂 Sorry dear!</h4>
                <p style="color: #7a8895; font-size: 13px; margin-bottom: 12px;">The AI provider couldn't be reached at the moment. Don't worry, you can still use the panel above manually.</p>
                <div id="sahdev-snapshot-tech-error" style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px 14px; text-align: left; font-size: 12px; font-family: monospace; color: #721c24; display: none; white-space: pre-wrap; word-break: break-word;"></div>
            </div>

            <!-- Loading skeleton -->
            <div id="sahdev-snapshot-loading" style="display: none; text-align: center; padding: 15px;">
                <i class="fas fa-spinner fa-spin fa-lg" style="color: #17a2b8;"></i>
                <p style="margin-top: 8px; font-size: 13px; color: #555;">Sahdev is sneaking a peek at this ticket...</p>
            </div>

            <!-- Results -->
            <div id="sahdev-snapshot-results" style="display: none;">
                <div class="row">
                    <div class="col-md-6">
                        <div class="well well-sm" style="background:#fff; border-left:4px solid #d9534f; margin-bottom:10px;">
                            <strong><i class="fas fa-search"></i> Root Cause:</strong>
                            <p id="sahdev-snap-cause" class="text-danger" style="margin-bottom:0; margin-top:4px;"></p>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="well well-sm" style="background:#fff; border-left:4px solid #f0ad4e; margin-bottom:10px;">
                                    <strong><i class="fas fa-users"></i> Responsibility:</strong>
                                    <p id="sahdev-snap-resp" class="text-warning" style="margin-bottom:0; margin-top:4px;"></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="well well-sm" style="background:#fff; border-left:4px solid #5bc0de; margin-bottom:10px;">
                                    <strong><i class="fas fa-exclamation-triangle"></i> Risk Level:</strong>
                                    <p id="sahdev-snap-risk" class="text-info" style="margin-bottom:0; margin-top:4px;"></p>
                                </div>
                            </div>
                        </div>
                        <div class="well well-sm" style="background:#fff; border-left:4px solid #5cb85c; margin-bottom:10px;">
                            <strong><i class="fas fa-clipboard-list"></i> Action Plan:</strong>
                            <p id="sahdev-snap-plan" class="text-success" style="margin-bottom:0; margin-top:4px; white-space: pre-wrap;"></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="panel panel-default" style="margin-bottom: 10px;">
                            <div class="panel-heading" style="display:flex; justify-content:space-between; align-items:center; padding: 8px 12px;">
                                <div>
                                    <strong style="font-size:13px;">Proposed Reply Preview</strong>
                                    <span id="sahdev-snap-reply-score-badge" class="label" style="display:none; margin-left:8px; font-size: 11px; cursor: help;"></span>
                                </div>
                                <button type="button" class="btn btn-xs btn-success" onclick="sahdevSnapInsertToEditor()"><i class="fas fa-arrow-down"></i> Use This Reply</button>
                            </div>
                            <div class="panel-body" style="max-height: 220px; overflow-y: auto; font-size: 13px;">
                                <div id="sahdev-snap-reply"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-muted small text-right" style="margin-top: 4px;">
                    <i class="fas fa-bolt"></i> Auto-loaded &nbsp;|&nbsp; <span id="sahdev-snap-stats"></span>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- AI Ticket Summarizer Panel -->
<div id="sahdev-summarizer-outer" style="margin-top: 15px;{$summarizerOuterStyle}">
    <div class="panel panel-default" id="sahdev-summarizer-panel" style="border-color: #6f42c1;">
        <div class="panel-heading" style="background: #f3f0fc; color: #4b2d8a; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="$('#sahdev-summarizer-body').slideToggle();">
            <h4 class="panel-title" style="margin: 0; font-size: 14px; font-weight: 700;">
                <i class="fas fa-compress-alt" style="margin-right: 6px;"></i>AI Ticket Summarizer
                <span id="sahdev-summary-active-badge" style="display:none;" class="label label-success" style="font-size: 10px; vertical-align: middle; margin-left: 6px;">&#9889; In Use — Saving Tokens</span>
            </h4>
            <div style="display:flex; gap:6px; align-items:center;">
                <span id="sahdev-summary-exists-badge" style="display:none;" class="label label-purple" style="background:#6f42c1; font-size:10px;">Summary Saved</span>
                <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
            </div>
        </div>
        <div class="panel-body" id="sahdev-summarizer-body" style="background: #fbf9ff; padding: 14px;">
            <p class="text-muted" style="font-size: 12px; margin-bottom: 10px;">
                <i class="fas fa-info-circle"></i>
                Condense this ticket's full conversation into a smart AI summary. Once saved, it replaces the raw message history in all AI prompts — <strong>cutting input token usage significantly</strong> for long threads.
            </p>
            <!-- Summary display -->
            <div id="sahdev-summary-display" style="display:none; background:#fff; border:1px solid #d8caff; border-radius:6px; padding:12px; margin-bottom:10px; font-size:13px; white-space:pre-wrap; color:#333; max-height:200px; overflow-y:auto;"></div>
            <!-- Loading -->
            <div id="sahdev-summary-loading" style="display:none; font-size:13px; color:#6f42c1; margin-bottom:8px;">
                <i class="fas fa-spinner fa-spin"></i> Sahdev is summarizing the conversation...
            </div>
            <!-- Error -->
            <div id="sahdev-summary-error" class="alert alert-danger" style="display:none; font-size:13px; padding:8px 12px; margin-bottom:8px;"></div>
            <!-- Buttons -->
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button type="button" id="btn-sahdev-generate-summary" class="btn btn-sm" style="background:#6f42c1; color:#fff; font-weight:600;">
                    <i class="fas fa-magic"></i> Generate Summary
                </button>
                <button type="button" id="btn-sahdev-delete-summary" class="btn btn-sm btn-danger" style="display:none; font-weight:600;">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <span id="sahdev-summary-meta" style="font-size:11px; color:#888; margin-left:4px;"></span>
            </div>
        </div>
    </div>
</div>

<!-- Canned Responses & KB Search Panel -->
<div id="sahdev-canned-outer" style="margin-top: 15px;{$cannedOuterStyle}">
    <div class="panel panel-default" id="sahdev-canned-panel" style="border-color: #f0ad4e;">
        <div class="panel-heading" style="background: #fff8eb; color: #d35400; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="$('#sahdev-canned-body').slideToggle();">
            <h4 class="panel-title" style="margin: 0; font-size: 14px; font-weight: 700;">
                <i class="fas fa-book" style="margin-right: 6px;"></i>Canned Responses & KB Search
            </h4>
            <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
        </div>
        <div class="panel-body" id="sahdev-canned-body" style="background: #fffdfa; padding: 14px; display: none;">
            <div class="input-group" style="margin-bottom: 10px;">
                <span class="input-group-addon"><i class="fas fa-search"></i></span>
                <input type="text" id="sahdev-canned-search" class="form-control" placeholder="Search Templates, Predefined Replies, or Knowledgebase...">
            </div>
            <div id="sahdev-canned-results" style="max-height: 250px; overflow-y: auto;">
                <p class="text-muted" style="font-size:12px; margin: 10px 0;">Type at least 3 characters to search.</p>
            </div>
        </div>
    </div>
</div>

<!-- Historical Client Context (Memory) Panel -->
<div id="sahdev-history-outer" style="margin-top: 15px;{$historyOuterStyle}">
    <div class="panel panel-default" id="sahdev-history-panel" style="border-color: #e83e8c;">
        <div class="panel-heading" style="background: #fce8f3; color: #a71d5d; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="$('#sahdev-history-body').slideToggle();">
            <h4 class="panel-title" style="margin: 0; font-size: 14px; font-weight: 700;">
                <i class="fas fa-brain" style="margin-right: 6px;"></i>Historical Client Context (Memory)
                <span id="sahdev-history-active-badge" style="display:none;" class="label label-success" style="font-size: 10px; vertical-align: middle; margin-left: 6px;">&#9889; Active</span>
            </h4>
            <div style="display:flex; gap:6px; align-items:center;">
                <span id="sahdev-history-exists-badge" style="display:none;" class="label" style="background:#e83e8c; font-size:10px;">Memory Saved</span>
                <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
            </div>
        </div>
        <div class="panel-body" id="sahdev-history-body" style="background: #fff5fa; padding: 14px; display: none;">
            <p class="text-muted" style="font-size: 12px; margin-bottom: 10px;">
                <i class="fas fa-info-circle"></i>
                Analyze the client's past tickets to detect recurring problems and give the AI long-term context before generating a reply.
            </p>
            
            <div class="form-group" style="margin-bottom: 10px;">
                <label style="font-size:12px;">Analyze last X tickets:</label>
                <input type="number" id="sahdev_history_limit" class="form-control input-sm" style="width: 80px; display:inline-block;" value="7" min="1" max="25">
            </div>

            <!-- Context display -->
            <div id="sahdev-history-display" style="display:none; background:#fff; border:1px solid #ffb8d9; border-radius:6px; padding:12px; margin-bottom:10px; font-size:13px; white-space:pre-wrap; color:#333; max-height:200px; overflow-y:auto;"></div>
            
            <!-- Loading -->
            <div id="sahdev-history-loading" style="display:none; font-size:13px; color:#a71d5d; margin-bottom:8px;">
                <i class="fas fa-spinner fa-spin"></i> Digging through client history...
            </div>
            
            <!-- Error -->
            <div id="sahdev-history-error" class="alert alert-danger" style="display:none; font-size:13px; padding:8px 12px; margin-bottom:8px;"></div>
            
            <!-- Controls -->
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; gap:8px;">
                    <button type="button" id="btn-sahdev-generate-history" class="btn btn-sm" style="background:#e83e8c; color:#fff; font-weight:600;">
                        <i class="fas fa-search"></i> Generate Memory
                    </button>
                    <button type="button" id="btn-sahdev-delete-history" class="btn btn-sm btn-danger" style="display:none; font-weight:600;">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
                
                <label style="font-weight: 600; font-size: 12px; margin: 0; cursor: pointer; color: #a71d5d; display:flex; align-items:center; gap:5px;" title="Inject this memory into the Main Reply AI prompt.">
                    <input type="checkbox" id="sahdev_include_history" value="1" disabled style="margin:0;">
                    Include in Reply Generation
                </label>
            </div>
            
            <div id="sahdev-history-meta" style="font-size:11px; color:#888; margin-top:8px; display:none;"></div>
        </div>
    </div>
</div>

HTML;


    // Output: CSS first (for remastered styling), then HTML
    $output = '';
    if ($uiTheme === \Sahdev\Lib\AdminPreferences::THEME_REMASTERED) {
        $rmCssPath = __DIR__ . '/ui/remastered.css';
        if (file_exists($rmCssPath)) {
            $output .= "<style>\n" . file_get_contents($rmCssPath) . "\n</style>\n";
        }
    }
    $output .= $htmlPanel;


    // Use string concatenation instead of output buffering to prevent WHMCS from dropping the buffer
    $jsContentStart = <<<HTML
<script>
    var sahdevAjaxUrl = "{$ajaxUrl}";
    var sahdevAutoAnalyze = {$autoAnalyzeEnabled};
    var sahdevQualityScorer = {$qualityScorerEnabled};
    console.log("Sahdev AI initialized with AJAX URL:", sahdevAjaxUrl, "| Auto-analyze:", sahdevAutoAnalyze);
HTML;

    // Inject active admin's signature into JS payload for dynamic appending
    $adminSignatureRaw = Capsule::table('tbladmins')->where('id', $adminId)->value('signature');
    $adminSignature = $adminSignatureRaw ? trim(strip_tags($adminSignatureRaw, '<br><p><a><b><strong><i><em>')) : '';
    $htmlPanel .= "<script>\nvar currentAdminSignature = " . json_encode($adminSignature) . ";\n</script>";

    $jsContentMain = <<<'EOT'

    function copySahdevReply() {
        var html = $('#sahdev-out-reply').html();
        var temp = $("<textarea>");
        $("body").append(temp);
        
        var plain = html.replace(/<br\s*\/?>/gi, "\n").replace(/(<([^>]+)>)/gi, "");
        temp.val(plain).select();
        document.execCommand("copy");
        temp.remove();
        alert("Reply copied to clipboard!");
    }

    function insertSahdevToTinyMce() {
        var replyHtml = $('#sahdev-out-reply').html();
        if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
            var currentContent = tinymce.activeEditor.getContent();
            tinymce.activeEditor.setContent(replyHtml + '<br><br>' + currentContent);
            
            $('html, body').animate({
                scrollTop: $("#replyticket").offset().top - 50
            }, 500);
        } else if ($('#replymessage').length) {
            var el = $('#replymessage').get(0);
            var plain = replyHtml.replace(/<br\s*\/?>/gi, "\n").replace(/(<([^>]+)>)/gi, "");
            
            var currentVal = el.value;
            el.value = plain + "\n\n" + currentVal;
            el.focus();
        }
    }

    // Intent pill button click handler
    $(document).on('click', '#sahdev-intent-btns .sahdev-intent-btn', function() {
        $('#sahdev-intent-btns .sahdev-intent-btn').removeClass('sahdev-intent-active');
        $(this).addClass('sahdev-intent-active');
        $('#sahdev_intent').val($(this).data('intent'));
    });

    // Handle Fetch from Clipboard for Technical Context
    $(document).on('click', '#btn-sahdev-paste-tech', async function() {
        try {
            var text = await navigator.clipboard.readText();
            if (!text) {
                alert("Clipboard is empty or contains no text.");
                return;
            }
            $('#sahdev_technical_context').val(text);
            
            // Try to parse basic info
            var infoDiv = $('#sahdev_tech_context_info');
            try {
                var jsonObj = JSON.parse(text);
                var keys = Object.keys(jsonObj).slice(0, 3).join(', ');
                var msg = "Valid JSON loaded (Keys: " + keys + "...). This will be fed to the AI.";
                
                // Specific heuristic for the example provided
                if (jsonObj.records && jsonObj.records.length > 0 && jsonObj.records[0].type) {
                     msg = "Detected DNS check/records data (" + jsonObj.records.length + " items). This will be fed to the AI.";
                } else if (jsonObj.metadata && jsonObj.metadata.query) {
                     msg = "Detected technical metadata for: " + jsonObj.metadata.query + ".";
                }
                
                infoDiv.text(msg).css('color', '#198754').show(); // green
            } catch (e) {
                // Not JSON, just show text size
                infoDiv.text("Loaded " + text.length + " characters of raw text context.").css('color', '#6c757d').show(); // gray
            }
        } catch (err) {
            console.error("Failed to read clipboard: ", err);
            alert("Could not read clipboard. Please ensure your browser allows clipboard access, or just paste the text manually into the box.");
        }
    });

    $(document).ready(function() {
        (function relocateOpenContextPanel() {
            var isOpenContextMode = ($('#sahdev_is_open_context_mode').val() === 'true');
            if (!isOpenContextMode) return;

            var blockSelectors = [
                '#sahdev-ai-panel',
                '#sahdev-snapshot-outer',
                '#sahdev-summarizer-outer',
                '#sahdev-canned-outer',
                '#sahdev-history-outer'
            ];
            var $blocks = $();
            for (var b = 0; b < blockSelectors.length; b++) {
                var $blk = $(blockSelectors[b]);
                if ($blk.length) {
                    $blocks = $blocks.add($blk.first());
                }
            }
            if (!$blocks.length) return;

            $('#sahdev-ai-panel').css({ width: '100%', marginTop: '0' });

            // Strictly relocate inside main content area; avoid sidebar forms.
            var $main = $('#contentarea, #content, .contentarea').first();
            if (!$main.length) {
                $main = $('body');
            }
            var $stack = $('#sahdev-open-context-stack');
            if (!$stack.length) {
                $stack = $('<div id="sahdev-open-context-stack" style="margin:10px 0 15px 0;"></div>');
            }
            $stack.append($blocks);

            var $heading = $main.find('h1, h2, h3').filter(function() {
                return ($(this).text() || '').toLowerCase().indexOf('open new ticket') !== -1;
            }).first();

            if ($heading.length) {
                $stack.insertAfter($heading);
                return;
            }

            var $ticketForm = $main.find('form').filter(function() {
                var $f = $(this);
                return $f.find('input[name="subject"], textarea[name="message"], select[name="deptid"]').length > 0;
            }).first();

            if ($ticketForm.length) {
                $stack.insertBefore($ticketForm);
                return;
            }

            // Fallback: keep panel near top of main content (still better than footer/sidebar)
            $main.prepend($stack);
        })();

        $(document).on('click', '#btn-sahdev-analyze, #btn-sahdev-regenerate', function(e) {
            e.preventDefault();
            console.log("Sahdev AI Button Clicked", $(this).attr('id'));
            
            var isRegenerate = $(this).data('force') === true;
            
            $('#sahdev-results').hide();
            $('#sahdev-error').hide();
            $('#sahdev-loading').show();
            var $btn = $(this);
            $('#btn-sahdev-analyze, #btn-sahdev-regenerate').prop('disabled', true);
            
            var baseReqData = {
                ticket_id: $('#sahdev_ticket_id').val(),
                userid: $('#sahdev_context_user_id').val(),
                tone: $('#sahdev_tone').val(),
                intensity: $('#sahdev_intensity').val(),
                instruction: $('#sahdev_instruction').val(),
                technical_context: $('#sahdev_technical_context').val(),
                intent: $('#sahdev_intent').val(),
                use_summary: $('#sahdev_use_summary').length && !$('#sahdev_use_summary').is(':checked') ? 0 : 1,
                include_tools_context: $('#sahdev_include_tools_context').length && !$('#sahdev_include_tools_context').is(':checked') ? 0 : 1,
                include_admin_notes: $('#sahdev_include_admin_notes').length && $('#sahdev_include_admin_notes').is(':checked') ? 1 : 0,
                include_historical_context: $('#sahdev_include_history').is(':checked') ? 1 : 0,
                token: $('input[name="token"]').val(),
                force_regenerate: isRegenerate ? 'true' : 'false',
                override_provider_id: $('#sahdev_override_provider').val() || '0'
            };

            var isOpenContextMode = ($('#sahdev_is_open_context_mode').val() === 'true');
            var payloadAction = isOpenContextMode ? 'get_open_payload' : 'get_payload';
            var payloadReqData = Object.assign({ action: payloadAction }, baseReqData);

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: payloadReqData,
                dataType: 'json',
                success: function(res) {
                    if (res && res.status === 'success') {
                        if (res.cached) {
                            renderSahdevResults(res.data, 'Cached', 0);
                            return;
                        }

                        executeBackendGoogleCall(baseReqData, $btn);
                    } else {
                        showSahdevError(res.message || 'Failed to initialize AI request.');
                        $('#btn-sahdev-analyze, #btn-sahdev-regenerate').prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    handleAjaxError(xhr, error);
                    $('#btn-sahdev-analyze, #btn-sahdev-regenerate').prop('disabled', false);
                }
            });
        });

        function buildToolOutputText(summary, viewMode) {
            if (!summary) return '';
            var mode = viewMode || 'readable';
            var lines = [];
            lines.push('Status: ' + (summary.status || 'unknown'));
            var runs = summary.runs || [];
            for (var i = 0; i < runs.length; i++) {
                var r = runs[i] || {};
                lines.push('[' + (r.method || '') + ' ' + (r.path || '') + '] status=' + (r.status || '') + ' http=' + (r.http_status || 0));
                var readable = r.normalized_summary ? String(r.normalized_summary) : '';
                var raw = r.response_body ? String(r.response_body) : '';
                if (mode === 'readable') {
                    lines.push('READABLE: ' + (readable || '(not available; raw fallback will be used for AI context)'));
                } else if (mode === 'raw') {
                    if (raw) lines.push('RAW: ' + raw);
                } else {
                    if (readable) lines.push('READABLE: ' + readable);
                    if (raw) lines.push('RAW: ' + raw);
                }
                if (r.normalization_error) lines.push('READABLE EXTRACTION NOTE: ' + String(r.normalization_error));
                if (r.error_message) lines.push('ERROR: ' + r.error_message);
                lines.push('---');
            }
            return lines.join('\n');
        }

        function refreshToolsStatus() {
            var reqData = {
                action: 'get_tools_ticket_status',
                ticket_id: $('#sahdev_ticket_id').val(),
                token: $('input[name="token"]').val()
            };
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    if (!res || res.status !== 'success' || !res.summary) return;
                    var s = res.summary;
                    var runs = (s.runs || []).length;
                    $('#sahdev-tools-status').text('Latest tools run: ' + (s.status || 'unknown') + ' | calls: ' + runs + ' | at: ' + (s.updated_at || 'n/a'));
                    $('#sahdev-tools-output-edit').val(buildToolOutputText(s, $('#sahdev-tools-view-mode').val()));
                    $('#sahdev-tools-meta').text('Suggestion ID: ' + (s.suggestion_id || '-') + ' | calls stored: ' + runs);
                }
            });
        }

        $(document).on('click', '#btn-sahdev-run-tools, #btn-sahdev-rerun-tools', function(e) {
            e.preventDefault();
            var isRerun = $(this).attr('id') === 'btn-sahdev-rerun-tools';
            $('#sahdev-tools-status').text((isRerun ? 'Re-running' : 'Running') + ' tool execution...');
            var reqData = {
                action: 'run_tools_for_ticket',
                ticket_id: $('#sahdev_ticket_id').val(),
                force: isRerun ? '1' : '0',
                token: $('input[name="token"]').val()
            };
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    if (res && res.status === 'success') {
                        var summary = res.summary || {};
                        var runs = (summary.runs || []).length;
                        $('#sahdev-tools-status').text('Tool execution complete. Status: ' + (summary.status || 'ok') + ' | calls: ' + runs);
                        $('#sahdev-tools-output-edit').val(buildToolOutputText(summary, $('#sahdev-tools-view-mode').val()));
                        $('#sahdev-tools-meta').text('Suggestion ID: ' + (summary.suggestion_id || '-') + ' | calls stored: ' + runs);
                    } else {
                        $('#sahdev-tools-status').text('Tool execution failed: ' + (res && res.message ? res.message : 'unknown error'));
                    }
                },
                error: function(xhr) {
                    var msg = xhr && xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : ('HTTP ' + xhr.status);
                    $('#sahdev-tools-status').text('Tool execution failed: ' + msg);
                }
            });
        });

        $(document).on('click', '#btn-sahdev-append-tools-context', function() {
            var txt = ($('#sahdev-tools-output-edit').val() || '').trim();
            if (!txt) return;
            var existing = ($('#sahdev_technical_context').val() || '').trim();
            var merged = existing ? (existing + "\n\n=== MANUALLY CURATED TOOL OUTPUT ===\n" + txt) : ("=== MANUALLY CURATED TOOL OUTPUT ===\n" + txt);
            $('#sahdev_technical_context').val(merged);
            $('#sahdev-tools-status').text('Tool output appended to Technical Context.');
        });

        $(document).on('click', '#sahdev-manual-json-toggle', function(e) {
            e.preventDefault();
            $('#sahdev-manual-json-wrap').toggle();
        });

        function loadToolOperations() {
            var reqData = {
                action: 'get_tools_operations',
                ticket_id: $('#sahdev_ticket_id').val(),
                token: $('input[name="token"]').val()
            };
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    if (!res || res.status !== 'success') return;
                    var ops = res.operations || [];
                    var $sel = $('#sahdev-manual-tool-op');
                    $sel.empty().append('<option value="">Select Tool Operation...</option>');
                    for (var i = 0; i < ops.length; i++) {
                        var op = ops[i];
                        var label = (op.method || 'GET') + ' ' + (op.path || '');
                        $sel.append('<option value="' + label.replace(/"/g, '&quot;') + '">' + label + '</option>');
                    }
                    var smart = res.smart || {};
                    
                    // Fill the new human-friendly fields
                    if (smart.default_domain) $('#sahdev-tool-domain').val(smart.default_domain);
                    if (smart.default_ip) $('#sahdev-tool-ip').val(smart.default_ip);
                    if (smart.default_url) {
                        if (!$('#sahdev-tool-domain').val()) $('#sahdev-tool-domain').val(smart.default_url);
                    }

                    // Also keep the raw JSON fields updated for advanced users
                    var pathParams = {};
                    if (smart.default_domain) pathParams.domain = smart.default_domain;
                    if (smart.default_ip) pathParams.ip = smart.default_ip;
                    if (smart.default_url) pathParams.target = smart.default_url;
                    $('#sahdev-manual-path-params').val(JSON.stringify(pathParams));
                    $('#sahdev-manual-query').val('{}');
                }
            });
        }

        // Unified parameter mapping logic
        var syncManualToolUI = function() {
            var op = $('#sahdev-manual-tool-op').val() || '';
            var splitAt = op.indexOf(' ');
            var path = splitAt > 0 ? op.substring(splitAt + 1) : op;
            
            var domainVal = $('#sahdev-tool-domain').val() || '';
            var ipVal = $('#sahdev-tool-ip').val() || '';
            var emailVal = $('#sahdev-tool-email').val() || '';
            var extraVal = $('#sahdev-tool-extra').val() || '';

            var pathParams = {};
            var queryParams = {};

            // Extract placeholders from the path - support {}, (), and [] due to environment mangling
            var placeholders = path.match(/[{(]([^})]+)[})]|\[([^\]]+)\]/g) || [];
            placeholders.forEach(function(ph) {
                var key = ph.replace(/[{}()\[\]]/g, '');
                pathParams[key] = ''; // Initialize
            });

            var mapInputToParams = function(targetObj) {
                var keys = Object.keys(targetObj);
                keys.forEach(function(key) {
                    var lowerKey = key.toLowerCase();
                    if (['domain', 'target', 'host', 'hostname', 'url'].indexOf(lowerKey) !== -1) {
                        if (domainVal) targetObj[key] = domainVal;
                    } else if (['ip', 'address'].indexOf(lowerKey) !== -1) {
                        if (ipVal) targetObj[key] = ipVal;
                    } else if (['email', 'account', 'user', 'username'].indexOf(lowerKey) !== -1) {
                        if (emailVal) targetObj[key] = emailVal;
                    } else if (['type', 'record_type', 'record', 'extra', 'q', 'query'].indexOf(lowerKey) !== -1) {
                        if (extraVal) targetObj[key] = extraVal;
                    }
                });
            };

            mapInputToParams(pathParams);
            
            // Default query params logic
            if (domainVal && !pathParams.domain && !pathParams.target && !pathParams.host) queryParams.domain = domainVal;
            if (ipVal && !pathParams.ip) queryParams.ip = ipVal;
            if (extraVal && !pathParams.type) queryParams.type = extraVal;
            
            mapInputToParams(queryParams);

            // Update the "Advanced JSON" fields
            if (!$('#sahdev-manual-path-params').is(':focus')) {
                $('#sahdev-manual-path-params').val(JSON.stringify(pathParams));
            }
            if (!$('#sahdev-manual-query').is(':focus')) {
                $('#sahdev-manual-query').val(JSON.stringify(queryParams));
            }

            validateJSONFields();
        };

        var validateJSONFields = function() {
            $('.sahdev-json-input').each(function() {
                var val = $(this).val();
                var $badge = $(this).parent().find('.sahdev-json-badge');
                if (!$badge.length) {
                    $badge = $('<span class="sahdev-json-badge" style="font-size:10px; margin-left:5px;"></span>');
                    $(this).after($badge);
                }
                try {
                    JSON.parse(val || '{}');
                    $badge.text('✓ Valid').css('color', 'green');
                } catch(e) {
                    $badge.text('✗ Invalid').css('color', 'red');
                }
            });
        };

        $(document).on('input change', '#sahdev-tool-domain, #sahdev-tool-ip, #sahdev-tool-email, #sahdev-tool-extra, #sahdev-manual-tool-op', function() {
            syncManualToolUI();
        });

        $(document).on('input', '.sahdev-json-input', function() {
            validateJSONFields();
        });

        $(document).on('click', '#btn-sahdev-manual-tool-run', function() {
            var op = $('#sahdev-manual-tool-op').val() || '';
            if (!op) {
                $('#sahdev-tools-status').text('Select a tool operation first.');
                return;
            }
            var splitAt = op.indexOf(' ');
            var method = splitAt > 0 ? op.substring(0, splitAt) : 'GET';
            var path = splitAt > 0 ? op.substring(splitAt + 1) : op;

            var pathParams = {};
            var queryParams = {};
            try {
                pathParams = JSON.parse($('#sahdev-manual-path-params').val() || '{}');
                queryParams = JSON.parse($('#sahdev-manual-query').val() || '{}');
            } catch(e) {
                $('#sahdev-tools-status').text('Invalid JSON in advanced fields.');
                return;
            }

            $('#sahdev-tools-status').text('Running manual tool execution...');
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'run_manual_tool',
                    ticket_id: $('#sahdev_ticket_id').val(),
                    method: method,
                    path: path,
                    path_params: JSON.stringify(pathParams),
                    query: JSON.stringify(queryParams),
                    body: $('#sahdev-manual-body').val() || '{}',
                    token: $('input[name="token"]').val()
                },
                success: function(res) {
                    if (res && res.status === 'success') {
                        var summary = res.summary || {};
                        var runs = (summary.runs || []).length;
                        $('#sahdev-tools-status').text('Manual tool run saved. Total calls: ' + runs);
                        $('#sahdev-tools-output-edit').val(buildToolOutputText(summary, $('#sahdev-tools-view-mode').val()));
                        $('#sahdev-tools-meta').text('Suggestion ID: ' + (summary.suggestion_id || '-') + ' | calls stored: ' + runs);
                    } else {
                        $('#sahdev-tools-status').text('Manual tool run failed: ' + (res && res.message ? res.message : 'unknown error'));
                    }
                },
                error: function(xhr) {
                    var msg = xhr && xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : ('HTTP ' + xhr.status);
                    $('#sahdev-tools-status').text('Manual tool run failed: ' + msg);
                }
            });
        });

        refreshToolsStatus();
        loadToolOperations();
        $(document).on('change', '#sahdev-tools-view-mode', function() {
            refreshToolsStatus();
        });
        
        function executeBackendGoogleCall(baseReqData, $btn) {
            var isOpenContextMode = ($('#sahdev_is_open_context_mode').val() === 'true');
            var backendAction = isOpenContextMode ? 'analyze_open_context' : 'analyze_ticket';
            var reqData = Object.assign({ action: backendAction }, baseReqData);
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    $btn.prop('disabled', false);
                    if (res && res.status === 'success') {
                        renderSahdevResults(res.data, res.tokens_used, res.execution_time_ms, res.tokens_details);
                    } else {
                        showSahdevError(res.message || 'Unknown error occurred.');
                    }
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false);
                    handleAjaxError(xhr, error);
                }
            });
        }

        async function executeLocalLMStudioCall(config, baseReqData, $btn) {
            if (!config.api_url) {
                showSahdevError("LM Studio API URL is not configured in WHMCS Addon Settings.");
                $btn.prop('disabled', false);
                return;
            }

            // config.custom_instruction and config.tone are passed from backend get_payload
            // Note: config.custom_instruction already contains the intent directive (injected server-side)
            var promptText = buildPromptText(config.context, config.tone, config.custom_instruction, config.user_prompt_template);
            var systemMessage = config.system_prompt || "You are a helpful Senior Technical Support Engineer.";

            // --- Context window budget guard ---
            // Estimate available input tokens: assume model n_ctx ≈ 4096 (conservative default).
            // Reserve config.max_tokens for output (or 512 if unset), rest is input budget.
            var outputReserve = (config.max_tokens > 0 ? config.max_tokens : 512);
            var estimatedNCtx = 4096; // conservative default; LM Studio doesn't expose n_ctx via API
            var inputBudgetTokens = Math.max(estimatedNCtx - outputReserve, 1024);
            var CHARS_PER_TOKEN = 4;
            var inputBudgetChars = inputBudgetTokens * CHARS_PER_TOKEN;

            // How many chars are we sending?
            var totalChars = systemMessage.length + promptText.length;

            if (totalChars > inputBudgetChars) {
                // Reserve at least 25% of budget for system message (core identity), rest for user prompt
                var minSystemChars = Math.floor(inputBudgetChars * 0.25);
                var maxSystemChars = Math.floor(inputBudgetChars * 0.40);
                var maxUserChars   = inputBudgetChars - Math.min(systemMessage.length, maxSystemChars);

                if (systemMessage.length > maxSystemChars) {
                    systemMessage = systemMessage.substring(0, maxSystemChars) + "\n...[system prompt truncated to fit context window]";
                }
                if (promptText.length > maxUserChars) {
                    promptText = promptText.substring(0, maxUserChars) + "\n...[user prompt truncated to fit context window]";
                }

                console.warn("Sahdev: Prompt trimmed to fit LM Studio context window. Budget: " + inputBudgetChars + " chars. Was: " + totalChars + " chars.");
            }
            // --- End budget guard ---

            var llmPayload = {
                model: config.model || "local-model",
                messages: [
                    { role: "system", content: systemMessage },
                    { role: "user", content: promptText }
                ],
                temperature: config.temperature || 0.7,
                stream: false
            };

            var startTime = performance.now();

            try {
                var response = await fetch(config.api_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(llmPayload)
                });

                if (!response.ok) {
                    var errText = await response.text();
                    throw new Error("HTTP " + response.status + " - " + errText);
                }

                var data = await response.json();
                var rawContent = data.choices && data.choices[0] && data.choices[0].message ? data.choices[0].message.content : "";
                
                var cleanContent = rawContent.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();
                
                // Sometimes models hallucinate markdown formatting or conversational padding
                var firstBrace = cleanContent.indexOf('{');
                var lastBrace = cleanContent.lastIndexOf('}');
                if (firstBrace !== -1 && lastBrace !== -1) {
                    cleanContent = cleanContent.substring(firstBrace, lastBrace + 1);
                }
                
                var parsedResponse;
                try {
                    parsedResponse = robustJsonParse(cleanContent);
                } catch (e) {
                    throw new Error("Failed to parse local AI JSON even after recovery. Raw output: " + cleanContent.substring(0, 200));
                }

                var tokenDetails = { input: 0, output: 0 };
                var totalTokens = 0;
                if (data.usage) {
                    tokenDetails.input = data.usage.prompt_tokens || 0;
                    tokenDetails.output = data.usage.completion_tokens || 0;
                    totalTokens = data.usage.total_tokens || (tokenDetails.input + tokenDetails.output);
                }
                var execTimeMs = Math.round(performance.now() - startTime);

                saveResponseToBackend(config.hash_signature, parsedResponse, totalTokens, execTimeMs, baseReqData, $btn, tokenDetails);

            } catch (err) {
                var isFailedToFetch = err.message.toLowerCase().indexOf('failed to fetch') !== -1 || err.message.toLowerCase().indexOf('networkerror') !== -1;
                var errMsg = isFailedToFetch ? "Could not connect to LM Studio at " + config.api_url + ". Ensure LM Studio is running, Local Server is started, and CORS is enabled." : err.message;
                
                if (config.has_fallback) {
                    $('#sahdev-loading p').text("Local AI failed. Attempting Fallback Provider...");
                    baseReqData.force_fallback = 'true';
                    executeBackendGoogleCall(baseReqData, $btn);
                } else {
                    showSahdevError("Local AI Error: " + errMsg);
                    $btn.prop('disabled', false);
                }
            }
        }

        function saveResponseToBackend(hashSignature, aiResponseObj, tokensUsed, execTime, baseReqData, $btn, tokenDetails) {
            // Encode as base64 to avoid backend framework sanitization destroying newlines and quotes
            var base64Json = btoa(unescape(encodeURIComponent(JSON.stringify(aiResponseObj))));

            var isOpenContextMode = ($('#sahdev_is_open_context_mode').val() === 'true');
            if (isOpenContextMode) {
                $btn.prop('disabled', false);
                renderSahdevResults(aiResponseObj, tokensUsed, execTime, tokenDetails);
                return;
            }

            var reqData = Object.assign({ 
                action: 'save_response',
                hash_signature: hashSignature,
                ai_response: base64Json,
                token_usage: tokensUsed,
                exec_time: execTime,
                token_details: JSON.stringify(tokenDetails || {})
            }, baseReqData);

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    $btn.prop('disabled', false);
                    if (res && res.status === 'success') {
                        renderSahdevResults(aiResponseObj, tokensUsed, execTime, tokenDetails);
                    } else {
                        showSahdevError("AI succeeded but failed to save: " + (res.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false);
                    handleAjaxError(xhr, error);
                }
            });
        }

        /**
         * Robust JSON parser — handles truncated, markdown-wrapped, and partially malformed AI responses.
         *
         * Pipeline:
         *  1. Direct JSON.parse (happy path)
         *  2. Strip markdown code fences
         *  3. Extract balanced JSON object via brace counting
         *  4. Attempt to repair truncated JSON (close open strings/arrays/objects)
         *  5. Regex fallback: extract individual fields so we always return something
         */
        function robustJsonParse(raw) {
            if (!raw) throw new Error("Empty response");

            // --- Stage 1: direct parse ---
            try { return JSON.parse(raw); } catch(e) {}

            // --- Stage 2: strip markdown fences ---
            var s = raw.replace(/```json\s*/gi, '').replace(/```\s*/g, '').trim();
            // Strip DeepSeek <think>...</think> blocks
            s = s.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();
            try { return JSON.parse(s); } catch(e) {}

            // --- Stage 3: extract balanced JSON object via brace counting ---
            var start = s.indexOf('{');
            if (start !== -1) {
                var depth = 0, inStr = false, esc = false, end = -1;
                for (var i = start; i < s.length; i++) {
                    var ch = s[i];
                    if (esc) { esc = false; continue; }
                    if (ch === '\\' && inStr) { esc = true; continue; }
                    if (ch === '"') { inStr = !inStr; continue; }
                    if (inStr) continue;
                    if (ch === '{') depth++;
                    else if (ch === '}') { depth--; if (depth === 0) { end = i; break; } }
                }
                if (end !== -1) {
                    try { return JSON.parse(s.substring(start, end + 1)); } catch(e) {}
                }

                // --- Stage 4: repair truncated JSON (JSON not fully closed) ---
                var partial = (end !== -1) ? s.substring(start, end + 1) : s.substring(start);
                var repaired = repairTruncatedJson(partial);
                if (repaired) {
                    try { return JSON.parse(repaired); } catch(e) {}
                }
            }

            // --- Stage 5: regex field extraction fallback ---
            console.warn('Sahdev: JSON recovery falling back to regex field extraction. Raw:', s.substring(0, 300));
            var defaults = {
                ROOT_CAUSE: 'Analysis incomplete (response truncated by model).',
                RESPONSIBILITY: 'Unknown',
                RISK_LEVEL: 'Unknown',
                INTERNAL_ACTION_PLAN: 'Review the ticket manually. The AI response was truncated before completing. Consider increasing max_tokens in settings.',
                CLIENT_REPLY: 'We have received your ticket and are reviewing the issue. We will get back to you shortly.'
            };
            var fields = ['ROOT_CAUSE', 'RESPONSIBILITY', 'RISK_LEVEL', 'INTERNAL_ACTION_PLAN', 'CLIENT_REPLY'];
            fields.forEach(function(f) {
                // Match "FIELD": "value..." — value may be truncated
                var re = new RegExp('"' + f + '"\\s*:\\s*"((?:[^"\\\\]|\\\\[\\s\\S])*)"?', 'i');
                var m = s.match(re);
                if (m && m[1]) defaults[f] = m[1].replace(/\\n/g, '\n').replace(/\\"/g, '"');
            });
            return defaults;
        }

        /**
         * Attempts to close an unclosed / truncated JSON string by tracking open
         * strings, arrays, and object keys.
         */
        function repairTruncatedJson(json) {
            try {
                // Remove trailing comma before closing
                var s = json.replace(/,\s*$/, '');
                var stack = [];
                var inStr = false, esc = false;

                for (var i = 0; i < s.length; i++) {
                    var ch = s[i];
                    if (esc) { esc = false; continue; }
                    if (ch === '\\' && inStr) { esc = true; continue; }
                    if (ch === '"') {
                        inStr = !inStr;
                        if (inStr) stack.push('"');
                        else if (stack[stack.length - 1] === '"') stack.pop();
                        continue;
                    }
                    if (inStr) continue;
                    if (ch === '{') stack.push('}');
                    else if (ch === '[') stack.push(']');
                    else if (ch === '}' || ch === ']') stack.pop();
                }

                // If still inside a string, close it
                var suffix = '';
                if (inStr) suffix += '"';

                // Close any open brackets/braces in reverse order
                var closers = stack.filter(function(c) { return c === '}' || c === ']'; }).reverse();
                suffix += closers.join('');

                return s + suffix;
            } catch(e) {
                return null;
            }
        }

        /**
         * Sanitize text before inserting into the AI prompt.
         * Removes HTML, control chars, code fences, and excess whitespace
         * that can confuse the model and cause malformed JSON responses.
         */
        function sanitizeForPrompt(text) {
            if (!text) return '';
            var s = String(text);
            // Strip HTML tags (ticket messages often contain HTML)
            s = s.replace(/<[^>]*>/g, ' ');
            // Decode common HTML entities
            s = s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&nbsp;/g, ' ');
            // Remove triple backtick blocks (code) — these can trip JSON mode
            s = s.replace(/```[\s\S]*?```/g, '[code block removed]');
            // Remove null bytes and other control chars (except newline/tab)
            s = s.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
            // Normalize multiple spaces/newlines
            s = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\n{4,}/g, '\n\n\n').replace(/ {3,}/g, '  ');
            return s.trim();
        }

        function buildPromptText(context, tone, customInstruction, userPromptTemplate) {
            // Build messages block
            var messagesBlock = '';
            if (context.messages && context.messages.length > 0) {
                var MSG_BUDGET = 8000;
                var used = 0;
                var lines = [];
                var msgs = context.messages.slice();
                msgs.reverse(); // newest first for budget
                for (var mi = 0; mi < msgs.length; mi++) {
                    var msg = msgs[mi];
                    var type = msg.admin ? 'ADMIN' : 'CLIENT';
                    var body = sanitizeForPrompt(msg.message || '');
                    var entry = '[' + type + '] (' + msg.date + '):\n' + body + '\n\n';
                    if (used + entry.length > MSG_BUDGET) break;
                    lines.push(entry);
                    used += entry.length;
                }
                lines.reverse();
                messagesBlock = lines.join('');
            }

            // Build services block
            var servicesBlock = context.services_summary
                ? ('Services:\n' + sanitizeForPrompt(context.services_summary) + '\n')
                : '';

            // Build attachments block
            var attachmentsBlock = context.attachments_text
                ? ('\n=== ATTACHMENT CONTEXT ===\n' + sanitizeForPrompt(context.attachments_text.substring(0, 2000)) + '\n')
                : '';

            // Build admin notes block
            var adminNotesBlock = context.admin_notes
                ? ('\n=== PRIVATE ADMIN NOTES ===\n' + sanitizeForPrompt(context.admin_notes) + '\n')
                : '';

            // Build custom instruction block — SUPREME PRIORITY always at the top
            var customInstructionBlock = '';
            if (customInstruction && customInstruction.trim()) {
                customInstructionBlock = '⚠️ PRIORITY OVERRIDE — ADMIN INSTRUCTION ⚠️\n' +
                    'This instruction supersedes all other context. Re-interpret all ticket data through this lens.\n' +
                    customInstruction.trim() + '\n' +
                    '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n';
            }

            // If admin has defined a custom template, use it
            if (userPromptTemplate && userPromptTemplate.trim()) {
                var prompt = userPromptTemplate
                    .replace('{{CUSTOM_INSTRUCTION_BLOCK}}', customInstructionBlock)
                    .replace('{{TONE}}', tone || 'Professional')
                    .replace('{{CLIENT_NAME}}', context.client_name || 'Unknown Client')
                    .replace('{{DEPARTMENT}}', context.department || 'Support')
                    .replace('{{SUBJECT}}', context.subject || 'Ticket')
                    .replace('{{SERVICES_BLOCK}}', servicesBlock)
                    .replace('{{MESSAGES}}', messagesBlock)
                    .replace('{{ATTACHMENTS_BLOCK}}', attachmentsBlock);
                return prompt;
            }

            // Fallback: hardcoded prompt with same structure
            var prompt = customInstructionBlock;
            prompt += "=== TASK ===\nAnalyze the provided technical support ticket and output ONLY a valid JSON object. No extra text.\n\n";
            prompt += "=== SCHEMA ===\n{\n";
            prompt += "  \"ROOT_CAUSE\": \"string (brief technical analysis)\",\n";
            prompt += "  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n";
            prompt += "  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n";
            prompt += "  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n";
            prompt += "  \"CLIENT_REPLY\": \"string (reply to client in Markdown — including a professional greeting, but no sign-off)\"\n}\n\n";
            prompt += "=== TONE ===\nWrite CLIENT_REPLY in a " + (tone || 'Professional') + " tone.\n\n";
            prompt += "=== TICKET DATA ===\n";
            prompt += "Client: " + (context.client_name || 'Unknown Client') + "\n";
            prompt += "Department: " + (context.department || 'Support') + "\n";
            prompt += "Subject: " + (context.subject || 'Ticket') + "\n";
            if (servicesBlock) prompt += servicesBlock;
            prompt += "\n=== CONVERSATION ===\n" + messagesBlock;
            if (attachmentsBlock) prompt += attachmentsBlock;
            if (adminNotesBlock) prompt += adminNotesBlock;
            return prompt;
        }

        function renderSahdevResults(data, tokensUsed, executionTimeMs, tokenDetails) {
            $('#sahdev-loading').hide();
            $('#sahdev-out-cause').text(data.ROOT_CAUSE || 'N/A');
            $('#sahdev-out-resp').text(data.RESPONSIBILITY || 'N/A');
            $('#sahdev-out-risk').text(data.RISK_LEVEL || 'N/A');
            $('#sahdev-out-plan').text(data.INTERNAL_ACTION_PLAN || 'N/A');
            
            var formattedReply = data.CLIENT_REPLY ? String(data.CLIENT_REPLY) : 'N/A';
            $('#sahdev-out-reply').text(formattedReply).css('white-space', 'pre-wrap');

            // Show intent badge
            var intentLabels = {
                'AUTO': '🤖 Auto', 'RESOLVE': '✅ Resolved', 'INVESTIGATE': '🔍 Investigating',
                'MORE_INFO': '❓ More Info', 'GUIDE': '🗺️ Guide', 'OUT_OF_SCOPE': '🚫 Out of Scope', 'DUPLICATE': '🔁 Duplicate'
            };
            var currentIntent = $('#sahdev_intent').val() || 'AUTO';
            var intentLabel = intentLabels[currentIntent] || currentIntent;
            $('#sahdev-intent-display').html('<span style="background:#e9ecef; border-radius:10px; padding:1px 8px; font-size:11px;">Intent: <strong>' + intentLabel + '</strong></span>');

            var stats = "Tokens: ";
            if (tokenDetails && (tokenDetails.input > 0 || tokenDetails.output > 0)) {
                stats += tokenDetails.input + " In / " + tokenDetails.output + " Out";
            } else {
                stats += (tokensUsed || 'Unknown');
            }
            stats += " | Time: " + (executionTimeMs || 0) + "ms";
            
            $('#sahdev-token-usage').text(stats);

            $('#sahdev-results').fadeIn();
            $('#btn-sahdev-regenerate').show();

            // Display embedded Quality Score if enabled and present
            var $aiBadge = $('#sahdev-reply-score-badge');
            if (sahdevQualityScorer && data.hasOwnProperty('SCORE')) {
                var score = parseInt(data.SCORE) || 0;
                var colorClass = score >= 85 ? 'label-success' : (score >= 70 ? 'label-warning' : 'label-danger');
                var title = "Clarity: " + (data.CLARITY||0) + "% | Tone: " + (data.TONE_SCORE||0) + "% | Completeness: " + (data.COMPLETENESS||0) + "%\nNote: " + (data.REPLY_NOTES||'');
                $aiBadge.removeClass('label-default label-success label-warning label-danger').addClass(colorClass).html('<i class="fas fa-robot"></i> AI Score: ' + score + '/100').attr('title', title).show();
            } else {
                $aiBadge.hide();
            }
        }

        function showSahdevError(msg) {
            $('#sahdev-loading').hide();
            $('#sahdev-error').html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
        }

        function handleAjaxError(xhr, error) {
            $('#sahdev-loading').hide();
            var msg = 'AJAX Error: ' + error + ' (Status: ' + xhr.status + ')<br><br>';
            if(xhr.responseJSON && xhr.responseJSON.message) {
                msg += xhr.responseJSON.message;
            } else if (xhr.responseText) {
                msg += '<strong>Raw Server Response:</strong><br><textarea class="form-control" rows="5" readonly>' + xhr.responseText + '</textarea>';
            } else {
                msg += 'No response text available.';
            }
            showSahdevError(msg);
        }

        // =====================================================================
        // FEATURE: Response Quality Scorer
        // =====================================================================
        function triggerReplyScoring(replyText, isAiGenerated, $badgeElement) {
            $badgeElement.removeClass('label-success label-warning label-danger label-info').addClass('label-default').html('<i class="fas fa-spinner fa-spin"></i> Scoring...').show();
            
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: {
                    action: 'score_reply',
                    ticket_id: $('#sahdev_ticket_id').val(),
                    reply_text: replyText,
                    is_ai_generated: isAiGenerated ? 'true' : 'false',
                    token: $('input[name="token"]').val()
                },
                dataType: 'json',
                success: function(res) {
                    if (res && res.status === 'success' && res.data) {
                        var score = parseInt(res.data.SCORE) || 0;
                        var colorClass = score >= 85 ? 'label-success' : (score >= 70 ? 'label-warning' : 'label-danger');
                        var title = "Clarity: " + (res.data.CLARITY||0) + "% | Tone: " + (res.data.TONE_SCORE||0) + "% | Completeness: " + (res.data.COMPLETENESS||0) + "%\nNote: " + (res.data.REPLY_NOTES||'');
                        $badgeElement.removeClass('label-default label-success label-warning label-danger label-info').addClass(colorClass).html('<i class="fas fa-bullseye"></i> Admin Draft Score: ' + score + '/100').attr('title', title);
                    } else {
                        $badgeElement.removeClass('label-default label-success label-warning label-danger label-info').addClass('label-danger').html('<i class="fas fa-exclamation-triangle"></i> Scoring Failed').removeAttr('title');
                    }
                },
                error: function() {
                    $badgeElement.removeClass('label-default label-success label-warning label-danger label-info').addClass('label-danger').html('<i class="fas fa-exclamation-triangle"></i> Scoring Error').removeAttr('title');
                }
            });
        }

        $(document).on('click', '#btn-sahdev-score-draft', function() {
            var draftText = '';
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                draftText = tinymce.activeEditor.getContent({ format: 'text' }).trim();
                if (!draftText) {
                    draftText = tinymce.activeEditor.getContent().replace(/<[^>]*>/g, '').trim();
                }
            }
            if (!draftText && $('#replymessage').length) {
                draftText = $('#replymessage').val().trim();
            }

            if (!draftText) {
                $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> Please write a draft reply in the editor first before scoring.').show();
                return;
            }

            $('#sahdev-rewrite-error').hide();
            triggerReplyScoring(draftText, false, $('#sahdev-rewrite-status'));
        });

        // =====================================================================
        // FEATURE: ✍️ Rewrite It — Expand Admin Draft Reply
        // Uses get_rewrite_payload → browser-side LM Studio fetch (or server-side for Google)
        // =====================================================================
        $(document).on('click', '#btn-sahdev-rewrite', function() {
            var draftText = '';

            // Try TinyMCE first
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                draftText = tinymce.activeEditor.getContent({ format: 'text' }).trim();
                if (!draftText) {
                    draftText = tinymce.activeEditor.getContent().replace(/<[^>]*>/g, '').trim();
                }
            }
            // Fallback to raw textarea
            if (!draftText && $('#replymessage').length) {
                draftText = $('#replymessage').val().trim();
            }

            if (!draftText) {
                $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> Please write a short draft reply in the editor first, then click Rewrite It.').show();
                return;
            }

            $('#sahdev-rewrite-error').hide();
            $('#sahdev-rewrite-loading').show();
            $('#sahdev-rewrite-status').text('');
            $('#btn-sahdev-rewrite').prop('disabled', true);

            var rewriteBaseData = {
                ticket_id: $('#sahdev_ticket_id').val(),
                draft_text: draftText,
                tone: $('#sahdev_tone').val() || 'Professional',
                instruction: $('#sahdev_instruction').val() || '',
                token: $('input[name="token"]').val()
            };

            // Step 1: Get payload from server (provider info + constructed prompt)
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: Object.assign({ action: 'get_rewrite_payload' }, rewriteBaseData),
                dataType: 'json',
                success: function(res) {
                    if (!res || res.status !== 'success') {
                        showRewriteError((res && res.message) ? res.message : 'Failed to prepare rewrite request.');
                        return;
                    }

                    $.ajax({
                        url: sahdevAjaxUrl,
                        type: 'POST',
                        data: Object.assign({ action: 'rewrite_reply' }, rewriteBaseData),
                        dataType: 'json',
                        success: function(r) {
                            $('#sahdev-rewrite-loading').hide();
                            $('#btn-sahdev-rewrite').prop('disabled', false);
                            if (r && r.status === 'success' && r.reply) {
                                insertRewriteResult(r.reply);
                            } else {
                                showRewriteError((r && r.message) ? r.message : 'Server rewrite failed.');
                            }
                        },
                        error: function(xhr, s, e) {
                            var msg = (xhr.responseJSON && xhr.responseJSON.message)
                                ? xhr.responseJSON.message
                                : ('Server error: ' + (e || 'HTTP ' + xhr.status));
                            showRewriteError(msg);
                        }
                    });
                },
                error: function(xhr, s, e) {
                    showRewriteError('Server error: ' + e + ' (HTTP ' + xhr.status + ')');
                }
            });
        });

        async function executeRewriteLMStudio(config, baseData) {
            if (!config.api_url) {
                showRewriteError('LM Studio API URL not configured.');
                return;
            }
            try {
                var sysContent  = config.system_prompt || 'You are a helpful support specialist.';
                var userContent = config.rewrite_prompt || '';

                // Context window guard: 4 chars ≈ 1 token.
                // Reserve output tokens, cap total input to remaining budget.
                var outputReserve    = Math.max(config.max_tokens || 512, 256);
                var ctxTokens        = 4096; // safe default for local models
                var inputBudgetChars = Math.max(ctxTokens - outputReserve, 1024) * 4;

                if (sysContent.length + userContent.length > inputBudgetChars) {
                    // Strategy: keep rewrite_prompt mostly intact — trim system prompt first.
                    // If the system prompt has a "RULES & KNOWLEDGEBASE" block, strip it first.
                    var kbSeparator = '=== RULES & KNOWLEDGEBASE ===';
                    var kbIdx = sysContent.indexOf(kbSeparator);
                    if (kbIdx !== -1) {
                        sysContent = sysContent.substring(0, kbIdx).trim() + '\n[Knowledgebase omitted to fit context window]';
                    }
                    // If still too long, hard-truncate system prompt to 40% of budget
                    var maxSys  = Math.floor(inputBudgetChars * 0.40);
                    var maxUser = inputBudgetChars - Math.min(sysContent.length, maxSys);
                    if (sysContent.length  > maxSys)  sysContent  = sysContent.substring(0, maxSys)   + '\n...[truncated]';
                    if (userContent.length > maxUser) userContent = userContent.substring(0, maxUser)  + '\n...[truncated]';
                }

                var llmPayload = {
                    model: config.model || 'local-model',
                    messages: [
                        { role: 'system', content: sysContent },
                        { role: 'user',   content: userContent }
                    ],
                    temperature: config.temperature || 0.7,
                    max_tokens: outputReserve,
                    stream: false
                };

                var response = await fetch(config.api_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(llmPayload)
                });

                if (!response.ok) { throw new Error('HTTP ' + response.status + ' - ' + await response.text()); }

                var data = await response.json();
                var rawText = data.choices && data.choices[0] && data.choices[0].message
                    ? data.choices[0].message.content : '';

                // Strip <think> blocks (DeepSeek etc.), trim whitespace
                rawText = rawText.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();

                $('#sahdev-rewrite-loading').hide();
                $('#btn-sahdev-rewrite').prop('disabled', false);

                if (rawText) {
                    insertRewriteResult(rawText);
                } else {
                    showRewriteError('LM Studio returned an empty response.');
                }
            } catch(err) {
                var isOffline = err.message.toLowerCase().indexOf('failed to fetch') !== -1 || err.message.toLowerCase().indexOf('networkerror') !== -1;
                if (isOffline && config.has_fallback) {
                    // Try server-side fallback (Google)
                    $.ajax({
                        url: sahdevAjaxUrl,
                        type: 'POST',
                        data: Object.assign({ action: 'rewrite_reply', force_fallback: 'true' }, baseData),
                        dataType: 'json',
                        success: function(r) {
                            $('#sahdev-rewrite-loading').hide();
                            $('#btn-sahdev-rewrite').prop('disabled', false);
                            if (r && r.status === 'success' && r.reply) { insertRewriteResult(r.reply); }
                            else { showRewriteError((r && r.message) || 'Fallback rewrite failed.'); }
                        },
                        error: function(xhr, s, e) {
                            var msg = (xhr.responseJSON && xhr.responseJSON.message)
                                ? xhr.responseJSON.message
                                : ('Fallback error: ' + (e || 'HTTP ' + xhr.status));
                            showRewriteError(msg);
                        }
                    });
                } else {
                    showRewriteError('LM Studio Error: ' + err.message);
                }
            }
        }

        function insertRewriteResult(replyText) {
            // --- Step 1: Unwrap if the model returned JSON instead of plain text ---
            var text = replyText ? replyText.trim() : '';

            // Strip <think> blocks (DeepSeek etc.)
            text = text.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();

            // Try JSON parse — handle {"body":"..."}, {"reply":"..."}, {"CLIENT_REPLY":"..."}, {"content":"..."}
            if (text.charAt(0) === '{' || text.charAt(0) === '[') {
                try {
                    var parsed = JSON.parse(text);
                    text = parsed.body || parsed.reply || parsed.CLIENT_REPLY || parsed.content || parsed.text || text;
                    if (typeof text !== 'string') text = JSON.stringify(text);
                } catch(e) {
                    // Not valid JSON — use as-is
                }
            }
            text = text.trim();

            // --- Step 2: Convert Markdown → HTML for TinyMCE ---
            function markdownToHtml(md) {
                var html = md;

                // Escape HTML special chars that aren't already HTML
                // (light touch — don't escape if it's already HTML from a previous pass)
                // Normalise line endings
                html = html.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

                // Headers: ### → <h3>
                html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
                html = html.replace(/^## (.+)$/gm,  '<h3>$1</h3>');

                // Bold: **text** or __text__
                html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                html = html.replace(/__(.+?)__/g,     '<strong>$1</strong>');

                // Italic: *text* or _text_ (but not ** already processed)
                html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
                html = html.replace(/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/g,      '<em>$1</em>');

                // Inline code: `code`
                html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

                // Horizontal rule: --- or ***
                html = html.replace(/^[-*]{3,}\s*$/gm, '<hr>');

                // Bullet lists: lines starting with * or - or •
                // Collect consecutive bullet lines and wrap in <ul>
                html = html.replace(/((?:^[ \t]*[-*•] .+\n?)+)/gm, function(block) {
                    var items = block.split('\n').filter(function(l){ return l.trim(); });
                    return '<ul>' + items.map(function(l) {
                        return '<li>' + l.replace(/^[ \t]*[-*•] /, '').trim() + '</li>';
                    }).join('') + '</ul>\n';
                });

                // Numbered lists: lines starting with 1. 2. etc.
                html = html.replace(/((?:^[ \t]*\d+\. .+\n?)+)/gm, function(block) {
                    var items = block.split('\n').filter(function(l){ return l.trim(); });
                    return '<ol>' + items.map(function(l) {
                        return '<li>' + l.replace(/^[ \t]*\d+\. /, '').trim() + '</li>';
                    }).join('') + '</ol>\n';
                });

                // Paragraphs: double newline → </p><p>, single newline → <br>
                // Split on double newlines to make paragraphs
                var blocks = html.split(/\n\n+/);
                html = blocks.map(function(block) {
                    block = block.trim();
                    if (!block) return '';
                    // Already block-level HTML — don't wrap in <p>
                    if (/^<(ul|ol|li|h[1-6]|hr|blockquote)/i.test(block)) return block;
                    // Single newlines inside a paragraph → <br>
                    return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
                }).join('\n');

                return html;
            }

            var finalHtml = markdownToHtml(text);

            // --- Step 3: Insert into TinyMCE or fallback textarea ---
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                tinymce.activeEditor.setContent(finalHtml);
            } else if ($('#replymessage').length) {
                // Textarea fallback: strip HTML tags, convert <br>/<p> to newlines
                var plainText = finalHtml
                    .replace(/<br\s*\/?>/gi, '\n')
                    .replace(/<\/p>/gi, '\n')
                    .replace(/<\/li>/gi, '\n')
                    .replace(/<[^>]+>/g, '')
                    .replace(/\n{3,}/g, '\n\n')
                    .trim();
                $('#replymessage').val(plainText);
            }

            $('#sahdev-rewrite-status').removeClass('label-default label-success label-warning label-danger label-info').addClass('label-success').html('<i class="fas fa-check-circle"></i> Draft polished!').removeAttr('title').show();
            if ($('#replyticket').length) {
                $('html, body').animate({ scrollTop: $('#replyticket').offset().top - 60 }, 400);
            }
        }

        function showRewriteError(msg) {
            $('#sahdev-rewrite-loading').hide();
            $('#btn-sahdev-rewrite').prop('disabled', false);
            $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
        }

        // =====================================================================
        // FEATURE: 📋 AI Ticket Summarizer
        // =====================================================================
        function sahdevSummaryUpdateUI(summary) {
            if (summary) {
                $('#sahdev-summary-display').text(summary).show();
                $('#sahdev-summary-exists-badge').show();
                $('#btn-sahdev-generate-summary').html('<i class="fas fa-sync"></i> Regenerate Summary');
                $('#btn-sahdev-delete-summary').show();
            } else {
                $('#sahdev-summary-display').hide().text('');
                $('#sahdev-summary-exists-badge').hide();
                $('#btn-sahdev-generate-summary').html('<i class="fas fa-magic"></i> Generate Summary');
                $('#btn-sahdev-delete-summary').hide();
            }
        }

        // Auto-load existing summary on page ready
        $(document).ready(function() {
            var ticketIdForSummary = $('#sahdev_ticket_id').val();
            if (ticketIdForSummary) {
                $.ajax({
                    url: sahdevAjaxUrl,
                    type: 'POST',
                    data: { action: 'get_summary', ticket_id: ticketIdForSummary, token: $('input[name="token"]').val() },
                    dataType: 'json',
                    success: function(r) {
                        if (r && r.status === 'success' && r.summary) {
                            sahdevSummaryUpdateUI(r.summary);
                            $('#sahdev-summary-meta').text('Auto-loaded from saved summary.');
                        }
                    }
                });
            }
        });

        // Generate / Regenerate
        $(document).on('click', '#btn-sahdev-generate-summary', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#sahdev-summary-error').hide();
            $('#sahdev-summary-loading').show();
            $('#sahdev-summary-meta').text('');

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: { action: 'generate_summary', ticket_id: $('#sahdev_ticket_id').val(), token: $('input[name="token"]').val() },
                dataType: 'json',
                success: function(r) {
                    $('#sahdev-summary-loading').hide();
                    $btn.prop('disabled', false);
                    if (r && r.status === 'success') {
                        sahdevSummaryUpdateUI(r.summary);
                        $('#sahdev-summary-meta').text('Generated in ' + (r.execution_time_ms || 0) + 'ms · ' + (r.message_count || '?') + ' messages condensed.');
                        $('#sahdev-summary-active-badge').show();
                    } else {
                        $('#sahdev-summary-error').html('<i class="fas fa-exclamation-circle"></i> ' + (r.message || 'Summary generation failed.')).show();
                    }
                },
                error: function(xhr, s, e) {
                    $('#sahdev-summary-loading').hide();
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : ('Error: ' + (e || 'HTTP ' + xhr.status));
                    $('#sahdev-summary-error').html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
                }
            });
        });

        // Delete summary
        $(document).on('click', '#btn-sahdev-delete-summary', function() {
            if (!confirm('Delete this ticket summary? The next AI generation will use the full message history again.')) return;
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: { action: 'delete_summary', ticket_id: $('#sahdev_ticket_id').val(), token: $('input[name="token"]').val() },
                dataType: 'json',
                success: function(r) {
                    $btn.prop('disabled', false);
                    sahdevSummaryUpdateUI(null);
                    $('#sahdev-summary-active-badge').hide();
                    $('#sahdev-summary-meta').text('Summary deleted.');
                },
                error: function() { $btn.prop('disabled', false); }
            });
        });


        // =====================================================================
        function renderSnapResults(data, tokensUsed, execTimeMs) {
            $('#sahdev-snapshot-loading').hide();
            $('#sahdev-snap-cause').text(data.ROOT_CAUSE || 'N/A');
            $('#sahdev-snap-resp').text(data.RESPONSIBILITY || 'N/A');
            $('#sahdev-snap-risk').text(data.RISK_LEVEL || 'N/A');
            $('#sahdev-snap-plan').text(data.INTERNAL_ACTION_PLAN || 'N/A');
            var snapReplyText = data.CLIENT_REPLY ? String(data.CLIENT_REPLY) : 'N/A';
            $('#sahdev-snap-reply').text(snapReplyText).css('white-space', 'pre-wrap');
            var statsText = (tokensUsed === 'Cached') ? 'Cached result (instant)' : ('Tokens: ' + (tokensUsed || '?') + ' | Time: ' + (execTimeMs || 0) + 'ms');
            $('#sahdev-snap-stats').text(statsText);
            
            var $aiBadge = $('#sahdev-snap-reply-score-badge');
            if (sahdevQualityScorer && data.hasOwnProperty('SCORE')) {
                var score = parseInt(data.SCORE) || 0;
                var colorClass = score >= 85 ? 'label-success' : (score >= 70 ? 'label-warning' : 'label-danger');
                var title = "Clarity: " + (data.CLARITY||0) + "% | Tone: " + (data.TONE_SCORE||0) + "% | Completeness: " + (data.COMPLETENESS||0) + "%\nNote: " + (data.REPLY_NOTES||'');
                $aiBadge.removeClass('label-default label-success label-warning label-danger').addClass(colorClass).html('<i class="fas fa-robot"></i> AI Score: ' + score + '/100').attr('title', title).show();
            } else {
                $aiBadge.hide();
            }

            $('#sahdev-snapshot-results').fadeIn();
        }

        function showSnapSleeping(techMsg) {
            $('#sahdev-snapshot-loading').hide();
            if (techMsg) { $('#sahdev-snapshot-tech-error').text(techMsg).show(); }
            $('#sahdev-snapshot-sleeping').show();
        }

        function sahdevSnapInsertToEditor() {
            var replyHtml = $('#sahdev-snap-reply').html();
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                tinymce.activeEditor.execCommand('mceInsertContent', false, replyHtml);
            } else if ($('#replymessage').length) {
                var plain = replyHtml.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/gi, '');
                $('#replymessage').val(plain);
            }
            if ($('#replyticket').length) {
                $('html, body').animate({ scrollTop: $('#replyticket').offset().top - 60 }, 400);
            }
        }

        if (typeof sahdevAutoAnalyze !== 'undefined' && sahdevAutoAnalyze === true) {
            $('#sahdev-snapshot-outer').show();
            $('#sahdev-snapshot-loading').show();

            var snapBaseData = {
                ticket_id: $('#sahdev_ticket_id').val(),
                tone: $('#sahdev_tone').val() || 'Professional',
                intensity: 3,
                instruction: '',
                intent: 'AUTO',
                use_summary: 1, // Snapshot always uses summary if available
                token: $('input[name="token"]').val(),
                force_regenerate: 'false',
                override_provider_id: $('#sahdev_override_provider').length ? ($('#sahdev_override_provider').val() || '0') : '0'
            };

            // Step 1: get_payload — same as main button (checks cache + gets provider info)
            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: Object.assign({ action: 'get_payload' }, snapBaseData),
                dataType: 'json',
                success: function(res) {
                    if (!res || res.status !== 'success') {
                        showSnapSleeping('Status: ' + (res ? res.status : 'unknown') + '\nMessage: ' + (res ? res.message : 'No response'));
                        return;
                    }

                    // Cache hit — render immediately
                    if (res.cached) {
                        renderSnapResults(res.data, 'Cached', 0);
                        return;
                    }

                    // No cache — execute server-side provider call.
                    $.ajax({
                        url: sahdevAjaxUrl,
                        type: 'POST',
                        data: Object.assign({ action: 'analyze_ticket' }, snapBaseData),
                        dataType: 'json',
                        success: function(r) {
                            if (r && r.status === 'success' && r.data) {
                                renderSnapResults(r.data, r.tokens_used, r.execution_time_ms);
                            } else {
                                showSnapSleeping('Status: ' + (r ? r.status : 'unknown') + '\nMessage: ' + (r ? r.message : 'empty'));
                            }
                        },
                        error: function(xhr, s, e) {
                            var techMsg = 'AJAX Error: ' + e + ' (HTTP ' + xhr.status + ')';
                            if (xhr.responseJSON && xhr.responseJSON.message) techMsg += '\nServer: ' + xhr.responseJSON.message;
                            showSnapSleeping(techMsg);
                        }
                    });
                },
                error: function(xhr, s, e) {
                    var techMsg = 'AJAX Error: ' + e + ' (HTTP ' + xhr.status + ')';
                    if (xhr.responseJSON && xhr.responseJSON.message) techMsg += '\nServer: ' + xhr.responseJSON.message;
                    showSnapSleeping(techMsg);
                }
            });
        }

        async function executeSnapLMStudio(config, snapBaseData) {
            if (!config.api_url) {
                showSnapSleeping('LM Studio API URL not configured in Sahdev settings.');
                return;
            }
            var promptText = buildPromptText(config.context, config.tone, config.custom_instruction, config.user_prompt_template);
            var systemMessage = config.system_prompt || 'You are a helpful Senior Technical Support Engineer.';

            // Context window guard (same logic as main button)
            var outputReserve = (config.max_tokens > 0 ? config.max_tokens : 512);
            var inputBudgetChars = Math.max(4096 - outputReserve, 1024) * 4;
            if (systemMessage.length + promptText.length > inputBudgetChars) {
                var maxSys = Math.floor(inputBudgetChars * 0.40);
                var maxUser = inputBudgetChars - Math.min(systemMessage.length, maxSys);
                if (systemMessage.length > maxSys) systemMessage = systemMessage.substring(0, maxSys) + '\n...[truncated]';
                if (promptText.length > maxUser) promptText = promptText.substring(0, maxUser) + '\n...[truncated]';
            }

            var llmPayload = {
                model: config.model || 'local-model',
                messages: [
                    { role: 'system', content: systemMessage },
                    { role: 'user', content: promptText }
                ],
                temperature: config.temperature || 0.7,
                stream: false
            };

            var startTime = performance.now();
            try {
                var response = await fetch(config.api_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(llmPayload)
                });
                if (!response.ok) { throw new Error('HTTP ' + response.status + ' - ' + await response.text()); }

                var data = await response.json();
                var rawContent = data.choices && data.choices[0] && data.choices[0].message ? data.choices[0].message.content : '';
                rawContent = rawContent.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();
                var firstBrace = rawContent.indexOf('{'), lastBrace = rawContent.lastIndexOf('}');
                if (firstBrace !== -1 && lastBrace !== -1) rawContent = rawContent.substring(firstBrace, lastBrace + 1);

                var parsedResponse;
                try { parsedResponse = robustJsonParse(rawContent); }
                catch(e) { throw new Error('JSON parse failed: ' + rawContent.substring(0, 200)); }

                var tokenDetails = { input: 0, output: 0 };
                var totalTokens = 0;
                if (data.usage) {
                    tokenDetails.input = data.usage.prompt_tokens || 0;
                    tokenDetails.output = data.usage.completion_tokens || 0;
                    totalTokens = data.usage.total_tokens || (tokenDetails.input + tokenDetails.output);
                }
                var execTimeMs = Math.round(performance.now() - startTime);

                // Save to backend cache (same as main flow save_response)
                var base64Json = btoa(unescape(encodeURIComponent(JSON.stringify(parsedResponse))));
                $.ajax({
                    url: sahdevAjaxUrl,
                    type: 'POST',
                    data: Object.assign({
                        action: 'save_response',
                        hash_signature: config.hash_signature,
                        ai_response: base64Json,
                        token_usage: totalTokens,
                        exec_time: execTimeMs,
                        token_details: JSON.stringify(tokenDetails)
                    }, snapBaseData),
                    dataType: 'json'
                });

                renderSnapResults(parsedResponse, totalTokens, execTimeMs);

            } catch(err) {
                var isOffline = err.message.toLowerCase().indexOf('failed to fetch') !== -1 || err.message.toLowerCase().indexOf('networkerror') !== -1;
                var errMsg = isOffline
                    ? 'Could not connect to LM Studio at ' + config.api_url + '. Ensure LM Studio is running, Local Server is started, and CORS is enabled.'
                    : err.message;
                showSnapSleeping(errMsg);
            }
        }

        // =====================================================================
        // FEATURE: 🧠 Historical Client Context (Memory)
        // =====================================================================
        function loadSahdevHistory() {
            var ticket_id = $('#sahdev_ticket_id').val();
            var token = $('input[name="token"]').val();
            $.post(sahdevAjaxUrl, { action: 'get_historical_context', ticket_id: ticket_id, token: token }, function(res) {
                if (res && res.status === 'success' && res.historical_context) {
                    $('#sahdev-history-display').text(res.historical_context).show();
                    $('#sahdev-history-active-badge').show();
                    $('#sahdev-history-exists-badge').show();
                    $('#btn-sahdev-delete-history').show();
                    $('#btn-sahdev-generate-history').html('<i class="fas fa-sync"></i> Refresh');
                    $('#sahdev_include_history').prop('disabled', false).prop('checked', true);
                } else {
                    $('#sahdev-history-display').hide();
                    $('#sahdev-history-active-badge').hide();
                    $('#sahdev-history-exists-badge').hide();
                    $('#btn-sahdev-delete-history').hide();
                    $('#btn-sahdev-generate-history').html('<i class="fas fa-search"></i> Generate Memory');
                    $('#sahdev_include_history').prop('disabled', true).prop('checked', false);
                }
            }, 'json');
        }

        // Auto-load history availability on document ready
        loadSahdevHistory();

        $(document).on('click', '#btn-sahdev-generate-history', function(e) {
            e.preventDefault();
            var limit = parseInt($('#sahdev_history_limit').val(), 10) || 7;
            var ticket_id = $('#sahdev_ticket_id').val();
            var token = $('input[name="token"]').val();
            
            $('#sahdev-history-loading').show();
            $('#sahdev-history-error').hide();
            $('#sahdev-history-display').hide();
            $('#btn-sahdev-generate-history').prop('disabled', true);
            
            $.post(sahdevAjaxUrl, { 
                action: 'generate_historical_context', 
                ticket_id: ticket_id, 
                limit: limit,
                token: token
            }, function(res) {
                $('#sahdev-history-loading').hide();
                $('#btn-sahdev-generate-history').prop('disabled', false);
                if (res && res.status === 'success') {
                    var ms = res.execution_time_ms || 0;
                    var numTickets = res.tickets_analyzed || 0;
                    $('#sahdev-history-meta').text('Analyzed ' + numTickets + ' tickets in ' + ms + 'ms').show();
                    loadSahdevHistory();
                } else {
                    $('#sahdev-history-error').text(res.message || 'Error parsing client history.').show();
                    loadSahdevHistory();
                }
            }, 'json').fail(function(xhr) {
                $('#sahdev-history-loading').hide();
                $('#btn-sahdev-generate-history').prop('disabled', false);
                
                var techMsg = 'Fatal AJAX Error while generating memory.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    techMsg = xhr.responseJSON.message;
                }
                
                $('#sahdev-history-error').text(techMsg).show();
            });
        });

        $(document).on('click', '#btn-sahdev-delete-history', function(e) {
            e.preventDefault();
            if (!confirm("Delete the cached memory for this client?")) return;
            var ticket_id = $('#sahdev_ticket_id').val();
            var token = $('input[name="token"]').val();
            
            $.post(sahdevAjaxUrl, { action: 'delete_historical_context', ticket_id: ticket_id, token: token }, function(res) {
                loadSahdevHistory();
                $('#sahdev-history-meta').hide();
            }, 'json');
        });

        // =====================================================================
        // FEATURE: 📚 Canned Responses & KB Search
        // =====================================================================
        var sahdevCannedSearchTimer;
        $(document).on('keyup', '#sahdev-canned-search', function() {
            var query = $(this).val().trim();
            var $resultsContainer = $('#sahdev-canned-results');
            
            clearTimeout(sahdevCannedSearchTimer);
            
            if (query.length < 3) {
                $resultsContainer.html('<p class="text-muted" style="font-size:12px; margin: 10px 0;">Type at least 3 characters to search.</p>');
                return;
            }
            
            $resultsContainer.html('<div style="text-align:center; padding: 10px;"><i class="fas fa-spinner fa-spin"></i> Searching...</div>');
            
            sahdevCannedSearchTimer = setTimeout(function() {
                $.ajax({
                    url: sahdevAjaxUrl,
                    type: 'POST',
                    data: { action: 'search_canned_responses', query: query, token: $('input[name="token"]').val() },
                    dataType: 'json',
                    success: function(r) {
                        if (r && r.status === 'success') {
                            if (r.results.length === 0) {
                                $resultsContainer.html('<p class="text-muted" style="font-size:12px; margin: 10px 0;">No matching templates or KB articles found.</p>');
                                return;
                            }
                            
                            var html = '<ul class="list-group" style="margin-bottom:0; font-size:12px;">';
                            $.each(r.results, function(i, item) {
                                var badge = '';
                                if (item.source === 'sahdev') badge = '<span class="label label-warning pull-right">AI Template</span>';
                                else if (item.source === 'whmcs_predef') badge = '<span class="label label-default pull-right">Predefined</span>';
                                else if (item.source === 'whmcs_kb') badge = '<span class="label label-info pull-right">KB Article</span>';
                                
                                html += '<li class="list-group-item" style="padding: 8px 10px;">';
                                html += '<strong>' + escapeHtml(item.title) + '</strong> ' + badge + '<br>';
                                html += '<div style="max-height:40px; overflow:hidden; text-overflow:ellipsis; color:#666; margin:4px 0;">' + escapeHtml(item.content).substring(0, 150) + '...</div>';
                                html += '<button type="button" class="btn btn-xs btn-default sahdev-insert-canned" style="margin-top:4px;" data-content="' + btoa(unescape(encodeURIComponent(item.content))) + '"><i class="fas fa-arrow-down"></i> Insert</button>';
                                html += '</li>';
                            });
                            html += '</ul>';
                            $resultsContainer.html(html);
                        } else {
                            $resultsContainer.html('<p class="text-danger" style="font-size:12px; margin: 10px 0;">' + escapeHtml(r.message || 'Search failed.') + '</p>');
                        }
                    },
                    error: function() {
                        $resultsContainer.html('<p class="text-danger" style="font-size:12px; margin: 10px 0;">Error connecting to server.</p>');
                    }
                });
            }, 400);
        });

        // Helper to escape HTML tags in search results
        function escapeHtml(unsafe) {
            return (unsafe || '').toString()
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        }

        $(document).on('click', '.sahdev-insert-canned', function() {
            var rawContent = $(this).data('content');
            var content = decodeURIComponent(escape(atob(rawContent)));
            
            if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
                // Determine if content has HTML or needs conversion
                var insertHtml = content;
                if(insertHtml.indexOf('<p>') === -1 && insertHtml.indexOf('<br') === -1) {
                    insertHtml = '<p>' + insertHtml.replace(/\n/g, '<br>') + '</p>';
                }
                tinymce.activeEditor.execCommand('mceInsertContent', false, insertHtml);
            } else if ($('#replymessage').length) {
                var el = $('#replymessage').get(0);
                var plain = content.replace(/<br\s*\/?>/gi, "\n").replace(/(<([^>]+)>)/gi, "");
                
                if (el.selectionStart || el.selectionStart == '0') {
                    var startPos = el.selectionStart;
                    var endPos = el.selectionEnd;
                    el.value = el.value.substring(0, startPos) + plain + el.value.substring(endPos, el.value.length);
                    el.selectionStart = startPos + plain.length;
                    el.selectionEnd = startPos + plain.length;
                    el.focus();
                } else {
                    el.value += "\n" + plain;
                    el.focus();
                }
            }
        });

        // Save as Canned Response logic
        $(document).on('click', '#btn-sahdev-save-canned', function() {
            var draftText = '';
            if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
                draftText = tinymce.activeEditor.getContent({format: 'text'});
            } else if ($('#replymessage').length) {
                draftText = $('#replymessage').val();
            }

            draftText = draftText.trim();
            if (!draftText) {
                alert('Your reply draft is empty. Please write a reply first to build a reusable Canned Response Template from it.');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#sahdev-canned-loading').show();
            $('#sahdev-rewrite-error').hide();

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: { action: 'generate_canned_template', draft_text: draftText, token: $('input[name="token"]').val() },
                dataType: 'json',
                success: function(r) {
                    $('#sahdev-canned-loading').hide();
                    $btn.prop('disabled', false);

                    if (r && r.status === 'success' && r.template) {
                        var title = prompt("AI generated a reusable template. Please enter a Title for this Canned Response:\n\nTemplate Preview:\n" + r.template.substring(0, 150) + "...");
                        if (title && title.trim().length > 0) {
                            $.ajax({
                                url: sahdevAjaxUrl,
                                type: 'POST',
                                data: { action: 'save_canned_response', title: title.trim(), template_text: r.template, token: $('input[name="token"]').val() },
                                dataType: 'json',
                                success: function(saveRes) {
                                    if(saveRes && saveRes.status === 'success') {
                                        alert('Successfully saved Canned Response! You can now search it in the Canned Responses panel.');
                                    } else {
                                        alert('Error saving: ' + (saveRes.message || 'Unknown error.'));
                                    }
                                }
                            });
                        }
                    } else {
                        $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + (r.message || 'Template generation failed.')).show();
                    }
                },
                error: function(xhr, s, e) {
                    $('#sahdev-canned-loading').hide();
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : ('Error: ' + (e || 'HTTP ' + xhr.status));
                    $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
                }
            });
        });

        // Save as KB Article logic
        $(document).on('click', '#btn-sahdev-save-kb', function() {
            var draftText = '';
            if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
                draftText = tinymce.activeEditor.getContent({format: 'text'});
            } else if ($('#replymessage').length) {
                draftText = $('#replymessage').val();
            }

            draftText = draftText.trim();
            if (!draftText) {
                alert('Your reply draft is empty. Please write a reply first to build a Knowledgebase Article from it.');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#sahdev-canned-loading').show();
            $('#sahdev-rewrite-error').hide();

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: { action: 'generate_canned_template', draft_text: draftText, token: $('input[name="token"]').val() },
                dataType: 'json',
                success: function(r) {
                    $('#sahdev-canned-loading').hide();
                    $btn.prop('disabled', false);

                    if (r && r.status === 'success' && r.template) {
                        var title = prompt("AI generated a KB Article. Please enter a Title for this Article:\n\nArticle Preview:\n" + r.template.substring(0, 150) + "...");
                        if (title && title.trim().length > 0) {
                            $.ajax({
                                url: sahdevAjaxUrl,
                                type: 'POST',
                                data: { action: 'save_kb_article', title: title.trim(), template_text: r.template, token: $('input[name="token"]').val() },
                                dataType: 'json',
                                success: function(saveRes) {
                                    if(saveRes && saveRes.status === 'success') {
                                        alert(saveRes.message || 'Saved.');
                                    } else {
                                        alert('Error saving: ' + (saveRes.message || 'Unknown error.'));
                                    }
                                }
                            });
                        }
                    } else {
                        $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + (r.message || 'Article generation failed.')).show();
                    }
                },
                error: function(xhr, s, e) {
                    $('#sahdev-canned-loading').hide();
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : ('Error: ' + (e || 'HTTP ' + xhr.status));
                    $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
                }
            });
        });

        // Expose globally for inline onclick handlers
        window.sahdevSnapInsertToEditor = sahdevSnapInsertToEditor;

    });

EOT;

    $output .= $jsContentStart . $jsContentMain . "\n</script>";

    // --- Remastered Theme: inject JS if active (CSS already injected before HTML) ---
    if ($uiTheme === \Sahdev\Lib\AdminPreferences::THEME_REMASTERED) {
        $rmJsPath  = __DIR__ . '/ui/remastered.js';
        if (file_exists($rmJsPath)) {
            $output .= "\n<script>\n$(function(){ " . file_get_contents($rmJsPath) . " });\n</script>";
        }
    }

    return $output;

}

// Helper to determine selected dropdown inside HEREDOC
// Since we can't easily parse functions in Heredoc without complex syntax, workaround:
if (!function_exists('sahdev_inject_ticket_panel_sel')) {
    function sahdev_inject_ticket_panel_sel($val, $current)
    {
        return $val === $current ? 'selected' : '';
    }
}

// ---------------------------------------------------------------------------
// Health Monitor: Admin header alert if cron is not running
// ---------------------------------------------------------------------------
add_hook('AdminAreaHeaderOutput', 1, function ($vars) {
    try {
        $output = '';
        if (!Capsule::schema()->hasTable('tblsahdev_settings')) return '';
        $settings = Capsule::table('tblsahdev_settings')->where('id', 1)->first();
        if (!$settings) {
            $settings = Capsule::table('tblsahdev_settings')->first();
        }
        if (!$settings) return '';

        // Find the most recent run date among all Sahdev automation columns for a more accurate health picture
        $lastRun = null;
        $timestampCols = [
            'last_cron_success', 
            'insights_cron_last_run_at', 
            'autopilot_cron_last_run_at', 
            'tools_cron_last_run_at'
        ];
        
        foreach ($timestampCols as $col) {
            if (!empty($settings->{$col})) {
                $dt = \Carbon\Carbon::parse($settings->{$col});
                if (!$lastRun || $dt->gt($lastRun)) {
                    $lastRun = $dt;
                }
            }
        }
        
        // Only show stale cron alert if at least one automation feature is enabled
        $active = !empty($settings->cron_insights_enabled) || !empty($settings->autopilot_enabled) || !empty($settings->tools_execution_enabled);
        if ($active) {
            $isStale = (!$lastRun || $lastRun->diffInMinutes(\Carbon\Carbon::now()) > 60);

            if ($isStale) {
                $msg = $lastRun 
                    ? "Sahdev AI automation has not run since " . $lastRun->format('Y-m-d H:i:s') . ". Please verify your system cron job."
                    : "Sahdev AI automation cron job has never run successfully. Manual configuration is required.";
                
                $configUrl = "addonmodules.php?module=sahdev&action=autopilot"; 
                
                $output .= <<<HTML
<div class="alert alert-warning sahdev-cron-alert" style="margin: 15px 20px 5px 20px; padding: 10px 15px; border-left: 5px solid #f39c12; font-size: 13px;">
    <div style="display:flex; align-items:center; justify-content:space-between;">
        <span><i class="fas fa-exclamation-triangle" style="color:#e67e22; margin-right:8px;"></i> <strong>Sahdev Health Alert:</strong> {$msg}</span>
        <a href="{$configUrl}" class="btn btn-xs btn-warning" style="font-weight:600; text-transform:uppercase; font-size:10px;">Troubleshoot</a>
    </div>
</div>
HTML;
            }
        }
        
        // Global Note Insert Button CSS injection - Using !important everywhere to override theme defaults
        $output .= <<<HTML
<style>
    .btn-sahdev-insert-note { 
        background: #28a745 !important; 
        color: #fff !important; 
        border: 1px solid #218838 !important; 
        padding: 8px 16px !important;
        font-size: 14px !important;
        border-radius: 4px !important;
        transition: all 0.15s ease !important; 
        font-weight: 600 !important; 
        margin-bottom: 12px !important;
        display: block !important;
        width: auto !important;
        min-width: 160px !important;
        cursor: pointer !important;
        box-shadow: 0 4px 6px rgba(0,0,0,0.15) !important;
        position: relative !important;
        z-index: 9999 !important;
        text-align: center !important;
    }
    .btn-sahdev-insert-note:hover { background: #218838 !important; opacity: 0.9 !important; transform: scale(1.02); }
    .btn-sahdev-insert-note i { margin-right: 8px !important; }
</style>
HTML;

        // Top navbar server widget
        $output .= sahdev_render_header_topbar_widget($vars);

        // Client service page server health card (clientsservices.php)
        $output .= sahdev_render_clientservices_server_card($vars);

        return $output;
    } catch (\Throwable $e) {
    }
    return '';
});

/**
 * Render compact live Server Health Card on clientsservices.php
 */
function sahdev_render_clientservices_server_card($vars)
{
    $adminId = $_SESSION['adminid'] ?? null;
    if (!$adminId) return '';

    require_once __DIR__ . '/lib/PermissionService.php';
    if (!\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TELEMETRY_VIEW)) {
        return '';
    }

    $filename = strtolower((string) (($vars['filename'] ?? '') ?: basename($_SERVER['SCRIPT_NAME'] ?? '')));
    $reqUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $scriptName = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    $isClientServices = (
        strpos($filename, 'clientsservices') !== false ||
        strpos($scriptName, 'clientsservices') !== false ||
        strpos($reqUri, 'clientsservices') !== false
    );

    if (!$isClientServices) {
        return '';
    }

    $serviceId = (int) ($_GET['id'] ?? ($_GET['serviceid'] ?? ($_POST['id'] ?? 0)));
    $userId = (int) ($_GET['userid'] ?? ($_POST['userid'] ?? 0));

    if ($serviceId <= 0 && $userId > 0) {
        $firstService = \WHMCS\Database\Capsule::table('tblhosting')->where('userid', $userId)->orderBy('id', 'asc')->first();
        if ($firstService) {
            $serviceId = (int) $firstService->id;
        }
    }

    if ($serviceId <= 0) {
        return '';
    }

    try {
        $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service || empty($service->server)) {
            return '';
        }

        $serverId = (int) $service->server;
        $username = trim((string) ($service->username ?? ''));

        require_once __DIR__ . '/lib/ServerTelemetryService.php';
        \Sahdev\Lib\ServerTelemetryService::ensureSchema();

        $srvRecord = \WHMCS\Database\Capsule::table('tblservers')->where('id', $serverId)->first();
        if (!$srvRecord) return '';

        $telemetry = \WHMCS\Database\Capsule::table('tblsahdev_server_telemetry')->where('server_id', $serverId)->first();
        $configuredRole = (string) ($telemetry->server_role ?? 'auto');
        $resolvedRole = \Sahdev\Lib\ServerTelemetryService::detectServerRole($srvRecord, $configuredRole);

        $srvName = htmlspecialchars($srvRecord->name ?: 'Server #' . $serverId);
        $srvHost = htmlspecialchars($srvRecord->hostname ?: $srvRecord->ipaddress);
        $accessUrl = \Sahdev\Lib\ServerTelemetryService::getServerAccessUrl($serverId);
        $canAccessServer = \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_SERVER_ACCESS);

        $isReachable = !empty($telemetry->is_reachable);
        $serverLoad = (string) ($telemetry->server_load ?? 'N/A');
        $lastPolled = !empty($telemetry->last_polled_at) ? substr((string) $telemetry->last_polled_at, 0, 16) : 'Never';

        // Role badge HTML with gradients
        $roleBadge = '';
        if ($resolvedRole === 'root') {
            $roleBadge = '<span class="sahdev-badge" style="background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff;" title="Root Server Access"><i class="fas fa-shield-alt"></i> ROOT SERVER</span>';
        } elseif ($resolvedRole === 'reseller') {
            $roleBadge = '<span class="sahdev-badge" style="background: linear-gradient(135deg, #581c87, #7c3aed); color: #fff;" title="WHM Reseller Account"><i class="fas fa-server"></i> RESELLER WHM</span>';
        } elseif ($resolvedRole === 'vps_node') {
            $roleBadge = '<span class="sahdev-badge" style="background: linear-gradient(135deg, #065f46, #059669); color: #fff;" title="VPS Node"><i class="fas fa-network-wired"></i> VPS NODE</span>';
        } else {
            $roleBadge = '<span class="sahdev-badge" style="background: #e2e8f0; color: #4a5568;">' . strtoupper(htmlspecialchars($srvRecord->type ?: 'cPanel')) . '</span>';
        }

        // Account telemetry
        $acctHealth = null;
        if ($username !== '' && !empty($telemetry->accounts_data_json)) {
            $accts = json_decode($telemetry->accounts_data_json, true);
            if (is_array($accts)) {
                foreach ($accts as $a) {
                    if (strtolower($a['user'] ?? '') === strtolower($username)) {
                        $acctHealth = $a;
                        break;
                    }
                }
            }
        }

        // Outages & Warnings
        $statsPayload = !empty($telemetry->server_stats_json) ? json_decode($telemetry->server_stats_json, true) : [];
        $outages = $statsPayload['flagged_service_outages'] ?? [];
        $warnings = $statsPayload['flagged_system_warnings'] ?? [];

        // Active incident
        $activeInc = \WHMCS\Database\Capsule::table('tblsahdev_incidents')
            ->where('server_id', $serverId)
            ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
            ->first();

        $statusBadge = $isReachable && empty($outages)
            ? '<span class="sahdev-badge" style="background: #def7ec; color: #03543f; border: 1px solid #bcf0da;"><span class="sahdev-pulse-green"></span> Online</span>'
            : ($isReachable ? '<span class="sahdev-badge" style="background: #fef08a; color: #713f12; border: 1px solid #fde047;"><span class="sahdev-pulse-orange"></span> Degraded</span>' : '<span class="sahdev-badge" style="background: #fde8e8; color: #9b1c1c; border: 1px solid #f8b4b4;"><span class="sahdev-pulse-red"></span> Offline</span>');

        $outagesHtml = '';
        if ($activeInc) {
            $incNum = htmlspecialchars($activeInc->incident_num);
            $incTitle = htmlspecialchars($activeInc->title);
            $outagesHtml .= "<div style='background: #fff5f5; border-top: 1px solid #fed7d7; padding: 8px 14px; font-size: 12px; color: #9b2c2c; display: flex; justify-content: space-between; align-items: center;'><div style='display: flex; align-items: center; gap: 6px;'><i class='fas fa-fire'></i> <strong>Active Outage: [{$incNum}] {$incTitle}</strong></div><a href='addonmodules.php?module=sahdev&action=incidents' target='_blank' class='btn btn-xs btn-danger' style='border-radius: 4px;'><i class='fas fa-search'></i> View Incident</a></div>";
        } elseif (!empty($outages)) {
            $outagesCount = count($outages);
            $outageText = htmlspecialchars(implode(', ', $outages));
            $outagesHtml .= "<div style='background: #fff5f5; border-top: 1px solid #fed7d7; padding: 8px 14px; font-size: 12px; color: #9b2c2c;'><i class='fas fa-exclamation-circle'></i> <strong>{$outagesCount} Service Outage(s):</strong> {$outageText}</div>";
        }

        // Disk quota progress bar
        $diskHtml = '';
        if ($acctHealth) {
            $diskUsed = htmlspecialchars($acctHealth['diskused'] ?? '0');
            $diskLimit = htmlspecialchars($acctHealth['disklimit'] ?? 'Unlimited');
            $pct = (float) str_replace('%', '', (string) ($acctHealth['percent_used'] ?? '0'));
            $progressColor = $pct >= 90 ? '#e53e3e' : ($pct >= 75 ? '#dd6b20' : '#38a169');
            $suspendedNotice = !empty($acctHealth['suspended']) ? '<span class="label label-danger" style="margin-left:8px; font-size: 10px;"><i class="fas fa-ban"></i> Suspended in WHM</span>' : '';

            $diskHtml = <<<HTML
            <div style="background: #f8fafc; border-top: 1px solid #edf2f7; padding: 8px 14px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #4a5568;">
                    <i class="fas fa-hdd" style="color: #718096;"></i>
                    <span><strong>Account Quota ({$username}):</strong> {$diskUsed} / {$diskLimit} ({$pct}%)</span>
                    {$suspendedNotice}
                </div>
                <div style="display: flex; align-items: center; gap: 8px; min-width: 140px; max-width: 200px; flex: 1;">
                    <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                        <div style="height: 100%; width: {$pct}%; background: {$progressColor}; border-radius: 3px; transition: width 0.3s ease;"></div>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; color: #718096;">{$pct}%</span>
                </div>
            </div>
HTML;
        }

        $accessBtnHtml = $canAccessServer 
            ? '<a href="' . $accessUrl . '" target="_blank" class="btn btn-default btn-xs" style="font-weight: 600; font-size: 11px; border-radius: 4px; padding: 4px 10px; background: #fff; border: 1px solid #cbd5e0; color: #2d3748;"><i class="fas fa-external-link-alt"></i> Access WHM</a>'
            : '';

        return <<<HTML
<!-- Sahdev Server Health Card for Client Service -->
<style>
#sahdev-clientservices-health-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin: 12px 0 16px 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
#sahdev-clientservices-health-card .sahdev-accent-bar {
    height: 3px;
    background: linear-gradient(90deg, #3182ce 0%, #805ad5 100%);
}
#sahdev-clientservices-health-card .sahdev-card-body {
    padding: 10px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
#sahdev-clientservices-health-card .sahdev-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 4px;
}
#sahdev-clientservices-health-card .sahdev-pulse-green {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #38a169;
    box-shadow: 0 0 0 rgba(72, 187, 120, 0.6);
    animation: sahdevPulseG 2s infinite;
}
#sahdev-clientservices-health-card .sahdev-pulse-orange {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #dd6b20;
}
#sahdev-clientservices-health-card .sahdev-pulse-red {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #e53e3e;
}
@keyframes sahdevPulseG {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(72, 187, 120, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(72, 187, 120, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(72, 187, 120, 0); }
}
</style>

<div id="sahdev-clientservices-health-card" style="display:none;">
    <div class="sahdev-accent-bar"></div>
    <div class="sahdev-card-body">
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-server" style="color: #3182ce; font-size: 14px;"></i>
                <strong style="font-size: 13px; color: #1a202c;">{$srvName}</strong>
            </div>
            <span style="font-size: 11px; color: #718096; background: #f7fafc; padding: 2px 6px; border-radius: 4px; border: 1px solid #edf2f7;">{$srvHost}</span>
            {$roleBadge}
            {$statusBadge}
            <span style="font-size: 11px; color: #4a5568; margin-left: 4px; background: #f8fafc; padding: 2px 6px; border-radius: 4px; border: 1px solid #edf2f7;">
                <i class="fas fa-microchip" style="color: #718096;"></i> <strong>Load:</strong> {$serverLoad}
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 6px;">
            {$accessBtnHtml}
            <a href="addonmodules.php?module=sahdev&action=incidents" target="_blank" class="btn btn-default btn-xs" style="font-size: 11px; border-radius: 4px; padding: 4px 8px; color: #4a5568;">
                <i class="fas fa-satellite-dish"></i> Incident Center
            </a>
        </div>
    </div>
    {$outagesHtml}
    {$diskHtml}
</div>

<script>
(function() {
    function injectCard() {
        var card = document.getElementById('sahdev-clientservices-health-card');
        if (!card) return;

        // Precisely target right before the product details form table inside the active tab
        var target = document.querySelector('form[name="packagefrm"] table.form') || 
                     document.querySelector('form[name="packagefrm"] .table') ||
                     document.querySelector('form[name="packagefrm"] table') ||
                     document.querySelector('form[name="packagefrm"]') ||
                     document.querySelector('#tab1 table.form') ||
                     document.querySelector('#tab1 .table') ||
                     document.querySelector('#tabProducts_Services') ||
                     document.querySelector('.tab-pane.active') ||
                     document.querySelector('#tab1');

        if (target) {
            if (target.tagName.toLowerCase() === 'form') {
                target.insertBefore(card, target.firstChild);
            } else if (target.parentNode) {
                target.parentNode.insertBefore(card, target);
            }
            card.style.display = 'block';
            return;
        }

        var contentArea = document.querySelector('.contentarea') || document.getElementById('contentarea');
        if (contentArea) {
            contentArea.insertBefore(card, contentArea.firstChild);
            card.style.display = 'block';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectCard);
    } else {
        injectCard();
    }
    setTimeout(injectCard, 150);
    setTimeout(injectCard, 600);
})();
</script>
HTML;
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Render Global Top Header Bar Widget for WHMCS Admin
 */
function sahdev_render_header_topbar_widget($vars)
{
    $adminId = $_SESSION['adminid'] ?? null;
    if (!$adminId) return '';

    require_once __DIR__ . '/lib/PermissionService.php';
    if (!\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_TELEMETRY_VIEW)) {
        return '';
    }

    // Check if header widget is enabled in settings
    try {
        $settings = Capsule::table('tblsahdev_settings')->where('id', 1)->first();
        if ($settings && isset($settings->header_widget_enabled) && (int) $settings->header_widget_enabled === 0) {
            return '';
        }
    } catch (\Throwable $e) {}

    $versionBuster = time();
    $ajaxUrl = "addonmodules.php?module=sahdev&sahdev_act=ajax_handler&v={$versionBuster}";

    // Detect context
    $serviceId = (int) ($_GET['id'] ?? ($_GET['serviceid'] ?? 0));
    $ticketId = (int) ($vars['ticketid'] ?? ($_GET['ticketid'] ?? ($_GET['id'] ?? 0)));
    $isTicketPage = (isset($vars['ticketid']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'supporttickets.php') !== false);
    if (!$isTicketPage) $ticketId = 0;

    return <<<HTML
<!-- Sahdev Global Top Header Bar Server Widget -->
<style>
.sahdev-header-li {
    display: inline-flex !important;
    align-items: center !important;
    vertical-align: middle !important;
    height: 100% !important;
    list-style: none !important;
    margin: 0 4px !important;
    padding: 0 !important;
}
.sahdev-top-nav-widget {
    position: relative !important;
    display: inline-flex !important;
    align-items: center !important;
    vertical-align: middle !important;
    margin: 0 !important;
    height: auto !important;
    z-index: 1000 !important;
}
.sahdev-nav-pill-btn {
    background: rgba(255, 255, 255, 0.16) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.3) !important;
    border-radius: 14px !important;
    padding: 3px 10px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
    height: 28px !important;
    line-height: 28px !important;
    box-sizing: border-box !important;
    white-space: nowrap !important;
}
.sahdev-nav-pill-btn:hover, .sahdev-nav-pill-btn:focus {
    background: rgba(255, 255, 255, 0.28) !important;
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2) !important;
}
.sahdev-nav-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #48bb78;
    display: inline-block;
    flex-shrink: 0;
}
.sahdev-nav-dot.pulse {
    box-shadow: 0 0 0 rgba(72, 187, 120, 0.6);
    animation: sahdevDotPulse 2s infinite;
}
.sahdev-mobile-icon {
    display: none;
    font-size: 13px;
}
.sahdev-popover-menu {
    display: none;
    position: fixed !important;
    z-index: 9999999 !important;
    width: 380px;
    max-width: calc(100vw - 20px) !important;
    max-height: calc(100vh - 80px) !important;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 16px 36px rgba(0,0,0,0.2), 0 4px 12px rgba(0,0,0,0.08);
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.sahdev-popover-menu *,
.sahdev-popover-menu a::after,
.sahdev-popover-menu a::before,
.sahdev-top-nav-widget a::after,
.sahdev-top-nav-widget a::before,
.sahdev-sso-btn::after,
.sahdev-sso-btn::before,
.sahdev-popover-footer a::after,
.sahdev-popover-footer a::before,
.sahdev-nav-pill-btn::after,
.sahdev-nav-pill-btn::before {
    box-sizing: border-box !important;
}
.sahdev-popover-menu a::after,
.sahdev-popover-menu a::before,
.sahdev-top-nav-widget a::after,
.sahdev-top-nav-widget a::before,
.sahdev-sso-btn::after,
.sahdev-sso-btn::before,
.sahdev-popover-footer a::after,
.sahdev-popover-footer a::before,
.sahdev-nav-pill-btn::after,
.sahdev-nav-pill-btn::before {
    display: none !important;
    content: none !important;
    content: "" !important;
    margin: 0 !important;
    padding: 0 !important;
    width: 0 !important;
    height: 0 !important;
}
.sahdev-popover-menu i,
.sahdev-popover-menu .fas,
.sahdev-popover-menu .far,
.sahdev-popover-menu .fa {
    position: static !important;
    float: none !important;
    display: inline-block !important;
    line-height: 1 !important;
    vertical-align: middle !important;
    margin: 0 !important;
}
.sahdev-popover-header {
    background: #0f172a;
    color: #ffffff;
    padding: 11px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.2;
}
.sahdev-popover-body {
    max-height: calc(100vh - 170px) !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 9px 11px !important;
    background: #f8fafc;
    box-sizing: border-box !important;
}
.sahdev-popover-body::-webkit-scrollbar {
    width: 5px !important;
}
.sahdev-popover-body::-webkit-scrollbar-track {
    background: transparent !important;
}
.sahdev-popover-body::-webkit-scrollbar-thumb {
    background: #cbd5e1 !important;
    border-radius: 4px !important;
}
.sahdev-popover-body::-webkit-scrollbar-thumb:hover {
    background: #94a3b8 !important;
}
.sahdev-popover-footer {
    background: #ffffff;
    padding: 9px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #e2e8f0;
    font-size: 11.5px;
}
.sahdev-server-row {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 9px 11px;
    margin-bottom: 7px;
    transition: all 0.15s ease;
    overflow: hidden !important;
    box-sizing: border-box !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}
.sahdev-server-row:hover {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.sahdev-server-row.is-pinned {
    border: 1px solid #86efac;
    border-left: 4px solid #10b981;
    background: #f0fdf4;
}
.sahdev-sso-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    padding: 3px 8px !important;
    height: 23px !important;
    line-height: 1 !important;
    background: #f8fafc !important;
    color: #2563eb !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    transition: all 0.15s ease !important;
}
.sahdev-sso-btn:hover {
    background: #eff6ff !important;
    border-color: #3b82f6 !important;
    color: #1d4ed8 !important;
    text-decoration: none !important;
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .sahdev-header-li {
        margin: 0 2px !important;
    }
    .sahdev-nav-pill-btn {
        width: 34px !important;
        height: 34px !important;
        padding: 0 !important;
        border-radius: 5px !important;
        position: relative !important;
    }
    .sahdev-nav-text,
    .sahdev-nav-caret {
        display: none !important;
    }
    .sahdev-mobile-icon {
        display: inline-block !important;
    }
    .sahdev-nav-dot {
        position: absolute !important;
        top: 4px !important;
        right: 4px !important;
        width: 7px !important;
        height: 7px !important;
        border: 1.5px solid #1a202c !important;
    }
}
</style>

<div id="sahdev-header-widget-container" style="display:none;">
    <div class="sahdev-top-nav-widget" id="sahdevNavWidgetWrap">
        <a href="javascript:void(0);" class="sahdev-nav-pill-btn" id="sahdevNavPillBtn" title="Sahdev Server Telemetry & Status">
            <span id="sahdevNavDot" class="sahdev-nav-dot pulse"></span>
            <i class="fas fa-server sahdev-mobile-icon"></i>
            <span id="sahdevNavLabel" class="sahdev-nav-text">Servers</span>
            <i class="fas fa-caret-down sahdev-nav-caret" style="font-size: 10px; opacity: 0.8;"></i>
        </a>
        <div class="sahdev-popover-menu" id="sahdevPopoverMenu">
            <div class="sahdev-popover-header">
                <div style="display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-satellite-dish" style="color:#10b981; font-size:12px;"></i>
                    <span>Server Health Intel</span>
                </div>
                <span id="sahdevWidgetSummary" style="font-size:11px; font-weight:600; color:#e2e8f0; background:rgba(255,255,255,0.12); padding:2px 8px; border-radius:10px;">Loading…</span>
            </div>
            <div class="sahdev-popover-body" id="sahdevPopoverBody">
                <div style="text-align:center; padding: 25px; color:#718096; font-size:12px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:20px; color:#3182ce; margin-bottom:8px; display:block;"></i>
                    Fetching live server telemetry…
                </div>
            </div>
            <div class="sahdev-popover-footer">
                <a href="javascript:void(0);" id="sahdevPollNowBtn" style="color:#2563eb; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:5px;">
                    <i class="fas fa-sync-alt"></i> Refresh Now
                </a>
                <a href="addonmodules.php?module=sahdev&action=incidents" target="_blank" style="color:#475569; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                    Incident Center <i class="fas fa-chevron-right" style="font-size:9px;"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var AJAX_URL = '{$ajaxUrl}';
    var SERVICE_ID = {$serviceId};
    var TICKET_ID = {$ticketId};
    var isMenuOpen = false;
    var cachedData = null;

    function positionPopover() {
        var btn = document.getElementById('sahdevNavPillBtn');
        var popover = document.getElementById('sahdevPopoverMenu');
        if (!btn || !popover) return;

        var rect = btn.getBoundingClientRect();
        var margin = 10;
        var popoverWidth = Math.min(380, window.innerWidth - (margin * 2));

        // Dynamic vertical position directly below button
        var topPos = rect.bottom + 6;
        popover.style.top = Math.max(margin, topPos) + 'px';

        if (window.innerWidth <= 480) {
            // Full mobile adaptation: pin to 10px on both sides
            popover.style.left = margin + 'px';
            popover.style.right = margin + 'px';
            popover.style.width = 'auto';
        } else {
            popover.style.width = popoverWidth + 'px';
            var rightPos = window.innerWidth - rect.right;
            var leftPos = rect.right - popoverWidth;

            if (leftPos < margin) {
                popover.style.left = margin + 'px';
                popover.style.right = 'auto';
            } else if (rightPos < margin) {
                popover.style.left = 'auto';
                popover.style.right = margin + 'px';
            } else {
                popover.style.left = 'auto';
                popover.style.right = Math.max(margin, rightPos) + 'px';
            }
        }
    }

    function injectIntoNavbar() {
        var container = document.getElementById('sahdev-header-widget-container');
        var widgetWrap = document.getElementById('sahdevNavWidgetWrap');
        if (!container || !widgetWrap) return;

        widgetWrap.style.display = 'inline-flex';

        var isMobile = (window.innerWidth <= 768);

        // Find WHMCS automation button (speedometer / gauge icon)
        var automateBtn = document.querySelector('a[href*="automationstatus.php"]') || 
                          document.querySelector('.btn-automation-status') ||
                          document.querySelector('a[title*="Automation"]') ||
                          document.querySelector('.header-actions a i.fa-tachometer-alt')?.closest('a') ||
                          document.querySelector('.header-actions a i.fa-tachometer')?.closest('a') ||
                          document.querySelector('.header-actions a i.fa-gauge')?.closest('a') ||
                          document.querySelector('header a i.fa-tachometer-alt')?.closest('a') ||
                          document.querySelector('header a i.fa-tachometer')?.closest('a');

        // Find Search Form / Search Box container
        var searchInput = document.querySelector('input[placeholder*="search" i]') || 
                          document.querySelector('input[name="searchterm"]') ||
                          document.querySelector('input[name="q"]') ||
                          document.querySelector('#header_search');

        var searchContainer = searchInput ? (searchInput.closest('form') || searchInput.closest('.navbar-form') || searchInput.closest('.header-search') || searchInput.parentNode) : null;
        if (!searchContainer) {
            searchContainer = document.querySelector('form[action*="search"]') || 
                              document.querySelector('form#headerSearchForm') || 
                              document.querySelector('form[name="frmsearch"]') ||
                              document.querySelector('.header-search') || 
                              document.querySelector('.navbar-form');
        }

        // Find Right Header Actions
        var headerActions = document.querySelector('.header-actions') || 
                            document.querySelector('#header .navbar-nav.navbar-right') || 
                            document.querySelector('.nav.navbar-nav.navbar-right') || 
                            document.querySelector('.top-navbar-right') ||
                            document.querySelector('.navbar-header .navbar-right') ||
                            document.querySelector('.navbar-right');

        if (isMobile) {
            // MOBILE: Position right next to WHMCS automation gauge button
            if (automateBtn && automateBtn.parentNode) {
                automateBtn.parentNode.insertBefore(widgetWrap, automateBtn);
            } else if (headerActions) {
                headerActions.insertBefore(widgetWrap, headerActions.firstChild);
            } else if (searchContainer && searchContainer.parentNode) {
                searchContainer.parentNode.insertBefore(widgetWrap, searchContainer);
            }
        } else {
            // DESKTOP: Place inside search container directly to the left of the input in the same horizontal row
            if (searchContainer) {
                searchContainer.style.display = 'inline-flex';
                searchContainer.style.alignItems = 'center';
                searchContainer.style.verticalAlign = 'middle';
                searchContainer.style.gap = '6px';
                searchContainer.insertBefore(widgetWrap, searchContainer.firstChild);
            } else if (automateBtn) {
                var parentLi = automateBtn.closest('li');
                if (parentLi && parentLi.parentNode) {
                    var li = document.createElement('li');
                    li.className = 'sahdev-header-li nav-item';
                    li.appendChild(widgetWrap);
                    parentLi.parentNode.insertBefore(li, parentLi);
                } else if (automateBtn.parentNode) {
                    automateBtn.parentNode.insertBefore(widgetWrap, automateBtn);
                }
            } else if (headerActions) {
                if (headerActions.tagName.toLowerCase() === 'ul') {
                    var li = document.createElement('li');
                    li.className = 'sahdev-header-li nav-item';
                    li.appendChild(widgetWrap);
                    headerActions.insertBefore(li, headerActions.firstChild);
                } else {
                    headerActions.insertBefore(widgetWrap, headerActions.firstChild);
                }
            } else {
                widgetWrap.style.position = 'fixed';
                widgetWrap.style.top = '10px';
                widgetWrap.style.right = '240px';
                widgetWrap.style.zIndex = '99999';
                document.body.appendChild(widgetWrap);
            }
        }

        if (container && container.parentNode) {
            container.remove();
        }
    }

    function renderWidget(data) {
        cachedData = data;
        var summary = data.summary || {};
        var servers = data.servers || [];
        var activeIncidents = data.active_incidents || [];

        var dot = document.getElementById('sahdevNavDot');
        var label = document.getElementById('sahdevNavLabel');
        var summaryEl = document.getElementById('sahdevWidgetSummary');
        var bodyEl = document.getElementById('sahdevPopoverBody');

        if (!dot || !label || !summaryEl || !bodyEl) return;

        if (summary.total_outages > 0 || activeIncidents.length > 0) {
            dot.style.background = '#ef4444';
            var count = summary.total_outages + activeIncidents.length;
            label.innerHTML = '<span style="color:#fca5a5;">' + count + ' Outage' + (count > 1 ? 's' : '') + '</span>';
        } else if (summary.total_warnings > 0) {
            dot.style.background = '#f59e0b';
            label.innerHTML = 'Servers (' + summary.reachable_servers + '/' + summary.monitored_servers + ')';
        } else {
            dot.style.background = '#10b981';
            label.innerHTML = 'Servers (' + summary.reachable_servers + '/' + summary.monitored_servers + ')';
        }

        summaryEl.textContent = summary.reachable_servers + '/' + summary.monitored_servers + ' Online';

        var html = '';

        // Active incidents banner inside popover
        if (activeIncidents.length > 0) {
            activeIncidents.forEach(function(inc) {
                html += '<div style="background:#fef2f2; border:1px solid #fecaca; border-left:3px solid #ef4444; border-radius:5px; padding:6px 9px; margin-bottom:7px; font-size:11px;">' +
                    '<strong style="color:#b91c1c;"><i class="fas fa-fire" style="color:#ef4444; margin-right:4px;"></i> [' + inc.incident_num + '] ' + inc.title + '</strong>' +
                    '<div style="color:#7f1d1d; margin-top:2px;">' + (inc.server_name || 'Infrastructure') + '</div>' +
                '</div>';
            });
        }

        if (!servers.length) {
            html += '<div style="text-align:center; padding:20px; color:#64748b; font-size:12px;">No monitored servers found.</div>';
        } else {
            servers.forEach(function(srv) {
                var isPinned = srv.is_context_pinned;
                var srvStatus = srv.is_reachable && (!srv.service_outages || srv.service_outages.length === 0);
                var statusColor = !srv.is_reachable ? '#ef4444' : (srv.service_outages && srv.service_outages.length > 0 ? '#ef4444' : (srv.system_warnings && srv.system_warnings.length > 0 ? '#f59e0b' : '#10b981'));

                var roleBadge = '';
                if (srv.server_role === 'root') {
                    roleBadge = '<span style="background:#0f172a;color:#f8fafc;font-size:9px;font-weight:700;padding:2px 5px;border-radius:3px;letter-spacing:0.4px;">ROOT</span>';
                } else if (srv.server_role === 'reseller') {
                    roleBadge = '<span style="background:#581c87;color:#fdf4ff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:3px;letter-spacing:0.4px;">RESELLER</span>';
                } else if (srv.server_role === 'vps_node') {
                    roleBadge = '<span style="background:#134e4a;color:#f0fdfa;font-size:9px;font-weight:700;padding:2px 5px;border-radius:3px;letter-spacing:0.4px;">VPS NODE</span>';
                }

                var rowClass = 'sahdev-server-row' + (isPinned ? ' is-pinned' : '');
                var pinnedBanner = isPinned ? '<div style="font-size:10px; font-weight:700; color:#059669; margin-bottom:4px; display:flex; align-items:center; gap:4px;"><i class="fas fa-star" style="color:#10b981;"></i> CURRENT SERVICE SERVER</div>' : '';

                var reachabilityHtml = '';
                if (!srv.is_reachable) {
                    var rawErr = (srv.reachability_error || 'Server is unreachable');
                    var errText = rawErr.replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/gu, '').trim();
                    reachabilityHtml = '<div style="font-size:10.5px; color:#991b1b; font-weight:500; margin-top:6px; padding:4px 8px; background:#fef2f2; border:1px solid #fecaca; border-radius:4px; line-height:1.3; display:flex; align-items:center; gap:5px;"><i class="fas fa-plug" style="color:#dc2626; font-size:10px; flex-shrink:0;"></i><span>' + errText + '</span></div>';
                }

                var outagesText = '';
                if (srv.service_outages && srv.service_outages.length > 0) {
                    var cleanOutages = srv.service_outages.map(function(o) { return o.replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/gu, '').trim(); }).join(' • ');
                    outagesText = '<div style="font-size:10.5px; color:#991b1b; font-weight:500; margin-top:6px; padding:4px 8px; background:#fef2f2; border:1px solid #fecaca; border-radius:4px; line-height:1.3; display:flex; align-items:center; gap:5px;"><i class="fas fa-exclamation-circle" style="color:#dc2626; font-size:10px; flex-shrink:0;"></i><span>' + cleanOutages + '</span></div>';
                }

                var accessBtnHtml = srv.access_url 
                    ? '<a href="' + srv.access_url + '" target="_blank" class="sahdev-sso-btn" title="Single Sign-On to Server Control Panel"><i class="fas fa-sign-in-alt" style="font-size:9px;"></i> Log in</a>' 
                    : '';

                html += '<div class="' + rowClass + '">' +
                    pinnedBanner +
                    '<div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">' +
                        '<div style="min-width:0; flex:1; overflow:hidden;">' +
                            '<div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">' +
                                '<strong style="font-size:12px; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:185px;" title="' + srv.server_name + '">' + srv.server_name + '</strong> ' + roleBadge +
                            '</div>' +
                            '<div style="font-size:10.5px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px;">' + srv.server_host + ' | Load: ' + srv.server_load + '</div>' +
                        '</div>' +
                        '<div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">' +
                            accessBtnHtml +
                            '<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:' + statusColor + '; flex-shrink:0;" title="' + (srv.is_reachable ? 'Online' : 'Offline') + '"></span>' +
                        '</div>' +
                    '</div>' +
                    reachabilityHtml +
                    outagesText +
                '</div>';
            });
        }

        bodyEl.innerHTML = html;
        if (isMenuOpen) {
            positionPopover();
        }
    }

    function fetchTelemetry(pollNow) {
        var url = AJAX_URL + '&action=get_header_server_widget&service_id=' + SERVICE_ID + '&ticket_id=' + TICKET_ID;
        if (pollNow) url += '&poll_now=1';

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d && d.status === 'success') {
                    renderWidget(d);
                } else {
                    var bodyEl = document.getElementById('sahdevPopoverBody');
                    var summaryEl = document.getElementById('sahdevWidgetSummary');
                    if (summaryEl) summaryEl.textContent = 'Error';
                    if (bodyEl) {
                        var errMsg = (d && d.message) ? d.message : 'Unable to retrieve server telemetry.';
                        bodyEl.innerHTML = '<div style="text-align:center; padding:20px; color:#c53030; font-size:12px;"><i class="fas fa-exclamation-triangle" style="font-size:18px; margin-bottom:6px; display:block;"></i> ' + errMsg + '<div style="margin-top:8px;"><a href="javascript:void(0);" onclick="document.getElementById(\'sahdevPollNowBtn\').click();" class="btn btn-default btn-xs">Try Again</a></div></div>';
                    }
                }
            })
            .catch(function(e) {
                var bodyEl = document.getElementById('sahdevPopoverBody');
                var summaryEl = document.getElementById('sahdevWidgetSummary');
                if (summaryEl) summaryEl.textContent = 'Error';
                if (bodyEl) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding:20px; color:#c53030; font-size:12px;"><i class="fas fa-exclamation-circle" style="font-size:18px; margin-bottom:6px; display:block;"></i> Connection error loading telemetry.<div style="margin-top:8px;"><a href="javascript:void(0);" onclick="document.getElementById(\'sahdevPollNowBtn\').click();" class="btn btn-default btn-xs">Retry</a></div></div>';
                }
            });
    }

    function setupEvents() {
        var btn = document.getElementById('sahdevNavPillBtn');
        var popover = document.getElementById('sahdevPopoverMenu');
        var pollBtn = document.getElementById('sahdevPollNowBtn');

        if (btn && popover) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                isMenuOpen = !isMenuOpen;
                if (isMenuOpen) {
                    popover.style.display = 'block';
                    positionPopover();
                    fetchTelemetry(false);
                } else {
                    popover.style.display = 'none';
                }
            });

            document.addEventListener('click', function(e) {
                if (!popover.contains(e.target) && e.target !== btn) {
                    isMenuOpen = false;
                    popover.style.display = 'none';
                }
            });

            window.addEventListener('resize', function() {
                injectIntoNavbar();
                if (isMenuOpen) {
                    positionPopover();
                }
            });

            window.addEventListener('scroll', function() {
                if (isMenuOpen) {
                    positionPopover();
                }
            }, true);
        }

        if (pollBtn) {
            pollBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var bodyEl = document.getElementById('sahdevPopoverBody');
                if (bodyEl) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding: 25px; color:#718096; font-size:12px;"><i class="fas fa-spinner fa-spin" style="font-size:20px; color:#3182ce; margin-bottom:8px; display:block;"></i> Polling all active servers…</div>';
                }
                fetchTelemetry(true);
            });
        }
    }

    function init() {
        injectIntoNavbar();
        setupEvents();
        setTimeout(function() {
            fetchTelemetry(false);
        }, 300);

        // Continuous automatic background polling every 60 seconds
        setInterval(function() {
            fetchTelemetry(false);
        }, 60000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    setTimeout(injectIntoNavbar, 150);
    setTimeout(injectIntoNavbar, 500);
})();
</script>
HTML;
}

add_hook('AdminAreaViewTicketPage', 1, function ($vars) {
    // Return early if not ticket page context
    if (!isset($vars['ticketid']))
        return '';

    return sahdev_inject_ticket_panel($vars);
});

function sahdev_is_admin_ticket_open_page(?array $vars = null): bool
{
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));
    $userId = (int) ($_GET['userid'] ?? 0);
    if ($action === 'open' && $userId > 0) {
        return true;
    }

    $filename = strtolower((string) (($vars['filename'] ?? '') ?: basename($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($filename === 'supporttickets.php' && $userId > 0) {
        return true;
    }

    // WHMCS 8+ routed admin pages can be index.php with rp path.
    if ($filename === 'index.php') {
        $rp = strtolower((string) ($_GET['rp'] ?? ''));
        if ($rp !== '' && strpos($rp, 'support') !== false && strpos($rp, 'ticket') !== false && strpos($rp, 'open') !== false && $userId > 0) {
            return true;
        }
    }

    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($userId > 0 && strpos($uri, 'support') !== false && strpos($uri, 'ticket') !== false && strpos($uri, 'open') !== false) {
        return true;
    }

    return false;
}

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    // ------------------------------------------------------------------
    // "Open New Ticket" page: inject the full Sahdev AI panel so admins
    // can generate AI replies while composing a new ticket for a client.
    // The panel's existing JS "open context mode" relocates itself above
    // the ticket form and uses userid-based AJAX actions.
    // ------------------------------------------------------------------
    if (sahdev_is_admin_ticket_open_page($vars)) {
        static $openTicketPanelInjected = false;
        if ($openTicketPanelInjected) {
            return '';
        }
        $openTicketPanelInjected = true;

        $userId = (int) ($_GET['userid'] ?? ($vars['userid'] ?? 0));
        $panelVars = is_array($vars) ? $vars : [];
        $panelVars['ticketid'] = 0;       // No ticket yet — triggers open context mode
        $panelVars['userid']   = $userId;

        return sahdev_inject_ticket_panel($panelVars);
    }

    // Existing ticket pages — leave the lightweight comment marker
    $ticketId = (int) ($_GET['id'] ?? ($vars['ticketid'] ?? 0));
    $userId = (int) ($_GET['userid'] ?? ($vars['userid'] ?? 0));

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $isTicketPage = ($ticketId > 0 || strpos($uri, 'supporttickets.php') !== false || strpos($uri, 'viewticket') !== false);

    if (!$isTicketPage) {
        return '';
    }

    $output = "\n<!-- Sahdev Note Tool Active (Ticket ID: $ticketId) -->\n";
    return $output;
});

// ---------------------------------------------------------------------------
// Unified Sahdev Background Cron Runner
// Executes server telemetry, incident surge clustering, ticket insights, and auto-tools.
// ---------------------------------------------------------------------------
function sahdev_execute_background_cron(bool $verbose = false): array
{
    $results = [
        'telemetry' => null,
        'incidents' => null,
        'insights'  => null,
        'tools'     => null,
        'errors'    => [],
    ];

    try {
        if (!\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_settings')) {
            return $results;
        }

        $moduleDir = __DIR__;
        $settings = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();

        // 1. Proactive Server Telemetry & Health Monitoring
        if (!isset($settings->telemetry_enabled) || (int) $settings->telemetry_enabled !== 0) {
            try {
                require_once $moduleDir . '/lib/ServerTelemetryService.php';
                $results['telemetry'] = \Sahdev\Lib\ServerTelemetryService::pollActiveServers(false);
            } catch (\Throwable $te) {
                $results['errors'][] = 'Server Telemetry polling error: ' . $te->getMessage();
            }
        }

        // 2. Incident & Surge Clustering
        if (!isset($settings->incident_detection_enabled) || (int) $settings->incident_detection_enabled !== 0) {
            try {
                require_once $moduleDir . '/lib/IncidentDetectionService.php';
                $results['incidents'] = \Sahdev\Lib\IncidentDetectionService::evaluateClusters();
            } catch (\Throwable $ie) {
                $results['errors'][] = 'Incident clustering error: ' . $ie->getMessage();
            }
        }

        // 3. Automated AI Ticket Insights & Sentiment
        if ($settings && !empty($settings->cron_insights_enabled)) {
            try {
                require_once $moduleDir . '/lib/AIProviderInterface.php';
                require_once $moduleDir . '/lib/GoogleAIProvider.php';
                require_once $moduleDir . '/lib/LMStudioAIProvider.php';
                require_once $moduleDir . '/lib/ReplicateAIProvider.php';
                require_once $moduleDir . '/lib/TicketDataExtractor.php';
                require_once $moduleDir . '/lib/AIController.php';
                require_once $moduleDir . '/lib/CronProcessor.php';

                $processor = new \Sahdev\Lib\CronProcessor();
                $results['insights'] = $processor->run($verbose);
            } catch (\Throwable $ce) {
                $results['errors'][] = 'Insights cron error: ' . $ce->getMessage();
            }
        }

        // 4. Tools Execution & Auto-Remediation
        if ($settings && !empty($settings->tools_execution_enabled)) {
            try {
                require_once $moduleDir . '/modules/ToolsExecution/ToolsExecutionService.php';
                $results['tools'] = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runCron($verbose);
            } catch (\Throwable $txe) {
                $results['errors'][] = 'Tools execution error: ' . $txe->getMessage();
            }
        }

        // Record successful cron heartbeat
        try {
            \WHMCS\Database\Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'last_cron_success' => \Carbon\Carbon::now(),
                'updated_at'        => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {}

    } catch (\Throwable $e) {
        $results['errors'][] = 'Global cron error: ' . $e->getMessage();
    }

    return $results;
}

add_hook('CronJob', 1, function () {
    sahdev_execute_background_cron(false);
});

add_hook('DailyCronJob', 1, function () {
    sahdev_execute_background_cron(false);
    try {
        require_once __DIR__ . '/lib/SchemaManager.php';
        require_once __DIR__ . '/lib/MetricsIntelligenceService.php';
        \Sahdev\Lib\MetricsIntelligenceService::runDailyAggregation();
    } catch (\Throwable $e) {
        // Safe failover for daily metrics cron
    }
});

add_hook('AfterCronJob', 1, function () {
    sahdev_execute_background_cron(false);
});

// ---------------------------------------------------------------------------
// Ticket Insights: Inject insight badges into the support tickets list page
// ---------------------------------------------------------------------------
// WHMCS AdminAreaPage expects an array of *template variables*, not raw HTML.
// Use AdminAreaFooterOutput (HTML before </body>) per developers.whmcs.com/hooks-reference/output/
// AdminSupportTicketPagePreTickets is list-specific and also accepts HTML (ticket hook reference).

/**
 * Build CSS/JS for the Support Tickets list (reads tblsahdev_sentiment via AJAX).
 */
function sahdev_build_ticket_list_insights_html(): string
{
    try {
        if (!\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_settings')) {
            return '';
        }

        $settings = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
        if (!$settings || empty($settings->cron_insights_enabled)) {
            return '';
        }

        $versionBuster = time();
        // json_encode for safe embedding in <script> — do NOT use htmlspecialchars() here (breaks & in query string)
        $ajaxUrlJs = json_encode(
            'addonmodules.php?module=sahdev&sahdev_act=ajax_handler&v=' . $versionBuster,
            JSON_UNESCAPED_SLASHES
        );

        $csrfTokenJs = json_encode(function_exists('generate_token') ? generate_token('plain') : '', JSON_UNESCAPED_SLASHES);
        return sahdev_render_ticket_list_insights($ajaxUrlJs, $csrfTokenJs);
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Detect admin Support Tickets list across classic scripts and routed admin URLs.
 *
 * @param array|null $vars Optional hook vars (e.g. AdminAreaFooterOutput / AdminAreaPage) — use filename when present.
 */
function sahdev_is_admin_support_tickets_list_page(?array $vars = null): bool
{
    $action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($action === 'view' && $id > 0) {
        return false;
    }

    if (is_array($vars) && !empty($vars['filename'])) {
        $fn = strtolower((string) $vars['filename']);
        if ($fn === 'supporttickets.php') {
            return true;
        }
        // WHMCS 8.x admin may route through index.php (rp / SPA-style)
        if ($fn === 'index.php') {
            $rp = isset($_GET['rp']) ? (string) $_GET['rp'] : '';
            if ($rp !== '') {
                $rpLower = strtolower($rp);
                if (strpos($rpLower, 'support') !== false && strpos($rpLower, 'ticket') !== false
                    && strpos($rpLower, 'viewticket') === false) {
                    return true;
                }
                $decoded = @base64_decode($rp, true);
                if ($decoded !== false && is_string($decoded)) {
                    $dl = strtolower($decoded);
                    if (strpos($dl, 'support') !== false && strpos($dl, 'ticket') !== false
                        && strpos($dl, 'viewticket') === false) {
                        return true;
                    }
                }
            }
        }
    }

    $script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    $self   = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    $uri    = strtolower($_SERVER['REQUEST_URI'] ?? '');

    if ($script === 'supporttickets.php' || $self === 'supporttickets.php') {
        return true;
    }
    if (strpos($uri, 'supporttickets.php') !== false) {
        return true;
    }
    if (preg_match('/supportticket/', $uri) && strpos($uri, 'action=view') === false) {
        return true;
    }
    if (!empty($_GET['rp']) && stripos((string) $_GET['rp'], 'ticket') !== false) {
        return true;
    }

    return false;
}

/**
 * Emit list insights HTML once per request (AdminSupportTicketPagePreTickets + AdminAreaFooterOutput).
 *
 * @param bool $skipUrlCheck true for AdminSupportTicketPagePreTickets (list-only hook)
 * @param array|null $footerVars AdminAreaFooterOutput $vars (filename, etc.) when not skipping URL check
 */
function sahdev_emit_ticket_list_insights_markup(bool $skipUrlCheck, ?array $footerVars = null): string
{
    static $emitted = false;
    if ($emitted) {
        return '';
    }
    if (!$skipUrlCheck && !sahdev_is_admin_support_tickets_list_page($footerVars)) {
        return '';
    }

    $html = sahdev_build_ticket_list_insights_html();
    if ($html !== '') {
        $emitted = true;
    }

    return $html;
}

// Official ticket hook: admin support tickets listing only (developers.whmcs.com — Ticket hooks)
add_hook('AdminSupportTicketPagePreTickets', 1, function () {
    return sahdev_emit_ticket_list_insights_markup(true, null);
});

// Fallback: HTML before </body> (Output hooks) when filename/routing differs by install
add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    return sahdev_emit_ticket_list_insights_markup(false, is_array($vars) ? $vars : null);
});

/**
 * Render the CSS + JS block injected into the support tickets list page.
 *
 * @param string $ajaxUrlJs JSON-encoded string literal for the ajax endpoint (e.g. from json_encode)
 * @param string $csrfTokenJs JSON-encoded plain CSRF token value.
 */
function sahdev_render_ticket_list_insights(string $ajaxUrlJs, string $csrfTokenJs): string
{
    // language=HTML
    return <<<HTML
<style>
/* ── Sahdev Ticket Insights — fused WHMCS-style panel + badges ─────────── */
.sdv-insight-panel {
    margin-top: 8px;
    margin-bottom: 0;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    overflow: hidden;
}
.sdv-insight-panel-hd {
    padding: 5px 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #495057;
    background: linear-gradient(180deg, #f8f9fa 0%, #eef1f4 100%);
    border-bottom: 1px solid #dee2e6;
}
.sdv-insight-panel-bd {
    padding: 8px 10px 10px;
}
.sdv-insight-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
}
.sdv-summary-line {
    margin-top: 8px;
    font-size: 12px;
    line-height: 1.45;
    color: #343a40;
    border-top: 1px solid #e9ecef;
    padding-top: 8px;
}
.sdv-summary-line .sdv-muted {
    font-size: 11px;
    color: #6c757d;
    margin-top: 4px;
}
.sdv-pill {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    font-weight: 700;
    line-height: 1;
    padding: 3px 8px;
    border-radius: 20px;
    white-space: nowrap;
    letter-spacing: .3px;
    cursor: default;
}
/* Urgency pills */
.sdv-urg-critical { background:#dc3545; color:#fff; }
.sdv-urg-high     { background:#fd7e14; color:#fff; }
.sdv-urg-medium   { background:#ffc107; color:#212529; }
.sdv-urg-low      { background:#198754; color:#fff; }
/* Sentiment pill — intensity tiers (score /10) */
.sdv-sent-unknown   { background:#e9ecef; color:#495057; border:1px solid #ced4da; font-weight:600; }
.sdv-sent-calm      { background: linear-gradient(135deg, #d4edda 0%, #e8f5e9 100%); color:#155724; border:1px solid #a3d9b1; font-weight:700; box-shadow: 0 0 0 1px rgba(25,135,84,0.12); }
.sdv-sent-moderate  { background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%); color:#856404; border:1px solid #ffecb5; font-weight:700; box-shadow: 0 0 0 1px rgba(255,193,7,0.25); }
.sdv-sent-high      { background: linear-gradient(135deg, #f8d7da 0%, #fde8e8 100%); color:#842029; border:1px solid #f1aeb5; font-weight:800; box-shadow: 0 0 0 1px rgba(220,53,69,0.35); animation: sdv-pulse-soft 2.2s ease-in-out infinite; }
@keyframes sdv-pulse-soft { 0%,100% { filter: brightness(1); } 50% { filter: brightness(0.97); } }
/* Tone pill — client emotional posture */
.sdv-tone-positive  { background: linear-gradient(135deg, #e7f6ec 0%, #f0fff4 100%); color:#0f5132; border:1px solid #a3cfbb; font-weight:600; font-style: normal; }
.sdv-tone-neutral   { background:#f8f9fa; color:#495057; border:1px solid #dee2e6; font-weight:600; font-style: normal; }
.sdv-tone-warm      { background: linear-gradient(135deg, #fff4e5 0%, #fffaf0 100%); color:#b45309; border:1px solid #fec89a; font-weight:700; font-style: normal; }
.sdv-tone-hot       { background: linear-gradient(135deg, #fde2e4 0%, #fff0f1 100%); color:#9b1c31; border:1px solid #f5a8b0; font-weight:800; font-style: normal; box-shadow: 0 0 0 1px rgba(220,53,69,0.25); }
.sdv-tone           { background:#fff; color:#6c757d; border:1px solid #dee2e6; font-style:italic; }
/* Admin reply badge */
.sdv-admin-rep    { background:#e7f3ff; color:#0d6efd; border:1px solid #b6d4fe; }
/* Row urgency — Subject cell (checkbox is first column on default WHMCS list) */
tr.sdv-row-critical td.sdv-insight-target { box-shadow: inset 4px 0 0 #dc3545 !important; }
tr.sdv-row-high     td.sdv-insight-target { box-shadow: inset 4px 0 0 #fd7e14 !important; }
tr.sdv-row-medium   td.sdv-insight-target { box-shadow: inset 4px 0 0 #ffc107 !important; }
tr.sdv-row-low      td.sdv-insight-target { box-shadow: inset 4px 0 0 #198754 !important; }
/* Subtle row wash by sentiment intensity (stacks with urgency stripe) */
tr.sdv-row-sent-calm td { background-color: rgba(25, 135, 84, 0.035) !important; }
tr.sdv-row-sent-moderate td { background-color: rgba(255, 193, 7, 0.06) !important; }
tr.sdv-row-sent-high td { background-color: rgba(220, 53, 69, 0.06) !important; }
.sdv-insight-panel--compact { border: 0; box-shadow: none; background: transparent; margin-top: 4px; }
.sdv-insight-panel--compact .sdv-insight-panel-hd { display: none; }
.sdv-insight-panel--compact .sdv-insight-panel-bd { padding: 2px 0 0; border: 0; background: transparent; }
.sdv-insight-panel--compact .sdv-pill { border-radius: 2px; font-size: 9px; padding: 2px 5px; letter-spacing: 0; }
.sdv-insight-tags-row { margin-top: 4px; display: flex; flex-wrap: wrap; gap: 3px 5px; align-items: center; }
.sdv-tag-chip { font-size: 9px !important; padding: 2px 6px !important; border-radius: 3px !important; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sdv-tag-chip.sdv-tag-ai { background: #cff4fc !important; color: #055160 !important; border: 1px solid #9eeaf9 !important; font-weight: 600; }
.sdv-insight-meta {
    margin-top: 4px;
    font-size: 10px;
    line-height: 1.35;
    color: #6c757d;
}
.sdv-insight-summary-toggle {
    background: none;
    border: none;
    color: #0d6efd;
    padding: 0;
    margin: 0;
    font-size: 10px;
    cursor: pointer;
    text-decoration: underline;
    vertical-align: baseline;
}
.sdv-insight-summary-toggle:hover { color: #0a58ca; }
.sdv-insight-summary-body {
    display: none;
    margin-top: 6px;
    padding: 6px 8px;
    font-size: 11px;
    line-height: 1.45;
    color: #343a40;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    max-height: 220px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
.sdv-insight-summary-body.sdv-open { display: block; }
/* List toolbar — Support Tickets page */
.sdv-list-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    margin: 0 0 12px 0;
    padding: 8px 12px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    font-size: 12px;
}
.sdv-list-toolbar .sdv-list-toolbar-label { font-weight: 600; color: #495057; margin-right: 4px; }
.sdv-list-toolbar button.btn-sdv {
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 4px;
    border: 1px solid #ced4da;
    background: #fff;
    color: #212529;
    cursor: pointer;
}
.sdv-list-toolbar button.btn-sdv:hover { background: #e9ecef; }
.sdv-list-toolbar button.btn-sdv:disabled { opacity: 0.55; cursor: not-allowed; }
.sdv-list-toolbar select.sdv-sort-select {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 4px;
    border: 1px solid #ced4da;
    max-width: 220px;
}
.sdv-list-toolbar .sdv-list-msg {
    flex: 1 1 100%;
    font-size: 11px;
    color: #6c757d;
    margin: 0;
    min-height: 1.2em;
}
.sdv-list-toolbar .sdv-list-msg.sdv-ok { color: #198754; }
.sdv-list-toolbar .sdv-list-msg.sdv-err { color: #dc3545; }
/* Tooltip */
.sdv-tooltip-wrap { position: relative; display: inline-flex; }
.sdv-tooltip-box {
    display: none;
    position: absolute;
    bottom: calc(100% + 8px);
    left: 0;
    min-width: 240px;
    max-width: 360px;
    background: #212529;
    color: #f8f9fa;
    font-size: 11px;
    font-weight: 400;
    line-height: 1.55;
    padding: 9px 12px;
    border-radius: 7px;
    box-shadow: 0 6px 20px rgba(0,0,0,.35);
    z-index: 99999;
    white-space: normal;
    pointer-events: none;
    font-style: normal;
    letter-spacing: 0;
}
.sdv-tooltip-box::after {
    content: '';
    position: absolute;
    top: 100%; left: 14px;
    border: 6px solid transparent;
    border-top-color: #212529;
}
.sdv-tooltip-wrap:hover .sdv-tooltip-box { display: block; }
</style>
<script>
(function () {
    'use strict';

    var AJAX_URL = (function () {
        var raw = {$ajaxUrlJs};
        try {
            return new URL(raw, window.location.href).href;
        } catch (e) {
            return raw;
        }
    })();
    var SAHDEV_CSRF_TOKEN = {$csrfTokenJs};

    function getCsrfToken() {
        var el = document.querySelector('input[name="token"]');
        if (el && typeof el.value === 'string' && el.value) {
            return el.value;
        }
        return SAHDEV_CSRF_TOKEN || '';
    }

    var URG_CLASS = {
        critical: 'sdv-urg-critical',
        high:     'sdv-urg-high',
        medium:   'sdv-urg-medium',
        low:      'sdv-urg-low'
    };
    var URG_ICON = { critical: '🔴', high: '🟠', medium: '🟡', low: '🟢' };
    var TONE_ICON = {
        Angry: '😡', Threatening: '⚠️', Demanding: '😤',
        Impatient: '⏳', Neutral: '😐', Confused: '😕',
        Polite: '🙂', Appreciative: '😊', Frustrated: '😤'
    };
    /** Higher = more hostile / needs attention — used for sorting & severity */
    var TONE_RANK = {
        Threatening: 10, Angry: 10, Frustrated: 8, Demanding: 8,
        Impatient: 6, Confused: 4, Neutral: 2, Polite: 1, Appreciative: 0
    };
    var URG_RANK = { critical: 4, high: 3, medium: 2, low: 1 };

    function sentimentTierClass(score) {
        var n = parseInt(score, 10);
        if (isNaN(n)) return 'sdv-sent-unknown';
        if (n <= 3) return 'sdv-sent-calm';
        if (n <= 6) return 'sdv-sent-moderate';
        return 'sdv-sent-high';
    }

    function sentimentRowClass(score) {
        var n = parseInt(score, 10);
        if (isNaN(n)) return '';
        if (n <= 3) return 'sdv-row-sent-calm';
        if (n <= 6) return 'sdv-row-sent-moderate';
        return 'sdv-row-sent-high';
    }

    function tonePillClass(tone) {
        if (!tone) return 'sdv-tone-neutral';
        var t = String(tone);
        if (TONE_RANK[t] !== undefined) {
            var r = TONE_RANK[t];
            if (r >= 8) return 'sdv-tone-hot';
            if (r >= 5) return 'sdv-tone-warm';
            if (r <= 1) return 'sdv-tone-positive';
            return 'sdv-tone-neutral';
        }
        var low = t.toLowerCase();
        if (/threat|angry|hostile|frustrat/i.test(low)) return 'sdv-tone-hot';
        if (/demand|impatient|urgent|upset|annoyed/i.test(low)) return 'sdv-tone-warm';
        if (/polite|thank|appreciat|kind/i.test(low)) return 'sdv-tone-positive';
        return 'sdv-tone-neutral';
    }

    function toneRankForSort(tone) {
        if (!tone) return 0;
        if (TONE_RANK[tone] !== undefined) return TONE_RANK[tone];
        var low = String(tone).toLowerCase();
        if (/threat|angry|hostile/i.test(low)) return 10;
        if (/frustrat/i.test(low)) return 8;
        if (/demand|impatient/i.test(low)) return 6;
        if (/confus/i.test(low)) return 4;
        if (/neutral/i.test(low)) return 2;
        if (/polite|appreciat/i.test(low)) return 1;
        return 3;
    }

    function getTicketListTable() {
        var scope = document.querySelector('#contentarea') || document.querySelector('.contentarea') || document.body;
        var tables = scope.querySelectorAll('table');
        var t, h, headers, txt;
        for (t = 0; t < tables.length; t++) {
            headers = tables[t].querySelectorAll('thead th, thead td, tbody tr:first-child th');
            for (h = 0; h < headers.length; h++) {
                txt = (headers[h].textContent || '').replace(/\s+/g, ' ').trim();
                if (txt.length < 80 && (/\bsubject\b/i.test(txt) || /\bbetreff\b/i.test(txt) || /\bsujet\b/i.test(txt) || /\basunto\b/i.test(txt))) {
                    return tables[t];
                }
            }
        }
        for (t = 0; t < tables.length; t++) {
            var firstRow = tables[t].querySelector('tbody tr');
            if (!firstRow) continue;
            var probe = firstRow.querySelector('a[href*="id="]');
            if (probe && isSupportTicketsHref(resolveHref(probe))) {
                return tables[t];
            }
        }
        return null;
    }

    function resolveHref(anchor) {
        var href = anchor.getAttribute('href') || '';
        if (!href) return '';
        try {
            return new URL(href, window.location.href).href;
        } catch (e) {
            return href;
        }
    }

    function isSupportTicketsHref(href) {
        if (!href) return false;
        var h = href.toLowerCase();
        if (h.indexOf('supportticket') !== -1) return true;
        try {
            var loc = window.location.href.toLowerCase();
            if (loc.indexOf('supportticket') !== -1 && /[?&]id=\d+/.test(h)) {
                return true;
            }
            if (loc.indexOf('supportticket') !== -1 && (/[?&]id=\d+/.test(h) || /[?&]action=view/.test(h))) {
                return true;
            }
        } catch (e) {}
        return false;
    }

    function extractTicketRefFromLink(anchor) {
        var href = resolveHref(anchor);
        if (!isSupportTicketsHref(href)) return null;
        var m = href.match(/[?&]id=(\d+)(?:&|#|$)/i);
        if (m) return { id: m[1] };
        m = href.match(/[?&]tid=([^&=#]+)/i);
        if (m) {
            try {
                return { mask: decodeURIComponent(m[1]) };
            } catch (e) {
                return { mask: m[1] };
            }
        }
        return null;
    }

    function tagRows() {
        var scope = document.querySelector('#contentarea') || document.querySelector('.contentarea') || document.body;
        var tables = scope.querySelectorAll('table');
        var allRows = [];

        // Capture rows from every table that looks like a support ticket list table.
        for (var t = 0; t < tables.length; t++) {
            var table = tables[t];
            var looksLikeTicketTable = false;
            var headers = table.querySelectorAll('thead th, thead td, tbody tr:first-child th');
            for (var h = 0; h < headers.length; h++) {
                var txt = (headers[h].textContent || '').replace(/\s+/g, ' ').trim();
                if (txt.length < 80 && (/\bsubject\b/i.test(txt) || /\bbetreff\b/i.test(txt) || /\bsujet\b/i.test(txt) || /\basunto\b/i.test(txt))) {
                    looksLikeTicketTable = true;
                    break;
                }
            }
            if (!looksLikeTicketTable) {
                var firstRow = table.querySelector('tbody tr');
                var probe = firstRow ? firstRow.querySelector('a[href*="id="]') : null;
                if (probe && isSupportTicketsHref(resolveHref(probe))) {
                    looksLikeTicketTable = true;
                }
            }
            if (!looksLikeTicketTable) continue;

            var rows = table.querySelectorAll('tbody tr');
            rows.forEach(function (r) { allRows.push(r); });
        }

        if (allRows.length === 0) {
            allRows = Array.prototype.slice.call(document.querySelectorAll('#contentarea table tbody tr, .contentarea table tbody tr, table tbody tr'));
        }

        allRows.forEach(function (row) {
            if (row.getAttribute('data-sdv-tid') || row.getAttribute('data-sdv-tmask')) return;
            var links = row.querySelectorAll('a[href]');
            for (var i = 0; i < links.length; i++) {
                var ref = extractTicketRefFromLink(links[i]);
                if (!ref) continue;
                if (ref.id) {
                    row.setAttribute('data-sdv-tid', ref.id);
                    return;
                }
                if (ref.mask) {
                    row.setAttribute('data-sdv-tmask', ref.mask);
                    return;
                }
            }
        });
    }

    function collectIdsAndMasks() {
        var ids = [];
        var masks = [];
        document.querySelectorAll('tr[data-sdv-tid]').forEach(function (el) {
            var tid = el.getAttribute('data-sdv-tid');
            if (tid && ids.indexOf(tid) === -1) ids.push(tid);
        });
        document.querySelectorAll('tr[data-sdv-tmask]').forEach(function (el) {
            var m = el.getAttribute('data-sdv-tmask');
            if (m && !el.getAttribute('data-sdv-tid') && masks.indexOf(m) === -1) masks.push(m);
        });
        return { ids: ids, masks: masks };
    }

    function findSubjectCell(row, tidAttr, maskAttr) {
        var links = row.querySelectorAll('a[href]');
        var i, ref, td;
        for (i = 0; i < links.length; i++) {
            if (!isSupportTicketsHref(resolveHref(links[i]))) continue;
            ref = extractTicketRefFromLink(links[i]);
            if (!ref) continue;
            if (tidAttr && ref.id && String(ref.id) === String(tidAttr)) {
                td = links[i].closest('td');
                if (td) return td;
            }
            if (maskAttr && ref.mask && String(ref.mask) === String(maskAttr)) {
                td = links[i].closest('td');
                if (td) return td;
            }
        }
        var tds = row.querySelectorAll('td');
        if (tds.length >= 2) {
            return tds[tds.length - 1];
        }
        return tds.length ? tds[0] : null;
    }

    function findRowsForNumericTicketId(nid, maskMap) {
        var rows = [];
        var q = document.querySelector('tr[data-sdv-tid="' + nid + '"]');
        if (q) rows.push(q);
        var mk;
        for (mk in maskMap) {
            if (!Object.prototype.hasOwnProperty.call(maskMap, mk)) continue;
            if (parseInt(maskMap[mk], 10) !== parseInt(nid, 10)) continue;
            document.querySelectorAll('tr[data-sdv-tmask]').forEach(function (row) {
                if (row.getAttribute('data-sdv-tmask') === mk && rows.indexOf(row) === -1) rows.push(row);
            });
        }
        return rows;
    }

    function injectIntoRow(row, ins) {
        if (row.querySelector('.sdv-insight-panel')) return;

        var urgency = (ins.urgency || 'medium').toLowerCase();
        var urgClass = URG_CLASS[urgency] || 'sdv-urg-medium';
        var urgIcon  = URG_ICON[urgency]  || '⚪';
        var urgLabel = ins.urgency || 'Medium';
        var sentiment = ins.sentiment_label || '';
        var score     = ins.sentiment_score ? ins.sentiment_score + '/10' : '';
        var tone      = ins.client_tone || '';
        var toneIcon  = TONE_ICON[tone] || '';
        var adminRep  = parseInt(ins.admin_reply_count, 10) || 0;
        var lastAdmin = ins.last_admin_name || '';
        var fullSummary = (ins.ticket_summary || '').trim();
        var tipPreview  = fullSummary.length > 260 ? fullSummary.substring(0, 260) + '…' : fullSummary;
        var analyzedAt = ins.analyzed_at || '';

        var tipLines = [];
        if (tipPreview) tipLines.push(tipPreview);
        if (adminRep > 0) {
            var adminLine = 'Admin replied ' + adminRep + ' time' + (adminRep > 1 ? 's' : '');
            if (lastAdmin) adminLine += ' · Last: ' + lastAdmin;
            tipLines.push(adminLine);
        }
        if (analyzedAt) tipLines.push('Analyzed: ' + analyzedAt);
        var tipHtml = tipLines.map(function (l) {
            return '<span>' + l.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>';
        }).join('<br>');

        var panel = document.createElement('div');
        panel.className = 'sdv-insight-panel sdv-insight-panel--compact';
        panel.setAttribute('data-sdv-injected', '1');

        var hd = document.createElement('div');
        hd.className = 'sdv-insight-panel-hd';
        hd.textContent = 'Ticket insights';
        panel.appendChild(hd);

        var bd = document.createElement('div');
        bd.className = 'sdv-insight-panel-bd';

        var bar = document.createElement('div');
        bar.className = 'sdv-insight-bar';

        var urgWrap = document.createElement('span');
        urgWrap.className = 'sdv-tooltip-wrap';
        urgWrap.innerHTML =
            '<span class="sdv-pill ' + urgClass + '">' + urgIcon + ' ' + urgLabel + '</span>' +
            (tipHtml ? '<div class="sdv-tooltip-box">' + tipHtml + '</div>' : '');
        bar.appendChild(urgWrap);

        if (sentiment || score) {
            var sentPill = document.createElement('span');
            sentPill.className = 'sdv-pill ' + sentimentTierClass(ins.sentiment_score);
            sentPill.setAttribute('title', 'Sentiment intensity (0–10): higher = stronger negative affect in the client message.');
            sentPill.textContent = sentiment + (score ? ' ' + score : '');
            bar.appendChild(sentPill);
        }

        if (tone) {
            var tonePill = document.createElement('span');
            tonePill.className = 'sdv-pill ' + tonePillClass(tone);
            tonePill.setAttribute('title', 'Detected client tone — color reflects severity.');
            tonePill.textContent = (toneIcon ? toneIcon + ' ' : '') + tone;
            bar.appendChild(tonePill);
        }

        if (adminRep > 0) {
            var repPill = document.createElement('span');
            repPill.className = 'sdv-pill sdv-admin-rep';
            repPill.title = lastAdmin ? 'Last reply by: ' + lastAdmin : '';
            repPill.textContent = '↩ ' + adminRep + ' admin reply' + (adminRep > 1 ? 's' : '');
            bar.appendChild(repPill);
        }

        bd.appendChild(bar);

        var tags = Array.isArray(ins.tags) ? ins.tags : [];
        row.setAttribute('data-sdv-tag-count', String(tags.length));
        if (tags.length) {
            var tagRow = document.createElement('div');
            tagRow.className = 'sdv-insight-tags-row';
            tags.forEach(function (t) {
                var sp = document.createElement('span');
                var ts = String(t);
                sp.className = 'sdv-pill sdv-tag-chip' + (ts.indexOf('ai-') === 0 ? ' sdv-tag-ai' : '');
                sp.textContent = ts;
                sp.setAttribute('title', 'Tag (WHMCS Tag Cloud when synced)');
                tagRow.appendChild(sp);
            });
            bd.appendChild(tagRow);
        }

        var meta = document.createElement('div');
        meta.className = 'sdv-insight-meta';
        if (analyzedAt) {
            meta.appendChild(document.createTextNode('Analyzed ' + analyzedAt));
        }
        if (fullSummary) {
            if (analyzedAt) meta.appendChild(document.createTextNode(' · '));
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'sdv-insight-summary-toggle';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = 'Show summary';
            var sumBody = document.createElement('div');
            sumBody.className = 'sdv-insight-summary-body';
            sumBody.textContent = fullSummary.length > 12000 ? fullSummary.substring(0, 12000) + '…' : fullSummary;
            toggle.addEventListener('click', function (ev) {
                ev.preventDefault();
                var open = sumBody.classList.toggle('sdv-open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.textContent = open ? 'Hide summary' : 'Show summary';
            });
            meta.appendChild(toggle);
            bd.appendChild(meta);
            bd.appendChild(sumBody);
        } else if (analyzedAt) {
            bd.appendChild(meta);
        }

        panel.appendChild(bd);
        row.classList.add('sdv-row-' + urgency);
        var sentRow = sentimentRowClass(ins.sentiment_score);
        if (sentRow) row.classList.add(sentRow);

        var scNum = parseInt(ins.sentiment_score, 10);
        if (isNaN(scNum)) scNum = 0;
        var trk = toneRankForSort(tone);
        var severity = scNum * 10 + trk;
        row.setAttribute('data-sdv-score', String(scNum));
        row.setAttribute('data-sdv-tone-rank', String(trk));
        row.setAttribute('data-sdv-severity', String(severity));
        row.setAttribute('data-sdv-urgency-rank', String(URG_RANK[urgency] || 0));

        var td = findSubjectCell(row, row.getAttribute('data-sdv-tid'), row.getAttribute('data-sdv-tmask'));
        if (td) {
            td.classList.add('sdv-insight-target');
            td.style.paddingBottom = '4px';
            td.style.verticalAlign = 'top';
            td.appendChild(panel);
        }
    }

    function injectInsightsPayload(data) {
        var insights = data.insights || {};
        var maskMap = data.mask_to_id || {};
        Object.keys(insights).forEach(function (k) {
            var nid = parseInt(k, 10);
            if (isNaN(nid) || !insights[k]) return;
            var rows = findRowsForNumericTicketId(nid, maskMap);
            for (var r = 0; r < rows.length; r++) {
                injectIntoRow(rows[r], insights[k]);
            }
        });
    }

    function loadInsights(ids, masks) {
        var idArr = ids || [];
        var maskArr = masks || [];
        if (!idArr.length && !maskArr.length) return;

        var fd = new FormData();
        fd.append('action', 'get_ticket_insights');
        var token = getCsrfToken();
        if (token) fd.append('token', token);
        idArr.forEach(function (id) { fd.append('ticket_ids[]', id); });
        maskArr.forEach(function (m) { fd.append('ticket_tids[]', m); });

        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'success' && data.insights) {
                    injectInsightsPayload(data);
                    var sel = document.getElementById('sdv-sort-insights');
                    if (sel && sel.value && sel.value !== 'default') {
                        applyTicketSort(sel.value);
                    }
                }
            })
            .catch(function (err) {
                if (window.console && console.warn) console.warn('Sahdev ticket list insights:', err);
            });
    }

    var moTimer = null;
    var moStarted = false;
    function scheduleRefresh() {
        if (moTimer) clearTimeout(moTimer);
        moTimer = setTimeout(function () {
            tagRows();
            stampOriginalRowOrder();
            var needIds = [];
            var needMasks = [];
            document.querySelectorAll('tr[data-sdv-tid], tr[data-sdv-tmask]').forEach(function (row) {
                if (row.querySelector('.sdv-insight-panel')) return;
                var tid = row.getAttribute('data-sdv-tid');
                var msk = row.getAttribute('data-sdv-tmask');
                if (tid) {
                    if (needIds.indexOf(tid) === -1) needIds.push(tid);
                } else if (msk) {
                    if (needMasks.indexOf(msk) === -1) needMasks.push(msk);
                }
            });
            loadInsights(needIds, needMasks);
        }, 380);
    }

    function postListAjax(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        var token = getCsrfToken();
        if (token) fd.append('token', token);
        if (extra) {
            Object.keys(extra).forEach(function (k) {
                var v = extra[k];
                if (v === undefined || v === null) return;
                if (Array.isArray(v)) {
                    v.forEach(function (item) { fd.append(k + '[]', item); });
                } else {
                    fd.append(k, v);
                }
            });
        }
        return fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function stampOriginalRowOrder() {
        var tbl = getTicketListTable();
        if (!tbl) return;
        var rows = tbl.querySelectorAll('tbody tr');
        for (var i = 0; i < rows.length; i++) {
            if (!rows[i].hasAttribute('data-sdv-orig-idx')) {
                rows[i].setAttribute('data-sdv-orig-idx', String(i));
            }
        }
    }

    function applyTicketSort(mode) {
        var tbl = getTicketListTable();
        if (!tbl) return;
        var tb = tbl.querySelector('tbody');
        if (!tb) return;
        var rows = Array.prototype.slice.call(tb.querySelectorAll('tr'));
        function orig(a) { return parseInt(a.getAttribute('data-sdv-orig-idx') || '0', 10); }
        function score(a) {
            var s = a.getAttribute('data-sdv-score');
            if (s === null || s === '') return -1;
            var n = parseInt(s, 10);
            return isNaN(n) ? -1 : n;
        }
        function urg(a) {
            var u = a.getAttribute('data-sdv-urgency-rank');
            if (u === null || u === '') return -1;
            var n = parseInt(u, 10);
            return isNaN(n) ? -1 : n;
        }
        function toneR(a) {
            var t = a.getAttribute('data-sdv-tone-rank');
            if (t === null || t === '') return -1;
            var n = parseInt(t, 10);
            return isNaN(n) ? -1 : n;
        }
        function sev(a) {
            var s = a.getAttribute('data-sdv-severity');
            if (s === null || s === '') return -1;
            var n = parseInt(s, 10);
            return isNaN(n) ? -1 : n;
        }
        function tagCnt(a) {
            var s = a.getAttribute('data-sdv-tag-count');
            if (s === null || s === '') return 0;
            var n = parseInt(s, 10);
            return isNaN(n) ? 0 : n;
        }
        if (mode === 'default') {
            rows.sort(function (a, b) { return orig(a) - orig(b); });
        } else if (mode === 'score_desc') {
            rows.sort(function (a, b) {
                var d = score(b) - score(a);
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'score_asc') {
            rows.sort(function (a, b) {
                var sa = score(a);
                var sb = score(b);
                var ta = sa < 0 ? 999 : sa;
                var tbv = sb < 0 ? 999 : sb;
                var d = ta - tbv;
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'urgency_desc') {
            rows.sort(function (a, b) {
                var d = urg(b) - urg(a);
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'urgency_asc') {
            rows.sort(function (a, b) {
                var ua = urg(a);
                var ub = urg(b);
                var ta = ua < 0 ? 99 : ua;
                var tbv = ub < 0 ? 99 : ub;
                var d = ta - tbv;
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'severity_desc') {
            rows.sort(function (a, b) {
                var d = sev(b) - sev(a);
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'severity_asc') {
            rows.sort(function (a, b) {
                var sa = sev(a);
                var sb = sev(b);
                var ta = sa < 0 ? 99999 : sa;
                var tbv = sb < 0 ? 99999 : sb;
                var d = ta - tbv;
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'tone_desc') {
            rows.sort(function (a, b) {
                var d = toneR(b) - toneR(a);
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'tone_asc') {
            rows.sort(function (a, b) {
                var ta = toneR(a);
                var tbv = toneR(b);
                var xa = ta < 0 ? 999 : ta;
                var xb = tbv < 0 ? 999 : tbv;
                var d = xa - xb;
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'tag_count_desc') {
            rows.sort(function (a, b) {
                var d = tagCnt(b) - tagCnt(a);
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else if (mode === 'tag_count_asc') {
            rows.sort(function (a, b) {
                var d = tagCnt(a) - tagCnt(b);
                if (d !== 0) return d;
                return orig(a) - orig(b);
            });
        } else {
            return;
        }
        rows.forEach(function (r) { tb.appendChild(r); });
    }

    function setToolbarMsg(el, text, kind) {
        if (!el) return;
        el.textContent = text || '';
        el.className = 'sdv-list-msg' + (kind === 'ok' ? ' sdv-ok' : kind === 'err' ? ' sdv-err' : '');
    }

    function injectToolbar() {
        if (document.getElementById('sdv-ticket-list-toolbar')) return;
        var host = document.querySelector('#contentarea') || document.querySelector('.contentarea');
        if (!host) return;

        var bar = document.createElement('div');
        bar.id = 'sdv-ticket-list-toolbar';
        bar.className = 'sdv-list-toolbar';
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', 'Sahdev ticket insights actions');

        var lbl = document.createElement('span');
        lbl.className = 'sdv-list-toolbar-label';
        lbl.textContent = 'Sahdev insights';
        bar.appendChild(lbl);

        var btnQueue = document.createElement('button');
        btnQueue.type = 'button';
        btnQueue.className = 'btn-sdv';
        btnQueue.id = 'sdv-btn-queue-analyze';
        btnQueue.title = 'Run AI analysis on tickets in the queue (same logic as cron batch)';
        btnQueue.textContent = 'Analyze queue';
        bar.appendChild(btnQueue);

        var btnForce = document.createElement('button');
        btnForce.type = 'button';
        btnForce.className = 'btn-sdv';
        btnForce.id = 'sdv-btn-force-page';
        btnForce.title = 'Re-run AI analysis for every ticket visible on this page (ignores “already analyzed”)';
        btnForce.textContent = 'Force re-analyze page';
        bar.appendChild(btnForce);

        var sortLbl = document.createElement('label');
        sortLbl.style.marginLeft = '8px';
        sortLbl.style.fontWeight = '600';
        sortLbl.style.color = '#495057';
        sortLbl.textContent = 'Sort:';
        bar.appendChild(sortLbl);

        var sel = document.createElement('select');
        sel.className = 'sdv-sort-select';
        sel.id = 'sdv-sort-insights';
        sel.setAttribute('aria-label', 'Sort tickets by Sahdev scores');
        [
            ['default', 'WHMCS order (original)'],
            ['severity_desc', 'Severity (sentiment + tone, worst first)'],
            ['severity_asc', 'Severity (calmest first)'],
            ['tag_count_desc', 'Tags (most tags first)'],
            ['tag_count_asc', 'Tags (fewest tags first)'],
            ['score_desc', 'Sentiment score (highest first)'],
            ['score_asc', 'Sentiment score (lowest first)'],
            ['tone_desc', 'Client tone (most hostile first)'],
            ['tone_asc', 'Client tone (calmest first)'],
            ['urgency_desc', 'Urgency (Critical → Low)'],
            ['urgency_asc', 'Urgency (Low → Critical)']
        ].forEach(function (opt) {
            var o = document.createElement('option');
            o.value = opt[0];
            o.textContent = opt[1];
            sel.appendChild(o);
        });
        bar.appendChild(sel);

        var msg = document.createElement('p');
        msg.className = 'sdv-list-msg';
        msg.id = 'sdv-list-toolbar-msg';
        bar.appendChild(msg);

        host.insertBefore(bar, host.firstChild);

        btnQueue.addEventListener('click', function () {
            btnQueue.disabled = true;
            btnForce.disabled = true;
            setToolbarMsg(msg, 'Fetching queue…', '');
            postListAjax('get_insights_queue')
                .then(function (data) {
                    if (data.status !== 'success') throw new Error(data.message || 'Queue failed');
                    var queue = data.queue || [];
                    if (!queue.length) {
                        setToolbarMsg(msg, 'No tickets in queue — all up to date for current rules.', 'ok');
                        btnQueue.disabled = false;
                        btnForce.disabled = false;
                        return;
                    }
                    setToolbarMsg(msg, 'Analyzing 0 / ' + queue.length + '…', '');
                    function step(idx, done, errs) {
                        if (idx >= queue.length) {
                            btnQueue.disabled = false;
                            btnForce.disabled = false;
                            var s = 'Done: ' + done + ' of ' + queue.length + ' analyzed.';
                            if (errs.length) s += ' Errors: ' + errs.length + '.';
                            setToolbarMsg(msg, s, errs.length ? 'err' : 'ok');
                            if (done > 0) window.location.reload();
                            return;
                        }
                        var tid = queue[idx];
                        setToolbarMsg(msg, 'Analyzing ticket #' + tid + ' (' + (idx + 1) + '/' + queue.length + ')…', '');
                        postListAjax('analyze_single_insight', { ticket_id: String(tid) })
                            .then(function (d) {
                                if (d.status === 'success') {
                                    step(idx + 1, done + 1, errs);
                                } else {
                                    errs.push('#' + tid + ': ' + (d.message || 'error'));
                                    step(idx + 1, done, errs);
                                }
                            })
                            .catch(function (e) {
                                errs.push('#' + tid + ': ' + e.message);
                                step(idx + 1, done, errs);
                            });
                    }
                    step(0, 0, []);
                })
                .catch(function (e) {
                    setToolbarMsg(msg, e.message || 'Failed', 'err');
                    btnQueue.disabled = false;
                    btnForce.disabled = false;
                });
        });

        btnForce.addEventListener('click', function () {
            var ids = [];
            document.querySelectorAll('tbody tr[data-sdv-tid]').forEach(function (tr) {
                var id = tr.getAttribute('data-sdv-tid');
                if (id && ids.indexOf(id) === -1) ids.push(id);
            });
            var masks = [];
            document.querySelectorAll('tbody tr[data-sdv-tmask]').forEach(function (tr) {
                if (tr.getAttribute('data-sdv-tid')) return;
                var m = tr.getAttribute('data-sdv-tmask');
                if (m && masks.indexOf(m) === -1) masks.push(m);
            });

            function runForceChain(idList) {
                if (!idList.length) {
                    setToolbarMsg(msg, 'No ticket rows with resolvable IDs on this page.', 'err');
                    return;
                }
                if (!window.confirm('Force re-analyze ' + idList.length + ' ticket(s) on this page? This calls the AI for each ticket.')) return;
                btnQueue.disabled = true;
                btnForce.disabled = true;
                function step(i, done, errs) {
                    if (i >= idList.length) {
                        btnQueue.disabled = false;
                        btnForce.disabled = false;
                        setToolbarMsg(msg, 'Finished: ' + done + ' of ' + idList.length + ' re-analyzed.', errs.length ? 'err' : 'ok');
                        if (done > 0) window.location.reload();
                        return;
                    }
                    var tid = idList[i];
                    setToolbarMsg(msg, 'Re-analyzing #' + tid + ' (' + (i + 1) + '/' + idList.length + ')…', '');
                    postListAjax('analyze_single_insight', { ticket_id: String(tid) })
                        .then(function (d) {
                            if (d.status === 'success') step(i + 1, done + 1, errs);
                            else {
                                errs.push('#' + tid);
                                step(i + 1, done, errs);
                            }
                        })
                        .catch(function () {
                            errs.push('#' + tid);
                            step(i + 1, done, errs);
                        });
                }
                step(0, 0, []);
            }

            if (masks.length) {
                var fd = new FormData();
                fd.append('action', 'get_ticket_insights');
                var token = getCsrfToken();
                if (token) fd.append('token', token);
                masks.forEach(function (m) { fd.append('ticket_tids[]', m); });
                fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var map = data.mask_to_id || {};
                        Object.keys(map).forEach(function (k) {
                            var nid = map[k];
                            if (nid == null) return;
                            var s = String(nid);
                            if (ids.indexOf(s) === -1) ids.push(s);
                        });
                        runForceChain(ids);
                    })
                    .catch(function (e) {
                        setToolbarMsg(msg, 'Could not resolve ticket #s: ' + (e.message || 'error'), 'err');
                    });
            } else {
                runForceChain(ids);
            }
        });

        sel.addEventListener('change', function () {
            applyTicketSort(sel.value);
        });
    }

    function tryInjectToolbar(attempt) {
        attempt = attempt || 0;
        injectToolbar();
        if (!document.getElementById('sdv-ticket-list-toolbar') && attempt < 8) {
            setTimeout(function () { tryInjectToolbar(attempt + 1); }, 350);
        }
    }

    function init() {
        tagRows();
        stampOriginalRowOrder();
        tryInjectToolbar(0);
        var pack = collectIdsAndMasks();
        loadInsights(pack.ids, pack.masks);
        if (!moStarted && document.body) {
            moStarted = true;
            try {
                var mo = new MutationObserver(scheduleRefresh);
                mo.observe(document.body, { childList: true, subtree: true });
            } catch (e) { /* ignore */ }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
HTML;
}

// ---------------------------------------------------------------------------
// Sahdev Admin Ops Copilot Drawer Injection
// Hooked on both Header and Footer with $drawerRendered singleton guard
// ---------------------------------------------------------------------------
add_hook('AdminAreaHeaderOutput', 1000, function ($vars) {
    return sahdev_render_admin_copilot_drawer(is_array($vars) ? $vars : []);
});

add_hook('AdminAreaFooterOutput', 1000, function ($vars) {
    return sahdev_render_admin_copilot_drawer(is_array($vars) ? $vars : []);
});

/**
 * Render the floating Admin Ops Copilot Drawer launcher and slide-out console.
 */
function sahdev_render_admin_copilot_drawer(array $vars = []): string
{
    static $drawerRendered = false;
    if ($drawerRendered) {
        return '';
    }

    require_once __DIR__ . '/lib/PermissionService.php';
    $adminId = \Sahdev\Lib\PermissionService::resolveCurrentAdminId();
    if ($adminId <= 0) {
        return '';
    }

    require_once __DIR__ . '/lib/SchemaManager.php';
    try {
        \Sahdev\Lib\SchemaManager::ensureAll();
    } catch (\Throwable $e) {}

    $canUseCopilot = ($adminId === 1)
        || \Sahdev\Lib\PermissionService::isSuperAdmin($adminId)
        || \Sahdev\Lib\PermissionService::hasPermission($adminId, \Sahdev\Lib\PermissionService::PERM_COPILOT_USE)
        || \Sahdev\Lib\PermissionService::hasPermission($adminId, \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE);

    if (!$canUseCopilot) {
        return '';
    }

    $copilotModel = 'AI Copilot';
    $activeVisitorCount = 0;
    try {
        if (\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_settings')) {
            $settings = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
            if ($settings && isset($settings->copilot_enabled)) {
                $val = $settings->copilot_enabled;
                if ($val === 0 || $val === '0' || $val === false) {
                    // Only suppress if explicitly set to 0
                    return '';
                }
            }

            // Resolve target Copilot provider from settings
            $copilotProvId = (int) ($settings->copilot_primary_provider_id ?? 0);
            if ($copilotProvId <= 0) {
                $copilotProvId = (int) ($settings->primary_provider_id ?? 0);
            }

            if ($copilotProvId > 0 && \WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_providers')) {
                $provRow = \WHMCS\Database\Capsule::table('tblsahdev_providers')->where('id', $copilotProvId)->first();
                if ($provRow) {
                    if (!empty($provRow->model_name)) {
                        $copilotModel = $provRow->model_name;
                    } elseif (!empty($provRow->name)) {
                        $copilotModel = $provRow->name;
                    }
                }
            } elseif (\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_providers')) {
                $anyProv = \WHMCS\Database\Capsule::table('tblsahdev_providers')->where('is_active', 1)->first();
                if ($anyProv && !empty($anyProv->model_name)) {
                    $copilotModel = $anyProv->model_name;
                }
            }

            if (!empty($settings->copilot_model_name) && empty($provRow->model_name)) {
                $copilotModel = $settings->copilot_model_name;
            }
        }
        if (\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_chat_sessions')) {
            $activeVisitorCount = (int) \WHMCS\Database\Capsule::table('tblsahdev_chat_sessions')
                ->where('session_type', 'client_livechat')
                ->where('status', 'active')
                ->count();
        }
    } catch (\Throwable $e) {}

    $drawerRendered = true;

    $csrfToken = '';
    if (function_exists('generate_token')) {
        try {
            $csrfToken = (string) generate_token('plain');
        } catch (\Throwable $e) {}
    }
    if (empty($csrfToken) && !empty($_SESSION['token'])) {
        $csrfToken = (string) $_SESSION['token'];
    }

    $ajaxUrl = 'addonmodules.php?module=sahdev&sahdev_act=ajax_handler';
    $hubUrl = 'addonmodules.php?module=sahdev&action=admin_copilot';
    $cleanModel = trim(str_replace(['models/', 'openai/'], '', $copilotModel));
    $displayModel = strlen($cleanModel) > 28 ? (substr($cleanModel, 0, 26) . '...') : $cleanModel;
    $modelBadge = htmlspecialchars(strtoupper($displayModel), ENT_QUOTES, 'UTF-8');
    $visitorBadgeHtml = $activeVisitorCount > 0
        ? '<span class="sdv-copilot-badge" id="sdv-copilot-visitor-badge">' . $activeVisitorCount . ' visitor' . ($activeVisitorCount > 1 ? 's' : '') . '</span>'
        : '<span class="sdv-copilot-badge" id="sdv-copilot-visitor-badge" style="display:none;">0</span>';

    $ajaxUrlJs = json_encode($ajaxUrl);
    $csrfTokenJs = json_encode($csrfToken);

    return <<<HTML
<style>
/* ── Sahdev Admin Ops Copilot Drawer ───────────────────────────────── */
#sdv-copilot-launcher {
    position: fixed !important;
    bottom: 24px !important;
    right: 24px !important;
    z-index: 999999 !important;
    display: flex !important;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 50px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(99, 102, 241, 0.2);
    cursor: pointer;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    user-select: none;
}
#sdv-copilot-launcher:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.6), 0 0 15px rgba(99, 102, 241, 0.4);
    border-color: rgba(129, 140, 248, 0.4);
}
#sdv-copilot-launcher .sdv-copilot-sparkle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    color: #818cf8;
}
#sdv-copilot-launcher kbd {
    background: rgba(255, 255, 255, 0.12);
    color: #cbd5e1;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 10px;
    font-family: monospace;
    margin-left: 2px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.sdv-copilot-badge {
    background: #ef4444;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    animation: sdv-pulse 2s infinite;
}
@keyframes sdv-pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.85; transform: scale(1.08); }
}
#sdv-copilot-drawer {
    position: fixed !important;
    bottom: 80px !important;
    right: 24px !important;
    width: 440px !important;
    height: 620px !important;
    max-height: calc(100vh - 110px) !important;
    max-width: calc(100vw - 36px) !important;
    z-index: 999999 !important;
    background: #090d16;
    color: #f1f5f9;
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    opacity: 0;
    transform: translateY(20px) scale(0.96);
    pointer-events: none;
    transition: opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1), transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
#sdv-copilot-drawer.sdv-open {
    opacity: 1 !important;
    transform: translateY(0) scale(1) !important;
    pointer-events: auto !important;
}
.sdv-drawer-header {
    padding: 14px 18px;
    background: #0f172a;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.sdv-drawer-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.sdv-copilot-status-dot {
    width: 9px;
    height: 9px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 8px #10b981;
}
.sdv-drawer-title {
    font-size: 14px;
    font-weight: 700;
    color: #f8fafc;
    letter-spacing: -0.01em;
}
.sdv-drawer-subtitle {
    font-size: 10px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.sdv-drawer-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
}
.sdv-drawer-btn {
    background: rgba(255, 255, 255, 0.08);
    border: none;
    color: #cbd5e1;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 11px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.sdv-drawer-btn:hover {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
    text-decoration: none;
}
.sdv-drawer-close {
    font-size: 16px;
    line-height: 1;
    padding: 4px 8px;
}
.sdv-drawer-chips {
    padding: 8px 14px;
    background: rgba(15, 23, 42, 0.7);
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    display: flex;
    gap: 6px;
    overflow-x: auto;
    white-space: nowrap;
}
.sdv-chip {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #94a3b8;
    padding: 4px 10px;
    border-radius: 14px;
    font-size: 11px;
    cursor: pointer;
    transition: all 0.15s;
}
.sdv-chip:hover {
    background: rgba(99, 102, 241, 0.2);
    border-color: rgba(99, 102, 241, 0.4);
    color: #c7d2fe;
}
.sdv-drawer-messages {
    flex: 1;
    padding: 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.sdv-msg {
    display: flex;
    flex-direction: column;
    max-width: 90%;
}
.sdv-msg-user {
    align-self: flex-end;
    background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
    color: #ffffff;
    padding: 9px 14px;
    border-radius: 14px 14px 2px 14px;
    font-size: 13px;
    line-height: 1.4;
    word-break: break-word;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
}
.sdv-msg-bot {
    align-self: flex-start;
    background: #131b2e;
    color: #e2e8f0;
    padding: 12px 15px;
    border-radius: 14px 14px 14px 2px;
    border: 1px solid rgba(255, 255, 255, 0.07);
    font-size: 13px;
    line-height: 1.45;
    word-break: break-word;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}
.sdv-action-proposal {
    background: #1e1b2e;
    border: 1px solid #6366f1;
    border-radius: 10px;
    padding: 12px;
    margin-top: 10px;
    font-size: 12px;
}
.sdv-action-proposal.tier-destructive {
    border-color: #ef4444;
    background: #2a1215;
}
.sdv-action-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.sdv-tier-pill {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 2px 6px;
    border-radius: 4px;
    background: #4f46e5;
    color: #fff;
}
.sdv-tier-pill.tier-destructive { background: #dc2626; }
.sdv-action-diff-tbl {
    width: 100%;
    margin: 8px 0;
    border-collapse: collapse;
    font-size: 11px;
}
.sdv-action-diff-tbl td {
    padding: 4px 6px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.sdv-diff-before { color: #f87171; text-decoration: line-through; }
.sdv-diff-after { color: #34d399; font-weight: 600; }
.sdv-action-footer {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    align-items: center;
}
.sdv-btn-confirm {
    background: #10b981;
    color: #fff;
    border: none;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.sdv-btn-confirm:hover { background: #059669; }
.sdv-btn-dismiss {
    background: rgba(255,255,255,0.1);
    color: #94a3b8;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 11px;
    cursor: pointer;
}
.sdv-btn-rollback {
    background: #f59e0b;
    color: #111;
    border: none;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}
.sdv-drawer-footer {
    padding: 12px;
    background: #0f172a;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.sdv-input-row {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}
#sdv-copilot-input {
    flex: 1;
    background: #1e293b;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    color: #f8fafc;
    padding: 8px 12px;
    font-size: 13px;
    resize: none;
    max-height: 100px;
    min-height: 38px;
    font-family: inherit;
    line-height: 1.4;
}
#sdv-copilot-input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25);
}
#sdv-copilot-send {
    background: #6366f1;
    border: none;
    color: #fff;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
    flex-shrink: 0;
}
#sdv-copilot-send:hover { background: #4f46e5; }
.sdv-copilot-status {
    font-size: 11px;
    color: #94a3b8;
    display: none;
    align-items: center;
    gap: 6px;
}
.sdv-spinner {
    width: 12px;
    height: 12px;
    border: 2px solid rgba(255,255,255,0.2);
    border-top-color: #818cf8;
    border-radius: 50%;
    animation: sdv-spin 0.6s linear infinite;
}
@keyframes sdv-spin { to { transform: rotate(360deg); } }
</style>

<!-- Floating Launcher -->
<div id="sdv-copilot-launcher" title="Open Sahdev Ops Copilot (Ctrl + Space)">
    <span class="sdv-copilot-sparkle">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2L14.4 7.6L20 10L14.4 12.4L12 18L9.6 12.4L4 10L9.6 7.6L12 2Z"/>
        </svg>
    </span>
    <span>Copilot</span>
    <kbd>Ctrl+Space</kbd>
    {$visitorBadgeHtml}
</div>

<!-- Slide-out Console -->
<div id="sdv-copilot-drawer">
    <div class="sdv-drawer-header">
        <div class="sdv-drawer-header-left">
            <div class="sdv-copilot-status-dot"></div>
            <div>
                <div class="sdv-drawer-title">Sahdev Ops Copilot</div>
                <div class="sdv-drawer-subtitle">Model: {$modelBadge}</div>
            </div>
        </div>
        <div class="sdv-drawer-header-right">
            <a href="{$hubUrl}" class="sdv-drawer-btn" title="Open Full Screen Ops Console" target="_blank">
                <span>Ops Hub</span>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
            </a>
            <button type="button" class="sdv-drawer-btn sdv-drawer-close" id="sdv-copilot-close" title="Close Drawer">&times;</button>
        </div>
    </div>

    <div class="sdv-drawer-chips">
        <button type="button" class="sdv-chip" data-prompt="Show 360 overview of server telemetry and system health">⚡ Server Health</button>
        <button type="button" class="sdv-chip" data-prompt="Generate financial and operational KPI summary">📊 BI Metrics</button>
        <button type="button" class="sdv-chip" data-prompt="List overdue invoices older than 30 days with client balances">💰 Overdue Invoices</button>
        <button type="button" class="sdv-chip" data-prompt="Analyze recent payment gateway failures or timeout logs">⚠️ Gateway Errors</button>
    </div>

    <div class="sdv-drawer-messages" id="sdv-copilot-msgs">
        <div class="sdv-msg sdv-msg-bot">
            <strong>Welcome, Administrator.</strong><br>
            I am your Organization Ops Copilot. Ask me to look up tickets, inspect client accounts, analyze payment gateways, or execute safe WHMCS operations with full 2-phase confirmation and 1-click atomic rollback.
        </div>
    </div>

    <div class="sdv-drawer-footer">
        <div class="sdv-copilot-status" id="sdv-copilot-status">
            <div class="sdv-spinner"></div>
            <span id="sdv-copilot-status-text">Thinking...</span>
        </div>
        <div class="sdv-input-row">
            <textarea id="sdv-copilot-input" placeholder="Type instruction or safe ops command..." rows="1"></textarea>
            <button type="button" id="sdv-copilot-send" title="Send (Enter)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    var ajaxUrl = {$ajaxUrlJs};
    var csrfToken = {$csrfTokenJs};
    var launcher = document.getElementById('sdv-copilot-launcher');
    var drawer = document.getElementById('sdv-copilot-drawer');
    var closeBtn = document.getElementById('sdv-copilot-close');
    var inputEl = document.getElementById('sdv-copilot-input');
    var sendBtn = document.getElementById('sdv-copilot-send');
    var msgsEl = document.getElementById('sdv-copilot-msgs');
    var statusEl = document.getElementById('sdv-copilot-status');
    var statusText = document.getElementById('sdv-copilot-status-text');

    var sessionUuid = sessionStorage.getItem('sdv_copilot_uuid');
    if (!sessionUuid) {
        sessionUuid = 'cop_' + Math.random().toString(36).substring(2, 12) + Date.now().toString(36);
        sessionStorage.setItem('sdv_copilot_uuid', sessionUuid);
    }

    function toggleDrawer(open) {
        var shouldOpen = typeof open === 'boolean' ? open : !drawer.classList.contains('sdv-open');
        if (shouldOpen) {
            drawer.classList.add('sdv-open');
            setTimeout(function() { inputEl.focus(); }, 100);
        } else {
            drawer.classList.remove('sdv-open');
        }
    }

    if (launcher) launcher.addEventListener('click', function() { toggleDrawer(); });
    if (closeBtn) closeBtn.addEventListener('click', function() { toggleDrawer(false); });

    // Keyboard shortcut: Ctrl + Space / Cmd + Space
    window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.code === 'Space') {
            e.preventDefault();
            toggleDrawer();
        } else if (e.key === 'Escape' && drawer.classList.contains('sdv-open')) {
            toggleDrawer(false);
        }
    });

    // Quick chips
    document.querySelectorAll('.sdv-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var prompt = this.getAttribute('data-prompt');
            if (prompt) {
                inputEl.value = prompt;
                sendMessage();
            }
        });
    });

    // Auto-expand textarea
    inputEl.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    sendBtn.addEventListener('click', sendMessage);

    function appendMsg(role, text) {
        var d = document.createElement('div');
        d.className = 'sdv-msg ' + (role === 'user' ? 'sdv-msg-user' : 'sdv-msg-bot');
        d.innerHTML = text.replace(/\\n/g, '<br>');
        msgsEl.appendChild(d);
        msgsEl.scrollTop = msgsEl.scrollHeight;
        return d;
    }

    function getPageContext() {
        var params = new URLSearchParams(window.location.search);
        return {
            url: window.location.href,
            pathname: window.location.pathname,
            ticket_id: params.get('id') || params.get('ticketid') || '',
            user_id: params.get('userid') || '',
            service_id: params.get('serviceid') || params.get('hostingid') || '',
            invoice_id: params.get('invoiceid') || '',
            action: params.get('action') || ''
        };
    }

    function sendMessage() {
        var text = (inputEl.value || '').trim();
        if (!text) return;

        appendMsg('user', text);
        inputEl.value = '';
        inputEl.style.height = '38px';
        inputEl.disabled = true;
        sendBtn.disabled = true;

        statusEl.style.display = 'flex';
        statusText.textContent = 'Processing request...';

        var body = new FormData();
        body.append('action', 'copilot_send_message');
        body.append('session_uuid', sessionUuid);
        body.append('message', text);
        body.append('page_context', JSON.stringify(getPageContext()));
        body.append('token', csrfToken);

        fetch(ajaxUrl, {
            method: 'POST',
            body: body
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            statusEl.style.display = 'none';
            inputEl.disabled = false;
            sendBtn.disabled = false;
            inputEl.focus();

            if (data.status === 'success' || data.success) {
                var botMsg = appendMsg('bot', data.reply || 'Operation completed.');

                // Render Action Cards if proposed
                if (data.action_cards && data.action_cards.length > 0) {
                    data.action_cards.forEach(function(card) {
                        renderActionCard(botMsg, card);
                    });
                }
            } else {
                appendMsg('bot', '⚠️ ' + (data.message || data.error || 'Request failed.'));
            }
        })
        .catch(function(err) {
            statusEl.style.display = 'none';
            inputEl.disabled = false;
            sendBtn.disabled = false;
            appendMsg('bot', '⚠️ Communication error: ' + err.message);
        });
    }

    function renderActionCard(parentEl, card) {
        var cardDiv = document.createElement('div');
        var isDestructive = card.risk_tier === 3 || card.requires_password;
        cardDiv.className = 'sdv-action-proposal' + (isDestructive ? ' tier-destructive' : '');

        var diffRows = '';
        if (card.diff) {
            for (var prop in card.diff) {
                if (card.diff.hasOwnProperty(prop)) {
                    var beforeVal = card.diff[prop].before != null ? String(card.diff[prop].before) : '(none)';
                    var afterVal = card.diff[prop].after != null ? String(card.diff[prop].after) : '(none)';
                    diffRows += '<tr><td><strong>' + prop + '</strong></td><td class="sdv-diff-before">' + beforeVal + '</td><td>&rarr;</td><td class="sdv-diff-after">' + afterVal + '</td></tr>';
                }
            }
        }

        var pwdPrompt = isDestructive
            ? '<div style="margin:8px 0;"><input type="password" class="sdv-tier3-pwd" placeholder="Enter admin password to authorize" style="width:100%;padding:6px;border-radius:4px;border:1px solid rgba(255,255,255,0.2);background:#1e1b2e;color:#fff;font-size:11px;"></div>'
            : '';

        cardDiv.innerHTML =
            '<div class="sdv-action-header">' +
                '<strong>' + (card.title || card.action_key) + '</strong>' +
                '<span class="sdv-tier-pill' + (isDestructive ? ' tier-destructive' : '') + '">' +
                    (card.risk_tier === 3 ? 'Tier 3 (Destructive)' : 'Tier 2 (Reversible)') +
                '</span>' +
            '</div>' +
            '<div style="font-size:11px;color:#cbd5e1;">' + (card.description || '') + '</div>' +
            (diffRows ? '<table class="sdv-action-diff-tbl"><tbody>' + diffRows + '</tbody></table>' : '') +
            pwdPrompt +
            '<div class="sdv-action-footer">' +
                '<button type="button" class="sdv-btn-confirm">Confirm & Execute</button>' +
                '<button type="button" class="sdv-btn-dismiss">Dismiss</button>' +
            '</div>';

        parentEl.appendChild(cardDiv);
        msgsEl.scrollTop = msgsEl.scrollHeight;

        var confirmBtn = cardDiv.querySelector('.sdv-btn-confirm');
        var dismissBtn = cardDiv.querySelector('.sdv-btn-dismiss');

        dismissBtn.addEventListener('click', function() {
            cardDiv.remove();
        });

        confirmBtn.addEventListener('click', function() {
            var pwd = isDestructive ? (cardDiv.querySelector('.sdv-tier3-pwd') ? cardDiv.querySelector('.sdv-tier3-pwd').value : '') : '';
            if (isDestructive && !pwd) {
                alert('Administrator password is required for Tier-3 operations.');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Executing...';

            var form = new FormData();
            form.append('action', 'copilot_execute_op');
            form.append('action_key', card.action_key);
            form.append('params', JSON.stringify(card.params || {}));
            form.append('session_id', card.session_id || 0);
            if (pwd) form.append('admin_password', pwd);
            form.append('token', csrfToken);

            fetch(ajaxUrl, {
                method: 'POST',
                body: form
            })
            .then(function(r) { return r.json(); })
            .then(function(execRes) {
                if (execRes.status === 'success' || execRes.success) {
                    var jId = execRes.journal_id || '';
                    cardDiv.innerHTML =
                        '<div style="color:#34d399;font-weight:700;font-size:12px;display:flex;justify-content:space-between;align-items:center;">' +
                            '<span>✓ Executed ' + (jId ? '[Journal #' + jId + ']' : '') + '</span>' +
                            (jId ? '<button type="button" class="sdv-btn-rollback" data-journal="' + jId + '">↩ Rollback</button>' : '') +
                        '</div>' +
                        '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">' + (execRes.message || 'Operation executed successfully.') + '</div>';

                    var rbBtn = cardDiv.querySelector('.sdv-btn-rollback');
                    if (rbBtn) {
                        rbBtn.addEventListener('click', function() {
                            rollbackJournal(jId, cardDiv);
                        });
                    }
                } else {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Retry';
                    alert('Execution failed: ' + (execRes.message || execRes.error || 'Unknown error'));
                }
            })
            .catch(function(err) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Retry';
                alert('Execution error: ' + err.message);
            });
        });
    }

    function rollbackJournal(journalId, containerEl) {
        if (!confirm('Are you sure you want to rollback this operation?')) return;

        var form = new FormData();
        form.append('action', 'copilot_rollback_op');
        form.append('journal_id', journalId);
        form.append('token', csrfToken);

        fetch(ajaxUrl, {
            method: 'POST',
            body: form
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'success' || data.success) {
                containerEl.innerHTML = '<div style="color:#f59e0b;font-weight:700;font-size:12px;">↩ Rolled Back Successfully</div><div style="font-size:11px;color:#94a3b8;">' + (data.message || '') + '</div>';
            } else {
                alert('Rollback failed: ' + (data.message || data.error || 'Unknown error'));
            }
        })
        .catch(function(err) {
            alert('Rollback error: ' + err.message);
        });
    }
})();
</script>
HTML;
}

// ---------------------------------------------------------------------------
// Sahdev Client Live Chat Widget Injection
// ---------------------------------------------------------------------------
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    return sahdev_render_client_livechat_widget(is_array($vars) ? $vars : []);
});

/**
 * Render the Client Live Chat Widget in the customer portal.
 */
function sahdev_render_client_livechat_widget(array $vars): string
{
    try {
        if (!\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_settings')) {
            return '';
        }
        $settings = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
        if (!$settings || empty($settings->client_chat_enabled)) {
            return '';
        }

        $clientId = !empty($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
        if (!empty($settings->client_chat_require_auth) && $clientId <= 0) {
            return '';
        }

        $chatTitle = !empty($settings->client_chat_title)
            ? htmlspecialchars($settings->client_chat_title, ENT_QUOTES, 'UTF-8')
            : 'Hosting Support Assistant';

        $brandColor = !empty($settings->client_chat_brand_color)
            ? htmlspecialchars($settings->client_chat_brand_color, ENT_QUOTES, 'UTF-8')
            : '#0d6efd';

        $chatPosition = ($settings->client_chat_position ?? 'bottom-right') === 'bottom-left' ? 'bottom-left' : 'bottom-right';

        $chatWidth = !empty($settings->client_chat_width) ? (int)$settings->client_chat_width : 380;
        $chatHeight = !empty($settings->client_chat_height) ? (int)$settings->client_chat_height : 560;
        $expandWidth = !empty($settings->client_chat_expand_width) ? (int)$settings->client_chat_expand_width : 700;
        $expandHeight = !empty($settings->client_chat_expand_height) ? (int)$settings->client_chat_expand_height : 720;
        $chatTheme = !empty($settings->client_chat_theme) ? preg_replace('/[^a-z0-9_]/', '', strtolower($settings->client_chat_theme)) : 'modern_light';
        $launcherStyle = ($settings->client_chat_launcher_style ?? 'circular') === 'pill' ? 'pill' : 'circular';
        $launcherText = !empty($settings->client_chat_launcher_text) ? htmlspecialchars($settings->client_chat_launcher_text, ENT_QUOTES, 'UTF-8') : 'Chat with Us';
        $historyEnabled = isset($settings->client_chat_history_enabled) ? (bool)$settings->client_chat_history_enabled : true;
        $chatLogo = !empty($settings->client_chat_logo)
            ? htmlspecialchars(trim($settings->client_chat_logo), ENT_QUOTES, 'UTF-8')
            : '';
        $kbEnabled = isset($settings->client_chat_kb_enabled) ? (bool)$settings->client_chat_kb_enabled : true;
        $maxMsgChars = isset($settings->client_chat_max_msg_chars) ? (int)$settings->client_chat_max_msg_chars : 1000;
        $maxSessionChars = isset($settings->client_chat_max_session_chars) ? (int)$settings->client_chat_max_session_chars : 10000;
        $csatEnabled = !isset($settings->client_chat_csat_enabled) || !empty($settings->client_chat_csat_enabled);
        $soundEnabled = !isset($settings->client_chat_sound_enabled) || !empty($settings->client_chat_sound_enabled);
        $poweredByShow = !isset($settings->client_chat_powered_by_show) || !empty($settings->client_chat_powered_by_show);
        $poweredByText = !empty($settings->client_chat_powered_by_text) ? htmlspecialchars(trim($settings->client_chat_powered_by_text), ENT_QUOTES, 'UTF-8') : 'Powered by Sahdev AI';
        $poweredByUrl = !empty($settings->client_chat_powered_by_url) ? htmlspecialchars(trim($settings->client_chat_powered_by_url), ENT_QUOTES, 'UTF-8') : '';
        $disclaimerEnabled = !empty($settings->client_chat_disclaimer_enabled);
        $disclaimerText = !empty($settings->client_chat_disclaimer_text) ? htmlspecialchars(trim($settings->client_chat_disclaimer_text), ENT_QUOTES, 'UTF-8') : 'AI Assistant: Responses may be AI-generated. Please verify critical information.';

        $welcomeMsgRaw = !empty($settings->client_chat_welcome_message)
            ? $settings->client_chat_welcome_message
            : (!empty($settings->client_chat_greeting)
                ? $settings->client_chat_greeting
                : 'Hi there! 👋 How can our organization assistant help you today?');
        $welcomeMsg = htmlspecialchars($welcomeMsgRaw, ENT_QUOTES, 'UTF-8');

        $proactiveDelay = isset($settings->client_chat_proactive_delay) ? max(0, (int)$settings->client_chat_proactive_delay) : 15;
        $proactiveMsgRaw = !empty($settings->client_chat_proactive_message)
            ? $settings->client_chat_proactive_message
            : (!empty($welcomeMsgRaw) ? $welcomeMsgRaw : 'Hi there! 👋 Need assistance with your hosting services, domains, or billing? Chat with our AI assistant anytime.');
        $proactiveMsg = htmlspecialchars($proactiveMsgRaw, ENT_QUOTES, 'UTF-8');
    } catch (\Throwable $e) {
        return '';
    }

    // Determine WHMCS system URL and base path for client area
    $fullSystemUrl = '';
    $whmcsBase = '';
    if (!empty($vars['systemurl'])) {
        $fullSystemUrl = rtrim($vars['systemurl'], '/');
        $parsed = parse_url($vars['systemurl'], PHP_URL_PATH);
        if (!empty($parsed)) {
            $whmcsBase = rtrim($parsed, '/');
        }
    } elseif (!empty($vars['systemsslurl'])) {
        $fullSystemUrl = rtrim($vars['systemsslurl'], '/');
        $parsed = parse_url($vars['systemsslurl'], PHP_URL_PATH);
        if (!empty($parsed)) {
            $whmcsBase = rtrim($parsed, '/');
        }
    } elseif (class_exists('\WHMCS\Config\Setting')) {
        try {
            $su = \WHMCS\Config\Setting::getValue('SystemSSLURL') ?: \WHMCS\Config\Setting::getValue('SystemURL');
            if (!empty($su)) {
                $fullSystemUrl = rtrim($su, '/');
                $parsed = parse_url($su, PHP_URL_PATH);
                if (!empty($parsed)) {
                    $whmcsBase = rtrim($parsed, '/');
                }
            }
        } catch (\Throwable $e) {}
    }
    if (empty($fullSystemUrl)) {
        if (!empty($GLOBALS['CONFIG']['SystemSSLURL'])) {
            $fullSystemUrl = rtrim($GLOBALS['CONFIG']['SystemSSLURL'], '/');
        } elseif (!empty($GLOBALS['CONFIG']['SystemURL'])) {
            $fullSystemUrl = rtrim($GLOBALS['CONFIG']['SystemURL'], '/');
        } else {
            try {
                $dbUrl = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'SystemSSLURL')->value('value')
                    ?: \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
                if (!empty($dbUrl)) {
                    $fullSystemUrl = rtrim($dbUrl, '/');
                }
            } catch (\Throwable $e) {}
        }
    }
    if ($whmcsBase === '' && !empty($fullSystemUrl)) {
        $parsed = parse_url($fullSystemUrl, PHP_URL_PATH);
        if (!empty($parsed)) {
            $whmcsBase = rtrim($parsed, '/');
        }
    }
    if ($whmcsBase === '' && isset($_SERVER['SCRIPT_NAME'])) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        if ($scriptDir !== '/' && $scriptDir !== '.') {
            $whmcsBase = rtrim($scriptDir, '/');
        }
    }

    // Endpoints:
    // 1. Native WHMCS routing via index.php?m=sahdev (works across all web server docroot setups)
    $nativeAjaxUrl = ($fullSystemUrl !== '' ? $fullSystemUrl : '') . '/index.php?m=sahdev&sahdev_act=ajax_handler';
    // 2. Direct script execution fallback
    $primaryAjaxUrl = ($whmcsBase !== '' ? $whmcsBase : '') . '/modules/addons/sahdev/ajax.php';

    $isLeft = ($chatPosition === 'bottom-left');
    $launcherPosCss = $isLeft ? 'left: 24px !important; right: auto !important;' : 'right: 24px !important; left: auto !important;';
    $windowPosCss = $isLeft ? 'left: 24px !important; right: auto !important;' : 'right: 24px !important; left: auto !important;';

    $debugMode = !empty($settings->client_chat_debug);

    $clientName = '';
    $clientEmail = '';
    if ($clientId > 0) {
        try {
            $cl = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname', 'email']);
            if ($cl) {
                $clientName = trim(($cl->firstname ?? '') . ' ' . ($cl->lastname ?? ''));
                $clientEmail = (string) ($cl->email ?? '');
            }
        } catch (\Throwable $e) {}
    }
    $isLoggedInJs = json_encode($clientId > 0);
    $currentClientIdJs = json_encode((int)$clientId);
    $clientNameJs = json_encode($clientName);
    $clientEmailJs = json_encode($clientEmail);

    $systemUrlJs = json_encode($fullSystemUrl);
    $whmcsBaseJs = json_encode($whmcsBase);
    $nativeAjaxUrlJs = json_encode($nativeAjaxUrl);
    $primaryAjaxUrlJs = json_encode($primaryAjaxUrl);
    $welcomeMsgJs = json_encode($welcomeMsgRaw);
    $proactiveMsgJs = json_encode($proactiveMsgRaw);
    $proactiveDelayJs = json_encode($proactiveDelay);
    $brandColorJs = json_encode($brandColor);
    $debugModeJs = json_encode($debugMode);
    $chatWidthJs = json_encode($chatWidth);
    $chatHeightJs = json_encode($chatHeight);
    $expandWidthJs = json_encode($expandWidth);
    $expandHeightJs = json_encode($expandHeight);
    $maxMsgCharsJs = json_encode($maxMsgChars);
    $maxSessionCharsJs = json_encode($maxSessionChars);
    $csatEnabledJs = json_encode($csatEnabled);
    $soundEnabledJs = json_encode($soundEnabled);
    $maxMsgAttr = $maxMsgChars > 0 ? 'maxlength="' . (int)$maxMsgChars . '"' : '';

    // Launcher markup based on style with robust inline onclick and keyboard handlers
    $launcherHtml = '';
    if ($launcherStyle === 'pill') {
        $launcherHtml = <<<HTML
<div id="sdv-client-chat-launcher" class="sdv-launcher-pill" role="button" tabindex="0" title="{$launcherText}" onclick="if(window.sdvToggleChat){window.sdvToggleChat(event);}else{(function(){var w=document.getElementById('sdv-client-chat-window');if(w){var o=w.classList.contains('sdv-open')&&w.style.display!=='none';w.classList.toggle('sdv-open',!o);w.style.setProperty('display',!o?'flex':'none','important');w.style.setProperty('visibility',!o?'visible':'hidden','important');w.style.setProperty('opacity',!o?'1':'0','important');w.style.setProperty('z-index','2147483647','important');}})();}" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();if(window.sdvToggleChat){window.sdvToggleChat(event);}}">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="pointer-events: none; flex-shrink: 0; display: block;">
        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z" style="pointer-events: none;"/>
    </svg>
    <span class="sdv-launcher-text" style="pointer-events: none;">{$launcherText}</span>
</div>
HTML;
    } else {
        $launcherHtml = <<<HTML
<div id="sdv-client-chat-launcher" class="sdv-launcher-circle" role="button" tabindex="0" title="Support Chat" onclick="if(window.sdvToggleChat){window.sdvToggleChat(event);}else{(function(){var w=document.getElementById('sdv-client-chat-window');if(w){var o=w.classList.contains('sdv-open')&&w.style.display!=='none';w.classList.toggle('sdv-open',!o);w.style.setProperty('display',!o?'flex':'none','important');w.style.setProperty('visibility',!o?'visible':'hidden','important');w.style.setProperty('opacity',!o?'1':'0','important');w.style.setProperty('z-index','2147483647','important');}})();}" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();if(window.sdvToggleChat){window.sdvToggleChat(event);}}">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="pointer-events: none; flex-shrink: 0; display: block;">
        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z" style="pointer-events: none;"/>
    </svg>
</div>
HTML;
    }

    $proactiveBubbleHtml = '';
    if ($proactiveDelay > 0) {
        $proactiveBadgeIcon = !empty($chatLogo)
            ? '<img src="' . $chatLogo . '" alt="Logo" style="width:14px;height:14px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:4px;">'
            : '<span class="sdv-proactive-pulse"></span>';
        $proactiveBubbleHtml = <<<HTML
<div id="sdv-proactive-bubble" class="sdv-proactive-bubble" style="display:none;" role="region" aria-label="Support Message" onclick="window.sdvAcceptProactive && window.sdvAcceptProactive(event);">
    <button type="button" class="sdv-proactive-close" title="Dismiss message" aria-label="Dismiss" onclick="window.sdvDismissProactive && window.sdvDismissProactive(event);">&times;</button>
    <div class="sdv-proactive-top">
        <div class="sdv-proactive-badge">{$proactiveBadgeIcon} {$chatTitle}</div>
        <span class="sdv-proactive-now">Just now</span>
    </div>
    <div class="sdv-proactive-text">{$proactiveMsg}</div>
    <div class="sdv-proactive-reply-btn">
        <span>Click to chat</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </div>
</div>
HTML;
    }

    $historyBtnHtml = $historyEnabled ? '<button type="button" class="sdv-cl-action-btn" id="sdv-cl-history-btn" title="Conversation History" onclick="window.sdvToggleHistory && window.sdvToggleHistory();"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></button>' : '';

    $kbBtnHtml = $kbEnabled ? '<button type="button" class="sdv-cl-action-btn" id="sdv-cl-kb-btn" title="Help & Knowledge Base" aria-label="Knowledge Base" onclick="window.sdvToggleKb && window.sdvToggleKb();"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="13" y2="11"/></svg></button>' : '';

    $exportBtnHtml = '<button type="button" class="sdv-cl-action-btn" id="sdv-cl-export-btn" title="Export Chat Transcript" aria-label="Export" onclick="window.sdvExportTranscript && window.sdvExportTranscript();"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg></button>';

    $soundBtnHtml = $soundEnabled ? '<button type="button" class="sdv-cl-action-btn" id="sdv-cl-sound-btn" title="Toggle Sound Notifications" aria-label="Sound" onclick="window.sdvToggleSound && window.sdvToggleSound();"><svg id="sdv-sound-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg></button>' : '';

    $avatarHtml = !empty($chatLogo)
        ? '<img src="' . $chatLogo . '" alt="Logo" class="sdv-cl-avatar-img">'
        : 'AI';

    $poweredByHtml = '';
    if ($poweredByShow) {
        if (!empty($poweredByUrl)) {
            $poweredByHtml = '<div class="sdv-cl-branding"><a href="' . $poweredByUrl . '" target="_blank" rel="noopener noreferrer">' . $poweredByText . '</a></div>';
        } else {
            $poweredByHtml = '<div class="sdv-cl-branding">' . $poweredByText . '</div>';
        }
    }

    $disclaimerHtml = '';
    if ($disclaimerEnabled) {
        $disclaimerHtml = <<<DISC
    <div id="sdv-cl-disclaimer" class="sdv-cl-disclaimer" role="note">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="sdv-disclaimer-icon" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span class="sdv-disclaimer-text">{$disclaimerText}</span>
        <button type="button" class="sdv-disclaimer-close" title="Dismiss notice" aria-label="Dismiss disclaimer" onclick="(function(btn){var d=document.getElementById('sdv-cl-disclaimer');if(d){d.style.display='none';try{sessionStorage.setItem('sdv_dismiss_disclaimer','1');}catch(e){}}})(this);">&times;</button>
    </div>
DISC;
    }

    return <<<HTML
<style>
/* ── Sahdev Client Live Chat Widget ────────────────────────────────── */
:root {
    --sdv-brand: {$brandColor};
    --sdv-chat-w: {$chatWidth}px;
    --sdv-chat-h: {$chatHeight}px;
    --sdv-expand-w: {$expandWidth}px;
    --sdv-expand-h: {$expandHeight}px;
}

#sdv-client-chat-launcher {
    position: fixed !important;
    bottom: 24px !important;
    {$launcherPosCss}
    background: {$brandColor} !important;
    background: var(--sdv-brand, {$brandColor}) !important;
    color: #ffffff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.2) !important;
    cursor: pointer !important;
    z-index: 2147483646 !important;
    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s ease !important;
    user-select: none !important;
    -webkit-user-select: none !important;
    outline: none !important;
    pointer-events: auto !important;
}
#sdv-client-chat-launcher.sdv-launcher-circle {
    width: 60px !important;
    height: 60px !important;
    border-radius: 50% !important;
}
#sdv-client-chat-launcher.sdv-launcher-pill {
    padding: 12px 22px !important;
    border-radius: 30px !important;
    gap: 9px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    letter-spacing: 0.2px !important;
}
#sdv-client-chat-launcher:hover {
    transform: scale(1.06) translateY(-2px) !important;
    box-shadow: 0 16px 32px -4px rgba(0, 0, 0, 0.45) !important;
}
#sdv-client-chat-launcher:active {
    transform: scale(0.96) translateY(0) !important;
}

/* Proactive Trigger Bubble */
.sdv-proactive-bubble {
    position: fixed !important;
    bottom: 96px !important;
    {$launcherPosCss}
    width: 290px !important;
    background: #ffffff !important;
    color: #1e293b !important;
    border-radius: 16px !important;
    box-shadow: 0 12px 30px -4px rgba(0, 0, 0, 0.22), 0 0 0 1px rgba(0, 0, 0, 0.08) !important;
    padding: 13px 15px !important;
    z-index: 999997 !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    animation: sdvBubblePop 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
.sdv-proactive-bubble:hover {
    transform: translateY(-3px) !important;
    box-shadow: 0 16px 36px -4px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(0, 0, 0, 0.1) !important;
}
.sdv-proactive-close {
    position: absolute !important;
    top: 8px !important;
    right: 8px !important;
    width: 20px !important;
    height: 20px !important;
    background: rgba(0, 0, 0, 0.06) !important;
    border: none !important;
    border-radius: 50% !important;
    font-size: 13px !important;
    line-height: 1 !important;
    color: #64748b !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: background 0.15s, color 0.15s !important;
}
.sdv-proactive-close:hover {
    background: #e2e8f0 !important;
    color: #0f172a !important;
}
.sdv-proactive-top {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-bottom: 6px !important;
    padding-right: 18px !important;
}
.sdv-proactive-badge {
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: var(--sdv-brand, {$brandColor}) !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.sdv-proactive-pulse {
    width: 7px !important;
    height: 7px !important;
    background: #10b981 !important;
    border-radius: 50% !important;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3) !important;
}
.sdv-proactive-now {
    font-size: 10px !important;
    color: #94a3b8 !important;
}
.sdv-proactive-text {
    font-size: 12.5px !important;
    line-height: 1.4 !important;
    color: #334155 !important;
    margin-bottom: 8px !important;
}
.sdv-proactive-reply-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    color: var(--sdv-brand, {$brandColor}) !important;
}
@keyframes sdvBubblePop {
    0% { opacity: 0; transform: translateY(12px) scale(0.92); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
}

#sdv-client-chat-window {
    position: fixed !important;
    bottom: 96px !important;
    {$windowPosCss}
    width: {$chatWidth}px !important;
    width: var(--sdv-chat-w, {$chatWidth}px) !important;
    height: {$chatHeight}px !important;
    height: var(--sdv-chat-h, {$chatHeight}px) !important;
    max-height: calc(100vh - 120px) !important;
    max-width: calc(100vw - 36px) !important;
    z-index: 2147483647 !important;
    background: #ffffff !important;
    color: #1e293b !important;
    border-radius: 16px !important;
    box-shadow: 0 24px 50px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0, 0, 0, 0.08) !important;
    display: none;
    flex-direction: column !important;
    overflow: hidden !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    box-sizing: border-box !important;
    transition: width 0.25s cubic-bezier(0.16, 1, 0.3, 1), height 0.25s cubic-bezier(0.16, 1, 0.3, 1), transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s ease !important;
}
#sdv-client-chat-window * {
    box-sizing: border-box;
}
#sdv-client-chat-window.sdv-open {
    display: flex !important;
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    animation: sdvFadeInUp 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
}
#sdv-client-chat-window.sdv-expanded {
    width: {$expandWidth}px !important;
    width: var(--sdv-expand-w, {$expandWidth}px) !important;
    height: {$expandHeight}px !important;
    height: var(--sdv-expand-h, {$expandHeight}px) !important;
    max-height: calc(100vh - 90px) !important;
    max-width: calc(100vw - 40px) !important;
}
@keyframes sdvFadeInUp {
    0% {
        opacity: 0;
        transform: translateY(14px) scale(0.97);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
@media (max-width: 640px) {
    #sdv-client-chat-window {
        bottom: 84px !important;
        left: 12px !important;
        right: 12px !important;
        width: auto !important;
        max-width: none !important;
        height: calc(100vh - 110px) !important;
        max-height: none !important;
    }
}

/* Header */
.sdv-cl-header {
    background: {$brandColor};
    background: var(--sdv-brand, {$brandColor});
    color: #ffffff;
    padding: 13px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.sdv-cl-header-info {
    display: flex;
    align-items: center;
    gap: 10px;
    overflow: hidden;
}
.sdv-cl-avatar {
    width: 34px;
    height: 34px;
    background: rgba(255, 255, 255, 0.22);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    flex-shrink: 0;
    overflow: hidden;
}
.sdv-cl-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    display: block;
}
.sdv-cl-title { font-size: 14px; font-weight: 700; line-height: 1.2; color: #ffffff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sdv-cl-status { font-size: 11px; color: rgba(255, 255, 255, 0.9); display: flex; align-items: center; gap: 5px; margin-top: 2px; }
.sdv-cl-status-dot { width: 7px; height: 7px; background: #34d399; border-radius: 50%; box-shadow: 0 0 0 2px rgba(255,255,255,0.4); }

.sdv-cl-controls {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}
.sdv-cl-action-btn {
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.88);
    font-size: 14px;
    cursor: pointer;
    line-height: 1;
    padding: 6px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, color 0.15s, transform 0.12s;
}
.sdv-cl-action-btn:hover { color: #ffffff; background: rgba(255, 255, 255, 0.2); }
.sdv-cl-action-btn:active { transform: scale(0.92); }

/* Escalation Bar */
.sdv-cl-escalate-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 8px 14px;
    font-size: 11.5px;
    color: #64748b;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.sdv-cl-escalate-btn {
    color: {$brandColor};
    color: var(--sdv-brand, {$brandColor});
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
}
.sdv-cl-escalate-btn:hover { text-decoration: underline; }

/* Message Area */
.sdv-cl-messages {
    flex: 1;
    padding: 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #f8fafc;
}
.sdv-cl-msg {
    max-width: 86%;
    padding: 11px 15px;
    font-size: 13.5px;
    line-height: 1.5;
    word-break: break-word;
}
.sdv-cl-msg-user {
    align-self: flex-end;
    background: {$brandColor};
    background: var(--sdv-brand, {$brandColor});
    color: #ffffff;
    border-radius: 14px 14px 2px 14px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.06);
}
.sdv-cl-msg-bot {
    align-self: flex-start;
    background: #ffffff;
    color: #1e293b;
    border-radius: 14px 14px 14px 2px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sdv-code-block {
    background: #1e293b;
    color: #e2e8f0;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 12px;
    overflow-x: auto;
    margin: 8px 0;
    border: 1px solid #334155;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
.sdv-inline-code {
    background: rgba(0,0,0,0.06);
    color: #0f172a;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    border: 1px solid rgba(0,0,0,0.08);
}
.sdv-chat-link {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    color: var(--sdv-brand, {$brandColor}) !important;
    background: rgba(13, 110, 253, 0.08) !important;
    border: 1px solid rgba(13, 110, 253, 0.24) !important;
    padding: 2px 8px !important;
    border-radius: 6px !important;
    font-weight: 600 !important;
    font-size: 12.5px !important;
    text-decoration: none !important;
    vertical-align: middle !important;
    line-height: 1.4 !important;
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1) !important;
    margin: 1px 2px !important;
}
.sdv-chat-link:hover {
    background: var(--sdv-brand, {$brandColor}) !important;
    color: #ffffff !important;
    border-color: var(--sdv-brand, {$brandColor}) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12) !important;
}
.sdv-chat-link .sdv-chat-link-icon {
    flex-shrink: 0 !important;
    opacity: 0.75 !important;
    transition: transform 0.15s ease !important;
}
.sdv-chat-link:hover .sdv-chat-link-icon {
    opacity: 1 !important;
    transform: translate(1px, -1px) !important;
}
.sdv-cl-msg-user .sdv-chat-link {
    background: rgba(255, 255, 255, 0.22) !important;
    color: #ffffff !important;
    border-color: rgba(255, 255, 255, 0.35) !important;
}
.sdv-cl-msg-user .sdv-chat-link:hover {
    background: #ffffff !important;
    color: var(--sdv-brand, {$brandColor}) !important;
}
.sdv-msg-ol, .sdv-msg-ul {
    margin: 8px 0 !important;
    padding-left: 20px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
}
.sdv-msg-ol {
    list-style-type: decimal !important;
}
.sdv-msg-ul {
    list-style-type: disc !important;
}
.sdv-msg-ol li, .sdv-msg-ul li {
    line-height: 1.55 !important;
    padding-left: 2px !important;
}
.sdv-msg-quote {
    margin: 8px 0 !important;
    padding: 8px 12px !important;
    border-left: 3px solid var(--sdv-brand, {$brandColor}) !important;
    background: rgba(0, 0, 0, 0.03) !important;
    border-radius: 0 8px 8px 0 !important;
    color: #475569 !important;
    font-style: italic !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
}
.sdv-msg-h1 {
    font-size: 15.5px !important;
    font-weight: 700 !important;
    margin: 10px 0 6px !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
}
.sdv-msg-h2 {
    font-size: 14px !important;
    font-weight: 700 !important;
    margin: 8px 0 4px !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
}
.sdv-msg-h3 {
    font-size: 13px !important;
    font-weight: 700 !important;
    margin: 6px 0 3px !important;
    color: #1e293b !important;
    line-height: 1.3 !important;
}
.sdv-badge-pill {
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    letter-spacing: 0.3px !important;
    padding: 2px 7px !important;
    border-radius: 10px !important;
    vertical-align: middle !important;
    line-height: 1.3 !important;
}
.sdv-badge-pill .sdv-badge-dot {
    width: 6px !important;
    height: 6px !important;
    border-radius: 50% !important;
    display: inline-block !important;
}
.sdv-badge-warning {
    background: #fef3c7 !important;
    color: #92400e !important;
    border: 1px solid #fde68a !important;
}
.sdv-badge-warning .sdv-badge-dot {
    background: #d97706 !important;
}
.sdv-badge-success {
    background: #d1fae5 !important;
    color: #065f46 !important;
    border: 1px solid #a7f3d0 !important;
}
.sdv-badge-success .sdv-badge-dot {
    background: #059669 !important;
}
.sdv-badge-danger {
    background: #fee2e2 !important;
    color: #991b1b !important;
    border: 1px solid #fecaca !important;
}
.sdv-badge-danger .sdv-badge-dot {
    background: #dc2626 !important;
}
.sdv-table-wrap {
    width: 100% !important;
    overflow-x: auto !important;
    margin: 8px 0 !important;
    border-radius: 8px !important;
    border: 1px solid #e2e8f0 !important;
}
.sdv-msg-table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 12px !important;
    text-align: left !important;
}
.sdv-msg-table th {
    background: #f1f5f9 !important;
    color: #334155 !important;
    font-weight: 700 !important;
    padding: 7px 10px !important;
    border-bottom: 1px solid #cbd5e1 !important;
    white-space: nowrap !important;
}
.sdv-msg-table td {
    padding: 6px 10px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #1e293b !important;
}
.sdv-msg-table tr:last-child td {
    border-bottom: none !important;
}
.sdv-escalate-success-card {
    background: #f0fdf4 !important;
    border: 1px solid #bbf7d0 !important;
    border-radius: 12px !important;
    padding: 13px !important;
    color: #166534 !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04) !important;
}
.sdv-escalate-header {
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    margin-bottom: 8px !important;
}
.sdv-escalate-icon {
    font-size: 22px !important;
    line-height: 1 !important;
}
.sdv-escalate-title {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #14532d !important;
}
.sdv-escalate-sub {
    font-size: 11.5px !important;
    color: #15803d !important;
}
.sdv-escalate-desc {
    font-size: 12.5px !important;
    line-height: 1.5 !important;
    margin: 6px 0 10px !important;
    color: #166534 !important;
}
.sdv-escalate-link {
    background: #16a34a !important;
    color: #ffffff !important;
    border-color: #15803d !important;
    font-weight: 700 !important;
}
.sdv-escalate-link:hover {
    background: #15803d !important;
    color: #ffffff !important;
}
.sdv-limit-alert-card {
    background: #fffbeb !important;
    border: 1px solid #fde68a !important;
    border-radius: 12px !important;
    padding: 13px !important;
    color: #92400e !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04) !important;
}
.sdv-limit-alert-card .sdv-escalate-title {
    color: #78350f !important;
}
.sdv-limit-alert-card .sdv-escalate-sub {
    color: #b45309 !important;
}
.sdv-limit-alert-card .sdv-escalate-desc {
    color: #92400e !important;
}
.sdv-limit-alert-card .sdv-escalate-link {
    background: #d97706 !important;
    border-color: #b45309 !important;
    color: #ffffff !important;
}
.sdv-limit-alert-card .sdv-escalate-link:hover {
    background: #b45309 !important;
}
.sdv-cl-char-counter {
    font-size: 11px !important;
    color: #94a3b8 !important;
    text-align: right !important;
    padding: 0 4px !important;
    line-height: 1.2 !important;
    transition: color 0.15s ease !important;
}

/* Footer & Input */
.sdv-cl-footer {
    padding: 12px;
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex-shrink: 0;
}
.sdv-cl-input-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
#sdv-cl-input {
    flex: 1;
    border: 1px solid #cbd5e1;
    border-radius: 20px;
    padding: 9px 15px;
    font-size: 13.5px;
    font-family: inherit;
    outline: none;
    background: #ffffff;
    color: #1e293b;
}
#sdv-cl-input:focus { border-color: var(--sdv-brand); box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.18); }
#sdv-cl-send {
    background: {$brandColor};
    background: var(--sdv-brand, {$brandColor});
    border: none;
    color: #fff;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: opacity 0.15s, transform 0.15s;
    flex-shrink: 0;
}
#sdv-cl-send:hover { opacity: 0.92; transform: scale(1.05); }
.sdv-cl-branding {
    font-size: 10px;
    color: #94a3b8;
    text-align: center;
}

/* ── Ultra-Modern Conversation History Drawer ──────────────────────── */
.sdv-cl-history-drawer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: #ffffff;
    z-index: 50;
    display: flex;
    flex-direction: column;
    transform: translateX(-100%);
    transition: transform 0.26s cubic-bezier(0.16, 1, 0.3, 1);
    box-sizing: border-box;
}
.sdv-cl-history-drawer.sdv-drawer-open {
    transform: translateX(0);
}
.sdv-drawer-header {
    background: #ffffff;
    border-bottom: 1px solid #edf2f7;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.sdv-drawer-back-btn {
    background: #f1f5f9;
    border: none;
    color: #334155;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    padding: 6px 12px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s, color 0.15s, transform 0.12s;
}
.sdv-drawer-back-btn:hover {
    background: #e2e8f0;
    color: #0f172a;
    transform: translateX(-2px);
}
.sdv-drawer-badge {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.sdv-drawer-hero-action {
    padding: 12px 14px 6px;
    flex-shrink: 0;
}
.sdv-hero-new-btn {
    width: 100%;
    background: linear-gradient(135deg, var(--sdv-brand, {$brandColor}) 0%, #312e81 100%);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    transition: transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.18s ease, filter 0.15s;
    text-align: left;
}
.sdv-hero-new-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    filter: brightness(1.06);
}
.sdv-hero-new-btn:active {
    transform: translateY(0);
}
.sdv-hero-new-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.22);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.sdv-hero-new-content {
    flex: 1;
    min-width: 0;
}
.sdv-hero-new-title {
    font-size: 13.5px;
    font-weight: 700;
    line-height: 1.2;
    color: #ffffff;
}
.sdv-hero-new-sub {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.82);
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sdv-hero-new-arrow {
    color: rgba(255, 255, 255, 0.7);
    flex-shrink: 0;
    transition: transform 0.15s;
}
.sdv-hero-new-btn:hover .sdv-hero-new-arrow {
    transform: translateX(3px);
    color: #ffffff;
}
.sdv-drawer-filter-bar {
    padding: 8px 14px;
    flex-shrink: 0;
}
.sdv-search-box {
    position: relative;
    display: flex;
    align-items: center;
}
.sdv-search-box svg.sdv-search-icon {
    position: absolute;
    left: 11px;
    color: #94a3b8;
    pointer-events: none;
}
.sdv-search-box input {
    width: 100%;
    padding: 8px 30px 8px 32px;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    font-size: 12.5px;
    outline: none;
    color: #1e293b;
    font-family: inherit;
    transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
}
.sdv-search-box input:focus {
    border-color: var(--sdv-brand, {$brandColor});
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
}
.sdv-search-clear {
    position: absolute;
    right: 8px;
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 16px;
    cursor: pointer;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 50%;
}
.sdv-search-clear:hover {
    color: #334155;
    background: #e2e8f0;
}
.sdv-drawer-list {
    flex: 1;
    overflow-y: auto;
    padding: 6px 14px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.sdv-convo-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 11px 12px;
    cursor: pointer;
    transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s;
    position: relative;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.sdv-convo-card:hover {
    border-color: var(--sdv-brand, {$brandColor});
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
    background: #fcfdff;
}
.sdv-convo-card.sdv-convo-current {
    border-color: var(--sdv-brand, {$brandColor});
    background: #f0f7ff;
    box-shadow: 0 0 0 1px var(--sdv-brand, {$brandColor});
}
.sdv-convo-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 5px;
}
.sdv-convo-title-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
}
.sdv-convo-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #cbd5e1;
    flex-shrink: 0;
}
.sdv-convo-current .sdv-convo-dot {
    background: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
}
.sdv-convo-title {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sdv-convo-time {
    font-size: 10.5px;
    color: #94a3b8;
    white-space: nowrap;
    flex-shrink: 0;
}
.sdv-convo-snippet {
    font-size: 12px;
    color: #64748b;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 7px;
}
.sdv-convo-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 11px;
    padding-top: 5px;
    border-top: 1px solid #f1f5f9;
}
.sdv-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
}
.sdv-pill-active {
    background: #ecfdf5;
    color: #059669;
}
.sdv-pill-ticket {
    background: #e0e7ff;
    color: #4338ca;
}
.sdv-pill-taken_over {
    background: #fdf4ff;
    color: #9333ea;
}
.sdv-pill-closed {
    background: #f1f5f9;
    color: #64748b;
}
.sdv-msg-count-chip {
    color: #94a3b8;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10.5px;
}
.sdv-history-empty {
    text-align: center;
    padding: 40px 16px;
    color: #64748b;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}
.sdv-history-empty-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
}
.sdv-history-empty-title {
    font-size: 13.5px;
    font-weight: 600;
    color: #334155;
}
.sdv-history-empty-desc {
    font-size: 11.5px;
    color: #94a3b8;
    max-width: 240px;
    line-height: 1.4;
}
.sdv-history-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 36px 16px;
    gap: 10px;
    color: #94a3b8;
    font-size: 12px;
}
.sdv-spinner {
    width: 22px;
    height: 22px;
    border: 2.5px solid #e2e8f0;
    border-top-color: var(--sdv-brand, {$brandColor});
    border-radius: 50%;
    animation: sdvSpin 0.7s linear infinite;
}
@keyframes sdvSpin {
    to { transform: rotate(360deg); }
}

/* ── White-Label & Branding Styles ────────────────────────────────── */
.sdv-cl-branding {
    text-align: center;
    font-size: 11px;
    color: #94a3b8;
    margin-top: 6px;
    padding-bottom: 2px;
    letter-spacing: 0.01em;
    user-select: none;
    line-height: 1.2;
}
.sdv-cl-branding a {
    color: inherit;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.15s ease;
}
.sdv-cl-branding a:hover {
    color: var(--sdv-brand, #0d6efd);
    text-decoration: underline;
}

/* ── AI Usage Disclaimer Notice Banner ────────────────────────────── */
.sdv-cl-disclaimer {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 12px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 11px;
    line-height: 1.35;
    color: #64748b;
    flex-shrink: 0;
    transition: all 0.2s ease;
}
.sdv-cl-disclaimer .sdv-disclaimer-icon {
    flex-shrink: 0;
    color: var(--sdv-brand, #0d6efd);
}
.sdv-cl-disclaimer .sdv-disclaimer-text {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sdv-cl-disclaimer .sdv-disclaimer-close {
    background: transparent;
    border: none;
    color: #94a3b8;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: color 0.15s ease, background 0.15s ease;
    flex-shrink: 0;
}
.sdv-cl-disclaimer .sdv-disclaimer-close:hover {
    color: #0f172a;
    background: rgba(0, 0, 0, 0.05);
}

/* ═════════════════════════════════════════════════════════════════════
   10 DISTINCT UI/UX ARCHITECTURE THEMES (COMPETITOR-BENCHMARKED)
   ═════════════════════════════════════════════════════════════════════ */

/* ── 1. Modern SaaS / Intercom (modern_light / intercom_saas) ──────── */
#sdv-client-chat-window.sdv-theme-modern_light,
#sdv-client-chat-window.sdv-theme-intercom_saas {
    border-radius: 18px;
    box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.14), 0 0 1px rgba(0, 0, 0, 0.1);
    background: #ffffff;
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-header,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-header {
    border-radius: 18px 18px 0 0;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-header .sdv-cl-title,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-header .sdv-cl-title {
    color: #0f172a;
    font-weight: 700;
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-header .sdv-cl-status,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-header .sdv-cl-status {
    color: #64748b;
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-action-btn,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-action-btn {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
    border-radius: 50%;
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-action-btn:hover,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-action-btn:hover {
    background: #e2e8f0;
    color: #0f172a;
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-msg-bot,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-msg-bot {
    background: #f1f5f9;
    color: #0f172a;
    border-radius: 18px 18px 18px 4px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-msg-user,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-msg-user {
    background: var(--sdv-brand, #0d6efd);
    color: #ffffff;
    border-radius: 18px 18px 4px 18px;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.22);
}
#sdv-client-chat-window.sdv-theme-modern_light .sdv-cl-footer,
#sdv-client-chat-window.sdv-theme-intercom_saas .sdv-cl-footer {
    border-radius: 0 0 18px 18px;
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
}
#sdv-client-chat-window.sdv-theme-modern_light #sdv-cl-input,
#sdv-client-chat-window.sdv-theme-intercom_saas #sdv-cl-input {
    border-radius: 24px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    padding: 9px 16px;
    transition: all 0.2s ease;
}
#sdv-client-chat-window.sdv-theme-modern_light #sdv-cl-input:focus,
#sdv-client-chat-window.sdv-theme-intercom_saas #sdv-cl-input:focus {
    background: #ffffff;
    border-color: var(--sdv-brand, #0d6efd);
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.12);
}
#sdv-client-chat-window.sdv-theme-modern_light #sdv-cl-send,
#sdv-client-chat-window.sdv-theme-intercom_saas #sdv-cl-send {
    border-radius: 50%;
}

/* ── 2. Crisp Bubbly Playful (crisp_bubbly) ────────────────────────── */
#sdv-client-chat-window.sdv-theme-crisp_bubbly {
    border-radius: 26px;
    box-shadow: 0 24px 50px -8px rgba(37, 99, 235, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.04);
    background: #ffffff;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-header {
    border-radius: 26px 26px 0 0;
    background: linear-gradient(135deg, var(--sdv-brand, #2563eb) 0%, #3b82f6 100%);
    color: #ffffff;
    padding: 16px 18px;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-header .sdv-cl-title {
    color: #ffffff;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-header .sdv-cl-status {
    color: rgba(255, 255, 255, 0.85);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-action-btn {
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
    border: none;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-action-btn:hover {
    background: rgba(255, 255, 255, 0.35);
    transform: scale(1.1);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-messages {
    background: #f8faff;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-msg-bot {
    background: #ffffff;
    color: #1e293b;
    border-radius: 22px 22px 22px 6px;
    border: 1.5px solid #edf2f7;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
    transition: transform 0.2s ease;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-msg-user {
    background: linear-gradient(135deg, var(--sdv-brand, #2563eb) 0%, #1d4ed8 100%);
    color: #ffffff;
    border-radius: 22px 22px 6px 22px;
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.32);
    transition: transform 0.2s ease;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-starter-chip {
    border-radius: 20px;
    border: 1.5px solid #dbeafe;
    background: #ffffff;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.08);
    transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-starter-chip:hover {
    transform: translateY(-2px) scale(1.02);
    border-color: var(--sdv-brand, #2563eb);
    background: #eff6ff;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly .sdv-cl-footer {
    border-radius: 0 0 26px 26px;
    background: #ffffff;
    border-top: 1px solid #f1f5f9;
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly #sdv-cl-input {
    border-radius: 24px;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly #sdv-cl-input:focus {
    border-color: var(--sdv-brand, #2563eb);
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly #sdv-cl-send {
    border-radius: 50%;
    background: linear-gradient(135deg, var(--sdv-brand, #2563eb) 0%, #1d4ed8 100%);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
#sdv-client-chat-window.sdv-theme-crisp_bubbly #sdv-cl-send:hover {
    transform: scale(1.08);
}

/* ── 3. Drift Conversational (drift_bold) ───────────────────────────── */
#sdv-client-chat-window.sdv-theme-drift_bold {
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
    border: 1px solid #cbd5e1;
    background: #ffffff;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-header {
    background: #0f172a;
    color: #ffffff;
    border-radius: 12px 12px 0 0;
    border-bottom: 2px solid #1e293b;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-header .sdv-cl-title {
    color: #ffffff;
    font-weight: 800;
    letter-spacing: -0.01em;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-header .sdv-cl-status {
    color: #94a3b8;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-action-btn {
    background: #1e293b;
    border: 1px solid #334155;
    color: #94a3b8;
    border-radius: 6px;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-action-btn:hover {
    background: #334155;
    color: #ffffff;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-escalate-bar {
    background: #1e293b;
    border-bottom: 1px solid #334155;
    color: #94a3b8;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-escalate-btn {
    background: #f59e0b;
    color: #0f172a;
    font-weight: 700;
    border-radius: 6px;
    padding: 3px 8px;
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-msg-bot {
    background: #f8fafc;
    color: #0f172a;
    border-radius: 2px 14px 14px 14px;
    border: 1.5px solid #cbd5e1;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-msg-user {
    background: #0f172a;
    color: #ffffff;
    border-radius: 14px 2px 14px 14px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3);
}
#sdv-client-chat-window.sdv-theme-drift_bold .sdv-cl-footer {
    border-radius: 0 0 12px 12px;
    background: #f8fafc;
    border-top: 2px solid #e2e8f0;
}
#sdv-client-chat-window.sdv-theme-drift_bold #sdv-cl-input {
    border-radius: 8px;
    border: 2px solid #0f172a;
    background: #ffffff;
    font-weight: 500;
}
#sdv-client-chat-window.sdv-theme-drift_bold #sdv-cl-send {
    border-radius: 8px;
    background: #0f172a;
    color: #ffffff;
}

/* ── 4. Zendesk Enterprise Support (zendesk_clean) ──────────────────── */
#sdv-client-chat-window.sdv-theme-zendesk_clean {
    border-radius: 8px;
    border: 1px solid #d8dcde;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    background: #ffffff;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-header {
    border-radius: 8px 8px 0 0;
    background: #ffffff;
    color: #2f3941;
    border-bottom: 1px solid #d8dcde;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-header .sdv-cl-title {
    color: #2f3941;
    font-weight: 700;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-header .sdv-cl-status {
    color: #68737d;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-action-btn {
    border-radius: 4px;
    background: #f8f9f9;
    border: 1px solid #d8dcde;
    color: #49545c;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-action-btn:hover {
    background: #e9ebed;
    color: #2f3941;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-escalate-bar {
    background: #f8f9f9;
    border-bottom: 1px solid #d8dcde;
    color: #49545c;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-msg-bot {
    background: #f8f9f9;
    color: #2f3941;
    border-radius: 6px;
    border: 1px solid #d8dcde;
    font-size: 13px;
    line-height: 1.5;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-msg-user {
    background: #1f73b7;
    color: #ffffff;
    border-radius: 6px;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-starter-chip {
    border-radius: 4px;
    border: 1px solid #d8dcde;
    background: #ffffff;
    color: #2f3941;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean .sdv-cl-footer {
    border-radius: 0 0 8px 8px;
    background: #ffffff;
    border-top: 1px solid #d8dcde;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean #sdv-cl-input {
    border-radius: 4px;
    border: 1px solid #707e86;
    background: #ffffff;
    color: #2f3941;
}
#sdv-client-chat-window.sdv-theme-zendesk_clean #sdv-cl-input:focus {
    border-color: #1f73b7;
    box-shadow: 0 0 0 2px rgba(31, 115, 183, 0.2);
}
#sdv-client-chat-window.sdv-theme-zendesk_clean #sdv-cl-send {
    border-radius: 4px;
    background: #1f73b7;
}

/* ── 5. Notion Editorial / Paper (notion_paper) ────────────────────── */
#sdv-client-chat-window.sdv-theme-notion_paper {
    background: #fbfbfa !important;
    border: 1px solid #ebe9e4 !important;
    border-radius: 12px !important;
    box-shadow: 0 16px 36px rgba(15, 15, 15, 0.08) !important;
    color: #37352f !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-header {
    background: #f7f6f3 !important;
    border-bottom: 1px solid #ebe9e4 !important;
    color: #37352f !important;
    border-radius: 12px 12px 0 0 !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-header .sdv-cl-title {
    color: #37352f !important;
    font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, sans-serif !important;
    font-weight: 600 !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-header .sdv-cl-status {
    color: #787774 !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-action-btn {
    background: transparent !important;
    border: 1px solid #e0ded9 !important;
    color: #787774 !important;
    border-radius: 6px !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-action-btn:hover {
    background: #ebe9e4 !important;
    color: #37352f !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-escalate-bar {
    background: #f7f6f3 !important;
    border-bottom: 1px solid #ebe9e4 !important;
    color: #787774 !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-messages {
    background: #fbfbfa !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-msg-bot {
    background: #ffffff !important;
    border: 1px solid #ebe9e4 !important;
    border-radius: 8px !important;
    color: #37352f !important;
    box-shadow: 0 1px 2px rgba(15, 15, 15, 0.04) !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-msg-user {
    background: #37352f !important;
    color: #ffffff !important;
    border-radius: 8px !important;
    box-shadow: none !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-starter-chip {
    background: #ffffff !important;
    border: 1px solid #ebe9e4 !important;
    color: #37352f !important;
    border-radius: 6px !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-footer {
    background: #fbfbfa !important;
    border-top: 1px solid #ebe9e4 !important;
    border-radius: 0 0 12px 12px !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper #sdv-cl-input {
    background: #ffffff !important;
    border: 1px solid #ebe9e4 !important;
    border-radius: 6px !important;
    color: #37352f !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper #sdv-cl-send {
    background: #37352f !important;
    color: #ffffff !important;
    border-radius: 6px !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-disclaimer {
    background: #f7f6f3 !important;
    border-bottom: 1px solid #ebe9e4 !important;
    color: #787774 !important;
}
#sdv-client-chat-window.sdv-theme-notion_paper .sdv-cl-branding {
    color: #787774 !important;
}

/* ── 6. Linear / Geist Dev HUD (linear_geist) ──────────────────────── */
#sdv-client-chat-window.sdv-theme-linear_geist {
    background: #09090b !important;
    border: 1px solid #27272a !important;
    border-radius: 12px !important;
    box-shadow: 0 20px 50px -10px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(255, 255, 255, 0.06) !important;
    color: #fafafa !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-header {
    background: #121215 !important;
    border-bottom: 1px solid #27272a !important;
    border-radius: 12px 12px 0 0 !important;
    color: #fafafa !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-header .sdv-cl-title {
    color: #fafafa !important;
    font-weight: 600 !important;
    letter-spacing: -0.01em !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-header .sdv-cl-status {
    color: #a1a1aa !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-action-btn {
    background: #18181b !important;
    border: 1px solid #27272a !important;
    color: #a1a1aa !important;
    border-radius: 6px !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-action-btn:hover {
    background: #27272a !important;
    color: #ffffff !important;
    border-color: #3f3f46 !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-escalate-bar {
    background: #121215 !important;
    border-bottom: 1px solid #27272a !important;
    color: #a1a1aa !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-messages {
    background: #09090b !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-msg-bot {
    background: #18181b !important;
    border: 1px solid #27272a !important;
    border-radius: 8px !important;
    color: #e4e4e7 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4) !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-msg-user {
    background: #5e6ad2 !important;
    color: #ffffff !important;
    border-radius: 8px !important;
    box-shadow: 0 4px 14px rgba(94, 106, 210, 0.35) !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-starter-chip {
    background: #18181b !important;
    border: 1px solid #27272a !important;
    color: #a1a1aa !important;
    border-radius: 6px !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-starter-chip:hover {
    background: #27272a !important;
    color: #ffffff !important;
    border-color: #5e6ad2 !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-footer {
    background: #09090b !important;
    border-top: 1px solid #27272a !important;
    border-radius: 0 0 12px 12px !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist #sdv-cl-input {
    background: #141417 !important;
    border: 1px solid #27272a !important;
    border-radius: 6px !important;
    color: #fafafa !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist #sdv-cl-input:focus {
    border-color: #5e6ad2 !important;
    box-shadow: 0 0 0 2px rgba(94, 106, 210, 0.25) !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist #sdv-cl-send {
    background: #5e6ad2 !important;
    border-radius: 6px !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-disclaimer {
    background: #121215 !important;
    border-bottom: 1px solid #27272a !important;
    color: #a1a1aa !important;
}
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-cl-branding {
    color: #71717a !important;
}

/* ── 7. Cyber Slate OLED (cyber_dark) ──────────────────────────────── */
#sdv-client-chat-window.sdv-theme-cyber_dark {
    background: #0b1120 !important;
    color: #f1f5f9 !important;
    border: 1px solid #1e293b !important;
    border-radius: 16px !important;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 15px rgba(56, 189, 248, 0.15) !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-header {
    background: #0f172a !important;
    border-bottom: 1px solid #1e293b !important;
    border-radius: 16px 16px 0 0 !important;
    color: #f8fafc !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-header .sdv-cl-title {
    color: #f8fafc !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-header .sdv-cl-status {
    color: #94a3b8 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-action-btn {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #94a3b8 !important;
    border-radius: 8px !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-action-btn:hover {
    background: #334155 !important;
    color: #f8fafc !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-escalate-bar {
    background: #0f172a !important;
    border-bottom: 1px solid #1e293b !important;
    color: #94a3b8 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-messages {
    background: #060911 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-msg-bot {
    background: #1e293b !important;
    color: #f8fafc !important;
    border: 1px solid #334155 !important;
    border-radius: 14px 14px 14px 4px !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-msg-user {
    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%) !important;
    color: #ffffff !important;
    border-radius: 14px 14px 4px 14px !important;
    box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4) !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-chat-link {
    background: rgba(59, 130, 246, 0.16) !important;
    color: #93c5fd !important;
    border-color: rgba(59, 130, 246, 0.32) !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-inline-code {
    background: rgba(255, 255, 255, 0.08) !important;
    color: #cbd5e1 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-starter-chip {
    background: #1e293b !important;
    border: 1px solid #334155 !important;
    color: #cbd5e1 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-footer {
    background: #0b1120 !important;
    border-top: 1px solid #1e293b !important;
    border-radius: 0 0 16px 16px !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark #sdv-cl-input {
    background: #0f172a !important;
    color: #f8fafc !important;
    border: 1px solid #334155 !important;
    border-radius: 10px !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark #sdv-cl-input:focus {
    border-color: #38bdf8 !important;
    box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.25) !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark #sdv-cl-send {
    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%) !important;
    border-radius: 10px !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-disclaimer {
    background: #0f172a !important;
    border-bottom: 1px solid #1e293b !important;
    color: #94a3b8 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-branding {
    color: #64748b !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-history-drawer {
    background: #0b1120 !important;
    color: #f1f5f9 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-convo-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-convo-title {
    color: #f8fafc !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-convo-snippet {
    color: #94a3b8 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-item {
    background: #1e293b !important;
    border-color: #334155 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-title {
    color: #f8fafc !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-snippet {
    color: #94a3b8 !important;
}

/* ── 8. Retro Terminal CLI (terminal_cli) ──────────────────────────── */
#sdv-client-chat-window.sdv-theme-terminal_cli {
    background: #0c0c0c !important;
    border: 2px solid #22c55e !important;
    border-radius: 0px !important;
    box-shadow: 0 0 24px rgba(34, 197, 94, 0.25), inset 0 0 10px rgba(34, 197, 94, 0.05) !important;
    color: #4ade80 !important;
    font-family: "Courier New", Courier, Consolas, monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-header {
    background: #152217 !important;
    border-bottom: 2px solid #22c55e !important;
    border-radius: 0px !important;
    color: #22c55e !important;
    font-family: "Courier New", Courier, monospace !important;
    text-transform: uppercase !important;
    letter-spacing: 1px !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-header .sdv-cl-title {
    color: #22c55e !important;
    font-weight: 700 !important;
    font-family: "Courier New", Courier, monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-header .sdv-cl-status {
    color: #86efac !important;
    font-family: monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-status-dot {
    background: #22c55e !important;
    box-shadow: 0 0 6px #22c55e !important;
    border-radius: 0px !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-action-btn {
    background: #000000 !important;
    border: 1px solid #22c55e !important;
    border-radius: 0px !important;
    color: #22c55e !important;
    font-family: monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-action-btn:hover {
    background: #22c55e !important;
    color: #052e16 !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-escalate-bar {
    background: #052e16 !important;
    border-bottom: 1px solid #22c55e !important;
    color: #86efac !important;
    font-family: monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-escalate-btn {
    background: #22c55e !important;
    color: #052e16 !important;
    font-family: monospace !important;
    font-weight: bold !important;
    border-radius: 0px !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-messages {
    background: #050a06 !important;
    font-family: "Courier New", Courier, monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-msg-bot {
    background: #0f1c12 !important;
    border: 1px solid #22c55e !important;
    border-radius: 0px !important;
    color: #4ade80 !important;
    font-family: "Courier New", Courier, monospace !important;
    box-shadow: inset 0 0 6px rgba(34, 197, 94, 0.12) !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-msg-user {
    background: #22c55e !important;
    border: 1px solid #22c55e !important;
    border-radius: 0px !important;
    color: #052e16 !important;
    font-family: "Courier New", Courier, monospace !important;
    font-weight: bold !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-starter-chip {
    background: #0f1c12 !important;
    border: 1px solid #22c55e !important;
    border-radius: 0px !important;
    color: #4ade80 !important;
    font-family: monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-starter-chip:hover {
    background: #22c55e !important;
    color: #052e16 !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-footer {
    background: #0c0c0c !important;
    border-top: 2px solid #22c55e !important;
    border-radius: 0px !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli #sdv-cl-input {
    background: #000000 !important;
    border: 1px solid #22c55e !important;
    border-radius: 0px !important;
    color: #4ade80 !important;
    font-family: "Courier New", Courier, monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli #sdv-cl-send {
    background: #22c55e !important;
    color: #052e16 !important;
    border-radius: 0px !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-disclaimer {
    background: #152217 !important;
    border-bottom: 1px solid #22c55e !important;
    color: #86efac !important;
    font-family: monospace !important;
}
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-cl-branding {
    color: #22c55e !important;
    font-family: monospace !important;
}

/* ── 9. Apple Frosted Glassmorphism (glassmorphism) ────────────────── */
#sdv-client-chat-window.sdv-theme-glassmorphism {
    background: rgba(255, 255, 255, 0.75) !important;
    backdrop-filter: blur(24px) saturate(180%) !important;
    -webkit-backdrop-filter: blur(24px) saturate(180%) !important;
    border: 1px solid rgba(255, 255, 255, 0.6) !important;
    border-radius: 22px !important;
    box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.22), inset 0 1px 1px rgba(255, 255, 255, 0.8) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-header {
    background: rgba(255, 255, 255, 0.5) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.4) !important;
    border-radius: 22px 22px 0 0 !important;
    color: #0f172a !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-action-btn {
    background: rgba(255, 255, 255, 0.5) !important;
    border: 1px solid rgba(255, 255, 255, 0.6) !important;
    color: #1e293b !important;
    border-radius: 50% !important;
    backdrop-filter: blur(8px) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-action-btn:hover {
    background: rgba(255, 255, 255, 0.85) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-escalate-bar {
    background: rgba(255, 255, 255, 0.4) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.4) !important;
    color: #475569 !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-messages {
    background: rgba(248, 250, 252, 0.45) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-msg-bot {
    background: rgba(255, 255, 255, 0.85) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    border: 1px solid rgba(255, 255, 255, 0.9) !important;
    border-radius: 18px 18px 18px 6px !important;
    color: #0f172a !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-msg-user {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.92) 0%, rgba(79, 70, 229, 0.92) 100%) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.3) !important;
    border-radius: 18px 18px 6px 18px !important;
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.28) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-starter-chip {
    background: rgba(255, 255, 255, 0.75) !important;
    backdrop-filter: blur(10px) !important;
    border: 1px solid rgba(255, 255, 255, 0.7) !important;
    border-radius: 16px !important;
    color: #1e293b !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-footer {
    background: rgba(255, 255, 255, 0.5) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border-top: 1px solid rgba(255, 255, 255, 0.4) !important;
    border-radius: 0 0 22px 22px !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism #sdv-cl-input {
    background: rgba(255, 255, 255, 0.7) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    border: 1px solid rgba(255, 255, 255, 0.8) !important;
    border-radius: 20px !important;
    color: #0f172a !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism #sdv-cl-send {
    background: rgba(37, 99, 235, 0.92) !important;
    backdrop-filter: blur(8px) !important;
    border-radius: 50% !important;
    border: 1px solid rgba(255, 255, 255, 0.4) !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-disclaimer {
    background: rgba(255, 255, 255, 0.45) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.4) !important;
    color: #64748b !important;
}
#sdv-client-chat-window.sdv-theme-glassmorphism .sdv-cl-branding {
    color: #64748b !important;
}

/* ── 10. Neumorphic Soft 3D (neumorphism_soft) ─────────────────────── */
#sdv-client-chat-window.sdv-theme-neumorphism_soft {
    background: #e0e5ec !important;
    border: none !important;
    border-radius: 24px !important;
    box-shadow: 14px 14px 28px #bec3c9, -14px -14px 28px #ffffff !important;
    color: #31456a !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-header {
    background: #e0e5ec !important;
    border-bottom: 1px solid #d1d9e6 !important;
    border-radius: 24px 24px 0 0 !important;
    color: #31456a !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-header .sdv-cl-title {
    color: #31456a !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-header .sdv-cl-status {
    color: #61738e !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-action-btn {
    background: #e0e5ec !important;
    border: none !important;
    border-radius: 8px !important;
    color: #31456a !important;
    box-shadow: 3px 3px 6px #bec3c9, -3px -3px 6px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-action-btn:hover {
    box-shadow: inset 2px 2px 4px #bec3c9, inset -2px -2px 4px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-escalate-bar {
    background: #e0e5ec !important;
    border-bottom: 1px solid #d1d9e6 !important;
    color: #61738e !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-messages {
    background: #e0e5ec !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-msg-bot {
    background: #e0e5ec !important;
    border: none !important;
    border-radius: 18px 18px 18px 4px !important;
    color: #31456a !important;
    box-shadow: 5px 5px 10px #bec3c9, -5px -5px 10px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-msg-user {
    background: var(--sdv-brand, #0d6efd) !important;
    border: none !important;
    border-radius: 18px 18px 4px 18px !important;
    color: #ffffff !important;
    box-shadow: 5px 5px 10px #bec3c9, -5px -5px 10px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-starter-chip {
    background: #e0e5ec !important;
    border: none !important;
    border-radius: 14px !important;
    color: #31456a !important;
    box-shadow: 4px 4px 8px #bec3c9, -4px -4px 8px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-starter-chip:hover {
    box-shadow: inset 2px 2px 4px #bec3c9, inset -2px -2px 4px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-footer {
    background: #e0e5ec !important;
    border-top: 1px solid #d1d9e6 !important;
    border-radius: 0 0 24px 24px !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft #sdv-cl-input {
    background: #e0e5ec !important;
    border: none !important;
    border-radius: 16px !important;
    color: #31456a !important;
    box-shadow: inset 4px 4px 8px #bec3c9, inset -4px -4px 8px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft #sdv-cl-send {
    background: var(--sdv-brand, #0d6efd) !important;
    border: none !important;
    border-radius: 50% !important;
    box-shadow: 4px 4px 8px #bec3c9, -4px -4px 8px #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft #sdv-cl-send:active {
    box-shadow: inset 2px 2px 4px rgba(0, 0, 0, 0.2) !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-disclaimer {
    background: #e0e5ec !important;
    border-bottom: 1px solid #d1d9e6 !important;
    color: #61738e !important;
}
#sdv-client-chat-window.sdv-theme-neumorphism_soft .sdv-cl-branding {
    color: #61738e !important;
}

/* ── Legacy Fallbacks (Compatibility) ──────────────────────────────── */
/* Midnight Indigo */
#sdv-client-chat-window.sdv-theme-midnight_indigo {
    background: #0f172a !important;
    color: #f1f5f9 !important;
    border: 1px solid #1e1b4b !important;
}
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-cl-header {
    background: #1e1b4b !important;
    border-bottom: 1px solid #312e81 !important;
    color: #e0e7ff !important;
}
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-cl-messages {
    background: #080c14 !important;
}
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-cl-msg-bot {
    background: #1e1b4b !important;
    color: #f8fafc !important;
    border: 1px solid #312e81 !important;
}
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-cl-msg-user {
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%) !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-cl-footer {
    background: #0f172a !important;
    border-top: 1px solid #1e1b4b !important;
}
#sdv-client-chat-window.sdv-theme-midnight_indigo #sdv-cl-input {
    background: #1e293b !important;
    color: #f8fafc !important;
    border: 1px solid #334155 !important;
}

/* Emerald Clean */
#sdv-client-chat-window.sdv-theme-emerald_clean .sdv-cl-header {
    background: linear-gradient(135deg, #065f46 0%, #047857 100%) !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-emerald_clean .sdv-cl-msg-user {
    background: #059669 !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-emerald_clean #sdv-cl-send {
    background: #059669 !important;
}

/* Sunset Amber */
#sdv-client-chat-window.sdv-theme-sunset_amber .sdv-cl-header {
    background: linear-gradient(135deg, #c2410c 0%, #ea580c 100%) !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-sunset_amber .sdv-cl-msg-user {
    background: linear-gradient(135deg, #ea580c 0%, #d97706 100%) !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-sunset_amber #sdv-cl-send {
    background: #ea580c !important;
}

/* Brand Gradient */
#sdv-client-chat-window.sdv-theme-brand_gradient .sdv-cl-header {
    background: linear-gradient(135deg, var(--sdv-brand, #0d6efd) 0%, #8b5cf6 50%, #ec4899 100%) !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-brand_gradient .sdv-cl-msg-user {
    background: linear-gradient(135deg, var(--sdv-brand, #0d6efd) 0%, #7c3aed 100%) !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-brand_gradient #sdv-cl-send {
    background: linear-gradient(135deg, var(--sdv-brand, #0d6efd) 0%, #7c3aed 100%) !important;
}

/* High Contrast Enterprise */
#sdv-client-chat-window.sdv-theme-high_contrast {
    background: #000000 !important;
    color: #ffffff !important;
    border: 2px solid #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-header {
    background: #000000 !important;
    border-bottom: 2px solid #ffffff !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-messages {
    background: #000000 !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-msg-bot {
    background: #121212 !important;
    color: #ffffff !important;
    border: 2px solid #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-msg-user {
    background: #ffffff !important;
    color: #000000 !important;
    border: 2px solid #ffffff !important;
    font-weight: 700 !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-footer {
    background: #000000 !important;
    border-top: 2px solid #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast #sdv-cl-input {
    background: #121212 !important;
    color: #ffffff !important;
    border: 2px solid #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast #sdv-cl-send {
    background: #ffffff !important;
    color: #000000 !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-disclaimer {
    background: #121212 !important;
    border-bottom: 2px solid #ffffff !important;
    color: #ffffff !important;
}
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-cl-branding {
    color: #ffffff !important;
}

/* Knowledge Base Drawer Styling */
.sdv-cl-kb-drawer {
    z-index: 15;
}
.sdv-kb-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 13px 15px;
    margin-bottom: 10px;
    display: flex;
    flex-direction: column;
    gap: 7px;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.sdv-kb-item:hover {
    border-color: var(--sdv-brand, #0d6efd);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
}
.sdv-kb-title {
    font-size: 13.5px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.35;
    display: flex;
    align-items: flex-start;
    gap: 7px;
}
.sdv-kb-title svg {
    color: var(--sdv-brand, #0d6efd);
    flex-shrink: 0;
    margin-top: 2px;
}
.sdv-kb-snippet {
    font-size: 12px;
    color: #64748b;
    line-height: 1.45;
}
.sdv-kb-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    padding-top: 8px;
    border-top: 1px solid #f1f5f9;
}
.sdv-kb-link {
    font-size: 12px;
    font-weight: 600;
    color: var(--sdv-brand, #0d6efd);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.sdv-kb-link:hover {
    text-decoration: underline;
}
.sdv-kb-ask-btn {
    font-size: 11px;
    font-weight: 600;
    background: rgba(99, 102, 241, 0.08);
    color: #4f46e5;
    border: 1px solid rgba(99, 102, 241, 0.2);
    border-radius: 6px;
    padding: 3px 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.sdv-kb-ask-btn:hover {
    background: #4f46e5;
    color: #ffffff;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-item {
    background: #1e293b !important;
    border-color: #334155 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-title {
    color: #f1f5f9 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-snippet {
    color: #94a3b8 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-actions {
    border-top-color: #334155 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-ask-btn {
    background: rgba(129, 140, 248, 0.15) !important;
    color: #a5b4fc !important;
    border-color: rgba(129, 140, 248, 0.3) !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-kb-ask-btn:hover {
    background: #6366f1 !important;
    color: #ffffff !important;
}

/* ── Interactive Prompt Starter Chips ──────────────────────────────── */
.sdv-starter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 8px;
    padding: 2px 2px 6px 2px;
}
.sdv-starter-chip {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 18px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    color: #334155;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    user-select: none;
    font-family: inherit;
}
.sdv-starter-chip:hover {
    background: #f8fafc;
    border-color: var(--sdv-brand, {$brandColor});
    color: var(--sdv-brand, {$brandColor});
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08);
}
.sdv-starter-chip:active {
    transform: translateY(0);
}
.sdv-starter-chip svg {
    flex-shrink: 0;
    color: var(--sdv-brand, {$brandColor});
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-starter-chip {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-starter-chip:hover {
    background: #25334d !important;
    border-color: #60a5fa !important;
    color: #93c5fd !important;
}

/* ── Rich Code Blocks with Header & 1-Click Copy ────────────────────── */
.sdv-code-container {
    margin: 9px 0;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #334155;
    background: #0f172a;
    box-shadow: 0 3px 8px rgba(0,0,0,0.15);
}
.sdv-code-header {
    background: #1e293b;
    padding: 5px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #334155;
    user-select: none;
}
.sdv-code-lang {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: #94a3b8;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}
.sdv-code-copy-btn {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #cbd5e1;
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 4px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
    font-family: inherit;
}
.sdv-code-copy-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}
.sdv-code-container .sdv-code-block {
    margin: 0 !important;
    border: none !important;
    border-radius: 0 !important;
    padding: 10px 12px !important;
    background: #0f172a !important;
    font-size: 12px !important;
    line-height: 1.45 !important;
}

/* ── Font Size Modes (Aa Options) ─────────────────────────────────── */
#sdv-client-chat-window.sdv-font-small .sdv-cl-msg {
    font-size: 12px !important;
    line-height: 1.4 !important;
}
#sdv-client-chat-window.sdv-font-small .sdv-cl-msg *:not(.sdv-msg-actions):not(.sdv-msg-actions *):not(.sdv-msg-menu):not(.sdv-msg-menu *) {
    font-size: 12px !important;
}
#sdv-client-chat-window.sdv-font-large .sdv-cl-msg {
    font-size: 15px !important;
    line-height: 1.55 !important;
}
#sdv-client-chat-window.sdv-font-large .sdv-cl-msg *:not(.sdv-msg-actions):not(.sdv-msg-actions *):not(.sdv-msg-menu):not(.sdv-msg-menu *) {
    font-size: 15px !important;
}

/* ── Reply Bar Above Input Row ─────────────────────────────────────── */
.sdv-cl-reply-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 12px;
    background: rgba(13, 110, 253, 0.08);
    border-top: 1px solid rgba(13, 110, 253, 0.15);
    font-size: 11.5px;
    color: #475569;
    gap: 8px;
    animation: sdvFadeInUp 0.15s ease;
}
.sdv-cl-reply-info {
    display: flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}
.sdv-cl-reply-label {
    font-weight: 600;
    color: var(--sdv-brand, #0d6efd);
    font-size: 11px;
}
.sdv-cl-reply-snippet {
    color: #64748b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 200px;
    font-size: 11px;
}
.sdv-cl-reply-close {
    background: transparent;
    border: none;
    font-size: 16px;
    cursor: pointer;
    color: #94a3b8;
    padding: 0 4px;
    line-height: 1;
}
.sdv-cl-reply-close:hover {
    color: #ef4444;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-reply-bar {
    background: rgba(30, 41, 59, 0.85);
    border-top-color: #334155;
    color: #94a3b8;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-reply-label {
    color: #93c5fd;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-cl-reply-snippet {
    color: #cbd5e1;
}

/* ── Message Actions & CSAT Rating Buttons ─────────────────────────── */
.sdv-msg-actions {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-top: 4px;
    padding-top: 2px;
    user-select: none;
    opacity: 0.6;
    transition: opacity 0.15s ease;
    position: relative;
}
.sdv-cl-msg-bot:hover .sdv-msg-actions,
.sdv-msg-actions:hover,
.sdv-msg-actions.sdv-has-open-menu {
    opacity: 1;
}
.sdv-cl-msg-user .sdv-msg-actions {
    display: none;
}
.sdv-msg-action-btn {
    background: transparent;
    border: none;
    color: #94a3b8;
    padding: 3px 5px;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 3px;
    transition: all 0.15s ease;
    font-family: inherit;
    line-height: 1;
}
.sdv-msg-action-btn:hover {
    background: rgba(0, 0, 0, 0.06);
    color: #334155;
}
.sdv-msg-action-btn.sdv-rated-up {
    color: #059669 !important;
    background: rgba(5, 150, 105, 0.12) !important;
    font-weight: 600;
}
.sdv-msg-action-btn.sdv-rated-down {
    color: #dc2626 !important;
    background: rgba(220, 38, 38, 0.12) !important;
    font-weight: 600;
}
.sdv-msg-actions-rated-notice {
    font-size: 10px;
    color: #059669;
    font-weight: 500;
    margin-left: 3px;
}
.sdv-action-more {
    padding: 2px 4px;
    border-radius: 4px;
    color: #94a3b8;
}
.sdv-action-more:hover,
.sdv-has-open-menu .sdv-action-more {
    background: rgba(0, 0, 0, 0.07);
    color: #1e293b;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-msg-action-btn {
    color: #64748b;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-msg-action-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #cbd5e1;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-action-more:hover,
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-has-open-menu .sdv-action-more {
    background: rgba(255, 255, 255, 0.1);
    color: #f1f5f9;
}

/* ── Short & Sweet 3-Dot Chat Bubble Menu ───────────────────────────── */
.sdv-msg-menu {
    position: absolute;
    left: 0;
    background: #ffffff;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 8px;
    box-shadow: 0 4px 18px -2px rgba(0, 0, 0, 0.16), 0 1px 3px rgba(0, 0, 0, 0.06);
    padding: 3px;
    width: 108px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 1px;
    animation: sdvFadeInUp 0.12s ease;
    user-select: none;
    box-sizing: border-box;
}
.sdv-msg-menu.sdv-menu-down {
    top: calc(100% + 3px);
}
.sdv-msg-menu.sdv-menu-up {
    bottom: calc(100% + 3px);
}
.sdv-msg-menu-item {
    background: transparent;
    border: none;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 7px;
    font-size: 11px;
    font-weight: 500;
    color: #334155;
    border-radius: 5px;
    cursor: pointer;
    width: 100%;
    text-align: left;
    transition: background 0.1s ease, color 0.1s ease;
    font-family: inherit;
    box-sizing: border-box;
    line-height: 1;
}
.sdv-msg-menu-item:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.sdv-msg-menu-item svg {
    flex-shrink: 0;
}
.sdv-msg-menu-divider {
    height: 1px;
    background: rgba(0, 0, 0, 0.06);
    margin: 2px 0;
}
.sdv-msg-menu-font-row {
    display: flex;
    gap: 3px;
    padding: 2px 1px;
    box-sizing: border-box;
}
.sdv-font-pill {
    flex: 1;
    border: 1px solid rgba(0, 0, 0, 0.08);
    background: #f8fafc;
    color: #475569;
    padding: 3px 0;
    border-radius: 4px;
    font-size: 10px;
    cursor: pointer;
    font-family: inherit;
    font-weight: 500;
    text-align: center;
    transition: all 0.1s ease;
    line-height: 1.2;
}
.sdv-font-pill:hover {
    background: #e2e8f0;
    color: #0f172a;
}
.sdv-font-pill.active {
    background: var(--sdv-brand, #0d6efd);
    color: #ffffff;
    border-color: var(--sdv-brand, #0d6efd);
    font-weight: 600;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-msg-menu,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-msg-menu,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-msg-menu,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-msg-menu,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-msg-menu {
    background: #18181b;
    border-color: rgba(255, 255, 255, 0.15);
    box-shadow: 0 8px 24px -2px rgba(0, 0, 0, 0.6);
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-msg-menu-item,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-msg-menu-item,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-msg-menu-item,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-msg-menu-item,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-msg-menu-item {
    color: #cbd5e1;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-msg-menu-item:hover,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-msg-menu-item:hover,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-msg-menu-item:hover,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-msg-menu-item:hover,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-msg-menu-item:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #f8fafc;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-msg-menu-divider,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-msg-menu-divider,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-msg-menu-divider,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-msg-menu-divider,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-msg-menu-divider {
    background: rgba(255, 255, 255, 0.08);
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-font-pill,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-font-pill,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-font-pill,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-font-pill,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-font-pill {
    background: #0f172a;
    border-color: rgba(255, 255, 255, 0.15);
    color: #94a3b8;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-font-pill:hover,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-font-pill:hover,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-font-pill:hover,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-font-pill:hover,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-font-pill:hover {
    background: #334155;
    color: #f8fafc;
}
#sdv-client-chat-window.sdv-theme-cyber_dark .sdv-font-pill.active,
#sdv-client-chat-window.sdv-theme-linear_geist .sdv-font-pill.active,
#sdv-client-chat-window.sdv-theme-terminal_cli .sdv-font-pill.active,
#sdv-client-chat-window.sdv-theme-midnight_indigo .sdv-font-pill.active,
#sdv-client-chat-window.sdv-theme-high_contrast .sdv-font-pill.active {
    background: var(--sdv-brand, #3b82f6);
    color: #ffffff;
    border-color: var(--sdv-brand, #3b82f6);
}

/* ── Animated 3-Dot Typing Wave ────────────────────────────────────── */
.sdv-typing-wave {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 6px;
}
.sdv-typing-dot {
    width: 6.5px;
    height: 6.5px;
    background: #94a3b8;
    border-radius: 50%;
    display: inline-block;
    animation: sdvWaveBounce 1.3s infinite ease-in-out both;
}
.sdv-typing-dot:nth-child(1) { animation-delay: -0.32s; }
.sdv-typing-dot:nth-child(2) { animation-delay: -0.16s; }
.sdv-typing-dot:nth-child(3) { animation-delay: 0s; }
@keyframes sdvWaveBounce {
    0%, 80%, 100% {
        transform: scale(0.6);
        opacity: 0.45;
    }
    40% {
        transform: scale(1.1);
        opacity: 1;
        background: var(--sdv-brand, {$brandColor});
    }
}
</style>

<!-- Proactive Teaser Bubble -->
{$proactiveBubbleHtml}

<!-- Client Launcher Button -->
{$launcherHtml}

<!-- Client Chat Window -->
<div id="sdv-client-chat-window" class="sdv-theme-{$chatTheme}">
    <!-- In-Widget History Drawer (Ultra-Modern Redesign) -->
    <div class="sdv-cl-history-drawer" id="sdv-cl-history-drawer">
        <!-- Navigation Header -->
        <div class="sdv-drawer-header">
            <button type="button" class="sdv-drawer-back-btn" id="sdv-drawer-close" title="Return to active chat" onclick="window.sdvCloseHistory && window.sdvCloseHistory();">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Back to Chat</span>
            </button>
            <div class="sdv-drawer-headline">
                <span class="sdv-drawer-badge">Past Chats</span>
            </div>
        </div>

        <!-- Hero New Conversation Card -->
        <div class="sdv-drawer-hero-action">
            <button type="button" class="sdv-hero-new-btn" id="sdv-btn-new-chat">
                <div class="sdv-hero-new-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="sdv-hero-new-content">
                    <div class="sdv-hero-new-title">New Discussion</div>
                    <div class="sdv-hero-new-sub">Start a fresh AI support inquiry</div>
                </div>
                <div class="sdv-hero-new-arrow">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M9 18l6-6-6-6"/></svg>
                </div>
            </button>
        </div>

        <!-- Filter & Search Bar -->
        <div class="sdv-drawer-filter-bar">
            <div class="sdv-search-box">
                <svg class="sdv-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="text" id="sdv-history-search" placeholder="Search past discussions..." oninput="window.sdvFilterHistory && window.sdvFilterHistory(this.value);" />
                <button type="button" class="sdv-search-clear" id="sdv-search-clear" onclick="var inp=document.getElementById('sdv-history-search');if(inp){inp.value='';window.sdvFilterHistory('');this.style.display='none';}" style="display:none;">&times;</button>
            </div>
        </div>

        <!-- Scrollable Conversation Cards List -->
        <div class="sdv-drawer-list" id="sdv-drawer-list">
            <div class="sdv-history-loading">
                <div class="sdv-spinner"></div>
                <span>Retrieving conversation history...</span>
            </div>
        </div>
    </div>

    <!-- In-Widget Knowledge Base Drawer -->
    <div class="sdv-cl-history-drawer sdv-cl-kb-drawer" id="sdv-cl-kb-drawer">
        <div class="sdv-drawer-header">
            <button type="button" class="sdv-drawer-back-btn" id="sdv-kb-back" title="Return to active chat" onclick="window.sdvCloseKb && window.sdvCloseKb();">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Back to Chat</span>
            </button>
            <div class="sdv-drawer-headline">
                <span class="sdv-drawer-badge" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px; vertical-align: -1px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>Knowledge Base
                </span>
            </div>
        </div>

        <div class="sdv-drawer-filter-bar">
            <div class="sdv-search-box">
                <svg class="sdv-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input type="text" id="sdv-kb-search" placeholder="Search knowledge base articles..." oninput="window.sdvFilterKb && window.sdvFilterKb(this.value);" />
                <button type="button" class="sdv-search-clear" id="sdv-kb-search-clear" onclick="var inp=document.getElementById('sdv-kb-search');if(inp){inp.value='';window.sdvFilterKb('');this.style.display='none';}" style="display:none;">&times;</button>
            </div>
        </div>

        <div class="sdv-drawer-list" id="sdv-kb-list">
            <div class="sdv-history-loading">
                <div class="sdv-spinner"></div>
                <span>Searching knowledge base...</span>
            </div>
        </div>
    </div>

    <!-- Slide-Out Guest Escalation Drawer -->
    <div class="sdv-cl-history-drawer sdv-cl-guest-drawer" id="sdv-cl-guest-drawer">
        <div class="sdv-drawer-header">
            <button type="button" class="sdv-drawer-back-btn" onclick="window.sdvCloseGuestDrawer && window.sdvCloseGuestDrawer();">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Back to Chat</span>
            </button>
            <span class="sdv-drawer-badge">Create Ticket</span>
        </div>

        <div class="sdv-drawer-body" style="padding: 18px 16px; overflow-y: auto; flex: 1; display: flex; flex-direction: column;">
            <div class="sdv-guest-intro-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 16px;">
                <div class="sdv-guest-intro-title" style="font-weight: 700; font-size: 13.5px; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <span>🎫</span> Connect with Support Staff
                </div>
                <div class="sdv-guest-intro-desc" style="font-size: 12px; color: #64748b; line-height: 1.45;">
                    Our support engineers will receive your complete live chat transcript. Please enter your contact details so our team can reply directly to your inbox.
                </div>
            </div>

            <form id="sdv-guest-form" onsubmit="return window.sdvSubmitGuestEscalation && window.sdvSubmitGuestEscalation(event);">
                <div class="sdv-guest-form-group" style="margin-bottom: 14px;">
                    <label class="sdv-guest-label" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px;">Your Full Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" id="sdv-guest-name" class="sdv-guest-input" required placeholder="e.g. Dhruv Joshi" style="width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; color: #0f172a;" />
                </div>

                <div class="sdv-guest-form-group" style="margin-bottom: 16px;">
                    <label class="sdv-guest-label" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px;">Your Email Address <span style="color: #ef4444;">*</span></label>
                    <input type="email" id="sdv-guest-email" class="sdv-guest-input" required placeholder="e.g. name@example.com" style="width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #fff; color: #0f172a;" />
                </div>

                <div id="sdv-guest-error" class="sdv-guest-error" style="display: none; color: #ef4444; font-size: 12px; margin-bottom: 12px; line-height: 1.4;"></div>

                <button type="submit" id="sdv-guest-submit-btn" class="sdv-hero-new-btn" style="justify-content: center; font-weight: 600; font-size: 13px; padding: 11px;">
                    <span>Submit &amp; Create Ticket &rarr;</span>
                </button>
            </form>
        </div>
    </div>

    <!-- Main Header -->
    <div class="sdv-cl-header">
        <div class="sdv-cl-header-info">
            <div class="sdv-cl-avatar">{$avatarHtml}</div>
            <div>
                <div class="sdv-cl-title">{$chatTitle}</div>
                <div class="sdv-cl-status"><span class="sdv-cl-status-dot"></span> Online &bull; Self-Help AI</div>
            </div>
        </div>
        <div class="sdv-cl-controls">
            {$kbBtnHtml}
            {$historyBtnHtml}
            {$exportBtnHtml}
            {$soundBtnHtml}
            <button type="button" class="sdv-cl-action-btn" id="sdv-cl-expand-btn" title="Expand / Minimize Window" aria-label="Expand" onclick="window.sdvToggleExpand && window.sdvToggleExpand(event);">
                <svg id="sdv-expand-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
            </button>
            <button type="button" class="sdv-cl-action-btn" id="sdv-cl-close" title="Minimize Window" aria-label="Close" onclick="window.sdvToggleChat && window.sdvToggleChat(false);">&minus;</button>
        </div>
    </div>

    <div class="sdv-cl-escalate-bar">
        <span>Need official staff assistance?</span>
        <a href="javascript:void(0);" class="sdv-cl-escalate-btn" id="sdv-cl-escalate" onclick="window.sdvEscalateToTicket && window.sdvEscalateToTicket();">Convert to Ticket &rarr;</a>
    </div>
    {$disclaimerHtml}

    <div class="sdv-cl-messages" id="sdv-cl-msgs">
        <div class="sdv-cl-msg sdv-cl-msg-bot">{$welcomeMsg}</div>
        <div id="sdv-starter-chips" class="sdv-starter-chips" style="display:none;"></div>
    </div>

    <div class="sdv-cl-footer">
        <div id="sdv-cl-reply-bar" class="sdv-cl-reply-bar" style="display:none;">
            <div class="sdv-cl-reply-info">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                <span class="sdv-cl-reply-label">Replying:</span>
                <span id="sdv-cl-reply-snippet" class="sdv-cl-reply-snippet"></span>
            </div>
            <button type="button" class="sdv-cl-reply-close" id="sdv-cl-reply-close" title="Cancel Reply" onclick="window.sdvCancelReply && window.sdvCancelReply();">&times;</button>
        </div>
        <div class="sdv-cl-input-row">
            <input type="text" id="sdv-cl-input" placeholder="Ask about services, invoices, domains..." autocomplete="off" {$maxMsgAttr} />
            <button type="button" id="sdv-cl-send" title="Send message">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" style="pointer-events:none;"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            </button>
        </div>
        <div id="sdv-cl-char-counter" class="sdv-cl-char-counter" style="display:none;"></div>
        {$poweredByHtml}
    </div>
</div>

<script>
(function() {
    // ── Safe LocalStorage Access Layer (Never throws DOMException) ─────────
    function sdvSafeGet(key, fallback) {
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                var v = window.localStorage.getItem(key);
                return v !== null ? v : fallback;
            }
        } catch(e) {}
        return fallback;
    }

    function sdvSafeSet(key, val) {
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                window.localStorage.setItem(key, val);
            }
        } catch(e) {}
    }

    var brandColor = {$brandColorJs};
    var systemUrl = {$systemUrlJs};
    var whmcsBase = {$whmcsBaseJs};
    var nativeUrl = {$nativeAjaxUrlJs};
    var directUrl = {$primaryAjaxUrlJs};
    var debugMode = {$debugModeJs};
    var proactiveDelay = {$proactiveDelayJs};
    var proactiveMsg = {$proactiveMsgJs};
    var defaultChatWidth = {$chatWidthJs};
    var defaultChatHeight = {$chatHeightJs};
    var defaultExpandWidth = {$expandWidthJs};
    var defaultExpandHeight = {$expandHeightJs};
    var isLoggedIn = {$isLoggedInJs};
    var currentClientId = {$currentClientIdJs};
    var clientNamePrefill = {$clientNameJs};
    var clientEmailPrefill = {$clientEmailJs};
    var configuredMaxChars = Number({$maxMsgCharsJs}) || 0;
    var configuredMaxSessionChars = Number({$maxSessionCharsJs}) || 0;
    var csatEnabled = Boolean({$csatEnabledJs});
    var soundEnabled = Boolean({$soundEnabledJs});
    var starterChipsData = [];
    var _lastToggleTime = 0;
    var _activeReplyText = '';

    // ── Emergency & Reliable Window Controls ──────────────────────────────
    window.sdvToggleChat = function(open) {
        var now = Date.now();
        if (typeof open !== 'boolean' && (now - _lastToggleTime) < 80) {
            return;
        }
        _lastToggleTime = now;

        var win = document.getElementById('sdv-client-chat-window');
        if (!win) return;

        var isCurrentlyOpen = false;
        try {
            var comp = window.getComputedStyle(win);
            isCurrentlyOpen = (comp && comp.display !== 'none' && comp.visibility !== 'hidden' && comp.opacity !== '0') && win.classList.contains('sdv-open');
        } catch(e) {
            isCurrentlyOpen = win.classList.contains('sdv-open') && win.style.display === 'flex';
        }

        var shouldOpen = (typeof open === 'boolean') ? open : !isCurrentlyOpen;

        if (shouldOpen) {
            win.classList.add('sdv-open');
            win.style.setProperty('display', 'flex', 'important');
            win.style.setProperty('visibility', 'visible', 'important');
            win.style.setProperty('opacity', '1', 'important');
            win.style.setProperty('pointer-events', 'auto', 'important');
            win.style.setProperty('z-index', '2147483647', 'important');

            sdvSafeSet('sdv_chat_open', '1');
            try { if (typeof window.sdvDismissProactive === 'function') window.sdvDismissProactive(); } catch(e) {}

            var input = document.getElementById('sdv-cl-input');
            if (input) {
                setTimeout(function() {
                    try { input.focus(); } catch(e) {}
                }, 120);
            }
            if (typeof window.sdvInitChat === 'function') {
                try { window.sdvInitChat(); } catch(e) {}
            }
            try { if (typeof sdvStartLivePolling === 'function') sdvStartLivePolling(); } catch(e) {}
        } else {
            win.classList.remove('sdv-open');
            win.style.setProperty('display', 'none', 'important');
            win.style.setProperty('pointer-events', 'none', 'important');
            sdvSafeSet('sdv_chat_open', '0');
            try { if (typeof sdvStopLivePolling === 'function') sdvStopLivePolling(); } catch(e) {}
            window.sdvCloseAllMsgMenus && window.sdvCloseAllMsgMenus();
        }
    };

    // Global capture listener ensuring click always activates launcher even if theme stops propagation
    document.addEventListener('click', function(e) {
        var launcher = e.target && e.target.closest ? e.target.closest('#sdv-client-chat-launcher') : null;
        if (launcher) {
            if (e.preventDefault) e.preventDefault();
            if (e.stopPropagation) e.stopPropagation();
            window.sdvToggleChat();
        }
        if (!e.target.closest('.sdv-action-more') && !e.target.closest('.sdv-msg-menu')) {
            window.sdvCloseAllMsgMenus && window.sdvCloseAllMsgMenus();
        }
    }, true);

    // ── Font Size (Aa) Sizing System ──────────────────────────────────────
    window.sdvSetFontSize = function(size) {
        var win = document.getElementById('sdv-client-chat-window');
        if (!win) return;
        win.classList.remove('sdv-font-small', 'sdv-font-normal', 'sdv-font-large');
        if (size === 'small' || size === 'large') {
            win.classList.add('sdv-font-' + size);
        }
        sdvSafeSet('sdv_font_size', size);
        window.sdvCloseAllMsgMenus && window.sdvCloseAllMsgMenus();
    };

    window.sdvCycleFontSize = function() {
        var cur = sdvSafeGet('sdv_font_size', 'normal');
        var next = (cur === 'normal') ? 'small' : ((cur === 'small') ? 'large' : 'normal');
        window.sdvSetFontSize(next);
    };

    // ── Reply-To Message Context Engine ───────────────────────────────────
    window.sdvReplyToMsg = function(btn) {
        if (!btn) return;
        var msgEl = btn.closest('.sdv-cl-msg');
        if (!msgEl) return;

        var clone = msgEl.cloneNode(true);
        var actions = clone.querySelector('.sdv-msg-actions');
        if (actions) actions.remove();
        var menu = clone.querySelector('.sdv-msg-menu');
        if (menu) menu.remove();

        var fullText = (clone.innerText || clone.textContent || '').trim();
        if (!fullText) return;

        _activeReplyText = fullText;
        var snippet = fullText.length > 55 ? (fullText.substring(0, 55) + '...') : fullText;

        var bar = document.getElementById('sdv-cl-reply-bar');
        var snippetEl = document.getElementById('sdv-cl-reply-snippet');
        if (bar && snippetEl) {
            snippetEl.textContent = snippet;
            bar.style.display = 'flex';
        }

        window.sdvCloseAllMsgMenus && window.sdvCloseAllMsgMenus();

        var input = document.getElementById('sdv-cl-input');
        if (input) {
            setTimeout(function() {
                try { input.focus(); } catch(e) {}
            }, 80);
        }
    };

    window.sdvCancelReply = function() {
        _activeReplyText = '';
        var bar = document.getElementById('sdv-cl-reply-bar');
        if (bar) bar.style.display = 'none';
    };

    // ── 3-Dot Contextual Menu Engine ──────────────────────────────────────
    window.sdvCloseAllMsgMenus = function() {
        var menus = document.querySelectorAll('.sdv-msg-menu');
        menus.forEach(function(m) {
            var parent = m.parentNode;
            if (parent) parent.classList.remove('sdv-has-open-menu');
            m.remove();
        });
    };

    window.sdvToggleMsgMenu = function(btn, msgId) {
        var parent = btn.parentNode;
        var existing = parent.querySelector('.sdv-msg-menu');
        if (existing) {
            window.sdvCloseAllMsgMenus();
            return;
        }
        window.sdvCloseAllMsgMenus();

        var menu = document.createElement('div');
        menu.className = 'sdv-msg-menu';
        menu.onclick = function(e) { if (e && e.stopPropagation) e.stopPropagation(); };

        // Smart positioning: open downwards unless near container bottom
        var btnRect = btn.getBoundingClientRect();
        var chatContainer = document.getElementById('sdv-cl-messages') || document.body;
        var chatRect = chatContainer.getBoundingClientRect();
        var spaceBelow = chatRect.bottom - btnRect.bottom;
        if (spaceBelow < 95) {
            menu.classList.add('sdv-menu-up');
        } else {
            menu.classList.add('sdv-menu-down');
        }

        var curFont = sdvSafeGet('sdv_font_size', 'normal');

        menu.innerHTML = 
            '<button type="button" class="sdv-msg-menu-item sdv-menu-copy">' +
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                '<span>Copy</span>' +
            '</button>' +
            '<button type="button" class="sdv-msg-menu-item sdv-menu-reply">' +
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>' +
                '<span>Reply</span>' +
            '</button>' +
            '<div class="sdv-msg-menu-divider"></div>' +
            '<div class="sdv-msg-menu-font-row">' +
                '<button type="button" class="sdv-font-pill' + (curFont === 'small' ? ' active' : '') + '" data-size="small" title="Small text">Small</button>' +
                '<button type="button" class="sdv-font-pill' + (curFont === 'normal' ? ' active' : '') + '" data-size="normal" title="Normal text (Aa)">Aa</button>' +
            '</div>';

        var copyBtn = menu.querySelector('.sdv-menu-copy');
        copyBtn.onclick = function() {
            window.sdvCopyMsgText(btn);
            copyBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><polyline points="20 6 9 17 4 12"/></svg><span style="color:#059669;font-weight:600;">Copied!</span>';
            setTimeout(function() {
                window.sdvCloseAllMsgMenus();
            }, 380);
        };

        menu.querySelector('.sdv-menu-reply').onclick = function() {
            window.sdvReplyToMsg(btn);
            window.sdvCloseAllMsgMenus();
        };

        menu.querySelectorAll('.sdv-font-pill').forEach(function(fb) {
            fb.onclick = function() {
                var sz = this.getAttribute('data-size');
                window.sdvSetFontSize(sz);
                window.sdvCloseAllMsgMenus();
            };
        });

        parent.classList.add('sdv-has-open-menu');
        parent.appendChild(menu);
    };

    // ── Web Audio API Synthetic Notification Chime ────────────────────────
    var _audioCtx = null;
    function sdvPlayNotificationChime() {
        if (!soundEnabled) return;
        if (sdvSafeGet('sdv_sound_muted', '0') === '1') return;

        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            if (!_audioCtx) {
                _audioCtx = new AudioContext();
            }
            if (_audioCtx.state === 'suspended') {
                _audioCtx.resume();
            }

            var now = _audioCtx.currentTime;

            // Note 1 (D5 ~ 587.33Hz)
            var osc1 = _audioCtx.createOscillator();
            var gain1 = _audioCtx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, now);
            gain1.gain.setValueAtTime(0.08, now);
            gain1.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
            osc1.connect(gain1);
            gain1.connect(_audioCtx.destination);
            osc1.start(now);
            osc1.stop(now + 0.19);

            // Note 2 (A5 ~ 880.00Hz)
            var osc2 = _audioCtx.createOscillator();
            var gain2 = _audioCtx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(880.0, now + 0.09);
            gain2.gain.setValueAtTime(0.09, now + 0.09);
            gain2.gain.exponentialRampToValueAtTime(0.0001, now + 0.35);
            osc2.connect(gain2);
            gain2.connect(_audioCtx.destination);
            osc2.start(now + 0.09);
            osc2.stop(now + 0.36);
        } catch(e) {}
    }

    function sdvUpdateSoundIcon(isMuted) {
        var btn = document.getElementById('sdv-cl-sound-btn');
        if (!btn) return;
        if (isMuted) {
            btn.title = 'Sound Muted (Click to Unmute)';
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
            btn.style.opacity = '0.6';
        } else {
            btn.title = 'Sound Enabled (Click to Mute)';
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>';
            btn.style.opacity = '1';
        }
    }

    window.sdvToggleSound = function() {
        var currentlyMuted = sdvSafeGet('sdv_sound_muted', '0') === '1';
        var nextMuted = !currentlyMuted;
        sdvSafeSet('sdv_sound_muted', nextMuted ? '1' : '0');
        sdvUpdateSoundIcon(nextMuted);
        if (!nextMuted) {
            sdvPlayNotificationChime();
        }
    };

    // ── 1-Click Conversation Transcript Export ────────────────────────────
    window.sdvExportTranscript = function() {
        var msgsEl = document.getElementById('sdv-cl-msgs');
        if (!msgsEl) return;

        var messageNodes = msgsEl.querySelectorAll('.sdv-cl-msg');
        if (!messageNodes || messageNodes.length === 0) {
            alert('No messages in the current conversation to export.');
            return;
        }

        var nl = String.fromCharCode(13, 10);
        var header = '==================================================' + nl +
                     '       SAHDEV AI SUPPORT CONVERSATION TRANSCRIPT' + nl +
                     '==================================================' + nl +
                     'Session ID : ' + (sessionUuid || 'Current Session') + nl +
                     'Export Date: ' + new Date().toLocaleString() + nl +
                     '==================================================' + nl + nl;

        var body = '';
        messageNodes.forEach(function(node) {
            var isUser = node.classList.contains('sdv-cl-msg-user');
            var sender = isUser ? 'CLIENT / USER' : 'SAHDEV AI ASSISTANT';

            var clone = node.cloneNode(true);
            var actions = clone.querySelector('.sdv-msg-actions');
            if (actions) actions.remove();

            var text = (clone.innerText || clone.textContent || '').trim();
            if (text) {
                body += '[' + sender + ']:' + nl + text + nl + nl + '--------------------------------------------------' + nl + nl;
            }
        });

        var fullText = header + body;
        var blob = new Blob([fullText], { type: 'text/plain;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        var dateStr = new Date().toISOString().slice(0, 10);
        a.download = 'Sahdev_Support_Transcript_' + dateStr + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 500);
    };

    // ── Code & Message Copy Helpers ───────────────────────────────────────
    window.sdvCopyCode = function(btn) {
        if (!btn) return;
        var container = btn.closest('.sdv-code-container');
        var codeEl = container ? container.querySelector('code') : null;
        var text = codeEl ? (codeEl.innerText || codeEl.textContent || '') : '';
        if (!text) return;

        function showSuccess() {
            var orig = btn.innerHTML;
            btn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg><span>Copied!</span>';
            setTimeout(function() { btn.innerHTML = orig; }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showSuccess);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); showSuccess(); } catch(e) {}
            document.body.removeChild(ta);
        }
    };

    window.sdvCopyMsgText = function(btn) {
        if (!btn) return;
        var msgEl = btn.closest('.sdv-cl-msg');
        if (!msgEl) return;

        var clone = msgEl.cloneNode(true);
        var actions = clone.querySelector('.sdv-msg-actions');
        if (actions) actions.remove();
        var menu = clone.querySelector('.sdv-msg-menu');
        if (menu) menu.remove();
        var text = (clone.innerText || clone.textContent || '').trim();
        if (!text) return;

        function showSuccess() {
            var orig = btn.innerHTML;
            var origTitle = btn.title;
            btn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><polyline points="20 6 9 17 4 12"/></svg>';
            btn.title = 'Copied!';
            setTimeout(function() {
                btn.innerHTML = orig;
                btn.title = origTitle;
            }, 1600);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showSuccess).catch(function() {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); showSuccess(); } catch(e) {}
                document.body.removeChild(ta);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); showSuccess(); } catch(e) {}
            document.body.removeChild(ta);
        }
    };

    // ── CSAT Rating Engine ────────────────────────────────────────────────
    window.sdvRateMessage = function(msgId, rating, btn) {
        if (!msgId || !btn) return;
        var actionsBar = btn.closest('.sdv-msg-actions');
        if (!actionsBar) return;

        var upBtn = actionsBar.querySelector('.sdv-rate-up');
        var downBtn = actionsBar.querySelector('.sdv-rate-down');
        if (upBtn) upBtn.disabled = true;
        if (downBtn) downBtn.disabled = true;

        if (rating === 1 && upBtn) {
            upBtn.classList.add('sdv-rated-up');
        } else if (rating === -1 && downBtn) {
            downBtn.classList.add('sdv-rated-down');
        }

        var form = new FormData();
        form.append('action', 'client_chat_rate_message');
        form.append('message_id', msgId);
        form.append('rating', rating);
        form.append('session_uuid', sessionUuid || '');
        form.append('visitor_token', visitorToken);

        postAjaxWithFallback(form, function(err, data) {
            if (!err && data && data.success) {
                var notice = document.createElement('span');
                notice.className = 'sdv-msg-actions-rated-notice';
                notice.textContent = rating === 1 ? 'Thanks for the feedback!' : 'Feedback recorded.';
                actionsBar.appendChild(notice);
                setTimeout(function() {
                    if (notice && notice.parentNode) notice.remove();
                }, 3000);
            }
        });
    };

    function attachMsgActions(el, msgId, rating) {
        if (!el || el.querySelector('.sdv-msg-actions')) return;
        var actions = document.createElement('div');
        actions.className = 'sdv-msg-actions';

        // 1. CSAT Rating Buttons if enabled
        if (csatEnabled && msgId) {
            var upBtn = document.createElement('button');
            upBtn.type = 'button';
            upBtn.className = 'sdv-msg-action-btn sdv-rate-up' + (rating == 1 ? ' sdv-rated-up' : '');
            upBtn.title = 'Helpful response';
            upBtn.setAttribute('aria-label', 'Helpful');
            upBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
            if (rating) upBtn.disabled = true;
            upBtn.onclick = function(e) { if (e && e.stopPropagation) e.stopPropagation(); window.sdvRateMessage(msgId, 1, this); };
            actions.appendChild(upBtn);

            var downBtn = document.createElement('button');
            downBtn.type = 'button';
            downBtn.className = 'sdv-msg-action-btn sdv-rate-down' + (rating == -1 ? ' sdv-rated-down' : '');
            downBtn.title = 'Not helpful';
            downBtn.setAttribute('aria-label', 'Not helpful');
            downBtn.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3"/></svg>';
            if (rating) downBtn.disabled = true;
            downBtn.onclick = function(e) { if (e && e.stopPropagation) e.stopPropagation(); window.sdvRateMessage(msgId, -1, this); };
            actions.appendChild(downBtn);
        }

        // 2. 3-dots More options micro-button
        var moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'sdv-msg-action-btn sdv-action-more';
        moreBtn.title = 'Options';
        moreBtn.setAttribute('aria-label', 'Options');
        moreBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="pointer-events:none;"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>';
        moreBtn.onclick = function(e) { if (e && e.stopPropagation) e.stopPropagation(); window.sdvToggleMsgMenu(this, msgId); };
        actions.appendChild(moreBtn);

        el.appendChild(actions);
    }

    // ── Conversation Starter Chips Engine ─────────────────────────────────
    function renderStarterChips(chips) {
        var container = document.getElementById('sdv-starter-chips');
        if (!container) return;
        if (!chips || !chips.length) {
            container.style.display = 'none';
            container.innerHTML = '';
            return;
        }

        starterChipsData = chips;
        var html = '';
        chips.forEach(function(chip) {
            var label = sdvEscapeHtml(chip.label || chip.prompt || '');
            var icon = chip.icon ? '<span style="font-size:12.5px;">' + sdvEscapeHtml(chip.icon) + '</span>' : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
            var safePromptAttr = encodeURIComponent(chip.prompt || chip.label || '');
            html += '<button type="button" class="sdv-starter-chip" onclick="window.sdvSendPromptChip && window.sdvSendPromptChip(decodeURIComponent(\'' + safePromptAttr + '\'));">' +
                icon +
                '<span>' + label + '</span>' +
            '</button>';
        });

        container.innerHTML = html;
        container.style.display = 'flex';
        var msgsEl = document.getElementById('sdv-cl-msgs');
        if (msgsEl) msgsEl.scrollTop = msgsEl.scrollHeight;
    }

    window.sdvSendPromptChip = function(promptText) {
        if (!promptText) return;
        var inputEl = document.getElementById('sdv-cl-input');
        if (!inputEl) return;
        inputEl.value = promptText;
        var container = document.getElementById('sdv-starter-chips');
        if (container) container.style.display = 'none';
        window.sdvSendMessage();
    };

    function resolveChatUrl(href) {
        if (!href) return '#';
        href = String(href).trim();
        // Block dangerous pseudo-protocols explicitly (XSS defense)
        if (/^(javascript|vbscript|data|file):/i.test(href)) {
            return '#';
        }
        // Block protocol-relative URLs (phishing / external domain hijack)
        if (/^\/\//.test(href)) {
            return '#';
        }
        // Safe standard protocols
        if (/^(https?:\/\/|mailto:|tel:|#)/i.test(href)) {
            return href;
        }
        // Relative paths within WHMCS
        if (systemUrl && typeof systemUrl === 'string' && systemUrl.length > 0) {
            return systemUrl.replace(/\/+$/, '') + '/' + href.replace(/^\/+/, '');
        }
        var origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
        var base = (whmcsBase && typeof whmcsBase === 'string') ? whmcsBase.replace(/\/+$/, '') : '';
        return origin + base + '/' + href.replace(/^\/+/, '');
    }

    var _lastToggleTime = 0;
    var _lastExpandTime = 0;
    var highestMsgId = 0;
    var pollTimer = null;
    var isPolling = false;
    var _cachedHistoryList = [];

    // Helper: Update SVG expand/compress icon
    function sdvUpdateExpandIcon(isExpanded) {
        var icon = document.getElementById('sdv-expand-icon');
        if (!icon) return;
        if (isExpanded) {
            icon.innerHTML = '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>';
        } else {
            icon.innerHTML = '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>';
        }
    }

    // ── Cross-Window Live Broadcast Engine ────────────────────────────────
    var currentTabId = 'tab_' + Math.random().toString(36).substring(2, 9) + Date.now().toString(36);
    var syncChannel = null;
    try {
        if (typeof window.BroadcastChannel !== 'undefined') {
            syncChannel = new window.BroadcastChannel('sdv_livechat_sync');
            syncChannel.onmessage = function(ev) {
                if (ev && ev.data) handleLiveSync(ev.data);
            };
        }
    } catch(e) {}

    window.addEventListener('storage', function(e) {
        if (e.key === 'sdv_livechat_event' && e.newValue) {
            try {
                var payload = JSON.parse(e.newValue);
                handleLiveSync(payload);
            } catch(err) {}
        }
    });

    function broadcastLiveSync(action, payload) {
        var data = Object.assign({ action: action, client_id: currentClientId, sender_tab: currentTabId, ts: Date.now() }, payload);
        if (syncChannel) {
            try { syncChannel.postMessage(data); } catch(e) {}
        }
        sdvSafeSet('sdv_livechat_event', JSON.stringify(data));
    }

    function handleLiveSync(data) {
        if (!data || !data.action) return;
        // Never process events broadcast by THIS tab itself
        if (data.sender_tab && data.sender_tab === currentTabId) {
            return;
        }
        // Strict Tenant Boundary: Ignore events belonging to a different client account
        if (typeof data.client_id !== 'undefined' && data.client_id !== currentClientId) {
            return;
        }
        if (data.action === 'new_message' && data.session_uuid === sessionUuid) {
            if (data.msg_id && document.getElementById('sdv-msg-' + data.msg_id)) {
                return;
            }
            // Message-level deduplication: If identical message was just rendered in this tab, skip
            var msgsEl = document.getElementById('sdv-cl-msgs');
            if (msgsEl && msgsEl.lastElementChild) {
                var lastText = (msgsEl.lastElementChild.textContent || '').trim();
                if (lastText === (data.text || '').trim()) {
                    return;
                }
            }
            appendClMsg(data.role, data.text, false, data.msg_id);
            if (data.msg_id && data.msg_id > highestMsgId) {
                highestMsgId = data.msg_id;
            }
            if (data.role === 'bot') {
                sdvPlayNotificationChime();
            }
        } else if (data.action === 'ticket_escalated' && data.session_uuid === sessionUuid) {
            renderTicketCreatedCard(data.action_card || {});
        } else if (data.action === 'session_switched' && data.session_uuid) {
            if (sessionUuid !== data.session_uuid) {
                sessionUuid = data.session_uuid;
                sdvSafeSet(activeSessionKey, sessionUuid);
                loadSessionTranscript(sessionUuid, false);
            }
        }
    }

    // ── Window Controls ───────────────────────────────────────────────────

    window.sdvToggleExpand = function(e) {
        if (e && e.preventDefault) e.preventDefault();
        if (e && e.stopPropagation) e.stopPropagation();

        var now = Date.now();
        if ((now - _lastExpandTime) < 250) {
            return;
        }
        _lastExpandTime = now;

        var win = document.getElementById('sdv-client-chat-window');
        if (!win) return;

        var isExpanded = win.classList.toggle('sdv-expanded');
        if (isExpanded) {
            win.style.setProperty('width', defaultExpandWidth + 'px', 'important');
            win.style.setProperty('height', defaultExpandHeight + 'px', 'important');
        } else {
            win.style.setProperty('width', defaultChatWidth + 'px', 'important');
            win.style.setProperty('height', defaultChatHeight + 'px', 'important');
        }
        sdvUpdateExpandIcon(isExpanded);
        sdvSafeSet('sdv_chat_expanded', isExpanded ? '1' : '0');
    };

    window.sdvToggleHistory = function() {
        var drawer = document.getElementById('sdv-cl-history-drawer');
        var kbDrawer = document.getElementById('sdv-cl-kb-drawer');
        if (kbDrawer) kbDrawer.classList.remove('sdv-drawer-open');
        if (drawer) {
            var isOpen = drawer.classList.toggle('sdv-drawer-open');
            if (isOpen && typeof loadHistoryList === 'function') {
                loadHistoryList();
            }
        }
    };

    window.sdvCloseHistory = function() {
        var drawer = document.getElementById('sdv-cl-history-drawer');
        if (drawer) drawer.classList.remove('sdv-drawer-open');
    };

    window.sdvToggleKb = function() {
        var kbDrawer = document.getElementById('sdv-cl-kb-drawer');
        var histDrawer = document.getElementById('sdv-cl-history-drawer');
        var guestDrawer = document.getElementById('sdv-cl-guest-drawer');
        if (histDrawer) histDrawer.classList.remove('sdv-drawer-open');
        if (guestDrawer) guestDrawer.classList.remove('sdv-drawer-open');
        if (kbDrawer) {
            var isOpen = kbDrawer.classList.toggle('sdv-drawer-open');
            if (isOpen && typeof loadKbArticles === 'function') {
                var searchInput = document.getElementById('sdv-kb-search');
                var q = searchInput ? (searchInput.value || '').trim() : '';
                loadKbArticles(q);
                if (searchInput) {
                    setTimeout(function() { try { searchInput.focus(); } catch(e) {} }, 100);
                }
            }
        }
    };

    window.sdvCloseKb = function() {
        var kbDrawer = document.getElementById('sdv-cl-kb-drawer');
        if (kbDrawer) kbDrawer.classList.remove('sdv-drawer-open');
    };

    // ── Proactive Message Trigger Engine ─────────────────────────────────
    window.sdvDismissProactive = function(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var bubble = document.getElementById('sdv-proactive-bubble');
        if (bubble) bubble.style.display = 'none';
        try {
            if (window.sessionStorage) sessionStorage.setItem('sdv_proactive_dismissed', '1');
        } catch(err) {}
    };

    window.sdvAcceptProactive = function(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        window.sdvDismissProactive();
        window.sdvToggleChat(true);
    };

    function initProactiveTrigger() {
        if (proactiveDelay <= 0) return;
        try {
            if (window.sessionStorage && sessionStorage.getItem('sdv_proactive_dismissed') === '1') {
                return;
            }
        } catch(err) {}

        var win = document.getElementById('sdv-client-chat-window');
        if (win && win.classList.contains('sdv-open')) return;

        setTimeout(function() {
            var currentWin = document.getElementById('sdv-client-chat-window');
            if (currentWin && currentWin.classList.contains('sdv-open')) return;
            try {
                if (window.sessionStorage && sessionStorage.getItem('sdv_proactive_dismissed') === '1') return;
            } catch(e) {}
            var bubble = document.getElementById('sdv-proactive-bubble');
            if (bubble) {
                bubble.style.display = 'block';
            }
        }, proactiveDelay * 1000);
    }

    // Restore expanded window preference on initial load
    try {
        if (sdvSafeGet('sdv_chat_expanded', '0') === '1') {
            var winEl = document.getElementById('sdv-client-chat-window');
            if (winEl) {
                winEl.classList.add('sdv-expanded');
                winEl.style.setProperty('width', defaultExpandWidth + 'px', 'important');
                winEl.style.setProperty('height', defaultExpandHeight + 'px', 'important');
                sdvUpdateExpandIcon(true);
            }
        }
    } catch(e) {}

    // Build resilient endpoint list prioritizing native WHMCS module routing
    var candidates = [];
    if (nativeUrl) candidates.push(nativeUrl);
    if (systemUrl) candidates.push(systemUrl + '/index.php?m=sahdev&sahdev_act=ajax_handler');

    var baseEl = document.querySelector('base');
    if (baseEl && baseEl.href) {
        var cleanBase = baseEl.href.replace(/\/+$/, '');
        candidates.push(cleanBase + '/index.php?m=sahdev&sahdev_act=ajax_handler');
        candidates.push(cleanBase + '/modules/addons/sahdev/ajax.php');
    }

    if (directUrl) candidates.push(directUrl);
    if (systemUrl) candidates.push(systemUrl + '/modules/addons/sahdev/ajax.php');

    var pathname = window.location.pathname;
    var lastSlash = pathname.lastIndexOf('/');
    if (lastSlash >= 0) {
        var dir = pathname.substring(0, lastSlash);
        if (dir && dir !== '/') {
            candidates.push(window.location.origin + dir.replace(/\/+$/, '') + '/index.php?m=sahdev&sahdev_act=ajax_handler');
            candidates.push(window.location.origin + dir.replace(/\/+$/, '') + '/modules/addons/sahdev/ajax.php');
        }
    }

    candidates.push('index.php?m=sahdev&sahdev_act=ajax_handler');
    candidates.push('/index.php?m=sahdev&sahdev_act=ajax_handler');
    candidates.push('modules/addons/sahdev/ajax.php');
    candidates.push('/modules/addons/sahdev/ajax.php');

    var uniqueEndpoints = [];
    candidates.forEach(function(u) {
        if (u && uniqueEndpoints.indexOf(u) === -1) {
            uniqueEndpoints.push(u);
        }
    });

    var cachedEndpoint = sdvSafeGet('sdv_active_endpoint', null);
    if (cachedEndpoint && uniqueEndpoints.indexOf(cachedEndpoint) > -1) {
        uniqueEndpoints = [cachedEndpoint].concat(uniqueEndpoints.filter(function(u) { return u !== cachedEndpoint; }));
    }

    function postAjaxWithFallback(form, callback, candidateIdx) {
        candidateIdx = candidateIdx || 0;
        var targetUrl = uniqueEndpoints[candidateIdx];
        var actionName = (form && typeof form.get === 'function') ? form.get('action') : '';

        if (!targetUrl) {
            try { callback(new Error('No reachable chat endpoint found.')); } catch(e) {}
            return;
        }

        if (debugMode) {
            console.log('[Sahdev LiveChat] Attempting endpoint (' + (candidateIdx + 1) + '/' + uniqueEndpoints.length + '): ' + targetUrl);
        }

        var isMutating = (actionName === 'client_chat_message' || actionName === 'client_chat_new_session' || actionName === 'client_chat_escalate');

        fetch(targetUrl, { method: 'POST', body: form })
        .then(function(r) {
            if (!r.ok) {
                // If it's a 4xx client/auth error or if mutating action already hit server, don't cascade fallback
                if (!isMutating && (candidateIdx + 1 < uniqueEndpoints.length)) {
                    return postAjaxWithFallback(form, callback, candidateIdx + 1);
                }
                throw new Error('Server returned HTTP ' + r.status);
            }

            return r.text().then(function(rawText) {
                var data;
                try {
                    data = JSON.parse(rawText);
                } catch (jsonErr) {
                    if (!isMutating && (candidateIdx + 1 < uniqueEndpoints.length)) {
                        return postAjaxWithFallback(form, callback, candidateIdx + 1);
                    }
                    throw new Error('Invalid JSON response');
                }

                sdvSafeSet('sdv_active_endpoint', targetUrl);
                try {
                    callback(null, data);
                } catch (cbErr) {
                    console.error('[Sahdev LiveChat] Callback exception:', cbErr);
                }
            });
        })
        .catch(function(err) {
            if (!isMutating && (candidateIdx + 1 < uniqueEndpoints.length)) {
                return postAjaxWithFallback(form, callback, candidateIdx + 1);
            }
            try {
                callback(err);
            } catch (cbErr) {
                console.error('[Sahdev LiveChat] Error callback exception:', cbErr);
            }
        });
    }

    function sdvGenerateSecureToken() {
        try {
            if (window.crypto && window.crypto.getRandomValues) {
                var buf = new Uint8Array(16);
                window.crypto.getRandomValues(buf);
                var hex = '';
                for (var i = 0; i < buf.length; i++) {
                    hex += ('0' + buf[i].toString(16)).slice(-2);
                }
                return 'vt_' + hex;
            }
        } catch (e) {}
        return 'vt_' + Math.random().toString(36).substring(2, 12) + Date.now().toString(36);
    }

    // Strict Multi-Tenant LocalStorage Partitioning (Prevents cross-account session leaks)
    var tenantStoragePrefix = currentClientId > 0 ? ('sdv_client_' + currentClientId + '_') : 'sdv_guest_';
    var visitorTokenKey = tenantStoragePrefix + 'visitor_token';
    var activeSessionKey = tenantStoragePrefix + 'active_session_uuid';

    // Account switch detection: If user switched between different client accounts on same machine
    var lastRecordedClientId = sdvSafeGet('sdv_active_client_id', null);
    if (lastRecordedClientId !== null && lastRecordedClientId !== String(currentClientId)) {
        try {
            if (typeof window !== 'undefined' && window.localStorage) {
                window.localStorage.removeItem('sdv_livechat_event');
            }
        } catch(e) {}
    }
    sdvSafeSet('sdv_active_client_id', String(currentClientId));

    var visitorToken = sdvSafeGet(visitorTokenKey, '');
    if (!visitorToken || !/^[a-zA-Z0-9_\-]{16,64}$/.test(visitorToken)) {
        visitorToken = sdvGenerateSecureToken();
        sdvSafeSet(visitorTokenKey, visitorToken);
    }

    var sessionUuid = sdvSafeGet(activeSessionKey, null);
    var isInitialized = false;

    function sdvDecodeEntities(str) {
        if (!str) return '';
        var s = String(str);
        for (var i = 0; i < 2; i++) {
            if (!/&(?:#0*39|#39|#x27|apos|quot|#0*34|#x22|lt|#0*60|#x3c|gt|#0*62|#x3e|amp|#0*38|#x26);/i.test(s)) {
                break;
            }
            s = s.replace(/&#0*39;|&apos;|&#x27;/gi, "'")
                 .replace(/&quot;|&#0*34;|&#x22;/gi, '"')
                 .replace(/&lt;|&#0*60;|&#x3c;/gi, '<')
                 .replace(/&gt;|&#0*62;|&#x3e;/gi, '>')
                 .replace(/&amp;|&#0*38;|&#x26;/gi, '&');
        }
        return s;
    }

    function sdvEscapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Markdown Parser
    function parseSimpleMarkdown(str) {
        if (!str) return '';
        var s = sdvDecodeEntities(String(str))
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Rich Code blocks
        var codeBlocks = [];
        s = s.replace(/```([a-zA-Z0-9_-]*)\\r?\\n?([\\s\\S]*?)```/g, function(_, lang, c) {
            var idx = codeBlocks.length;
            var cleanLang = (lang || '').trim().toLowerCase() || 'code';
            var trimmedCode = (c || '').trim();
            var blockHtml = '<div class="sdv-code-container">' +
                '<div class="sdv-code-header">' +
                    '<span class="sdv-code-lang">' + sdvEscapeHtml(cleanLang) + '</span>' +
                    '<button type="button" class="sdv-code-copy-btn" title="Copy code" onclick="window.sdvCopyCode && window.sdvCopyCode(this);">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                        '<span>Copy Code</span>' +
                    '</button>' +
                '</div>' +
                '<pre class="sdv-code-block"><code>' + trimmedCode + '</code></pre>' +
            '</div>';
            codeBlocks.push(blockHtml);
            return '@@@CB' + idx + '@@@';
        });

        // Inline code `code` (with smart status badge detection)
        var inlineCodes = [];
        s = s.replace(/`([^`]+)`/g, function(_, c) {
            var trimmedC = c.trim();
            if (/^(Unpaid|Pending|Processing)$/i.test(trimmedC)) {
                return '<span class="sdv-badge-pill sdv-badge-warning"><span class="sdv-badge-dot"></span>' + trimmedC + '</span>';
            } else if (/^(Paid|Active|Completed)$/i.test(trimmedC)) {
                return '<span class="sdv-badge-pill sdv-badge-success"><span class="sdv-badge-dot"></span>' + trimmedC + '</span>';
            } else if (/^(Overdue|Cancelled|Suspended|Terminated|Fraud)$/i.test(trimmedC)) {
                return '<span class="sdv-badge-pill sdv-badge-danger"><span class="sdv-badge-dot"></span>' + trimmedC + '</span>';
            }
            var idx = inlineCodes.length;
            inlineCodes.push('<code class="sdv-inline-code">' + c + '</code>');
            return '@@@IC' + idx + '@@@';
        });

        // Bold and Italics
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*([^*\\n\\r]+)\*/g, '<em>$1</em>');

        // Markdown Links [text](url) - smartly resolve WHMCS base URL
        s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function(_, anchor, href) {
            var resolved = resolveChatUrl(href);
            var safeResolved = resolved.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            return '<a href="' + safeResolved + '" target="_blank" rel="noopener noreferrer" class="sdv-chat-link">' +
                   anchor +
                   '<svg class="sdv-chat-link-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
                   '</a>';
        });

        // Auto-linkify standalone URLs
        s = s.replace(/(^|[\s(])(https?:\/\/[^\s<)"]+)([\s)]|$)/g, function(_, pre, url, post) {
            var safeUrl = url.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            return pre + '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer" class="sdv-chat-link">' + safeUrl + '</a>' + post;
        });

        // Contextual status badges (e.g. "is currently Unpaid", "Status: Paid")
        s = s.replace(/\b(is currently|Status:\s*)\s*(Unpaid|Paid|Overdue|Cancelled|Active|Suspended|Pending)\b/gi, function(match, prefix, status) {
            var st = status.toLowerCase();
            var cls = 'sdv-badge-warning';
            if (st === 'paid' || st === 'active' || st === 'completed') cls = 'sdv-badge-success';
            else if (st === 'overdue' || st === 'cancelled' || st === 'suspended') cls = 'sdv-badge-danger';
            return prefix + ' <span class="sdv-badge-pill ' + cls + '"><span class="sdv-badge-dot"></span>' + status + '</span>';
        });

        var lines = s.split(String.fromCharCode(10));
        var out = [];
        var curListType = null;
        var curListItems = [];
        var inQuote = false;
        var quoteLines = [];
        var inTable = false;
        var tableLines = [];

        function flushList() {
            if (curListType && curListItems.length > 0) {
                out.push('<' + curListType + ' class="sdv-msg-' + curListType + '">' + curListItems.join('') + '</' + curListType + '>');
                curListType = null;
                curListItems = [];
            }
        }

        function flushQuote() {
            if (inQuote && quoteLines.length > 0) {
                out.push('<blockquote class="sdv-msg-quote">' + quoteLines.join('<br>') + '</blockquote>');
                inQuote = false;
                quoteLines = [];
            }
        }

        function flushTable() {
            if (inTable && tableLines.length > 0) {
                if (tableLines.length >= 2 && /^\s*\|(?:\s*[:-]+[-:]*\s*\|)+\s*$/.test(tableLines[1])) {
                    var ths = tableLines[0].split('|').map(function(c){return c.trim();}).filter(function(c, idx, arr){ return idx > 0 && idx < arr.length - 1; });
                    var html = '<div class="sdv-table-wrap"><table class="sdv-msg-table"><thead><tr>';
                    ths.forEach(function(th) { html += '<th>' + th + '</th>'; });
                    html += '</tr></thead><tbody>';
                    for (var r = 2; r < tableLines.length; r++) {
                        var tds = tableLines[r].split('|').map(function(c){return c.trim();}).filter(function(c, idx, arr){ return idx > 0 && idx < arr.length - 1; });
                        html += '<tr>';
                        tds.forEach(function(td) {
                            var trimmedTd = td.trim();
                            if (/^(Unpaid|Pending|Processing)$/i.test(trimmedTd)) {
                                td = '<span class="sdv-badge-pill sdv-badge-warning"><span class="sdv-badge-dot"></span>' + trimmedTd + '</span>';
                            } else if (/^(Paid|Active|Completed)$/i.test(trimmedTd)) {
                                td = '<span class="sdv-badge-pill sdv-badge-success"><span class="sdv-badge-dot"></span>' + trimmedTd + '</span>';
                            } else if (/^(Overdue|Cancelled|Suspended|Terminated|Fraud)$/i.test(trimmedTd)) {
                                td = '<span class="sdv-badge-pill sdv-badge-danger"><span class="sdv-badge-dot"></span>' + trimmedTd + '</span>';
                            }
                            html += '<td>' + td + '</td>';
                        });
                        html += '</tr>';
                    }
                    html += '</tbody></table></div>';
                    out.push(html);
                } else {
                    tableLines.forEach(function(tl) { out.push(tl); });
                }
                inTable = false;
                tableLines = [];
            }
        }

        function flushAll() {
            flushList();
            flushQuote();
            flushTable();
        }

        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            var trimmed = ln.trim();

            if (/^\s*\|.+\|\s*$/.test(trimmed)) {
                flushList(); flushQuote();
                inTable = true;
                tableLines.push(trimmed);
            } else if (/^###\s+(.*)/.test(trimmed)) {
                flushAll();
                out.push('<h4 class="sdv-msg-h3">' + trimmed.replace(/^###\s+/, '') + '</h4>');
            } else if (/^##\s+(.*)/.test(trimmed)) {
                flushAll();
                out.push('<h3 class="sdv-msg-h2">' + trimmed.replace(/^##\s+/, '') + '</h3>');
            } else if (/^#\s+(.*)/.test(trimmed)) {
                flushAll();
                out.push('<h2 class="sdv-msg-h1">' + trimmed.replace(/^#\s+/, '') + '</h2>');
            } else if (/^\s*&gt;\s*(.*)/.test(ln)) {
                flushList(); flushTable();
                inQuote = true;
                quoteLines.push(ln.replace(/^\s*&gt;\s*/, ''));
            } else if (/^\s*\d+\.\s+(.*)/.test(ln)) {
                flushQuote(); flushTable();
                if (curListType !== 'ol') {
                    flushList();
                    curListType = 'ol';
                }
                curListItems.push('<li>' + ln.replace(/^\s*\d+\.\s+/, '') + '</li>');
            } else if (/^\s*[-*]\s+(.*)/.test(ln)) {
                flushQuote(); flushTable();
                if (curListType !== 'ul') {
                    flushList();
                    curListType = 'ul';
                }
                curListItems.push('<li>' + ln.replace(/^\s*[-*]\s+/, '') + '</li>');
            } else if (trimmed === '') {
                flushAll();
                out.push('');
            } else {
                flushAll();
                out.push(ln);
            }
        }
        flushAll();

        var res = out.join('<br>');
        res = res.replace(/(?:<br>\s*)+(<(?:ul|ol|blockquote|h[1-4]|div|pre)[\s\S]*?>)/g, '$1');
        res = res.replace(/(<\/(?:ul|ol|blockquote|h[1-4]|div|pre)>)(?:\s*<br>)+/g, '$1');

        res = res.replace(/@@@CB(\d+)@@@/g, function(_, idx) {
            return codeBlocks[Number(idx)] || '';
        });
        res = res.replace(/@@@IC(\d+)@@@/g, function(_, idx) {
            return inlineCodes[Number(idx)] || '';
        });

        return res;
    }

    function appendClMsg(role, text, isHtml, msgId, rating) {
        var msgsEl = document.getElementById('sdv-cl-msgs');
        if (!msgsEl) return null;
        var d = document.createElement('div');
        d.className = 'sdv-cl-msg ' + (role === 'user' ? 'sdv-cl-msg-user' : 'sdv-cl-msg-bot');
        if (msgId) {
            d.id = 'sdv-msg-' + msgId;
            if (msgId > highestMsgId) highestMsgId = msgId;
        }
        if (isHtml) {
            d.innerHTML = text;
        } else {
            d.innerHTML = parseSimpleMarkdown(text);
        }
        if (role === 'bot' && !isHtml) {
            attachMsgActions(d, msgId, rating);
        }
        msgsEl.appendChild(d);
        msgsEl.scrollTop = msgsEl.scrollHeight;
        return d;
    }

    function renderEscalateSuccessCardHtml(data) {
        data = data || {};
        var tid = data.tid || data.ticket_id || '';
        var ticketUrl = resolveChatUrl('supporttickets.php');
        var clientEmail = data.client_email || '';

        var emailNotice = clientEmail && clientEmail !== 'visitor@chat.local'
            ? '<div style="font-size: 12px; margin-top: 6px; color: #047857; font-weight: 500;">✉️ Confirmation sent to <strong>' + sdvEscapeHtml(clientEmail) + '</strong>.</div>'
            : '';

        return '<div class="sdv-escalate-success-card">' +
            '<div class="sdv-escalate-header">' +
                '<span class="sdv-escalate-icon">🎫</span>' +
                '<div>' +
                    '<div class="sdv-escalate-title">Support Ticket #' + tid + ' Created</div>' +
                    '<div class="sdv-escalate-sub">Live chat conversation escalated to staff</div>' +
                '</div>' +
            '</div>' +
            '<div class="sdv-escalate-desc">' +
                'Our technical staff has received your complete conversation transcript and account details. You can track updates or reply directly from your client area.' +
                emailNotice +
            '</div>' +
            '<a href="' + ticketUrl + '" target="_blank" rel="noopener noreferrer" class="sdv-chat-link sdv-escalate-link">' +
                '<span>Open Support Ticket' + (tid ? ' #' + tid : '') + '</span>' +
                '<svg class="sdv-chat-link-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
            '</a>' +
        '</div>';
    }

    function renderTicketCreatedCard(data) {
        var cardHtml = renderEscalateSuccessCardHtml(data);
        appendClMsg('bot', cardHtml, true);
        var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
        if (escalateBar) escalateBar.style.display = 'none';

        broadcastLiveSync('ticket_escalated', {
            session_uuid: sessionUuid,
            action_card: {
                type: 'ticket_escalated',
                tid: data.tid || data.ticket_id || '',
                ticket_url: 'supporttickets.php',
                client_email: data.client_email || ''
            }
        });
    }

    function renderLimitNoticeCardHtml(messageText) {
        return '<div class="sdv-limit-alert-card">' +
            '<div class="sdv-escalate-header">' +
                '<span class="sdv-escalate-icon">🛡️</span>' +
                '<div>' +
                    '<div class="sdv-escalate-title">Inquiry Limit Reached</div>' +
                    '<div class="sdv-escalate-sub">Staff support ticket assistance available</div>' +
                '</div>' +
            '</div>' +
            '<div class="sdv-escalate-desc">' +
                parseSimpleMarkdown(messageText) +
            '</div>' +
            '<a href="javascript:void(0);" onclick="window.sdvEscalateToTicket && window.sdvEscalateToTicket();" class="sdv-chat-link sdv-escalate-link">' +
                '<span>Convert Conversation to Support Ticket &rarr;</span>' +
                '<svg class="sdv-chat-link-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
            '</a>' +
        '</div>';
    }

    function sdvApplyLimitState(limitInfo) {
        var inputEl = document.getElementById('sdv-cl-input');
        var sendBtn = document.getElementById('sdv-cl-send');
        var counterEl = document.getElementById('sdv-cl-char-counter');
        var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
        var escalateBtn = document.getElementById('sdv-cl-escalate');

        if (inputEl) {
            inputEl.disabled = true;
            inputEl.placeholder = 'Inquiry limit reached. Please convert to a ticket.';
            inputEl.style.background = '#f8fafc';
            inputEl.style.color = '#94a3b8';
            inputEl.style.cursor = 'not-allowed';
        }
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.style.opacity = '0.45';
            sendBtn.style.cursor = 'not-allowed';
        }
        if (counterEl) {
            counterEl.style.display = 'none';
        }
        if (escalateBar) {
            escalateBar.style.display = 'flex';
            escalateBar.style.background = '#fffbeb';
            escalateBar.style.borderBottomColor = '#fde68a';
            escalateBar.style.color = '#92400e';
        }
        if (escalateBtn) {
            escalateBtn.style.background = 'var(--sdv-brand, #0d6efd)';
            escalateBtn.style.color = '#ffffff';
            escalateBtn.style.padding = '3px 10px';
            escalateBtn.style.borderRadius = '12px';
            escalateBtn.style.textDecoration = 'none';
        }
    }

    function sdvUpdateCharCounter() {
        var inputEl = document.getElementById('sdv-cl-input');
        var counterEl = document.getElementById('sdv-cl-char-counter');
        if (!inputEl || !counterEl || configuredMaxChars <= 0) return;

        var len = (inputEl.value || '').length;
        if (len === 0) {
            counterEl.style.display = 'none';
            return;
        }

        counterEl.style.display = 'block';
        counterEl.textContent = len + ' / ' + configuredMaxChars;

        var ratio = len / configuredMaxChars;
        if (ratio >= 1) {
            counterEl.style.color = '#ef4444';
            counterEl.style.fontWeight = '700';
        } else if (ratio >= 0.85) {
            counterEl.style.color = '#f59e0b';
            counterEl.style.fontWeight = '600';
        } else {
            counterEl.style.color = '#94a3b8';
            counterEl.style.fontWeight = 'normal';
        }
    }

    function renderSingleMessage(m) {
        if (!m) return;
        if (m.id && document.getElementById('sdv-msg-' + m.id)) {
            return;
        }

        // Check if message is an escalation confirmation card
        if (m.action_card && m.action_card.type === 'ticket_escalated') {
            var cardHtml = renderEscalateSuccessCardHtml(m.action_card);
            appendClMsg('bot', cardHtml, true, m.id);
            var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
            if (escalateBar) escalateBar.style.display = 'none';
            return;
        }

        // Fallback: detect if plain text is an escalation message
        if (m.message_text && m.message_text.indexOf('Support Ticket #') > -1 && m.message_text.indexOf('escalated to staff') > -1) {
            var matchTid = m.message_text.match(/Support Ticket #([^\s:]+)/);
            var tid = matchTid ? matchTid[1] : '';
            var cardHtml = renderEscalateSuccessCardHtml({ tid: tid, ticket_url: 'supporttickets.php' });
            appendClMsg('bot', cardHtml, true, m.id);
            var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
            if (escalateBar) escalateBar.style.display = 'none';
            return;
        }

        // Detect if message is a quota / limit notice
        if (m.sender_type !== 'user' && m.message_text && (
            m.message_text.indexOf('inquiry limit') > -1 ||
            m.message_text.indexOf('cumulative discussion limit') > -1 ||
            m.message_text.indexOf('guest inquiry limit') > -1 ||
            m.message_text.indexOf('exceeds the maximum allowed limit') > -1
        )) {
            var cardHtml = renderLimitNoticeCardHtml(m.message_text);
            appendClMsg('bot', cardHtml, true, m.id);
            sdvApplyLimitState();
            return;
        }

        var role = (m.sender_type === 'user') ? 'user' : 'bot';
        appendClMsg(role, m.message_text, false, m.id, m.rating);
    }

    window.sdvInitChat = function() {
        if (isInitialized) return;
        isInitialized = true;

        var savedUuid = sdvSafeGet(activeSessionKey, '');
        var form = new FormData();
        form.append('action', 'client_chat_init');
        form.append('visitor_token', visitorToken);
        if (savedUuid) {
            form.append('session_uuid', savedUuid);
        }
        form.append('page_url', window.location.href);

        postAjaxWithFallback(form, function(err, data) {
            if (err) return;
            if (data && data.status === 'success') {
                sessionUuid = data.session_uuid;
                sdvSafeSet(activeSessionKey, sessionUuid);

                if (data.max_msg_chars) {
                    configuredMaxChars = parseInt(data.max_msg_chars, 10) || configuredMaxChars;
                    var inp = document.getElementById('sdv-cl-input');
                    if (inp && configuredMaxChars > 0) {
                        inp.setAttribute('maxlength', String(configuredMaxChars));
                    }
                }

                if (data.status_chat === 'escalated_ticket' || data.status === 'escalated_ticket') {
                    var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
                    if (escalateBar) escalateBar.style.display = 'none';
                }

                if (data.messages && data.messages.length > 0) {
                    var msgsEl = document.getElementById('sdv-cl-msgs');
                    if (msgsEl) {
                        msgsEl.innerHTML = '';
                        data.messages.forEach(function(m) {
                            renderSingleMessage(m);
                        });
                    }
                }

                if (data.limit_status && data.limit_status.limit_reached) {
                    sdvApplyLimitState(data.limit_status);
                    var msgsEl = document.getElementById('sdv-cl-msgs');
                    if (msgsEl && (!data.messages || data.messages.length === 0)) {
                        var cardHtml = renderLimitNoticeCardHtml(data.limit_status.message);
                        appendClMsg('bot', cardHtml, true);
                    }
                }

                if (data.starter_chips && Array.isArray(data.starter_chips)) {
                    starterChipsData = data.starter_chips;
                    if (!data.messages || data.messages.length <= 1) {
                        renderStarterChips(data.starter_chips);
                    }
                }
                if (typeof data.csat_enabled !== 'undefined') {
                    csatEnabled = Boolean(data.csat_enabled);
                }
                if (typeof data.sound_enabled !== 'undefined') {
                    soundEnabled = Boolean(data.sound_enabled);
                }

                sdvStartLivePolling();
            }
        });
    };

    var isSendingMessage = false;
    var lastSentMessageText = '';
    var lastSentMessageTime = 0;

    window.sdvSendMessage = function() {
        if (isSendingMessage) {
            return;
        }

        var inputEl = document.getElementById('sdv-cl-input');
        var sendBtn = document.getElementById('sdv-cl-send');
        if (!inputEl) return;
        var text = (inputEl.value || '').trim();
        if (!text) return;

        var now = Date.now();
        if (text === lastSentMessageText && (now - lastSentMessageTime) < 1200) {
            return;
        }

        if (configuredMaxChars > 0 && text.length > configuredMaxChars) {
            alert('Your message exceeds the limit of ' + configuredMaxChars + ' characters. Please shorten your message or submit a support ticket.');
            return;
        }

        isSendingMessage = true;
        lastSentMessageText = text;
        lastSentMessageTime = now;

        var chipsEl = document.getElementById('sdv-starter-chips');
        if (chipsEl) chipsEl.style.display = 'none';

        var textToSend = text;
        if (_activeReplyText) {
            var snippet = _activeReplyText.replace(/\s+/g, ' ').trim();
            if (snippet.length > 75) snippet = snippet.substring(0, 75) + '...';
            textToSend = '[Replying to: "' + snippet + '"]' + String.fromCharCode(10) + text;
            window.sdvCancelReply();
        }

        appendClMsg('user', text, false);
        inputEl.value = '';
        sdvUpdateCharCounter();
        inputEl.disabled = true;
        if (sendBtn) sendBtn.disabled = true;

        var waveHtml = '<div class="sdv-typing-wave"><span class="sdv-typing-dot"></span><span class="sdv-typing-dot"></span><span class="sdv-typing-dot"></span></div>';
        var tempBot = appendClMsg('bot', waveHtml, true);

        broadcastLiveSync('new_message', {
            session_uuid: sessionUuid,
            role: 'user',
            text: text
        });

        var form = new FormData();
        form.append('action', 'client_chat_message');
        form.append('visitor_token', visitorToken);
        if (sessionUuid) form.append('session_uuid', sessionUuid);
        form.append('message', textToSend);

        postAjaxWithFallback(form, function(err, data) {
            isSendingMessage = false;

            if (err) {
                inputEl.disabled = false;
                if (sendBtn) sendBtn.disabled = false;
                try { inputEl.focus(); } catch(e) {}
                if (tempBot) tempBot.innerHTML = '⚠️ ' + (err.message || 'Connection error. Please try again.');
                return;
            }

            if (data.limit_reached) {
                var limitNoticeText = data.reply || data.message || 'Inquiry limit reached. Please convert this discussion to a support ticket.';
                if (tempBot) {
                    tempBot.innerHTML = renderLimitNoticeCardHtml(limitNoticeText);
                } else {
                    appendClMsg('bot', renderLimitNoticeCardHtml(limitNoticeText), true);
                }
                sdvApplyLimitState(data);
                if (data.session_uuid && data.session_uuid !== sessionUuid) {
                    sessionUuid = data.session_uuid;
                    sdvSafeSet(activeSessionKey, sessionUuid);
                }
                broadcastLiveSync('new_message', {
                    session_uuid: sessionUuid,
                    role: 'bot',
                    text: limitNoticeText
                });
                return;
            }

            inputEl.disabled = false;
            if (sendBtn) sendBtn.disabled = false;
            try { inputEl.focus(); } catch(e) {}

            if (data.status === 'success' || data.success) {
                var replyText = data.reply || 'Message received.';
                if (tempBot) {
                    tempBot.innerHTML = parseSimpleMarkdown(replyText);
                    if (data.message_id) {
                        tempBot.id = 'sdv-msg-' + data.message_id;
                        if (data.message_id > highestMsgId) highestMsgId = data.message_id;
                    }
                    attachMsgActions(tempBot, data.message_id, 0);
                }
                sdvPlayNotificationChime();
                if (data.session_uuid && data.session_uuid !== sessionUuid) {
                    sessionUuid = data.session_uuid;
                    sdvSafeSet(activeSessionKey, sessionUuid);
                }
                broadcastLiveSync('new_message', {
                    session_uuid: sessionUuid,
                    role: 'bot',
                    text: replyText,
                    msg_id: data.message_id || 0
                });
            } else {
                if (tempBot) tempBot.innerHTML = '⚠️ ' + (data.message || data.error || 'Could not send message.');
            }
        });
    };

    // ── Live Polling Engine for Multi-Tab & Staff Takeover ────────────────
    function sdvStartLivePolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function() {
            sdvPollMessages();
        }, 4000);
    }

    function sdvStopLivePolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function sdvPollMessages() {
        if (!sessionUuid || isPolling) return;
        var win = document.getElementById('sdv-client-chat-window');
        if (!win || !win.classList.contains('sdv-open') || win.style.display === 'none') {
            return;
        }

        isPolling = true;
        var form = new FormData();
        form.append('action', 'client_chat_poll');
        form.append('session_uuid', sessionUuid);
        form.append('visitor_token', visitorToken);
        form.append('after_id', highestMsgId);

        postAjaxWithFallback(form, function(err, res) {
            isPolling = false;
            if (err || !res || !res.success) return;

            if (res.messages && res.messages.length > 0) {
                var msgsEl = document.getElementById('sdv-cl-msgs');
                var shouldScroll = false;
                var shouldPlayChime = false;
                res.messages.forEach(function(m) {
                    if (m.id > highestMsgId) highestMsgId = m.id;
                    var exists = document.getElementById('sdv-msg-' + m.id);
                    if (!exists && msgsEl) {
                        renderSingleMessage(m);
                        shouldScroll = true;
                        if (m.sender_type !== 'user') {
                            shouldPlayChime = true;
                        }
                    }
                });
                if (shouldPlayChime) {
                    sdvPlayNotificationChime();
                }
                if (shouldScroll && msgsEl) {
                    msgsEl.scrollTop = msgsEl.scrollHeight;
                }
            }
        });
    }

    window.sdvCloseGuestDrawer = function() {
        var dr = document.getElementById('sdv-cl-guest-drawer');
        if (dr) dr.classList.remove('sdv-drawer-open');
    };

    window.sdvOpenGuestDrawer = function() {
        var nameInput = document.getElementById('sdv-guest-name');
        var emailInput = document.getElementById('sdv-guest-email');
        if (nameInput && !nameInput.value) {
            nameInput.value = sdvSafeGet('sdv_guest_name', clientNamePrefill || '');
        }
        if (emailInput && !emailInput.value) {
            emailInput.value = sdvSafeGet('sdv_guest_email', clientEmailPrefill || '');
        }
        var errEl = document.getElementById('sdv-guest-error');
        if (errEl) errEl.style.display = 'none';

        var dr = document.getElementById('sdv-cl-guest-drawer');
        if (dr) dr.classList.add('sdv-drawer-open');
    };

    window.sdvSubmitGuestEscalation = function(e) {
        if (e && e.preventDefault) e.preventDefault();
        var nameInput = document.getElementById('sdv-guest-name');
        var emailInput = document.getElementById('sdv-guest-email');
        var errEl = document.getElementById('sdv-guest-error');
        var submitBtn = document.getElementById('sdv-guest-submit-btn');

        var name = (nameInput ? nameInput.value : '').trim();
        var email = (emailInput ? emailInput.value : '').trim();

        if (!name) {
            if (errEl) { errEl.textContent = 'Please enter your name.'; errEl.style.display = 'block'; }
            if (nameInput) nameInput.focus();
            return false;
        }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            if (errEl) { errEl.textContent = 'Please enter a valid email address.'; errEl.style.display = 'block'; }
            if (emailInput) emailInput.focus();
            return false;
        }

        sdvSafeSet('sdv_guest_name', name);
        sdvSafeSet('sdv_guest_email', email);

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>Creating Ticket...</span>';
        }

        performEscalate(name, email, function(err, data) {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span>Submit &amp; Create Ticket &rarr;</span>';
            }
            if (err || !(data.status === 'success' || data.success)) {
                if (errEl) {
                    errEl.textContent = err ? err.message : (data.message || data.error || 'Failed to create ticket.');
                    errEl.style.display = 'block';
                }
                return;
            }
            window.sdvCloseGuestDrawer();
            renderTicketCreatedCard(data);
        });
        return false;
    };

    function performEscalate(customName, customEmail, callback) {
        var form = new FormData();
        form.append('action', 'client_chat_escalate');
        form.append('session_uuid', sessionUuid);
        form.append('visitor_token', visitorToken);
        if (customName) form.append('client_name', customName);
        if (customEmail) form.append('client_email', customEmail);

        postAjaxWithFallback(form, callback);
    }

    window.sdvEscalateToTicket = function() {
        var escalateBtn = document.getElementById('sdv-cl-escalate');
        if (!sessionUuid) {
            alert('Please send a message before converting to a support ticket.');
            return;
        }

        if (isLoggedIn) {
            if (!confirm('Would you like our staff to assist you? This will convert your chat transcript into a support ticket.')) return;
            if (escalateBtn) escalateBtn.textContent = 'Creating ticket...';
            performEscalate(clientNamePrefill, clientEmailPrefill, function(err, data) {
                if (escalateBtn) escalateBtn.textContent = 'Convert to Ticket →';
                if (err || !(data.status === 'success' || data.success)) {
                    alert('Escalation failed: ' + (err ? err.message : (data.message || data.error || 'Unknown error')));
                    return;
                }
                renderTicketCreatedCard(data);
            });
        } else {
            window.sdvOpenGuestDrawer();
        }
    };

    // ── Ultra-Modern Conversation History Engine ──────────────────────────
    function renderHistoryItems(items) {
        var drawerList = document.getElementById('sdv-drawer-list');
        if (!drawerList) return;

        if (!items || items.length === 0) {
            drawerList.innerHTML = '<div class="sdv-history-empty">' +
                '<div class="sdv-history-empty-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>' +
                '<div class="sdv-history-empty-title">No conversations found</div>' +
                '<div class="sdv-history-empty-desc">Start a new discussion to get instant answers from our AI assistant.</div>' +
                '</div>';
            return;
        }

        var html = '';
        items.forEach(function(item) {
            var isActive = (item.session_uuid === sessionUuid);
            var statusPill = '';
            if (item.status === 'escalated_ticket') {
                statusPill = '<span class="sdv-status-pill sdv-pill-ticket">🎟️ Ticket Opened</span>';
            } else if (item.status === 'taken_over') {
                statusPill = '<span class="sdv-status-pill sdv-pill-taken_over">👤 Staff Takeover</span>';
            } else if (item.status === 'closed') {
                statusPill = '<span class="sdv-status-pill sdv-pill-closed">✓ Closed</span>';
            } else {
                statusPill = '<span class="sdv-status-pill sdv-pill-active"><span class="sdv-convo-dot" style="background:#10b981;"></span> Active</span>';
            }

            html += '<div class="sdv-convo-card' + (isActive ? ' sdv-convo-current' : '') + '" data-uuid="' + item.session_uuid + '">';
            html += '<div class="sdv-convo-card-top">';
            html += '<div class="sdv-convo-title-wrap">';
            html += '<span class="sdv-convo-dot"></span>';
            html += '<span class="sdv-convo-title">' + (item.title || 'Support Discussion') + '</span>';
            html += '</div>';
            html += '<span class="sdv-convo-time">' + (item.created_at || '') + '</span>';
            html += '</div>';
            html += '<div class="sdv-convo-snippet">' + (item.last_message || 'Conversation started') + '</div>';
            html += '<div class="sdv-convo-card-bottom">';
            html += statusPill;
            html += '<span class="sdv-msg-count-chip"><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg> ' + item.message_count + ' msgs</span>';
            html += '</div>';
            html += '</div>';
        });
        drawerList.innerHTML = html;

        var cardEls = drawerList.querySelectorAll('.sdv-convo-card');
        cardEls.forEach(function(el) {
            el.addEventListener('click', function() {
                var uuid = el.getAttribute('data-uuid');
                if (uuid) loadSessionTranscript(uuid, true);
            });
        });
    }

    function loadHistoryList() {
        var drawerList = document.getElementById('sdv-drawer-list');
        if (!drawerList) return;
        drawerList.innerHTML = '<div class="sdv-history-loading"><div class="sdv-spinner"></div><span>Retrieving discussions...</span></div>';

        var form = new FormData();
        form.append('action', 'client_chat_get_history');
        form.append('visitor_token', visitorToken);

        postAjaxWithFallback(form, function(err, data) {
            if (err || !data || data.status !== 'success' || !data.history) {
                renderHistoryItems([]);
                return;
            }
            _cachedHistoryList = data.history;
            var searchInp = document.getElementById('sdv-history-search');
            var q = searchInp ? searchInp.value : '';
            window.sdvFilterHistory(q);
        });
    }

    window.sdvFilterHistory = function(query) {
        var clearBtn = document.getElementById('sdv-search-clear');
        var q = (query || '').toLowerCase().trim();
        if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

        if (!_cachedHistoryList || _cachedHistoryList.length === 0) {
            renderHistoryItems([]);
            return;
        }

        var filtered = _cachedHistoryList.filter(function(item) {
            if (!q) return true;
            var title = (item.title || '').toLowerCase();
            var snippet = (item.last_message || '').toLowerCase();
            var status = (item.status || '').toLowerCase();
            return title.indexOf(q) > -1 || snippet.indexOf(q) > -1 || status.indexOf(q) > -1;
        });

        renderHistoryItems(filtered);
    };

    function loadSessionTranscript(uuid, closeDrawerOnDone) {
        var form = new FormData();
        form.append('action', 'client_chat_load_session');
        form.append('session_uuid', uuid);
        form.append('visitor_token', visitorToken);

        postAjaxWithFallback(form, function(err, data) {
            if (err || !data || !data.success) {
                alert('Could not load conversation transcript.');
                return;
            }
            sessionUuid = data.session_uuid;
            sdvSafeSet(activeSessionKey, sessionUuid);

            var msgsEl = document.getElementById('sdv-cl-msgs');
            if (msgsEl) {
                msgsEl.innerHTML = '';
                var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
                if (data.status === 'escalated_ticket') {
                    if (escalateBar) escalateBar.style.display = 'none';
                } else if (escalateBar) {
                    escalateBar.style.display = 'flex';
                }

                if (data.messages && data.messages.length > 0) {
                    data.messages.forEach(function(m) {
                        renderSingleMessage(m);
                    });
                } else {
                    appendClMsg('bot', 'No messages recorded in this conversation yet.', false);
                }

                if (data.limit_status && data.limit_status.limit_reached) {
                    sdvApplyLimitState(data.limit_status);
                } else if (data.status !== 'escalated_ticket') {
                    var inputEl = document.getElementById('sdv-cl-input');
                    var sendBtn = document.getElementById('sdv-cl-send');
                    if (inputEl) {
                        inputEl.disabled = false;
                        inputEl.placeholder = 'Type your question here...';
                        inputEl.style.background = '';
                        inputEl.style.color = '';
                        inputEl.style.cursor = '';
                    }
                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.style.opacity = '';
                        sendBtn.style.cursor = '';
                    }
                }
            }
            if (closeDrawerOnDone) {
                window.sdvCloseHistory();
            }
            broadcastLiveSync('session_switched', { session_uuid: sessionUuid });
        });
    }

    var isStartingNewChat = false;

    window.sdvStartNewChat = function() {
        if (isStartingNewChat) return;
        isStartingNewChat = true;

        window.sdvCloseHistory();
        window.sdvCloseKb();

        var form = new FormData();
        form.append('action', 'client_chat_new_session');
        form.append('visitor_token', visitorToken);
        form.append('page_url', window.location.href);

        postAjaxWithFallback(form, function(err, data) {
            isStartingNewChat = false;

            if (err || !data || (data.status !== 'success' && !data.success && !data.session_uuid)) {
                console.warn('[Sahdev LiveChat] Could not start new chat session:', err || data);
                // If a session is already active, don't break the user's flow with an alert
                if (!sessionUuid) {
                    alert('Could not start new chat.');
                }
                return;
            }
            sessionUuid = data.session_uuid;
            sdvSafeSet(activeSessionKey, sessionUuid);

            var msgsEl = document.getElementById('sdv-cl-msgs');
            if (msgsEl) {
                msgsEl.innerHTML = '';
                var botWelcome = appendClMsg('bot', data.greeting || 'Hi! How can I help you today?', false);
                var chipsDiv = document.createElement('div');
                chipsDiv.id = 'sdv-starter-chips';
                chipsDiv.className = 'sdv-starter-chips';
                chipsDiv.style.display = 'none';
                msgsEl.appendChild(chipsDiv);
            }
            if (data.starter_chips && data.starter_chips.length) {
                renderStarterChips(data.starter_chips);
            } else if (starterChipsData && starterChipsData.length) {
                renderStarterChips(starterChipsData);
            }
            var escalateBar = document.querySelector('.sdv-cl-escalate-bar');
            if (escalateBar) escalateBar.style.display = 'flex';
            var escalateBtn = document.getElementById('sdv-cl-escalate');
            if (escalateBtn) {
                escalateBtn.textContent = 'Convert to Ticket →';
                escalateBtn.style.display = 'inline';
            }

            var inputEl = document.getElementById('sdv-cl-input');
            var sendBtn = document.getElementById('sdv-cl-send');
            if (data.limit_status && data.limit_status.limit_reached) {
                sdvApplyLimitState(data.limit_status);
                if (msgsEl) {
                    var cardHtml = renderLimitNoticeCardHtml(data.limit_status.message);
                    appendClMsg('bot', cardHtml, true);
                }
            } else {
                if (inputEl) {
                    inputEl.disabled = false;
                    inputEl.placeholder = 'Type your question here...';
                    inputEl.style.background = '';
                    inputEl.style.color = '';
                    inputEl.style.cursor = '';
                    inputEl.value = '';
                    try { inputEl.focus(); } catch(e) {}
                }
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.style.opacity = '';
                    sendBtn.style.cursor = '';
                }
            }
            sdvUpdateCharCounter();

            broadcastLiveSync('session_switched', { session_uuid: sessionUuid });
        });
    };

    // ── Knowledge Base Self-Help Engine ───────────────────────────────────
    var _cachedKbList = [];
    var _cachedKbCats = [];
    var _kbSearchTimer = null;

    function loadKbArticles(query) {
        var listEl = document.getElementById('sdv-kb-list');
        if (!listEl) return;
        listEl.innerHTML = '<div class="sdv-history-loading"><div class="sdv-spinner"></div><span>Searching knowledge base...</span></div>';

        var form = new FormData();
        form.append('action', 'client_chat_kb_search');
        form.append('query', query || '');
        form.append('visitor_token', visitorToken);

        postAjaxWithFallback(form, function(err, data) {
            if (err || !data || (data.status !== 'success' && !data.success) || !data.articles) {
                renderKbItems([]);
                return;
            }
            _cachedKbList = data.articles;
            _cachedKbCats = data.categories || [];
            renderKbItems(data.articles, data.categories);
        });
    }

    window.sdvFilterKb = function(query) {
        var clearBtn = document.getElementById('sdv-kb-search-clear');
        var q = (query || '').trim();
        if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

        if (_kbSearchTimer) clearTimeout(_kbSearchTimer);
        _kbSearchTimer = setTimeout(function() {
            loadKbArticles(q);
        }, 220);
    };

    function renderKbItems(articles, categories) {
        var listEl = document.getElementById('sdv-kb-list');
        if (!listEl) return;

        if (!articles || articles.length === 0) {
            listEl.innerHTML = '<div class="sdv-history-empty">' +
                '<div class="sdv-history-empty-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>' +
                '<div class="sdv-history-empty-title">No articles found</div>' +
                '<div class="sdv-history-empty-desc">Try another keyword, browse categories, or ask our AI assistant directly.</div>' +
            '</div>';
            return;
        }

        var html = '';

        if (categories && categories.length > 0) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;padding:0 2px;">';
            categories.forEach(function(cat) {
                var safeName = String(cat.name).replace(/</g, '&lt;').replace(/>/g, '&gt;');
                var catUrl = resolveChatUrl(cat.rel_url);
                html += '<a href="' + catUrl + '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;font-size:11.5px;padding:3px 9px;background:rgba(0,0,0,0.05);color:#475569;border:1px solid rgba(0,0,0,0.08);border-radius:20px;display:inline-flex;align-items:center;gap:4px;">' +
                    '📁 ' + safeName +
                '</a>';
            });
            html += '</div>';
        }

        articles.forEach(function(art) {
            var safeTitle = String(art.title).replace(/</g, '&lt;').replace(/>/g, '&gt;');
            var safeSnippet = String(art.snippet || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            var artUrl = resolveChatUrl(art.rel_url);
            var safeTitleAttr = encodeURIComponent(art.title || '');

            html += '<div class="sdv-kb-item">' +
                '<div class="sdv-kb-title">' +
                    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
                    '<span>' + safeTitle + '</span>' +
                '</div>' +
                '<div class="sdv-kb-snippet">' + safeSnippet + '</div>' +
                '<div class="sdv-kb-actions">' +
                    '<a href="' + artUrl + '" target="_blank" rel="noopener noreferrer" class="sdv-kb-link">' +
                        '<span>Read Article</span>' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
                    '</a>' +
                    '<button type="button" class="sdv-kb-ask-btn" onclick="window.sdvAskArticle(decodeURIComponent(\'' + safeTitleAttr + '\'));">Ask AI &rarr;</button>' +
                '</div>' +
            '</div>';
        });

        listEl.innerHTML = html;
    }

    window.sdvAskArticle = function(title) {
        window.sdvCloseKb();
        var input = document.getElementById('sdv-cl-input');
        if (input) {
            input.value = 'Can you summarize and help me with the article: "' + title + '"?';
            input.focus();
        }
    };

    // Attach listeners defensively to avoid missing events or double clicks
    function attachListeners() {
        var launcher = document.getElementById('sdv-client-chat-launcher');
        if (launcher && !launcher._sdvBound) {
            launcher._sdvBound = true;
            launcher.removeAttribute('onclick');
            launcher.addEventListener('click', function(e) {
                if (e && e.preventDefault) e.preventDefault();
                window.sdvToggleChat();
            });
        }

        var sendBtn = document.getElementById('sdv-cl-send');
        if (sendBtn && !sendBtn._sdvBound) {
            sendBtn._sdvBound = true;
            sendBtn.removeAttribute('onclick');
            sendBtn.addEventListener('click', function(e) {
                if (e && e.preventDefault) e.preventDefault();
                window.sdvSendMessage();
            });
        }

        var newChatBtn = document.getElementById('sdv-btn-new-chat');
        if (newChatBtn && !newChatBtn._sdvBound) {
            newChatBtn._sdvBound = true;
            newChatBtn.removeAttribute('onclick');
            newChatBtn.addEventListener('click', function(e) {
                if (e && e.preventDefault) e.preventDefault();
                window.sdvStartNewChat();
            });
        }

        var inputEl = document.getElementById('sdv-cl-input');
        if (inputEl && !inputEl._sdvBound) {
            inputEl._sdvBound = true;
            inputEl.addEventListener('input', sdvUpdateCharCounter);
            inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    window.sdvSendMessage();
                }
            });
        }
    }

    function initWidget() {
        attachListeners();
        initProactiveTrigger();

        // Restore font size preference
        var savedFontSize = sdvSafeGet('sdv_font_size', 'normal');
        if (savedFontSize === 'small' || savedFontSize === 'large') {
            var chatWin = document.getElementById('sdv-client-chat-window');
            if (chatWin) chatWin.classList.add('sdv-font-' + savedFontSize);
        }

        // Restore sound state icon
        if (soundEnabled) {
            var isMuted = sdvSafeGet('sdv_sound_muted', '0') === '1';
            sdvUpdateSoundIcon(isMuted);
        }

        // Restore AI Disclaimer dismissed state
        try {
            if (sessionStorage.getItem('sdv_dismiss_disclaimer') === '1') {
                var disEl = document.getElementById('sdv-cl-disclaimer');
                if (disEl) disEl.style.display = 'none';
            }
        } catch(e) {}

        // Attach action toolbar to initial static welcome message if present
        var initialBot = document.querySelector('#sdv-cl-msgs .sdv-cl-msg-bot');
        if (initialBot) {
            attachMsgActions(initialBot, 0, 0);
        }

        // Restore chat window open state across tabs / page navigations
        if (sdvSafeGet('sdv_chat_open', '0') === '1') {
            setTimeout(function() {
                window.sdvToggleChat(true);
            }, 80);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }
})();
</script>
HTML;
}


