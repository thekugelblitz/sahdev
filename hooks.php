<?php

use WHMCS\Database\Capsule;

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
        if (!Capsule::schema()->hasTable('tblsahdev_settings')) return '';
        $settings = Capsule::table('tblsahdev_settings')->where('id', 1)->first();
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
                    if (!cachedData) {
                        fetchTelemetry(false);
                    }
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

