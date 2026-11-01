/* One server-backed appearance preference. Settings selections are previews until saved. */
(function () {
    'use strict';
    var saving = false;
    var modes = ['black', 'dark', 'white'];
    function roots() { return document.querySelectorAll('.sahdev-ui[data-sahdev-theme]'); }
    function apply(mode) {
        roots().forEach(function (root) { root.setAttribute('data-sahdev-theme', mode); });
        document.querySelectorAll('[data-sahdev-mode]').forEach(function (button) {
            var active = button.getAttribute('data-sahdev-mode') === mode;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
    }
    function status(message) {
        var el = document.getElementById('sahdev-theme-status');
        if (!el && roots()[0]) {
            el = document.createElement('div');
            el.id = 'sahdev-theme-status';
            el.className = 'sdv-theme-status';
            el.setAttribute('role', 'status');
            roots()[0].appendChild(el);
        }
        if (el) el.textContent = message;
    }
    window.sahdevGlobalSetTheme = function (mode, persist) {
        if (modes.indexOf(mode) === -1 || !roots().length || saving) return;
        var root = roots()[0];
        var previous = root.getAttribute('data-sahdev-theme');
        apply(mode);
        if (persist === false) return;
        saving = true;
        status('Saving appearance…');
        document.querySelectorAll('[data-sahdev-mode]').forEach(function (b) { b.disabled = true; });
        var data = new URLSearchParams();
        data.set('action', 'set_theme_mode');
        data.set('theme_mode', mode);
        data.set('token', root.getAttribute('data-sahdev-token') || (document.querySelector('input[name="token"]') || {}).value || '');
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 15000);
        fetch('addonmodules.php?module=sahdev&sahdev_act=ajax_handler', {
            method: 'POST', credentials: 'same-origin', signal: controller.signal,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: data.toString()
        }).then(function (response) {
            if (!response.ok) throw new Error('Appearance could not be saved.');
            return response.json();
        }).then(function (result) {
            if (result.status !== 'success' || result.theme_mode !== mode) throw new Error('Appearance could not be saved.');
            status('Appearance saved for your account.');
        }).catch(function () {
            apply(previous);
            status('Appearance was not saved. Refresh the page and try again.');
        }).finally(function () {
            clearTimeout(timeout);
            saving = false;
            document.querySelectorAll('[data-sahdev-mode]').forEach(function (b) { b.disabled = false; });
        });
    };
    window.sahdevSetThemeMode = window.sahdevGlobalSetTheme;
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-sahdev-mode]');
        if (button) window.sahdevGlobalSetTheme(button.getAttribute('data-sahdev-mode'));
    });
    function init() {
        // The addon renders after WHMCS header hooks, so its saved value is freshest.
        var main = document.querySelector('.sdv-workspace[data-sahdev-theme],#sahdev-ai-panel[data-sahdev-theme]') || roots()[0];
        if (main) apply(main.getAttribute('data-sahdev-theme'));
        document.querySelectorAll('.sdv-workspace-nav').forEach(function (nav) {
            var toggle = nav.querySelector('.sdv-menu-toggle');
            if (!toggle) return;
            nav.classList.add('sdv-nav-enhanced');
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') !== 'true';
                toggle.setAttribute('aria-expanded', String(open));
                nav.setAttribute('data-menu-open', String(open));
            });
        });
        document.querySelectorAll('.sdv-menu-search').forEach(function (input) {
            input.addEventListener('input', function () {
                var nav = input.closest('.sdv-workspace-nav');
                var query = input.value.trim().toLowerCase();
                var count = 0;
                nav.querySelectorAll('.sdv-nav-group').forEach(function (group) {
                    var matches = 0;
                    group.querySelectorAll('a').forEach(function (link) {
                        var match = (group.querySelector('summary').textContent + ' ' + link.textContent + ' ' + link.title).toLowerCase().indexOf(query) !== -1;
                        link.hidden = !match;
                        if (match) matches++;
                    });
                    group.hidden = !matches;
                    group.open = query ? !!matches : group.hasAttribute('data-current-group');
                    count += matches;
                });
                nav.querySelector('.sdv-menu-empty').hidden = count !== 0;
            });
        });
        if (document.querySelector('.sdv-menu-search')) {
            window.sahdevOpenQuickJump = function () {
                var nav = document.querySelector('.sdv-workspace-nav');
                nav.setAttribute('data-menu-open', 'true');
                nav.querySelector('.sdv-menu-toggle').setAttribute('aria-expanded', 'true');
                nav.querySelector('.sdv-menu-search').focus();
            };
            document.addEventListener('keydown', function (event) {
                var typing = event.target.closest('input,textarea,select,[contenteditable="true"]');
                var key = event.key.toLowerCase();
                if ((event.altKey && key === 'k') || ((event.ctrlKey || event.metaKey) && (key === 'k' || key === '/')) || (key === '/' && !typing)) {
                    event.preventDefault();
                    window.sahdevOpenQuickJump();
                }
            });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
