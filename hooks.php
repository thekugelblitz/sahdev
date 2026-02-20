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

    return <<<HTML
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
                <div class="col-md-4">
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
                <div class="col-md-8">
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

<script>
    var sahdevAjaxUrl = "{$ajaxUrl}";

    function copySahdevReply() {
        var html = $('#sahdev-out-reply').html();
        var temp = $("<textarea>");
        $("body").append(temp);
        
        // Convert BRs to newlines for raw text copy if needed, but modern clipboards handles HTML somewhat
        // Let's copy plain text format
        var plain = html.replace(/<br\\s*\\/?>/gi, "\\n").replace(/(<([^>]+)>)/gi, "");
        temp.val(plain).select();
        document.execCommand("copy");
        temp.remove();
        alert("Reply copied to clipboard!");
    }

    function insertSahdevToTinyMce() {
        var replyHtml = $('#sahdev-out-reply').html();
        if (typeof tinymce !== "undefined" && tinymce.activeEditor) {
            tinymce.activeEditor.execCommand('mceInsertContent', false, replyHtml);
            
            // Scroll to the reply box gently
            $('html, body').animate({
                scrollTop: $("#replyticket").offset().top - 50
            }, 500);
        } else if ($('#replymessage').length) {
            // Fallback for native textarea (e.g. WHMCS mobile/lite versions or tinyMCE disabled)
            var currentVal = $('#replymessage').val();
            var plain = replyHtml.replace(/<br\\s*\\/?>/gi, "\\n").replace(/(<([^>]+)>)/gi, "");
            $('#replymessage').val(currentVal + "\\n" + plain);
        }
    }

    $(document).ready(function() {
        $('#btn-sahdev-analyze').on('click', function(e) {
            e.preventDefault();
            
            $('#sahdev-results').hide();
            $('#sahdev-error').hide();
            $('#sahdev-loading').show();
            $(this).prop('disabled', true);
            
            var reqData = {
                action: 'analyze_ticket',
                ticket_id: $('#sahdev_ticket_id').val(),
                tone: $('#sahdev_tone').val(),
                instruction: $('#sahdev_instruction').val(),
                token: $('input[name="token"]').val() // WHMCS global CSRF token from page
            };

            $.ajax({
                url: sahdevAjaxUrl,
                type: 'POST',
                data: reqData,
                dataType: 'json',
                success: function(res) {
                    $('#sahdev-loading').hide();
                    $('#btn-sahdev-analyze').prop('disabled', false);

                    if (res && res.status === 'success') {
                        // Populate UI
                        var data = res.data;
                        $('#sahdev-out-cause').text(data.ROOT_CAUSE || 'N/A');
                        $('#sahdev-out-resp').text(data.RESPONSIBILITY || 'N/A');
                        $('#sahdev-out-risk').text(data.RISK_LEVEL || 'N/A');
                        $('#sahdev-out-plan').text(data.INTERNAL_ACTION_PLAN || 'N/A');
                        
                        // Treat the reply as HTML
                        // Format new lines from GPT as HTML BRs just in case
                        var formattedReply = data.CLIENT_REPLY ? data.CLIENT_REPLY.replace(/\\n/g, '<br>') : 'N/A';
                        $('#sahdev-out-reply').html(formattedReply);

                        var stats = "Tokens: " + (res.tokens_used || 'Cached') + " | Time: " + (res.execution_time_ms || 0) + "ms";
                        $('#sahdev-token-usage').text(stats);

                        $('#sahdev-results').fadeIn();
                    } else {
                        $('#sahdev-error').html('<i class="fas fa-exclamation-circle"></i> ' + (res.message || 'Unknown error occurred.')).show();
                    }
                },
                error: function(xhr, status, error) {
                    $('#sahdev-loading').hide();
                    $('#btn-sahdev-analyze').prop('disabled', false);
                    var msg = 'AJAX Error: ' + error + ' (Status: ' + xhr.status + ')<br><br>';
                    
                    if(xhr.responseJSON && xhr.responseJSON.message) {
                        msg += xhr.responseJSON.message;
                    } else if (xhr.responseText) {
                        // Dump raw response for debugging purposes
                        msg += '<strong>Raw Server Response:</strong><br><textarea class="form-control" rows="5" readonly>' + xhr.responseText + '</textarea>';
                        console.error('Sahdev Raw Response:', xhr.responseText);
                    } else {
                        msg += 'No response text available. Check browser console or network tab.';
                    }
                    
                    $('#sahdev-error').html('<i class="fas fa-exclamation-triangle"></i> ' + msg).show();
                }
            });
        });
    });
</script>
HTML;
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
