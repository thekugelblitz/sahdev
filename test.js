
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

$(document).ready(function () {
    $('#btn-sahdev-analyze, #btn-sahdev-regenerate').on('click', function (e) {
        e.preventDefault();

        var isRegenerate = $(this).data('force') === true;

        $('#sahdev-results').hide();
        $('#sahdev-error').hide();
        $('#sahdev-loading').show();
        var $btn = $(this);
        $('#btn-sahdev-analyze, #btn-sahdev-regenerate').prop('disabled', true);

        var baseReqData = {
            ticket_id: $('#sahdev_ticket_id').val(),
            tone: $('#sahdev_tone').val(),
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
            success: function (res) {
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
            error: function (xhr, status, error) {
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
            success: function (res) {
                $btn.prop('disabled', false);
                if (res && res.status === 'success') {
                    renderSahdevResults(res.data, res.tokens_used, res.execution_time_ms);
                } else {
                    showSahdevError(res.message || 'Unknown error occurred.');
                }
            },
            error: function (xhr, status, error) {
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

        var userContent = [];
        userContent.push({ type: "text", text: promptText });

        if (config.context && config.context.attachments_images && config.context.attachments_images.length > 0) {
            config.context.attachments_images.forEach(function (img) {
                if (img.url) {
                    userContent.push({
                        type: "image_url",
                        image_url: { url: img.url }
                    });
                }
            });
        }

        var llmPayload = {
            model: config.model || "local-model",
            messages: [
                { role: "system", content: systemMessage },
                { role: "user", content: userContent }
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
        // Encode as base64 to avoid backend framework sanitization destroying newlines and quotes
        var base64Json = btoa(unescape(encodeURIComponent(JSON.stringify(aiResponseObj))));

        var reqData = Object.assign({
            action: 'save_response',
            hash_signature: hashSignature,
            ai_response: base64Json,
            token_usage: tokensUsed,
            exec_time: execTime
        }, baseReqData);

        $.ajax({
            url: sahdevAjaxUrl,
            type: 'POST',
            data: reqData,
            dataType: 'json',
            success: function (res) {
                $btn.prop('disabled', false);
                if (res && res.status === 'success') {
                    renderSahdevResults(aiResponseObj, tokensUsed, execTime);
                } else {
                    showSahdevError("AI succeeded but failed to save: " + (res.message || 'Unknown error'));
                }
            },
            error: function (xhr, status, error) {
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
            context.messages.forEach(function (msg) {
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
        $('#btn-sahdev-regenerate').show();
    }

    function showSahdevError(msg) {
        $('#sahdev-loading').hide();
        $('#sahdev-error').html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
    }

    function handleAjaxError(xhr, error) {
        $('#sahdev-loading').hide();
        var msg = 'AJAX Error: ' + error + ' (Status: ' + xhr.status + ')<br><br>';
        if (xhr.responseJSON && xhr.responseJSON.message) {
            msg += xhr.responseJSON.message;
        } else if (xhr.responseText) {
            msg += '<strong>Raw Server Response:</strong><br><textarea class="form-control" rows="5" readonly>' + xhr.responseText + '</textarea>';
        } else {
            msg += 'No response text available.';
        }
        showSahdevError(msg);
    }
});
