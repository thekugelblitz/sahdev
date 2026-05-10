/**
 * Sahdev AI — Remastered Theme JS
 * Transforms the Classic stacked-panel layout into a compact tabbed UI.
 * Works by DOM manipulation on the existing Classic HTML (same IDs).
 */
(function() {
    'use strict';

    var $panel = $('#sahdev-ai-panel');
    if (!$panel.length) return;

    // Panel already has .sahdev-remastered class from server-side rendering.
    // Body is hidden via inline style — will be revealed at the end after transform.

    // --- 2. Reference panel body (hidden server-side via inline style) ---
    var $body = $('#sahdev-ai-body');

    // --- 3. Build Tab Bar ---
    var tabs = [
        { id: 'rm-tab-analysis', icon: '⚡', label: 'Analysis', pane: 'rm-pane-analysis' },
        { id: 'rm-tab-tools',    icon: '🛠️', label: 'Tools',    pane: 'rm-pane-tools' },
        { id: 'rm-tab-summary',  icon: '📋', label: 'Summary',  pane: 'rm-pane-summary' },
        { id: 'rm-tab-kb',       icon: '📚', label: 'KB',       pane: 'rm-pane-kb' },
        { id: 'rm-tab-memory',   icon: '🧠', label: 'Memory',   pane: 'rm-pane-memory' }
    ];

    var tabBarHtml = '<div class="sahdev-rm-tabs">';
    for (var i = 0; i < tabs.length; i++) {
        var t = tabs[i];
        var activeClass = (i === 0) ? ' active' : '';
        tabBarHtml += '<div class="sahdev-rm-tab' + activeClass + '" data-pane="' + t.pane + '" id="' + t.id + '">';
        tabBarHtml += '<span class="rm-tab-icon">' + t.icon + '</span>' + t.label + '</div>';
    }
    tabBarHtml += '</div>';

    // Insert tab bar at the top of panel body
    $body.prepend(tabBarHtml);

    // --- 4. Wrap existing content into tab panes ---

    // Analysis pane: form + loading + results + error + snapshot + rewrite section
    var $form = $('#sahdev-ai-form');
    var $loading = $('#sahdev-loading');
    var $results = $('#sahdev-results');
    var $error = $('#sahdev-error');
    var $snapshotOuter = $('#sahdev-snapshot-outer');
    // Find the rewrite section (the dashed border div after the form)
    var $rewriteSection = $form.nextAll().filter(function() {
        return $(this).find('#btn-sahdev-rewrite').length > 0 || $(this).attr('id') === 'sahdev-loading' || $(this).attr('id') === 'sahdev-results' || $(this).attr('id') === 'sahdev-error';
    });

    // Wrap analysis content
    var $analysisPane = $('<div class="sahdev-rm-pane active" id="rm-pane-analysis"></div>');
    $body.append($analysisPane);

    // Move form into analysis pane
    $form.appendTo($analysisPane);

    // Move loading, results, error into analysis pane
    $loading.appendTo($analysisPane);
    $results.appendTo($analysisPane);
    $error.appendTo($analysisPane);

    // Move the rewrite section (it's after the form in the original HTML)
    // Find it by the dashed border div containing rewrite button
    $body.children().filter(function() {
        var $el = $(this);
        return $el.find('#btn-sahdev-rewrite').length > 0 ||
               $el.find('#sahdev-rewrite-loading').length > 0 ||
               $el.find('#sahdev-canned-loading').length > 0 ||
               $el.find('#sahdev-rewrite-error').length > 0;
    }).appendTo($analysisPane);

    // Also move snapshot into analysis pane
    if ($snapshotOuter.length) {
        $snapshotOuter.appendTo($analysisPane);
        $snapshotOuter.show();
    }

    // Tools pane
    var $toolsForm = $body.find('[id*="sahdev-tools-panel"]').closest('.form-group');
    var $toolsPane = $('<div class="sahdev-rm-pane" id="rm-pane-tools"></div>');
    $body.append($toolsPane);
    // Move tools-related elements
    $body.find('#btn-sahdev-run-tools, #btn-sahdev-rerun-tools').closest('.form-group').appendTo($toolsPane);
    $('#sahdev-tools-status').appendTo($toolsPane);
    $('#sahdev-tools-panel').parent('.form-group').length
        ? $('#sahdev-tools-panel').parent('.form-group').appendTo($toolsPane)
        : $('#sahdev-tools-panel').appendTo($toolsPane);

    // Summary pane
    var $summaryPane = $('<div class="sahdev-rm-pane" id="rm-pane-summary"></div>');
    $body.append($summaryPane);
    var $summaryOuter = $('#sahdev-summarizer-outer');
    if ($summaryOuter.length) {
        $summaryOuter.appendTo($summaryPane);
        $summaryOuter.show();
        $('#sahdev-summarizer-body').show();
    }

    // KB pane
    var $kbPane = $('<div class="sahdev-rm-pane" id="rm-pane-kb"></div>');
    $body.append($kbPane);
    var $cannedOuter = $('#sahdev-canned-outer');
    if ($cannedOuter.length) {
        $cannedOuter.appendTo($kbPane);
        $cannedOuter.show();
        $('#sahdev-canned-body').show();
    }

    // Memory pane
    var $memoryPane = $('<div class="sahdev-rm-pane" id="rm-pane-memory"></div>');
    $body.append($memoryPane);
    var $historyOuter = $('#sahdev-history-outer');
    if ($historyOuter.length) {
        $historyOuter.appendTo($memoryPane);
        $historyOuter.show();
        $('#sahdev-history-body').show();
    }

    // --- 5. Tab Click Handler ---
    $(document).on('click', '.sahdev-rm-tab', function() {
        var $tab = $(this);
        var paneId = $tab.data('pane');

        // Switch active tab
        $('.sahdev-rm-tab').removeClass('active');
        $tab.addClass('active');

        // Switch active pane
        $('.sahdev-rm-pane').removeClass('active');
        $('#' + paneId).addClass('active');
    });

    // --- 6. Compact Tech Context (collapsible — eye-catching banner) ---
    var $techGroup = $('#sahdev_technical_context').closest('.row');
    if ($techGroup.length) {
        // Inject keyframe animations via style tag (only once)
        if (!document.getElementById('sahdev-tech-anim')) {
            var styleTag = document.createElement('style');
            styleTag.id = 'sahdev-tech-anim';
            styleTag.textContent =
                '@keyframes sahdevTechPulse{0%,100%{box-shadow:0 0 0 0 rgba(99,102,241,0.25)}50%{box-shadow:0 0 0 6px rgba(99,102,241,0)}}' +
                '@keyframes sahdevTechShimmer{0%{left:-100%}100%{left:200%}}' +
                '.sahdev-rm-tech-toggle::before{content:"";position:absolute;top:0;left:-100%;width:60%;height:100%;' +
                    'background:linear-gradient(90deg,transparent,rgba(255,255,255,0.55),transparent);' +
                    'animation:sahdevTechShimmer 2s ease-in-out 1s 1;pointer-events:none}' +
                '.sahdev-rm-tech-toggle:hover{transform:translateY(-1px) !important;' +
                    'box-shadow:0 4px 14px rgba(79,70,229,0.18) !important;border-color:#818cf8 !important}' +
                '.sahdev-rm-tech-toggle:active{transform:translateY(0) !important}' +
                '.sahdev-rm-tech-toggle.open .sahdev-tech-chevron{transform:rotate(90deg) !important}' +
                '.sahdev-rm-tech-toggle.open .sahdev-tech-badge{display:none !important}' +
                '@media(max-width:480px){.sahdev-rm-tech-toggle .sahdev-tech-badge{display:none !important}' +
                    '.sahdev-rm-tech-toggle{padding:10px 12px !important;gap:8px !important}}';
            document.head.appendChild(styleTag);
        }

        var toggleHtml =
            '<div class="sahdev-rm-tech-toggle" style="' +
                'cursor:pointer;display:flex;align-items:center;gap:12px;' +
                'margin:4px 0 12px;padding:12px 16px;' +
                'background:linear-gradient(135deg,#eef2ff 0%,#e0e7ff 50%,#ede9fe 100%);' +
                'border:1.5px solid #a5b4fc;border-radius:10px;' +
                'font-size:13px;font-weight:600;color:#312e81;letter-spacing:0.2px;' +
                'transition:all 0.2s ease;position:relative;overflow:hidden;user-select:none;' +
                'animation:sahdevTechPulse 2.5s ease-in-out 2s 3' +
            '">' +
                '<span class="sahdev-tech-icon" style="' +
                    'display:inline-flex;align-items:center;justify-content:center;' +
                    'width:32px;height:32px;min-width:32px;' +
                    'background:linear-gradient(135deg,#4f46e5,#7c3aed);' +
                    'border-radius:8px;color:#fff;font-size:14px;' +
                    'box-shadow:0 2px 6px rgba(79,70,229,0.35)' +
                '"><i class="fas fa-code"></i></span>' +
                '<span style="flex:1;display:flex;flex-direction:column;line-height:1.35">' +
                    '<span style="font-size:13px;font-weight:700;color:#312e81">📎 Technical Context</span>' +
                    '<small style="font-size:11px;font-weight:400;color:#6366f1;margin-top:1px">Paste logs, errors or server info for smarter analysis</small>' +
                '</span>' +
                '<span class="sahdev-tech-badge" style="' +
                    'font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;' +
                    'background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;' +
                    'padding:3px 9px;border-radius:12px;white-space:nowrap;' +
                    'box-shadow:0 1px 4px rgba(124,58,237,0.3)' +
                '">OPTIONAL</span>' +
                '<i class="fas fa-chevron-right sahdev-tech-chevron" style="' +
                    'font-size:12px;color:#6366f1;transition:transform 0.25s cubic-bezier(0.4,0,0.2,1);flex-shrink:0' +
                '"></i>' +
            '</div>';

        $techGroup.before(toggleHtml);
        $techGroup.wrap('<div class="sahdev-rm-tech-wrap" style="' +
            'border:1px solid #c7d2fe;border-top:none;border-radius:0 0 10px 10px;' +
            'margin-top:-12px;margin-bottom:12px;padding:12px 16px;background:#f8faff' +
        '"></div>');

        $(document).on('click', '.sahdev-rm-tech-toggle', function() {
            var $toggle = $(this);
            var $wrap = $toggle.next('.sahdev-rm-tech-wrap');
            $wrap.slideToggle(200);
            $toggle.toggleClass('open');
            // Stop pulse animation after first interaction
            $toggle.css('animation', 'none');
        });
        // Start collapsed
        $techGroup.parent().hide();
    }

    // --- 7. Compact Model Select (collapsible) ---
    var $modelRow = $('#sahdev_override_provider').closest('.row');
    if ($modelRow.length) {
        var $modelContent = $modelRow.find('.col-md-12');
        $modelContent.find('small').hide();
        $modelContent.find('label').css({ fontSize: '11px', marginBottom: '2px' });
    }

    // --- 8. Reorganize controls into grid ---
    var $toneGroup = $('#sahdev_tone').closest('.col-md-3');
    var $intensityGroup = $('#sahdev_intensity').closest('.col-md-3');
    var $instructionGroup = $('#sahdev_instruction').closest('.col-md-6');
    var $controlsRow = $toneGroup.closest('.row');
    if ($controlsRow.length && $toneGroup.length) {
        $controlsRow.addClass('sahdev-rm-controls').removeClass('row');
        $toneGroup.removeClass('col-md-3').css({ padding: 0 });
        $intensityGroup.removeClass('col-md-3').css({ padding: 0 });
        $instructionGroup.removeClass('col-md-6').css({ padding: 0 });
    }

    // --- 9. Reveal transformed panel (clear inline hiding set by server) ---
    $body.css({ display: 'block', visibility: 'visible', background: '#ffffff' });

})();
