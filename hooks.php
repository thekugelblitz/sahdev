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

    $isSel = function ($val, $current) {
        return $val === $current ? 'selected' : '';
    };

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
            <input type="hidden" id="sahdev_intent" value="AUTO">

            <!-- Intent Selector -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;"><i class="fas fa-bullseye"></i> Reply Intent</label>
                <div id="sahdev-intent-btns" style="display: flex; flex-wrap: wrap; gap: 6px;">
                    <button type="button" class="btn btn-xs sahdev-intent-btn sahdev-intent-active" data-intent="AUTO" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">🤖 Auto (AI Decides)</button>
                    <button type="button" class="btn btn-xs sahdev-intent-btn" data-intent="RESOLVE" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">✅ Resolved Query</button>
                    <button type="button" class="btn btn-xs sahdev-intent-btn" data-intent="INVESTIGATE" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">🔍 Checking Query</button>
                    <button type="button" class="btn btn-xs sahdev-intent-btn" data-intent="MORE_INFO" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">❓ Need More Info</button>
                    <button type="button" class="btn btn-xs sahdev-intent-btn" data-intent="GUIDE" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">🗺️ Guide to Solution</button>
                    <button type="button" class="btn btn-xs sahdev-intent-btn" data-intent="OUT_OF_SCOPE" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">🚫 Out of Scope</button>
                    <button type="button" class="btn btn-xs sahdev-intent-btn" data-intent="DUPLICATE" style="border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600;">🔁 Duplicate Ticket</button>
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

            <div class="form-group text-right" style="margin-top: 10px;">
                <button type="button" id="btn-sahdev-analyze" class="btn btn-primary" style="font-weight: 600;">
                    <i class="fas fa-magic"></i> Analyze & Generate Reply
                </button>
                <button type="button" id="btn-sahdev-regenerate" class="btn btn-warning" style="font-weight: 600; display: none; margin-left: 5px;" data-force="true">
                    <i class="fas fa-sync"></i> Regenerate Reply
                </button>
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
                <span id="sahdev-rewrite-status" style="font-size: 12px; color: #666;"></span>
            </div>
            <div id="sahdev-rewrite-loading" style="display: none; margin-top: 8px; font-size: 13px; color: #17a2b8;">
                <i class="fas fa-spinner fa-spin"></i> Sahdev is polishing your draft...
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
                        <div class="panel-heading" style="display: flex; justify-content: space-between;">
                            <strong>Proposed Client Reply</strong>
                            <div>
                                <button type="button" class="btn btn-xs btn-default" onclick="copySahdevReply()"><i class="fas fa-copy"></i> Copy</button>
                                <button type="button" class="btn btn-xs btn-success" onclick="insertSahdevToTinyMce()"><i class="fas fa-arrow-down"></i> Insert to Editor</button>
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
                                <strong style="font-size:13px;">Proposed Reply Preview</strong>
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
HTML;

    // Output the HTML first
    $output = $htmlPanel;

    // Use string concatenation instead of output buffering to prevent WHMCS from dropping the buffer
    $jsContentStart = <<<HTML
<script>
    var sahdevAjaxUrl = "{$ajaxUrl}";
    var sahdevAutoAnalyze = {$autoAnalyzeEnabled};
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
                intent: $('#sahdev_intent').val(),
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
        // FEATURE: Rewrite It — Expand Admin Draft Reply from TinyMCE
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

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: {
                    action: 'rewrite_reply',
                    ticket_id: $('#sahdev_ticket_id').val(),
                    draft_text: draftText,
                    tone: $('#sahdev_tone').val() || 'Professional',
                    instruction: $('#sahdev_instruction').val() || '',
                    token: $('input[name="token"]').val()
                },
                dataType: 'json',
                success: function(res) {
                    $('#sahdev-rewrite-loading').hide();
                    $('#btn-sahdev-rewrite').prop('disabled', false);

                    if (res && res.status === 'success' && res.reply) {
                        var polishedReply = res.reply;
                        var polishedHtml  = polishedReply.replace(/\n/g, '<br>');

                        // Insert back into TinyMCE or fallback textarea
                        if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                            tinymce.activeEditor.setContent(polishedHtml);
                        } else if ($('#replymessage').length) {
                            $('#replymessage').val(polishedReply);
                        }

                        $('#sahdev-rewrite-status').html('<span style="color:#198754;"><i class="fas fa-check-circle"></i> Draft polished &amp; inserted into editor!</span>');

                        // Scroll to reply box
                        if ($('#replyticket').length) {
                            $('html, body').animate({ scrollTop: $('#replyticket').offset().top - 60 }, 400);
                        }
                    } else {
                        var errMsg = (res && res.message) ? res.message : 'Sahdev could not rewrite the reply. Please try again.';
                        $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + errMsg).show();
                    }
                },
                error: function(xhr, status, error) {
                    $('#sahdev-rewrite-loading').hide();
                    $('#btn-sahdev-rewrite').prop('disabled', false);
                    var errDetail = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : ('Server error: ' + error + ' (HTTP ' + xhr.status + ')');
                    $('#sahdev-rewrite-error').html('<i class="fas fa-exclamation-circle"></i> ' + errDetail).show();
                }
            });
        });

        // =====================================================================
        // FEATURE: Auto-Load AI Snapshot on Ticket Page Open
        // =====================================================================
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

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: {
                    action: 'auto_analyze',
                    ticket_id: $('#sahdev_ticket_id').val(),
                    tone: $('#sahdev_tone').val() || 'Professional',
                    intensity: 3,
                    instruction: '',
                    intent: 'AUTO',
                    token: $('input[name="token"]').val(),
                    force_regenerate: 'false'
                },
                dataType: 'json',
                success: function(res) {
                    $('#sahdev-snapshot-loading').hide();

                    if (res && res.status === 'success' && res.data) {
                        var d = res.data;
                        $('#sahdev-snap-cause').text(d.ROOT_CAUSE || 'N/A');
                        $('#sahdev-snap-resp').text(d.RESPONSIBILITY || 'N/A');
                        $('#sahdev-snap-risk').text(d.RISK_LEVEL || 'N/A');
                        $('#sahdev-snap-plan').text(d.INTERNAL_ACTION_PLAN || 'N/A');

                        var snapReplyHtml = d.CLIENT_REPLY ? d.CLIENT_REPLY.replace(/\n/g, '<br>') : 'N/A';
                        $('#sahdev-snap-reply').html(snapReplyHtml);

                        var statsText = res.cached
                            ? 'Cached result (instant)'
                            : ('Tokens: ' + (res.tokens_used || '?') + ' | Time: ' + (res.execution_time_ms || 0) + 'ms');
                        $('#sahdev-snap-stats').text(statsText);

                        $('#sahdev-snapshot-results').fadeIn();
                    } else {
                        // AI responded but with an error status
                        var errMsg = (res && res.message) ? res.message : 'AI returned an unexpected response.';
                        $('#sahdev-snapshot-tech-error').text('Status: ' + (res ? res.status : 'unknown') + '\nMessage: ' + errMsg).show();
                        $('#sahdev-snapshot-sleeping').show();
                    }
                },
                error: function(xhr, status, error) {
                    $('#sahdev-snapshot-loading').hide();
                    var techMsg = 'AJAX Error: ' + error + ' (HTTP ' + xhr.status + ')';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        techMsg += '\nServer: ' + xhr.responseJSON.message;
                    } else if (xhr.responseText && xhr.responseText.length < 600) {
                        techMsg += '\nRaw: ' + xhr.responseText;
                    }
                    $('#sahdev-snapshot-tech-error').text(techMsg).show();
                    $('#sahdev-snapshot-sleeping').show();
                }
            });
        }

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
