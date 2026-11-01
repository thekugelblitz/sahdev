/* Progressive settings navigation. All categories stay in the same POST form. */
(function () {
    'use strict';
    var form = document.getElementById('sdvSettingsForm');
    if (!form) return;
    var panes = Array.from(form.querySelectorAll('.sdv-settings-pane'));
    var links = Array.from(form.querySelectorAll('[data-tab]'));
    var search = document.getElementById('sdv-settings-search');
    var empty = document.getElementById('sdv-settings-empty');
    var subtab = document.getElementById('sdv_active_subtab');
    var status = document.getElementById('sdv-save-status');
    var reset = document.getElementById('sdv-settings-reset');
    var dirty = false;
    var submitting = false;
    var current = subtab.value;
    var previousTheme = (form.closest('[data-sahdev-theme]') || {}).dataset;
    previousTheme = previousTheme ? previousTheme.sahdevTheme : 'black';
    function snapshot() {
        return JSON.stringify(Array.from(new FormData(form).entries()).filter(function (entry) {
            return ['active_subtab', 'token'].indexOf(entry[0]) === -1;
        }));
    }
    var original = snapshot();
    function updateDirty() {
        dirty = snapshot() !== original;
        status.textContent = dirty ? 'Unsaved changes' : 'All changes saved';
        reset.hidden = !dirty;
    }
    function paneFor(id) {
        if (id === 'tab-ticket') id = 'tab-ticket-ai';
        return panes.find(function (pane) { return pane.id === id; });
    }
    function activate(id, updateHistory) {
        var target = paneFor(id);
        if (!target) return false; // Invalid hashes never hide the current section.
        current = target.id;
        subtab.value = current;
        panes.forEach(function (pane) { pane.classList.toggle('active', pane === target); });
        links.forEach(function (link) {
            var selected = link.dataset.tab === current;
            link.classList.toggle('active', selected);
            if (selected) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        if (updateHistory && location.hash !== '#' + current) {
            try { history.pushState(null, '', '#' + current); } catch (ignore) {}
        }
        return true;
    }
    // Search explanatory copy only, never credential values or input contents.
    var index = panes.map(function (pane) {
        var copy = pane.cloneNode(true);
        copy.querySelectorAll('input,textarea,select,script').forEach(function (node) { node.remove(); });
        var link = links.find(function (item) { return item.dataset.tab === pane.id; });
        return { pane: pane, text: (copy.textContent + ' ' + (link ? link.textContent : '')).toLowerCase() };
    });
    function filter() {
        var query = search.value.trim().toLowerCase();
        var found = index.filter(function (item) { return item.text.indexOf(query) !== -1; });
        if (!found.length) found = index.filter(function (item) {
            return query.split(/\s+/).every(function (word) { return item.text.indexOf(word) !== -1; });
        });
        var matches = found.map(function (item) { return item.pane.id; });
        links.forEach(function (link) { link.hidden = matches.indexOf(link.dataset.tab) === -1; });
        form.querySelectorAll('.sdv-settings-category').forEach(function (group) {
            group.hidden = !Array.from(group.querySelectorAll('[data-tab]')).some(function (link) { return !link.hidden; });
        });
        empty.hidden = matches.length > 0;
        if (matches.length) activate(matches.indexOf(current) !== -1 ? current : matches[0], false);
        else panes.forEach(function (pane) { pane.classList.remove('active'); });
    }
    window.switchSettingsSection = function (id) {
        if (!paneFor(id)) return;
        search.value = '';
        filter();
        activate(id, true);
    };
    links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            activate(link.dataset.tab, true);
        });
    });
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault(); // Search must never implicitly save the settings form.
            var result = links.find(function (link) { return !link.hidden; });
            if (result) result.focus();
        }
        if (event.key === 'Escape') { search.value = ''; filter(); }
    });
    window.addEventListener('hashchange', function () {
        var id = location.hash.slice(1);
        if (!paneFor(id)) return;
        search.value = '';
        filter();
        activate(id, false);
    });
    form.addEventListener('input', updateDirty);
    form.addEventListener('change', function (event) {
        if (event.target.name === 'ui_theme_mode') window.sahdevGlobalSetTheme(event.target.value, false);
        updateDirty();
    });
    form.addEventListener('reset', function () {
        setTimeout(function () {
            subtab.value = current;
            window.sahdevGlobalSetTheme(previousTheme, false);
            filter();
            updateDirty();
        }, 0);
    });
    // Reveal invalid fields before the browser tries to focus them.
    form.addEventListener('invalid', function (event) {
        var pane = event.target.closest('.sdv-settings-pane');
        var firstInvalid = form.querySelector(':invalid');
        if (pane && event.target === firstInvalid) {
            search.value = '';
            filter();
            activate(pane.id, true);
        }
    }, true);
    form.addEventListener('submit', function (event) {
        if (submitting) { event.preventDefault(); return; }
        submitting = true;
        status.textContent = 'Saving settings…';
        form.querySelector('[type="submit"]').disabled = true;
    });
    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            if (!submitting) form.requestSubmit();
        }
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', function () {
        submitting = false;
        form.querySelector('[type="submit"]').disabled = false;
        updateDirty();
    });
    // Associate legacy field labels with controls, including switch descriptions.
    form.querySelectorAll('.form-group,.sdv-switch-row').forEach(function (group, index) {
        var control = group.querySelector('input:not([type="hidden"]),select,textarea');
        var label = group.querySelector('label,.sdv-switch-label');
        if (!control || !label || control.closest('label')) return;
        if (!control.id) control.id = 'sdv-setting-field-' + index;
        if (label.tagName === 'LABEL') label.htmlFor = control.id;
        else {
            if (!label.id) label.id = 'sdv-setting-label-' + index;
            control.setAttribute('aria-labelledby', label.id);
        }
    });
    activate(location.hash.slice(1), false) || activate(current, false) || activate(panes[0].id, false);
    form.classList.add('sdv-settings-enhanced');
})();
