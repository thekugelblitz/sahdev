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

    // We use a relative path for AJAX to avoid CORS problems if SystemURL has an HTTP/HTTPS mismatch
    $ajaxUrl = '../modules/addons/sahdev/ajax.php';

    // Output HTML Panel (collapsible using WHMCS bootstrap structure)
    // Needs to append into the "viewticket" page typically above replies or side sidebar
    // AdminAreaViewTicketPage hook outputs raw HTML onto the ticket view

    // Load Tone Defaults from DB
    $settings = Capsule::table('tblsahdev_settings')->first();
    $defaultTone = $settings ? $settings->tone_default : 'Professional';

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
                <span id="sahdev-token-usage"></span> • <span id="sahdev-exec-time"></span>
            </div>
        </div>

        <div id="sahdev-error" class="alert alert-danger" style="display: none; margin-top: 20px;"></div>

    </div>
</div>
HTML;

    // Output the HTML first
    $output = $htmlPanel;

    // Use string concatenation instead of output buffering to prevent WHMCS from dropping the buffer
    $jsContentStart = <<<HTML
<script>
    var sahdevAjaxUrl = "{$ajaxUrl}";
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

            var promptText = buildPromptText(config.context, config.tone, config.customInstruction);
            var systemMessage = config.system_prompt || "You are a helpful assistant.";

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
                        'Authorization': 'Bearer local'
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
                    parsedResponse = JSON.parse(cleanContent);
                } catch (e) {
                    throw new Error("Failed to parse local AI JSON. Raw output: " + cleanContent.substring(0, 100));
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

        function buildPromptText(context, tone, customInstruction) {
            var prompt = "Analyze the given ticket and output strictly in a valid JSON object matching this schema without any markdown formatting block:\n";
            prompt += "{\n";
            prompt += "  \"ROOT_CAUSE\": \"string (brief analysis)\",\n";
            prompt += "  \"RESPONSIBILITY\": \"string (Client, Host, 3rd Party)\",\n";
            prompt += "  \"RISK_LEVEL\": \"string (Low, Medium, High, Critical)\",\n";
            prompt += "  \"INTERNAL_ACTION_PLAN\": \"string (steps team needs to take)\",\n";
            prompt += "  \"CLIENT_REPLY\": \"string (html formatted reply to be sent to user)\"\n";
            prompt += "}\n\n";

            if (tone) {
                prompt += "The generated CLIENT_REPLY must have a " + tone + " tone.\n";
            }
            if (customInstruction) {
                prompt += "CUSTOM ADMIN INSTRUCTION (Follow strictly): " + customInstruction + "\n\n";
            }
            // Remove the signature verbatim injection for markdown bodies
            prompt += "CRITICAL FOR CLIENT_REPLY: Generate ONLY the core body of the reply in Markdown format. Do NOT include any greetings (like 'Hi Name,') and do NOT include any sign-offs or signatures (like 'Regards, Support'). The admin will inject this between their existing greeting and signature.\n\n";

            prompt += "=== TICKET DATA ===\n";
            prompt += "Client Name: " + (context.client_name || 'Unknown') + "\n";
            prompt += "Department: " + (context.department || 'Unknown') + "\n";
            prompt += "Subject: " + (context.subject || 'Unknown') + "\n";

            if (context.services_summary) {
                prompt += "Relevant Services: " + context.services_summary + "\n";
            }

            prompt += "\n--- MESSAGES HISTORY ---\n";
            if (context.messages && context.messages.length > 0) {
                context.messages.forEach(function(msg) {
                    var type = msg.admin ? 'ADMIN/SUPPORT' : 'CLIENT';
                    prompt += "[" + type + "] " + msg.date + ":\n" + msg.message + "\n------------\n";
                });
            }

            if (context.attachments_text) {
                prompt += "\n--- ATTACHMENT EXCERPTS ---\n" + context.attachments_text + "\n";
            }

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
