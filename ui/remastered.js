/**
 * Sahdev AI — Remastered Theme JS
 * Transforms the Classic stacked-panel layout into a compact tabbed UI.
 * Works by DOM manipulation on the existing Classic HTML (same IDs).
 */
(function() {
    'use strict';

    var $panel = $('#sahdev-ai-panel');
    if (!$panel.length) return;

    // --- 1. Add .sahdev-remastered class to outer panel ---
    $panel.addClass('sahdev-remastered');

    // --- 2. Ensure panel body is visible (Remastered starts open) ---
    var $body = $('#sahdev-ai-body');
    $body.show();

    // --- 2b. Swap header badge + switch button for Remastered context ---
    var $switchBtn = $('#sahdev-theme-switch');
    if ($switchBtn.length) {
        $switchBtn.attr('data-target', 'classic').attr('title', 'Switch to Classic Theme')
            .html('<i class="fas fa-undo"></i> Classic');
    }
    $panel.find('.panel-heading .label').text('Remastered')
        .css({ background: 'rgba(99,102,241,0.3)', color: '#c7d2fe' });

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

    // --- 6. Compact Tech Context (collapsible) ---
    var $techGroup = $('#sahdev_technical_context').closest('.row');
    if ($techGroup.length) {
        var $techWrap = $('<div class="sahdev-rm-tech-wrap" style="display:none;"></div>');
        $techGroup.before('<div class="sahdev-rm-tech-toggle"><i class="fas fa-chevron-right" style="font-size:10px;transition:transform 0.15s;"></i> Technical Context</div>');
        $techGroup.wrap($techWrap.clone().removeAttr('style'));
        $(document).on('click', '.sahdev-rm-tech-toggle', function() {
            var $wrap = $(this).next();
            $wrap.slideToggle(150);
            $(this).find('i').toggleClass('fa-chevron-right fa-chevron-down');
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

})();
