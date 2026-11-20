/* Private Autopilot notes use WHMCS's inline reply controls, with note-only persistence. */
(function ($) {
    'use strict';
    var config = window.sahdevAutopilotDraftConfig;
    if (!$ || !config || window.sahdevAutopilotDraftsLoaded) return;
    window.sahdevAutopilotDraftsLoaded = true;
    var marker = '[Sahdev Autopilot Draft] Review before sending - Draft Mode is ON';

    function safeHtml(html) {
        var parsed = new DOMParser().parseFromString(String(html || ''), 'text/html');
        var allowed = /^(P|BR|STRONG|B|EM|I|U|S|DEL|A|UL|OL|LI|BLOCKQUOTE|PRE|CODE|H[1-6]|HR|TABLE|THEAD|TBODY|TR|TH|TD)$/;
        function clean(parent) {
            Array.prototype.slice.call(parent.children).forEach(function (node) {
                if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|SVG|MATH|FORM|INPUT|IMG)$/i.test(node.tagName)) {
                    node.remove();
                    return;
                }
                clean(node);
                if (!allowed.test(node.tagName)) {
                    node.replaceWith.apply(node, Array.prototype.slice.call(node.childNodes));
                    return;
                }
                Array.prototype.slice.call(node.attributes).forEach(function (attr) {
                    if (node.tagName === 'A' && attr.name === 'href'
                        && /^(https?:\/\/|mailto:|\/(?!\/)|#)/i.test(attr.value.trim())) return;
                    if (node.tagName === 'OL' && attr.name === 'start' && /^\d+$/.test(attr.value)) return;
                    node.removeAttribute(attr.name);
                });
            });
        }
        clean(parsed.body);
        return parsed.body.innerHTML;
    }

    function editorSnapshot() {
        var field = document.getElementById('replymessage');
        if (!field) throw new Error('The WHMCS ticket reply editor is not available.');
        var tiny = window.tinymce || window.tinyMCE;
        var rich = tiny && typeof tiny.get === 'function' ? tiny.get(field.id) : null;
        // Never use activeEditor: it may be the private-note editor.
        if (rich && typeof rich.isHidden === 'function' && rich.isHidden()) rich = null;
        var markdown = !rich ? $(field).data('markdown') : null;
        return {field: field, rich: rich, markdown: markdown,
            value: rich ? rich.getContent() : markdown && markdown.getContent ? markdown.getContent() : field.value};
    }

    function insertDraft(draft, before) {
        var now = editorSnapshot();
        if (now.field !== before.field || now.rich !== before.rich || now.markdown !== before.markdown || now.value !== before.value) {
            throw new Error('The reply editor changed while the draft was loading. Click Insert to Editor again to keep your latest edits.');
        }
        var next = before.rich
            ? safeHtml(draft.html) + (before.value ? '<p><br></p>' + before.value : '')
            : draft.body + (before.value ? '\n\n' + before.value.replace(/^(?:\r?\n)+/, '') : '');
        // Prepend to the current reply, which already contains WHMCS's signature.
        // Do not reconstruct it, strip Markdown, or add a second signature.
        if (before.rich) {
            var write = function () { before.rich.setContent(next); };
            if (before.rich.undoManager) before.rich.undoManager.transact(write); else write();
            if (before.rich.save) before.rich.save();
            if (before.rich.fire) before.rich.fire('change');
        } else {
            if (before.markdown && before.markdown.setContent) before.markdown.setContent(next);
            before.field.value = next;
        }
        before.field.dispatchEvent(new Event('input', {bubbles: true}));
        before.field.dispatchEvent(new Event('change', {bubbles: true}));
        var pane = $(before.field).closest('.tab-pane');
        var tab = pane.length ? $('a[data-toggle="tab"], a[data-bs-toggle="tab"]').filter(function () {
            return this.getAttribute('href') === '#' + pane.attr('id');
        }).first() : $();
        if (tab.length) tab.trigger('click');
        if (before.rich && before.rich.focus) before.rich.focus(); else before.field.focus();
        var target = $(before.field).closest('form').get(0) || before.field;
        if (target.scrollIntoView) target.scrollIntoView({behavior: 'smooth', block: 'start'});
        return editorSnapshot().value;
    }

    function request(noteId, action, extra) {
        var token = $('#sahdev-ai-panel input[name="token"]').val()
            || $('#frmAddTicketReply input[name="token"]').val()
            || $('input[name="token"]').first().val() || window.csrfToken;
        return $.ajax({url: config.ajaxUrl, type: 'POST', dataType: 'json',
            data: $.extend({action: action, ticket_id: config.ticketId, note_id: noteId, token: token}, extra || {})
        }).then(function (response) {
            if (!response || response.status !== 'success' || !response.data || typeof response.data.body !== 'string') {
                throw new Error(response && response.message || 'Unable to load the draft. Please try again.');
            }
            return response.data;
        });
    }

    function errorText(error) {
        return error && error.responseJSON && error.responseJSON.message
            || error && error.message || 'Unable to load or save the draft. Please try again.';
    }

    function nativeButton(handler, label, fallbackClass) {
        // Clone the theme's actual control (input in Blend, button in Iris).
        var template = $('#ticketreplies [onclick]').filter(function () {
            return (this.getAttribute('onclick') || '').indexOf(handler + '(') !== -1;
        }).first();
        var button = template.length ? template.clone(false) : $('<button>').text(label).attr('class', fallbackClass);
        button.removeAttr('id name onclick style disabled').attr('type', 'button').prop('disabled', false);
        return button;
    }

    function enhance(card, noteId) {
        var message = card.find('.message').first();
        if (!message.length || message.text().trim().indexOf(marker) !== 0 || card.data('sahdev-draft-ready')) return;
        var groups = card.find('[class]').filter(function () { return /(?:^|\s)editbtns[rn]\d+(?:\s|$)/.test(this.className); });
        var normal = groups.first(), editing = groups.eq(1);
        if (!normal.length || !editing.length) return;
        card.data('sahdev-draft-ready', true);

        // WHMCS assigns r<ID> to notes too. Isolate this note from a public
        // reply with the same numeric ID before reusing the native controls.
        groups.each(function () { this.className = this.className.replace(/editbtns[rn]\d+/g, 'editbtnssahdevnote' + noteId); });
        var wrap = message.parent();
        if (/^content[rn]\d+$/.test(wrap.attr('id') || '')) wrap.attr('id', 'contentsahdevnote' + noteId);

        var edit = nativeButton('editTicket', 'Edit', 'btn btn-xs btn-small btn-default').addClass('sahdev-draft-edit');
        var insert = edit.clone(false).addClass('sahdev-draft-insert').removeClass('sahdev-draft-edit');
        if (insert.is('input')) insert.val('Insert to Editor'); else insert.text('Insert to Editor');
        edit.css('margin-right', '5px'); insert.css('margin-right', '5px');
        normal.prepend(insert).prepend(edit);
        var save = editing.find('[onclick*="editTicketSave("]').first();
        var cancel = editing.find('[onclick*="editTicketCancel("]').first();
        if (!save.length) { save = nativeButton('editTicketSave', 'Save', 'btn btn-xs btn-small btn-success'); editing.append(save); }
        if (!cancel.length) { cancel = nativeButton('editTicketCancel', 'Cancel', 'btn btn-xs btn-small btn-default'); editing.append(cancel); }
        save.removeAttr('onclick').addClass('sahdev-draft-save');
        cancel.removeAttr('onclick').addClass('sahdev-draft-cancel');
        var status = $('<div role="status" aria-live="polite" class="sahdev-draft-status">').hide().appendTo(wrap);
        var field = null, revision = '', busy = false, lastInserted = null;

        function feedback(text, failed) {
            status.text(text).toggleClass('text-danger', !!failed).toggleClass('text-success', !failed).toggle(!!text);
        }
        function loading(value) {
            busy = value;
            edit.add(insert).add(save).add(cancel).prop('disabled', value);
            if (field) field.prop('disabled', value);
            groups.find('.saveSpinner').toggle(value);
        }
        function finishEdit() {
            if (field) field.remove();
            field = null;
            message.show(); editing.hide(); normal.show();
        }
        edit.on('click', function (event) {
            event.preventDefault();
            if (busy || field) return;
            loading(true); feedback('Loading draft…');
            request(noteId, 'get_autopilot_draft').then(function (draft) {
                revision = draft.revision;
                field = $('<textarea rows="15" class="form-control sahdev-draft-text">')
                    .attr({id: 'sahdev-draft-edit-' + noteId, 'aria-label': 'Edit Sahdev Autopilot draft'})
                    .val(draft.body).insertAfter(message);
                message.hide(); normal.hide(); editing.show(); feedback('');
                field.trigger('focus');
            }).catch(function (error) { feedback(errorText(error), true); }).always(function () { loading(false); });
        });
        cancel.on('click', function (event) {
            event.preventDefault();
            if (busy) return;
            finishEdit(); feedback('');
        });
        save.on('click', function (event) {
            event.preventDefault();
            if (busy || !field) return;
            if (!field.val().trim()) { feedback('The draft cannot be empty.', true); return; }
            loading(true); feedback('Saving draft…');
            request(noteId, 'save_autopilot_draft', {body: field.val(), revision: revision}).then(function (draft) {
                message.empty().append($('<p>').text(marker), $('<hr>'), safeHtml(draft.html));
                lastInserted = null;
                finishEdit(); feedback('Draft saved.');
            }).catch(function (error) { feedback(errorText(error), true); }).always(function () { loading(false); });
        });
        insert.on('click', function (event) {
            event.preventDefault();
            if (busy || field) return;
            var snapshot;
            try { snapshot = editorSnapshot(); } catch (error) { feedback(errorText(error), true); return; }
            loading(true); feedback('Loading draft…');
            request(noteId, 'get_autopilot_draft').then(function (draft) {
                if (lastInserted && lastInserted.body === draft.body && lastInserted.value === snapshot.value) {
                    feedback('Draft is already in the reply editor.'); return;
                }
                lastInserted = {body: draft.body, value: insertDraft(draft, snapshot)};
                feedback('Draft inserted above the existing reply and signature.');
            }).catch(function (error) { feedback(errorText(error), true); }).always(function () { loading(false); });
        });
    }

    function scan() {
        $('#ticketreplies input[name="nids[]"]').each(function () {
            var id = Number(this.value), card = $(this).closest('.reply');
            if (id > 0 && card.length) enhance(card, id);
        });
    }
    $(function () {
        scan();
        var root = document.getElementById('ticketreplies');
        if (root && window.MutationObserver) {
            var pending = false;
            new MutationObserver(function () {
                if (pending) return;
                pending = true;
                window.setTimeout(function () { pending = false; scan(); }, 0);
            }).observe(root, {childList: true, subtree: true});
        }
    });
})(window.jQuery);
