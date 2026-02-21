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
            <input type="hidden" id="sahdev_ticket_id" value="{$ticketId}">
            <input type="hidden" name="token" value="{$csrfToken}">

            <div class="form-group">
                <label for="sahdev_tone">Tone:</label>
                <select class="form-control" id="sahdev_tone" name="tone">
                    <option value="Professional" {$isSel('Professional', $defaultTone)}>Professional</option>
                    <option value="Friendly" {$isSel('Friendly', $defaultTone)}>Friendly</option>
                    <option value="Formal" {$isSel('Formal', $defaultTone)}>Formal</option>
                    <option value="Concise" {$isSel('Concise', $defaultTone)}>Concise</option>
                    <option value="Empathetic" {$isSel('Empathetic', $defaultTone)}>Empathetic</option>
                    <option value="Direct" {$isSel('Direct', $defaultTone)}>Direct</option>
                </select>
            </div>

            <div class="form-group">
                <label for="sahdev_instruction">Custom Instruction (Optional):</label>
                <textarea class="form-control" id="sahdev_instruction" name="instruction" rows="2" placeholder="e.g., 'Summarize the issue and provide a solution.'"></textarea>
            </div>

            <button type="submit" id="btn-sahdev-analyze" class="btn btn-primary btn-block">
                <i class="fas fa-magic"></i> Generate Reply
            </button>
        </form>

        <div id="sahdev-loading" style="display: none; text-align: center; margin-top: 20px;">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p>Generating AI Reply...</p>
        </div>

        <div id="sahdev-results" style="display: none; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
            <h4>AI Generated Reply:</h4>
            <div id="sahdev-out-reply" class="well well-sm" style="white-space: pre-wrap; background-color: #e9ecef; border-color: #ced4da; color: #495057;"></div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Reply Actions" style="margin-top: 10px;">
                <button type="button" class="btn btn-success" onclick="copySahdevReply()"><i class="fas fa-copy"></i> Copy</button>
                <button type="button" class="btn btn-info" onclick="insertSahdevToTinyMce()"><i class="fas fa-paste"></i> Insert into Reply</button>
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

    // Then append the javascript strictly wrapped in its own output buffering or clean string concatenation 
    // to avoid ANY PHP variable interpolation issues breaking JS regexes.
    ob_start();
    ?>
<script>
    var sahdevAjaxUrl = "<?php echo $ajaxUrl; ?>";

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
            var currentVal = $('#replymessage').val();
            var plain = replyHtml.replace(/<br\s*\/?>/gi, "\n").replace(/(<([^>]+)>)/gi, "");
            $('#replymessage').val(currentVal + "\n" + plain);
        }
    }

    $(document).ready(function() {
        $('#btn-sahdev-analyze').on('click', function(e) {
            e.preventDefault();
            
            $('#sahdev-results').hide();
            $('#sahdev-error').hide();
            $('#sahdev-loading').show();
            var $btn = $(this);
            $btn.prop('disabled', true);
            
            var baseReqData = {
                ticket_id: $('#sahdev_ticket_id').val(),
                tone: $('#sahdev_tone').val(),
                instruction: $('#sahdev_instruction').val(),
                token: $('input[name="token"]').val()
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
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    handleAjaxError(xhr, error);
                    $btn.prop('disabled', false);
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
                        renderSahdevResults(res.data, res.tokens_used, res.execution_time_ms);
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
                cleanContent = cleanContent.replace(/```json\s*/gi, '').replace(/```\s*$/gi, '').trim();
                
                var parsedResponse;
                try {
                    parsedResponse = JSON.parse(cleanContent);
                } catch (e) {
                    throw new Error("Failed to parse local AI JSON. Raw output: " + cleanContent.substring(0, 100));
                }

                var tokensUsed = data.usage ? data.usage.total_tokens : 0;
                var execTimeMs = Math.round(performance.now() - startTime);

                saveResponseToBackend(config.hash_signature, parsedResponse, tokensUsed, execTimeMs, baseReqData, $btn);

            } catch (err) {
                var isFailedToFetch = err.message.toLowerCase().indexOf('failed to fetch') !== -1 || err.message.toLowerCase().indexOf('networkerror') !== -1;
                var errMsg = isFailedToFetch ? "Could not connect to LM Studio at " + config.api_url + ". Ensure LM Studio is running, Local Server is started, and CORS is enabled." : err.message;
                showSahdevError("Local AI Error: " + errMsg);
                $btn.prop('disabled', false);
            }
        }

        function saveResponseToBackend(hashSignature, aiResponseObj, tokensUsed, execTime, baseReqData, $btn) {
            var reqData = Object.assign({ 
                action: 'save_response',
                hash_signature: hashSignature,
                ai_response: JSON.stringify(aiResponseObj),
                token_usage: tokensUsed,
                exec_time: execTime
            }, baseReqData);

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    $btn.prop('disabled', false);
                    if (res && res.status === 'success') {
                        renderSahdevResults(aiResponseObj, tokensUsed, execTime);
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

        function renderSahdevResults(data, tokensUsed, executionTimeMs) {
            $('#sahdev-loading').hide();
            $('#sahdev-out-cause').text(data.ROOT_CAUSE || 'N/A');
            $('#sahdev-out-resp').text(data.RESPONSIBILITY || 'N/A');
            $('#sahdev-out-risk').text(data.RISK_LEVEL || 'N/A');
            $('#sahdev-out-plan').text(data.INTERNAL_ACTION_PLAN || 'N/A');
            
            var formattedReply = data.CLIENT_REPLY ? data.CLIENT_REPLY.replace(/\n/g, '<br>') : 'N/A';
            $('#sahdev-out-reply').html(formattedReply);

            var stats = "Tokens: " + (tokensUsed || 'Unknown') + " | Time: " + (executionTimeMs || 0) + "ms";
            $('#sahdev-token-usage').text(stats);

            $('#sahdev-results').fadeIn();
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
</script>
<?php
    $jsContent = ob_get_clean();
    $output .= $jsContent;
    
    return $output;
}

// Helper to determine selected dropdown
if (!function_exists('sahdev_inject_ticket_panel_sel')) {
    function sahdev_inject_ticket_panel_sel($val, $current)
    {
        return $val === $current ? 'selected' : '';
    }
}

add_hook('AdminAreaViewTicketPage', 1, function ($vars) {
    // Return early if not ticket page context
    if (!isset($vars['ticketid']))
        return '';

    return sahdev_inject_ticket_panel($vars);
});
