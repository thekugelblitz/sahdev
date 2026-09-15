/**
 * Sahdev AI Live Chat - Universal Embed Widget (embed.js)
 *
 * Embeddable on any external website (e.g. hostingspell.com, landing pages).
 * Features:
 *  - Pre-chat identification form (Name & Email) to link with WHMCS accounts
 *  - Real-time AI chat + Live Human Agent Takeover
 *  - Real-time keystroke typing sneak-peek (debounced at 400ms)
 *  - 1-click "Talk to Human" summon trigger
 *  - Cross-Origin Resource Sharing (CORS) compatible
 *  - LocalStorage session & visitor identity persistence
 */
(function() {
    'use strict';

    if (window._sdvEmbedLoaded) return;
    window._sdvEmbedLoaded = true;

    // 1. Resolve WHMCS Endpoint Base URL
    var currentScript = document.currentScript || (function() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            if (scripts[i].src && scripts[i].src.indexOf('embed.js') !== -1) {
                return scripts[i];
            }
        }
        return null;
    })();

    var whmcsBaseUrl = '';
    if (currentScript) {
        whmcsBaseUrl = currentScript.getAttribute('data-whmcs-url') || '';
        if (!whmcsBaseUrl && currentScript.src) {
            try {
                var scriptUrl = new URL(currentScript.src);
                whmcsBaseUrl = scriptUrl.origin + scriptUrl.pathname.replace(/\/modules\/addons\/sahdev\/embed\.js.*$/, '');
            } catch (e) {
                whmcsBaseUrl = '';
            }
        }
    }
    whmcsBaseUrl = (whmcsBaseUrl || '').replace(/\/+$/, '');

    var endpointUrl = whmcsBaseUrl ? (whmcsBaseUrl + '/modules/addons/sahdev/ajax.php') : '/modules/addons/sahdev/ajax.php';

    // 2. Storage Helpers
    function sdvGet(key, fallback) {
        try {
            var v = localStorage.getItem(key);
            return v !== null ? v : fallback;
        } catch(e) { return fallback; }
    }
    function sdvSet(key, val) {
        try { localStorage.setItem(key, val); } catch(e) {}
    }

    var visitorToken = sdvGet('sdv_embed_token', '');
    if (!visitorToken) {
        visitorToken = 'emb_' + Math.random().toString(36).substring(2, 15) + '_' + Date.now();
        sdvSet('sdv_embed_token', visitorToken);
    }

    var sessionUuid = sdvGet('sdv_embed_session_uuid', '');
    var visitorName = sdvGet('sdv_embed_visitor_name', '');
    var visitorEmail = sdvGet('sdv_embed_visitor_email', '');

    // State
    var isOpen = false;
    var isIdentified = Boolean(visitorEmail && visitorName);
    var isTypingDebounceTimer = null;
    var pollTimer = null;
    var isSending = false;
    var activeStaffName = null;
    var isStaffTakeover = false;

    // 3. Inject CSS Styles
    var css = `
        #sdv-embed-launcher {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.45), 0 4px 10px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 2147483646;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease;
            user-select: none;
        }
        #sdv-embed-launcher:hover {
            transform: scale(1.06);
            box-shadow: 0 14px 30px -4px rgba(37, 99, 235, 0.55);
        }
        #sdv-embed-container {
            position: fixed;
            bottom: 96px;
            right: 24px;
            width: 400px;
            max-width: calc(100vw - 32px);
            height: 620px;
            max-height: calc(100vh - 120px);
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 50px -10px rgba(15, 23, 42, 0.28), 0 0 0 1px rgba(0, 0, 0, 0.06);
            display: none;
            flex-direction: column;
            overflow: hidden;
            z-index: 2147483647;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 14px;
            line-height: 1.45;
            color: #1e293b;
            box-sizing: border-box;
        }
        #sdv-embed-container.sdv-embed-open {
            display: flex !important;
            animation: sdvEmbedSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes sdvEmbedSlideUp {
            from { opacity: 0; transform: translateY(16px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .sdv-em-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #ffffff;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .sdv-em-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sdv-em-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: #fff;
            flex-shrink: 0;
        }
        .sdv-em-title {
            font-size: 14px;
            font-weight: 700;
            color: #f8fafc;
        }
        .sdv-em-status {
            font-size: 11px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .sdv-em-status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
        }
        .sdv-em-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .sdv-em-btn {
            background: rgba(255, 255, 255, 0.12);
            border: none;
            color: #f8fafc;
            border-radius: 6px;
            padding: 5px 9px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s ease;
        }
        .sdv-em-btn:hover {
            background: rgba(255, 255, 255, 0.22);
        }
        .sdv-em-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: #f8fafc;
        }
        .sdv-em-msg {
            max-width: 82%;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13.5px;
            line-height: 1.45;
            word-break: break-word;
        }
        .sdv-em-msg-bot {
            align-self: flex-start;
            background: #ffffff;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            border-bottom-left-radius: 3px;
        }
        .sdv-em-msg-user {
            align-self: flex-end;
            background: #2563eb;
            color: #ffffff;
            border-bottom-right-radius: 3px;
        }
        .sdv-em-msg-staff {
            align-self: flex-start;
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            border-bottom-left-radius: 3px;
        }
        .sdv-em-msg-staff-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 3px;
        }
        .sdv-em-footer {
            padding: 12px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .sdv-em-input {
            flex: 1;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.15s ease;
        }
        .sdv-em-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }
        .sdv-em-send {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .sdv-em-send:hover {
            background: #1d4ed8;
        }
        /* Pre-Chat Form Card */
        .sdv-em-prechat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 12px;
        }
        .sdv-em-prechat-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .sdv-em-prechat-sub {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 12px;
        }
        .sdv-em-form-group {
            margin-bottom: 10px;
        }
        .sdv-em-label {
            display: block;
            font-size: 11.5px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
        }
        .sdv-em-field {
            width: 100%;
            box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }
        .sdv-em-prechat-btn {
            width: 100%;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 9px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 4px;
        }
        .sdv-em-prechat-btn:hover {
            background: #1d4ed8;
        }
        .sdv-em-branding {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            padding-top: 6px;
        }
        .sdv-em-system-note {
            text-align: center;
            font-size: 12px;
            color: #64748b;
            background: rgba(0,0,0,0.03);
            border-radius: 6px;
            padding: 6px 10px;
            margin: 4px 0;
        }
    `;

    var styleEl = document.createElement('style');
    styleEl.innerHTML = css;
    document.head.appendChild(styleEl);

    // 4. Inject Markup
    var launcherEl = document.createElement('div');
    launcherEl.id = 'sdv-embed-launcher';
    launcherEl.setAttribute('role', 'button');
    launcherEl.setAttribute('aria-label', 'Open Live Support Chat');
    launcherEl.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';

    var containerEl = document.createElement('div');
    containerEl.id = 'sdv-embed-container';
    containerEl.innerHTML = `
        <div class="sdv-em-header">
            <div class="sdv-em-header-left">
                <div class="sdv-em-avatar">AI</div>
                <div>
                    <div class="sdv-em-title">Live Hosting Support</div>
                    <div class="sdv-em-status"><span class="sdv-em-status-dot"></span> Online &bull; AI Assistant</div>
                </div>
            </div>
            <div class="sdv-em-actions">
                <button type="button" class="sdv-em-btn" id="sdv-em-summon-btn" title="Request Live Agent" aria-label="Request Live Agent">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                </button>
                <button type="button" class="sdv-em-btn" id="sdv-em-close-btn" title="Minimize">&minus;</button>
            </div>
        </div>
        <div class="sdv-em-messages" id="sdv-em-msgs"></div>
        <div class="sdv-em-footer">
            <input type="text" class="sdv-em-input" id="sdv-em-input" placeholder="Type your message..." autocomplete="off" />
            <button type="button" class="sdv-em-send" id="sdv-em-send" title="Send message">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            </button>
        </div>
        <div class="sdv-em-branding">Powered by Sahdev AI Live Suite</div>
    `;

    document.body.appendChild(launcherEl);
    document.body.appendChild(containerEl);

    var msgsEl = document.getElementById('sdv-em-msgs');
    var inputEl = document.getElementById('sdv-em-input');
    var sendBtn = document.getElementById('sdv-em-send');
    var summonBtn = document.getElementById('sdv-em-summon-btn');
    var closeBtn = document.getElementById('sdv-em-close-btn');

    // 5. Render Pre-Chat Form if Not Identified
    function renderPreChatForm() {
        if (isIdentified) return;
        var existingCard = document.getElementById('sdv-em-prechat-card');
        if (existingCard) return;

        var card = document.createElement('div');
        card.id = 'sdv-em-prechat-card';
        card.className = 'sdv-em-prechat-card';
        card.innerHTML = `
            <div class="sdv-em-prechat-title">Welcome to Live Support 👋</div>
            <div class="sdv-em-prechat-sub">Please introduce yourself so our AI and live engineers can access your services & assist you promptly.</div>
            <form id="sdv-em-prechat-form">
                <div class="sdv-em-form-group">
                    <label class="sdv-em-label">Full Name *</label>
                    <input type="text" id="sdv-em-name-field" class="sdv-em-field" required placeholder="e.g. John Doe" value="${escapeHtml(visitorName)}" />
                </div>
                <div class="sdv-em-form-group">
                    <label class="sdv-em-label">Email Address *</label>
                    <input type="email" id="sdv-em-email-field" class="sdv-em-field" required placeholder="name@example.com" value="${escapeHtml(visitorEmail)}" />
                </div>
                <button type="submit" class="sdv-em-prechat-btn">Start Live Chat &rarr;</button>
            </form>
        `;
        msgsEl.appendChild(card);

        var form = document.getElementById('sdv-em-prechat-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var name = (document.getElementById('sdv-em-name-field').value || '').trim();
            var email = (document.getElementById('sdv-em-email-field').value || '').trim();
            if (!name || !email) return;

            visitorName = name;
            visitorEmail = email;
            sdvSet('sdv_embed_visitor_name', name);
            sdvSet('sdv_embed_visitor_email', email);
            isIdentified = true;

            card.remove();
            appendMsg('bot', 'Hi ' + name + '! How can we assist you with your hosting, domains, or billing today?');

            // Link email to backend session
            linkVisitorEmail(name, email);
        });
    }

    function linkVisitorEmail(name, email) {
        if (!sessionUuid) {
            initSession(function() {
                sendLinkEmailRequest(name, email);
            });
        } else {
            sendLinkEmailRequest(name, email);
        }
    }

    function sendLinkEmailRequest(name, email) {
        var fd = new FormData();
        fd.append('action', 'client_chat_link_email');
        fd.append('session_uuid', sessionUuid);
        fd.append('visitor_token', visitorToken);
        fd.append('name', name);
        fd.append('email', email);

        fetch(endpointUrl, {
            method: 'POST',
            body: fd,
            credentials: 'include'
        }).then(function(r){ return r.json(); }).then(function(d){
            if (d && d.client_id) {
                appendSystemNote('Linked to your WHMCS client account (' + (d.client_name || email) + '). Live telemetry enabled.');
            }
        }).catch(function(){});
    }

    // 6. Network Request Helpers
    function initSession(callback) {
        var fd = new FormData();
        fd.append('action', 'client_chat_init');
        fd.append('visitor_token', visitorToken);
        if (sessionUuid) fd.append('session_uuid', sessionUuid);
        fd.append('source_domain', window.location.hostname || 'external');
        fd.append('source_page', window.location.href || '');

        fetch(endpointUrl, {
            method: 'POST',
            body: fd,
            credentials: 'include'
        }).then(function(r){ return r.json(); }).then(function(d){
            if (d && d.session_uuid) {
                sessionUuid = d.session_uuid;
                sdvSet('sdv_embed_session_uuid', sessionUuid);
            }
            if (typeof callback === 'function') callback(d);
        }).catch(function(err){
            if (typeof callback === 'function') callback(null);
        });
    }

    function broadcastTypingPreview(text) {
        if (!sessionUuid) return;
        var fd = new FormData();
        fd.append('action', 'client_chat_typing');
        fd.append('session_uuid', sessionUuid);
        fd.append('visitor_token', visitorToken);
        fd.append('preview', text || '');

        fetch(endpointUrl, {
            method: 'POST',
            body: fd,
            credentials: 'include'
        }).catch(function(){});
    }

    function appendMsg(role, text, staffName, msgId) {
        if (!text) return null;
        var rawText = String(text).trim();
        if (!rawText) return null;

        if (msgId && document.getElementById('sdv-em-msg-' + msgId)) {
            return document.getElementById('sdv-em-msg-' + msgId);
        }

        // Deduplication against recent messages
        var norm = rawText.replace(/\s+/g, ' ').toLowerCase();
        var bubbles = msgsEl ? msgsEl.children : [];
        var start = Math.max(0, bubbles.length - 8);
        for (var i = bubbles.length - 1; i >= start; i--) {
            var b = bubbles[i];
            var bText = (b.getAttribute('data-msg-text') || b.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (bText && bText === norm) {
                if (msgId && !b.id) b.id = 'sdv-em-msg-' + msgId;
                return b;
            }
        }

        var msg = document.createElement('div');
        msg.className = 'sdv-em-msg ' + (role === 'user' ? 'sdv-em-msg-user' : (role === 'staff' ? 'sdv-em-msg-staff' : 'sdv-em-msg-bot'));
        msg.setAttribute('data-msg-text', rawText);
        if (msgId) msg.id = 'sdv-em-msg-' + msgId;

        if (role === 'staff') {
            var name = staffName || 'Support Agent';
            msg.innerHTML = '<div class="sdv-em-msg-staff-badge"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> ' + escapeHtml(name) + '</div>' + escapeHtml(rawText).replace(/\n/g, '<br/>');
        } else {
            msg.innerHTML = escapeHtml(rawText).replace(/\n/g, '<br/>');
        }
        msgsEl.appendChild(msg);
        msgsEl.scrollTop = msgsEl.scrollHeight;
        return msg;
    }

    function appendSystemNote(text) {
        var rawText = String(text || '').trim();
        if (!rawText) return;
        var norm = rawText.replace(/\s+/g, ' ').toLowerCase();
        var bubbles = msgsEl ? msgsEl.querySelectorAll('.sdv-em-system-note') : [];
        for (var i = bubbles.length - 1; i >= 0 && i >= bubbles.length - 3; i--) {
            if ((bubbles[i].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase() === norm) {
                return;
            }
        }
        var note = document.createElement('div');
        note.className = 'sdv-em-system-note';
        note.textContent = rawText;
        msgsEl.appendChild(note);
        msgsEl.scrollTop = msgsEl.scrollHeight;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // 7. Messaging Logic
    function sendMessage() {
        if (isSending) return;
        var text = (inputEl.value || '').trim();
        if (!text) return;

        if (!isIdentified) {
            renderPreChatForm();
            inputEl.focus();
            return;
        }

        isSending = true;
        inputEl.value = '';
        broadcastTypingPreview(''); // Clear typing peek

        appendMsg('user', text);

        var fd = new FormData();
        fd.append('action', 'client_chat_message');
        fd.append('visitor_token', visitorToken);
        fd.append('session_uuid', sessionUuid || '');
        fd.append('message', text);
        fd.append('visitor_name', visitorName);
        fd.append('visitor_email', visitorEmail);
        fd.append('source_domain', window.location.hostname || 'external');

        fetch(endpointUrl, {
            method: 'POST',
            body: fd,
            credentials: 'include'
        }).then(function(r){ return r.json(); }).then(function(d){
            isSending = false;
            if (d && d.session_uuid) {
                sessionUuid = d.session_uuid;
                sdvSet('sdv_embed_session_uuid', sessionUuid);
            }
            if (d && (d.is_takeover || d.status === 'taken_over')) {
                isStaffTakeover = true;
                startPolling();
                return;
            }
            if (d && d.reply) {
                appendMsg('bot', d.reply, null, d.message_id || 0);
            }
        }).catch(function(err){
            isSending = false;
            appendMsg('bot', 'Thank you for your message! Our team has received your inquiry.');
        });
    }

    // 8. Human Summon Trigger
    function summonHuman() {
        if (!isIdentified) {
            renderPreChatForm();
            appendSystemNote('Please provide your name & email so our support staff can review your account services.');
            return;
        }

        var fd = new FormData();
        fd.append('action', 'client_chat_summon');
        fd.append('visitor_token', visitorToken);
        fd.append('session_uuid', sessionUuid || '');
        fd.append('reason', 'Client requested live staff assistance via external embed');

        fetch(endpointUrl, {
            method: 'POST',
            body: fd,
            credentials: 'include'
        }).then(function(r){ return r.json(); }).then(function(d){
            appendSystemNote('🔔 A live support agent has been notified and will join shortly. Feel free to describe your inquiry in the interim.');
        }).catch(function(){});
    }

    // 9. Real-time Live Polling Engine
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function() {
            if (!isOpen || !sessionUuid) return;
            var fd = new FormData();
            fd.append('action', 'client_chat_poll');
            fd.append('session_uuid', sessionUuid);
            fd.append('visitor_token', visitorToken);

            fetch(endpointUrl, {
                method: 'POST',
                body: fd,
                credentials: 'include'
            }).then(function(r){ return r.json(); }).then(function(d){
                if (!d || !d.success) return;
                // Handle new incoming staff messages
                if (d.messages && d.messages.length > 0) {
                    d.messages.forEach(function(m) {
                        if (m.sender_type === 'staff') {
                            appendMsg('staff', m.message_text, m.sender_name || 'Support Agent', m.id);
                        } else if (m.sender_type === 'system') {
                            appendSystemNote(m.message_text);
                        }
                    });
                }
                if (d.new_messages && d.new_messages.length > 0) {
                    d.new_messages.forEach(function(m) {
                        if (m.sender_role === 'staff' || m.is_staff) {
                            appendMsg('staff', m.message_content || m.message_text, m.admin_name || m.sender_name || 'Support Agent', m.id);
                        }
                    });
                }
                // Handle takeover status change
                if (d.status === 'live_takeover' || (d.takeover_admin_id && d.takeover_admin_id > 0)) {
                    isStaffTakeover = true;
                } else if (isStaffTakeover && d.status !== 'live_takeover') {
                    isStaffTakeover = false;
                }
            }).catch(function(){});
        }, 3000);
    }

    // 10. Event Listeners & Initialization
    function toggleChat(open) {
        isOpen = (typeof open === 'boolean') ? open : !isOpen;
        if (isOpen) {
            containerEl.classList.add('sdv-embed-open');
            if (!sessionUuid) {
                initSession(function(){
                    if (!isIdentified) renderPreChatForm();
                    else appendMsg('bot', 'Hi ' + (visitorName || 'there') + '! How can we help you today?');
                });
            } else {
                if (!isIdentified) renderPreChatForm();
                else if (msgsEl.children.length === 0) {
                    appendMsg('bot', 'Hi ' + (visitorName || 'there') + '! How can we help you today?');
                }
            }
            startPolling();
            setTimeout(function(){ inputEl.focus(); }, 100);
        } else {
            containerEl.classList.remove('sdv-embed-open');
            if (pollTimer) clearInterval(pollTimer);
        }
    }

    launcherEl.addEventListener('click', function(e) {
        e.preventDefault();
        toggleChat();
    });

    closeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        toggleChat(false);
    });

    summonBtn.addEventListener('click', function(e) {
        e.preventDefault();
        summonHuman();
    });

    sendBtn.addEventListener('click', function(e) {
        e.preventDefault();
        sendMessage();
    });

    inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });

    // 400ms Keystroke Sneak-Peek Debounce
    inputEl.addEventListener('input', function() {
        if (isTypingDebounceTimer) clearTimeout(isTypingDebounceTimer);
        isTypingDebounceTimer = setTimeout(function() {
            broadcastTypingPreview(inputEl.value || '');
        }, 400);
    });

})();
