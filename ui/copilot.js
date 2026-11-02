(function () {
    'use strict';
    if (window.SahdevCopilot) return;
    const node = (tag, text, cls) => {
        const el = document.createElement(tag);
        if (text != null) el.textContent = String(text);
        if (cls) el.className = cls;
        return el;
    };
    function safeUrl(value) {
        if (typeof value !== 'string' || /[\x00-\x20\\]/.test(value) || value.startsWith('//')) return null;
        try {
            const url = new URL(value, location.href);
            return /^https?:$/.test(url.protocol) ? url.href : null;
        } catch (_) { return null; }
    }
    function link(text, url) {
        const href = safeUrl(url);
        const el = node(href ? 'a' : 'span', text);
        if (href) { el.href = href; el.target = '_blank'; el.rel = 'noopener noreferrer'; }
        return el;
    }
    // Markdown is built as DOM nodes. Raw HTML and unsafe URLs are never evaluated.
    function inline(parent, text, depth = 0) {
        if (depth > 8) { parent.appendChild(document.createTextNode(text)); return; }
        const re = /`([^`\n]+)`|\[([^\]\n]+)\]\(([^\s)]+)\)|\*\*([^*\n]+)\*\*|__([^_\n]+)__|\*([^*\n]+)\*/g;
        let last = 0, match;
        while ((match = re.exec(text))) {
            parent.appendChild(document.createTextNode(text.slice(last, match.index)));
            let el;
            if (match[1]) el = node('code', match[1]);
            else if (match[2]) el = link(match[2], match[3]);
            else { el = node(match[6] ? 'em' : 'strong'); inline(el, match[4] || match[5] || match[6], depth + 1); }
            parent.appendChild(el); last = re.lastIndex;
        }
        parent.appendChild(document.createTextNode(text.slice(last)));
    }
    function markdown(parent, value) {
        const root = node('div', null, 'sdv-copilot-markdown'); parent.appendChild(root);
        const lines = String(value || '').replace(/\r\n?/g, '\n').split('\n');
        const cells = line => line.trim().replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map(v => v.trim().replace(/\\\|/g, '|'));
        const separator = line => /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line || '');
        for (let i = 0; i < lines.length;) {
            const line = lines[i];
            if (!line.trim()) { i++; continue; }
            if (/^\s*```/.test(line)) {
                const code = []; i++;
                while (i < lines.length && !/^\s*```/.test(lines[i])) code.push(lines[i++]);
                i++; const pre = node('pre'); pre.appendChild(node('code', code.join('\n'))); root.appendChild(pre); continue;
            }
            if (i + 1 < lines.length && line.includes('|') && separator(lines[i + 1])) {
                const wrap = node('div', null, 'sdv-copilot-table-scroll'); const table = node('table');
                const header = node('tr'); cells(line).forEach(v => { const th = node('th'); inline(th, v); header.appendChild(th); });
                const head = node('thead'); head.appendChild(header); table.appendChild(head); const body = node('tbody'); i += 2;
                while (i < lines.length && lines[i].trim() && lines[i].includes('|')) {
                    const tr = node('tr'); cells(lines[i++]).forEach(v => { const td = node('td'); inline(td, v); tr.appendChild(td); }); body.appendChild(tr);
                }
                table.appendChild(body); wrap.appendChild(table); root.appendChild(wrap); continue;
            }
            const heading = /^(#{1,6})\s+(.+)$/.exec(line);
            if (heading) { const el = node('h' + Math.min(6, heading[1].length + 1)); inline(el, heading[2]); root.appendChild(el); i++; continue; }
            const list = /^\s*([-*+] |\d+[.)] )(.+)$/.exec(line);
            if (list) {
                const ordered = /^\d/.test(list[1]); const el = node(ordered ? 'ol' : 'ul');
                const pattern = ordered ? /^\s*\d+[.)] (.+)$/ : /^\s*[-*+] (.+)$/;
                let item;
                while (i < lines.length && (item = pattern.exec(lines[i]))) { const li = node('li'); inline(li, item[1]); el.appendChild(li); i++; }
                root.appendChild(el); continue;
            }
            const el = node(/^>\s?/.test(line) ? 'blockquote' : 'p');
            inline(el, line.replace(/^>\s?/, '')); root.appendChild(el); i++;
        }
        return root;
    }
    function records(parent, card, options) {
        const root = node('section', null, 'sdv-copilot-records');
        root.appendChild(node('h4', card.title)); root.appendChild(node('p', card.subtitle, 'sdv-copilot-meta'));
        (card.links || []).forEach(item => root.appendChild(link(item.text, item.url)));
        const wrap = node('div', null, 'sdv-copilot-table-scroll'); const table = node('table');
        const head = node('thead'); const tr = node('tr');
        (card.columns || []).forEach(col => tr.appendChild(node('th', col.replace(/_/g, ' ')))); head.appendChild(tr); table.appendChild(head);
        const hasDetails = (card.rows || []).some(row => row.details && Object.keys(row.details).length);
        if (hasDetails) tr.appendChild(node('th', 'Details'));
        const body = node('tbody');
        (card.rows || []).forEach(row => {
            const tr = node('tr'); (row.cells || []).forEach(cell => {
                const td = node('td'); const content = link(cell.text, cell.url);
                if (cell.badge) content.classList.add('sdv-copilot-state'); td.appendChild(content); tr.appendChild(td);
            });
            if (hasDetails) {
                const td = node('td');
                if (row.details && Object.keys(row.details).length) {
                    const details = node('details'); details.appendChild(node('summary', 'More fields'));
                    const dl = node('dl'); Object.keys(row.details).forEach(field => { dl.appendChild(node('dt', field)); dl.appendChild(node('dd', row.details[field])); });
                    details.appendChild(dl); td.appendChild(details);
                }
                tr.appendChild(td);
            }
            body.appendChild(tr);
        });
        table.appendChild(body); wrap.appendChild(table); root.appendChild(wrap);
        if (!card.rows || !card.rows.length) root.appendChild(node('p', 'No matching records in your permitted scope.'));
        if (card.next_prompt && options.next) {
            const btn = node('button', 'Next page', 'btn btn-default btn-sm'); btn.type = 'button';
            btn.onclick = () => options.next(card.next_prompt); root.appendChild(btn);
        }
        parent.appendChild(root); return root;
    }
    function changes(parent, plan) {
        (plan.changes || []).forEach(change => {
            parent.appendChild(node('h5', change.kind.toUpperCase() + ' · ' + change.label));
            const wrap = node('div', null, 'sdv-copilot-table-scroll'); const table = node('table', null, 'diff-table');
            const head = node('thead'); const title = node('tr'); ['Field', 'Before', 'After'].forEach(v => title.appendChild(node('th', v))); head.appendChild(title); table.appendChild(head);
            const body = node('tbody');
            const before = change.before || {}, after = change.after || {};
            [...new Set([...Object.keys(before), ...Object.keys(after)])].forEach(key => {
                const row = node('tr'); row.appendChild(node('th', key));
                [before, after].forEach(values => row.appendChild(node('td', Object.prototype.hasOwnProperty.call(values, key) ? (values[key] === null ? '(null)' : values[key]) : '(absent)')));
                body.appendChild(row);
            });
            table.appendChild(body); wrap.appendChild(table); parent.appendChild(wrap);
            if (change.kind === 'create') parent.appendChild(node('p', 'The database assigns the new record ID on execution. The exact ID is saved for rollback.', 'sdv-copilot-meta'));
        });
    }
    function action(parent, card, options) {
        const root = node('section', null, 'action-card sdv-action-proposal'); parent.appendChild(root);
        root.appendChild(node('h4', card.title || 'Action plan'));
        root.appendChild(node('p', card.description || ''));
        const target = node('p', 'Target: '); target.appendChild(link(card.target || 'Record', card.plan && card.plan.target_url)); root.appendChild(target);
        root.appendChild(node('p', 'Prepared for admin #' + (card.admin_id || '—') + ' · ' + (card.created_at || ''), 'sdv-copilot-meta'));
        root.appendChild(node('p', 'Proposal: ' + (card.proposal_id || 'legacy') + ' · Expires: ' + (card.expires_at || 'unavailable'), 'sdv-copilot-meta'));
        if (card.plan) changes(root, card.plan);
        if (card.plan && card.plan.currency_id) root.appendChild(node('p', 'Amounts are in client currency ID #' + card.plan.currency_id + '. Open the client profile to verify its currency.'));
        root.appendChild(node('p', card.rollback_details || 'This older proposal must be regenerated to obtain a verified rollback plan.', 'sdv-copilot-meta'));
        const status = node('p', 'Status: ' + (card.status || 'legacy'), 'sdv-copilot-action-status'); status.setAttribute('role', 'status'); root.appendChild(status);
        const footer = node('div', null, 'sdv-copilot-actions'); root.appendChild(footer);
        root.appendChild(link('Open action and rollback log', 'addonmodules.php?module=sahdev&action=admin_copilot&subtab=journal'));
        function addRollback(id) {
            if (!options.rollback) return;
            const btn = node('button', 'Rollback · Journal #' + id, 'btn btn-danger btn-sm sdv-btn-rollback'); btn.type = 'button'; footer.appendChild(btn);
            btn.onclick = async () => {
                if (btn.disabled || !window.confirm('Restore every saved database change in this plan? Later conflicting changes will prevent rollback.')) return;
                btn.disabled = true;
                try {
                    const result = await options.rollback(id);
                    if (!result.success && result.status !== 'success') throw new Error(result.error || result.message || 'Rollback failed.');
                    status.textContent = result.message || 'Rolled back.'; btn.remove();
                } catch (error) { status.textContent = error.message; btn.disabled = false; }
            };
        }
        if (card.status === 'executed' && card.journal_id) addRollback(card.journal_id);
        if (card.status !== 'pending' || !card.can_execute || !options.execute) return root;
        let password;
        if (card.requires_password) {
            password = node('input', null, 'form-control copilot-admin-password sdv-tier3-pwd'); password.type = 'password'; password.autocomplete = 'current-password'; password.placeholder = 'Administrator password for deletion'; password.setAttribute('aria-label', password.placeholder); root.insertBefore(password, footer);
        }
        const btn = node('button', 'Confirm & Execute', 'btn btn-success btn-sm copilot-confirm-action sdv-btn-confirm'); btn.type = 'button'; footer.appendChild(btn);
        const dismiss = node('button', 'Dismiss', 'btn btn-default btn-sm sdv-btn-dismiss'); dismiss.type = 'button'; dismiss.onclick = () => root.remove(); footer.appendChild(dismiss);
        btn.onclick = async () => {
            if (btn.disabled) return;
            if (password && !password.value) { status.textContent = 'Administrator password is required for deletion.'; password.focus(); return; }
            btn.disabled = true; dismiss.disabled = true; status.textContent = 'Executing the saved plan…';
            const secret = password ? password.value : ''; if (password) password.value = '';
            try {
                const result = await options.execute(card, secret);
                if (!result.success && result.status !== 'success') throw new Error(result.error || result.message || 'Execution failed.');
                status.textContent = (result.message || 'Executed.') + ' Journal #' + result.journal_id;
                if (password) password.remove(); btn.remove(); dismiss.remove();
                if (result.can_rollback) addRollback(result.journal_id);
            } catch (error) { status.textContent = error.message; btn.disabled = false; dismiss.disabled = false; }
        };
        return root;
    }
    function message(parent, payload, options = {}) {
        markdown(parent, payload.reply || payload.error || '');
        (payload.result_cards || []).forEach(card => records(parent, card, options));
        (payload.action_cards || []).forEach(card => action(parent, card, options));
    }
    window.SahdevCopilot = { markdown, records, action, message, changes, safeUrl };
})();
