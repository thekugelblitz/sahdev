<?php

use WHMCS\Database\Capsule;

function sahdev_inject_ticket_panel($vars)
{
    // Ensure we are viewing a specific ticket
    $ticketId = (int) $vars['ticketid'];
    if (!$ticketId)
        return '';

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

    // Load Tone Defaults from DB
    $settings = Capsule::table('tblsahdev_settings')->first();
    $defaultTone = $settings ? $settings->tone_default : 'Professional';

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

    $autoAnalyzeEnabled = $settings && !empty($settings->auto_analyze_on_load) ? 'true' : 'false';
    $qualityScorerEnabled = $settings && !empty($settings->quality_scorer_enabled) ? 'true' : 'false';

    $isSel = function ($val, $current) {
        return $val === $current ? 'selected' : '';
    };

    $scoreBtnStyle = $qualityScorerEnabled === 'true' ? '' : 'display: none;';

    // Hardcode emojis for well known intents, since some DBs don't support utf8mb4 emojis natively
    $intentIconMap = [
        'AUTO'         => '🤖 ',
        'RESOLVE'      => '✅ ',
        'INVESTIGATE'  => '🧐 ',
        'MORE_INFO'    => '❓ ',
        'GUIDE'        => '🗺️ ',
        'OUT_OF_SCOPE' => '🚫 ',
        'DUPLICATE'    => '🔁 ',
    ];

    // Load active intents from DB, ordered by sort_order
    $intentsList = [];
    try {
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

    $htmlPanel = <<<HTML
<div class="panel panel-info" id="sahdev-ai-panel" style="margin-top: 20px; border-color: #0d6efd;">
    <div class="panel-heading" style="background-color: #0d6efd; color: white; display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="$('#sahdev-ai-body').slideToggle();">
        <h3 class="panel-title"><i class="fas fa-robot"></i> Sahdev AI Ticket Intelligence</h3>
        <i class="fas fa-chevron-down"></i>
    </div>
    <div class="panel-body" id="sahdev-ai-body" style="display: none; background: #f8f9fa;">
        
        <form id="sahdev-ai-form">
            {$csrfToken}
            <input type="hidden" id="sahdev_ticket_id" value="{$ticketId}">
            <input type="hidden" id="sahdev_intent" value="{$defaultIntentVal}">

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
            </style>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>AI Tone</label>
                        <select id="sahdev_tone" class="form-control">
                            <option value="Professional" {$isSel('Professional', $defaultTone)}>Professional</option>
                            <option value="Technical" {$isSel('Technical', $defaultTone)}>Technical</option>
                            <option value="Friendly" {$isSel('Friendly', $defaultTone)}>Friendly</option>
                            <option value="Strict" {$isSel('Strict', $defaultTone)}>Strict</option>
                            <option value="Custom" {$isSel('Custom', $defaultTone)}>Custom</option>
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
                <div>
                    <button type="button" id="btn-sahdev-analyze" class="btn btn-primary" style="font-weight: 600;">
                        <i class="fas fa-magic"></i> Analyze & Generate Reply
                    </button>
                    <button type="button" id="btn-sahdev-regenerate" class="btn btn-warning" style="font-weight: 600; display: none; margin-left: 5px;" data-force="true">
                        <i class="fas fa-sync"></i> Regenerate Reply
                    </button>
                </div>
            </div>
        </form>

        <!-- Rewrite It: Expand Admin Draft from Editor -->
        <div style="border-top: 2px dashed #c0d9f5; margin-top: 12px; padding-top: 12px;">
            <label style="font-weight: 700; font-size: 13px; margin-bottom: 6px; display: block;"><i class="fas fa-pen-nib" style="color:#17a2b8;"></i> ✍️ Expand &amp; Polish My Draft Reply</label>
            <p class="text-muted" style="font-size: 12px; margin-bottom: 8px;">Write a short rough reply in the editor below first, then click <strong>Rewrite It</strong> — Sahdev will expand it into a complete, professional reply and put it right back in the editor.</p>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="btn-sahdev-rewrite" class="btn btn-info btn-sm" style="font-weight: 600;">
                    <i class="fas fa-pen-nib"></i> Rewrite It
                </button>
                <button type="button" id="btn-sahdev-score-draft" class="btn btn-default btn-sm" style="font-weight: 600; {$scoreBtnStyle}" title="Get AI feedback on your manual draft before sending">
                    <i class="fas fa-tachometer-alt"></i> Score Admin Draft
                </button>
                <button type="button" id="btn-sahdev-save-canned" class="btn btn-warning btn-sm" style="font-weight: 600; margin-left: auto;" title="Save your draft as a reusable Canned Response">
                    <i class="fas fa-save"></i> Save as Canned
                </button>
                <button type="button" id="btn-sahdev-save-kb" class="btn btn-primary btn-sm" style="font-weight: 600;" title="Stores a KB-style draft in Sahdev (tblsahdev_canned_responses only). Does not write to WHMCS core tables.">
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
        <div id="sahdev-loading" style="display: none; text-align: center; padding: 20px;">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p style="margin-top: 10px;">Sahdev AI is analyzing ticket data securely...</p>
        </div>

        <!-- Output sections -->
        <div id="sahdev-results" style="display: none; margin-top: 20px;">
            
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

        <div id="sahdev-error" class="alert alert-danger" style="display: none; margin-top: 20px;"></div>

    </div>
</div>

<!-- AI Snapshot Panel: Auto-loads analysis on page open (controlled by backend setting) -->
<div id="sahdev-snapshot-outer" style="margin-top: 15px; display: none;">
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
<div id="sahdev-summarizer-outer" style="margin-top: 15px;">
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
<div id="sahdev-canned-outer" style="margin-top: 15px;">
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
<div id="sahdev-history-outer" style="margin-top: 15px;">
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

    // Output the HTML first
    $output = $htmlPanel;


    // Use string concatenation instead of output buffering to prevent WHMCS from dropping the buffer
    $jsContentStart = <<<HTML
<script>
    var sahdevAjaxUrl = "{$ajaxUrl}";
    var sahdevAutoAnalyze = {$autoAnalyzeEnabled};
    var sahdevQualityScorer = {$qualityScorerEnabled};
    console.log("Sahdev AI initialized with AJAX URL:", sahdevAjaxUrl, "| Auto-analyze:", sahdevAutoAnalyze);
HTML;

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
            tinymce.activeEditor.execCommand('mceInsertContent', false, replyHtml);
            
            $('html, body').animate({
                scrollTop: $("#replyticket").offset().top - 50
            }, 500);
        } else if ($('#replymessage').length) {
            var el = $('#replymessage').get(0);
            var plain = replyHtml.replace(/<br\s*\/?>/gi, "\n").replace(/(<([^>]+)>)/gi, "");
            
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
                tone: $('#sahdev_tone').val(),
                intensity: $('#sahdev_intensity').val(),
                instruction: $('#sahdev_instruction').val(),
                technical_context: $('#sahdev_technical_context').val(),
                intent: $('#sahdev_intent').val(),
                use_summary: $('#sahdev_use_summary').length && !$('#sahdev_use_summary').is(':checked') ? 0 : 1,
                include_historical_context: $('#sahdev_include_history').is(':checked') ? 1 : 0,
                token: $('input[name="token"]').val(),
                force_regenerate: isRegenerate ? 'true' : 'false'
            };

            var payloadReqData = Object.assign({ action: 'get_payload' }, baseReqData);

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

                        if (res.provider === 'lmstudio') {
                            executeLocalLMStudioCall(res, baseReqData, $btn);
                        } else {
                            executeBackendGoogleCall(baseReqData, $btn);
                        }
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
        
        function executeBackendGoogleCall(baseReqData, $btn) {
            var reqData = Object.assign({ action: 'analyze_ticket' }, baseReqData);
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
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + (config.api_key || 'local')
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
            prompt += "  \"CLIENT_REPLY\": \"string (reply to client in Markdown — body only, no greeting or sign-off)\"\n}\n\n";
            prompt += "=== TONE ===\nWrite CLIENT_REPLY in a " + (tone || 'Professional') + " tone.\n\n";
            prompt += "=== TICKET DATA ===\n";
            prompt += "Client: " + (context.client_name || 'Unknown Client') + "\n";
            prompt += "Department: " + (context.department || 'Support') + "\n";
            prompt += "Subject: " + (context.subject || 'Ticket') + "\n";
            if (servicesBlock) prompt += servicesBlock;
            prompt += "\n=== CONVERSATION ===\n" + messagesBlock;
            if (attachmentsBlock) prompt += attachmentsBlock;
            return prompt;
        }

        function renderSahdevResults(data, tokensUsed, executionTimeMs, tokenDetails) {
            $('#sahdev-loading').hide();
            $('#sahdev-out-cause').text(data.ROOT_CAUSE || 'N/A');
            $('#sahdev-out-resp').text(data.RESPONSIBILITY || 'N/A');
            $('#sahdev-out-risk').text(data.RISK_LEVEL || 'N/A');
            $('#sahdev-out-plan').text(data.INTERNAL_ACTION_PLAN || 'N/A');
            
            var formattedReply = data.CLIENT_REPLY ? data.CLIENT_REPLY.replace(/\n/g, '<br>') : 'N/A';
            $('#sahdev-out-reply').html(formattedReply);

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

                    if (res.provider === 'lmstudio') {
                        // Step 2a: LM Studio — browser calls directly (same as main analyze flow)
                        executeRewriteLMStudio(res, rewriteBaseData);
                    } else {
                        // Step 2b: Google — server handles it
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
                    }
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
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (config.api_key || 'local') },
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
            var snapReplyHtml = data.CLIENT_REPLY ? data.CLIENT_REPLY.replace(/\n/g, '<br>') : 'N/A';
            $('#sahdev-snap-reply').html(snapReplyHtml);
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
                force_regenerate: 'false'
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

                    // No cache — need to call AI
                    if (res.provider === 'lmstudio') {
                        // Step 2a: browser calls LM Studio directly
                        executeSnapLMStudio(res, snapBaseData);
                    } else {
                        // Step 2b: server-side Google call
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
                    }
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
                    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (config.api_key || 'local') },
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

// Since we are inside hooks.php, let's declare helper at file scope but use it via string replacement or just manual checks
// Let's refactor the HEREDOC slightly for the dropdown to be exact and clean instead of calling function inside heredoc

add_hook('AdminAreaViewTicketPage', 1, function ($vars) {
    // Return early if not ticket page context
    if (!isset($vars['ticketid']))
        return '';

    return sahdev_inject_ticket_panel($vars);
});

// ---------------------------------------------------------------------------
// Ticket Insights: WHMCS CronJob hook — batch-analyzes Awaiting Reply tickets
// ---------------------------------------------------------------------------
add_hook('CronJob', 1, function () {
    try {
        $moduleDir = __DIR__;

        // Guard: only run if the module tables exist
        if (!\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_settings')) {
            return;
        }

        $settings = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
        if (!$settings || empty($settings->cron_insights_enabled)) {
            return;
        }

        require_once $moduleDir . '/lib/AIProviderInterface.php';
        require_once $moduleDir . '/lib/GoogleAIProvider.php';
        require_once $moduleDir . '/lib/LMStudioAIProvider.php';
        require_once $moduleDir . '/lib/ReplicateAIProvider.php';
        require_once $moduleDir . '/lib/TicketDataExtractor.php';
        require_once $moduleDir . '/lib/AIController.php';
        require_once $moduleDir . '/lib/CronProcessor.php';

        $processor = new \Sahdev\Lib\CronProcessor();
        $processor->run();
    } catch (\Throwable $e) {
        // Silently swallow — never crash the WHMCS cron
    }
});

// ---------------------------------------------------------------------------
// Ticket Insights: Inject insight badges into the support tickets list page
// ---------------------------------------------------------------------------
add_hook('AdminAreaPage', 1, function ($vars) {
    try {
        // Detect supporttickets.php list view (not individual ticket view).
        // Themes may use action=list or omit action; single ticket is action=view&id=.
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $isTicketView = ($action === 'view' && $id > 0);
        $isTicketList = (
            $script === 'supporttickets.php' &&
            !$isTicketView
        );

        if (!$isTicketList) {
            return;
        }

        // Guard: check module is available
        if (!\WHMCS\Database\Capsule::schema()->hasTable('tblsahdev_settings')) {
            return;
        }

        $settings = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
        if (!$settings || empty($settings->cron_insights_enabled)) {
            return;
        }

        $versionBuster = time();
        $ajaxUrl = htmlspecialchars("addonmodules.php?module=sahdev&sahdev_act=ajax_handler&v={$versionBuster}");

        return sahdev_render_ticket_list_insights($ajaxUrl);
    } catch (\Throwable $e) {
        return '';
    }
});

/**
 * Render the CSS + JS block injected into the support tickets list page.
 */
function sahdev_render_ticket_list_insights(string $ajaxUrl): string
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
/* Sentiment pill */
.sdv-sentiment    { background:#f1f3f5; color:#495057; border:1px solid #dee2e6; font-weight:600; }
/* Tone pill */
.sdv-tone         { background:#fff; color:#6c757d; border:1px solid #dee2e6; font-style:italic; }
/* Admin reply badge */
.sdv-admin-rep    { background:#e7f3ff; color:#0d6efd; border:1px solid #b6d4fe; }
/* Row urgency left-border highlight */
tr.sdv-row-critical td:first-child { border-left: 4px solid #dc3545 !important; }
tr.sdv-row-high     td:first-child { border-left: 4px solid #fd7e14 !important; }
tr.sdv-row-medium   td:first-child { border-left: 4px solid #ffc107 !important; }
tr.sdv-row-low      td:first-child { border-left: 4px solid #198754 !important; }
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

    var AJAX_URL = '{$ajaxUrl}';

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
        Polite: '🙂', Appreciative: '😊'
    };

    /* ── Step 1: scan all <tr> rows and tag them with data-sdv-tid ── */
    function tagRows() {
        var rows = document.querySelectorAll('table tbody tr, table tr');
        rows.forEach(function (row) {
            // Already tagged
            if (row.getAttribute('data-sdv-tid')) return;
            // Look for a subject link inside this row
            var links = row.querySelectorAll('a[href]');
            for (var i = 0; i < links.length; i++) {
                var m = links[i].href.match(/[?&]id=(\d+)(?:&|$)/);
                if (!m) m = links[i].href.match(/supporttickets\.php\?.*[?&]id=(\d+)/);
                if (!m) m = links[i].href.match(/[?&]id=(\d+)/);
                if (m) {
                    row.setAttribute('data-sdv-tid', m[1]);
                    break;
                }
            }
        });
    }

    /* ── Step 2: collect all tagged ticket IDs ── */
    function collectIds() {
        var ids = [];
        document.querySelectorAll('[data-sdv-tid]').forEach(function (el) {
            var tid = el.getAttribute('data-sdv-tid');
            if (tid && ids.indexOf(tid) === -1) ids.push(tid);
        });
        return ids;
    }

    function findSubjectCell(row, tid) {
        var want = String(tid);
        var links = row.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            var m = href.match(/(?:\?|&)id=(\d+)(?:&|#|$)/);
            if (m && m[1] === want) {
                return links[i].closest('td');
            }
        }
        return null;
    }

    /* ── Step 3: build and inject the insight panel into each row ── */
    function injectInsights(insights) {
        Object.keys(insights).forEach(function (tid) {
            var ins = insights[tid];
            var row = document.querySelector('[data-sdv-tid="' + tid + '"]');
            if (!row) return;

            // Avoid double-injection
            if (row.querySelector('.sdv-insight-panel')) return;

            var urgency = (ins.urgency || 'medium').toLowerCase();
            var urgClass = URG_CLASS[urgency] || 'sdv-urg-medium';
            var urgIcon  = URG_ICON[urgency]  || '⚪';
            var urgLabel = ins.urgency || 'Medium';
            var sentiment = ins.sentiment_label || '';
            var score     = ins.sentiment_score ? ins.sentiment_score + '/10' : '';
            var tone      = ins.client_tone || '';
            var toneIcon  = TONE_ICON[tone] || '';
            var adminRep  = parseInt(ins.admin_reply_count) || 0;
            var lastAdmin = ins.last_admin_name || '';
            var summary   = (ins.ticket_summary || '').substring(0, 320);
            var analyzedAt = ins.analyzed_at || '';

            // Build tooltip content
            var tipLines = [];
            if (summary) tipLines.push(summary);
            if (adminRep > 0) {
                var adminLine = '👤 Admin replied ' + adminRep + ' time' + (adminRep > 1 ? 's' : '');
                if (lastAdmin) adminLine += ' · Last: ' + lastAdmin;
                tipLines.push(adminLine);
            }
            if (analyzedAt) tipLines.push('🕐 Analyzed: ' + analyzedAt);
            var tipHtml = tipLines.map(function(l) {
                return '<span>' + l.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>';
            }).join('<br>');

            var panel = document.createElement('div');
            panel.className = 'sdv-insight-panel';
            panel.setAttribute('data-sdv-injected', '1');

            var hd = document.createElement('div');
            hd.className = 'sdv-insight-panel-hd';
            hd.textContent = 'Ticket insights';
            panel.appendChild(hd);

            var bd = document.createElement('div');
            bd.className = 'sdv-insight-panel-bd';

            var bar = document.createElement('div');
            bar.className = 'sdv-insight-bar';

            // Urgency pill (with tooltip)
            var urgWrap = document.createElement('span');
            urgWrap.className = 'sdv-tooltip-wrap';
            urgWrap.innerHTML =
                '<span class="sdv-pill ' + urgClass + '">' + urgIcon + ' ' + urgLabel + '</span>' +
                (tipHtml ? '<div class="sdv-tooltip-box">' + tipHtml + '</div>' : '');
            bar.appendChild(urgWrap);

            // Sentiment pill
            if (sentiment || score) {
                var sentPill = document.createElement('span');
                sentPill.className = 'sdv-pill sdv-sentiment';
                sentPill.textContent = sentiment + (score ? ' ' + score : '');
                bar.appendChild(sentPill);
            }

            // Tone pill
            if (tone) {
                var tonePill = document.createElement('span');
                tonePill.className = 'sdv-pill sdv-tone';
                tonePill.textContent = (toneIcon ? toneIcon + ' ' : '') + tone;
                bar.appendChild(tonePill);
            }

            // Admin reply count (only if > 0)
            if (adminRep > 0) {
                var repPill = document.createElement('span');
                repPill.className = 'sdv-pill sdv-admin-rep';
                repPill.title = lastAdmin ? 'Last reply by: ' + lastAdmin : '';
                repPill.textContent = '↩ ' + adminRep + ' admin reply' + (adminRep > 1 ? 's' : '');
                bar.appendChild(repPill);
            }

            bd.appendChild(bar);

            if (summary) {
                var sum = document.createElement('div');
                sum.className = 'sdv-summary-line';
                sum.textContent = summary + (ins.ticket_summary && ins.ticket_summary.length > 320 ? '…' : '');
                if (analyzedAt) {
                    var mu = document.createElement('div');
                    mu.className = 'sdv-muted';
                    mu.textContent = 'Analyzed ' + analyzedAt;
                    sum.appendChild(mu);
                }
                bd.appendChild(sum);
            }

            panel.appendChild(bd);

            // Add row urgency left-border class
            row.classList.add('sdv-row-' + urgency);

            var td = findSubjectCell(row, tid);
            if (td) {
                td.style.paddingBottom = '6px';
                td.style.verticalAlign = 'top';
                td.appendChild(panel);
            }
        });
    }

    /* ── Step 4: fetch insights from DB via AJAX ── */
    function loadInsights(ids) {
        if (!ids.length) return;
        var fd = new FormData();
        fd.append('action', 'get_ticket_insights');
        ids.forEach(function (id) { fd.append('ticket_ids[]', id); });

        fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'success' && data.insights) {
                    injectInsights(data.insights);
                }
            })
            .catch(function () { /* fail silently on the list page */ });
    }

    var moTimer = null;
    var moStarted = false;
    function scheduleRefresh() {
        if (moTimer) clearTimeout(moTimer);
        moTimer = setTimeout(function () {
            tagRows();
            var need = [];
            document.querySelectorAll('[data-sdv-tid]').forEach(function (row) {
                var t = row.getAttribute('data-sdv-tid');
                if (t && !row.querySelector('.sdv-insight-panel')) need.push(t);
            });
            loadInsights(need);
        }, 380);
    }

    function init() {
        tagRows();
        loadInsights(collectIds());
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

