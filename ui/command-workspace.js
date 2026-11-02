/* Recompose the real controls; preserve IDs and delegated handlers. No duplicate editor. */
(function(){
    'use strict';
    var panel=document.getElementById('sahdev-ai-panel');
    if(!panel||!panel.querySelector('.sdv-case-workspace'))return;
    panel.classList.add('sdv-workbench');
    var permitted=window.sahdevWorkspaceFeatures||{};
    ['tools','summary','kb','memory'].forEach(function(key){
        if(permitted[key]!==false)return;
        var pane=document.getElementById('rm-pane-'+key);if(pane){pane.replaceChildren();header(pane,'WORKSPACE UNAVAILABLE','This feature is disabled','Check My Sahdev Preferences or ask an administrator to review your access.');pane.dataset.sdvDisabled='true';}
    });
    function node(tag,text,cls,parent){var el=document.createElement(tag);if(text)el.textContent=text;if(cls)el.className=cls;if(parent)parent.appendChild(el);return el;}
    function move(id,parent){var el=document.getElementById(id);if(el)parent.appendChild(el);return el;}
    function header(parent,eyebrow,title,description){var h=node('header',null,'sdv-desk-heading',parent);node('small',eyebrow,'sdv-eyebrow',h);node('h2',title,null,h);node('p',description,null,h);return h;}
    function card(parent,title,description){var c=node('section',null,'sdv-desk-card',parent);node('h3',title,null,c);if(description)node('p',description,'sdv-desk-hint',c);return c;}
    function actions(parent){return node('div',null,'sdv-desk-actions',parent);}
    function field(id,label,parent){var el=document.getElementById(id);if(!el)return;var wrap=node('div',null,'sdv-desk-field',parent);var l=node('label',label,null,wrap);l.htmlFor=id;wrap.appendChild(el);return wrap;}
    function readingEmpty(id,parent,title,description){
        var display=move(id,parent);if(!display)return;
        display.classList.add('sdv-reading');
        var empty=node('div',null,'sdv-empty',parent);node('strong',title,null,empty);node('p',description,null,empty);
        function sync(){empty.hidden=!!display.textContent.trim()&&display.style.display!=='none';}
        new MutationObserver(sync).observe(display,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['style']});sync();
    }
    // Compact editor action bar replaces the legacy dashed panel and its old title.
    var polish=panel.querySelector('.sdv-case-polish');
    if(polish){
        var held=document.createDocumentFragment();
        ['btn-sahdev-rewrite','btn-sahdev-score-draft','btn-sahdev-save-canned','btn-sahdev-save-kb','sahdev-rewrite-loading','sahdev-canned-loading','sahdev-rewrite-error','sahdev-rewrite-status'].forEach(function(id){move(id,held);});
        polish.replaceChildren();polish.removeAttribute('style');
        var copy=node('div',null,'sdv-polish-copy',polish);node('small','WHMCS REPLY EDITOR','sdv-eyebrow',copy);node('h3','Refine your draft',null,copy);node('p','Polish the text above your signature, then review it in the ticket editor.',null,copy);
        var toolbar=actions(polish);['btn-sahdev-rewrite','btn-sahdev-score-draft','btn-sahdev-save-canned','btn-sahdev-save-kb'].forEach(function(id){var el=held.querySelector('#'+id);if(el)toolbar.appendChild(el);});
        polish.appendChild(held);
    }
    var form=document.getElementById('sahdev-ai-form');
    var generationStatus=node('p',null,'sdv-inline-progress',form);generationStatus.id='sdv-generation-status';generationStatus.hidden=true;generationStatus.setAttribute('role','status');
    // Tools: ticket recipe, manual operation, and evidence editing have separate work areas.
    var tools=document.getElementById('rm-pane-tools');
    if(tools&&!tools.dataset.sdvDisabled){
        var old=Array.from(tools.childNodes);header(tools,'TOOLS / READ-ONLY DIAGNOSTICS','Diagnostics desk','Run a ticket check, inspect its evidence, and choose what to pass into your reply.');
        var grid=node('div',null,'sdv-desk-grid sdv-tools-grid',tools),left=node('div',null,'sdv-desk-stack',grid);
        var auto=card(left,'Ticket checks','Use the configured diagnostic workflow for this ticket.');
        var buttons=actions(auto);move('btn-sahdev-run-tools',buttons);move('btn-sahdev-rerun-tools',buttons);
        var manual=card(left,'Run a specific check','Choose an operation and review its target before running.');
        field('sahdev-manual-tool-op','Diagnostic operation',manual);
        var fields=node('div',null,'sdv-field-grid',manual);
        [['sahdev-tool-domain','Domain or URL'],['sahdev-tool-ip','IP address'],['sahdev-tool-email','Email or account'],['sahdev-tool-extra','Record type / extra']].forEach(function(pair){field(pair[0],pair[1],fields);});
        var advanced=card(left,'Request parameters','These fields remain editable for custom read-only checks.');
        field('sahdev-manual-path-params','Path parameters · JSON',advanced);field('sahdev-manual-query','Query parameters · JSON',advanced);
        move('btn-sahdev-manual-tool-run',actions(manual));
        var evidence=card(grid,'Evidence workspace','Inspect and edit the evidence before appending it to Technical Context.');evidence.classList.add('sdv-evidence-desk');
        field('sahdev-tools-view-mode','Evidence view',evidence);field('sahdev-tools-output-edit','Latest tool evidence',evidence);
        move('btn-sahdev-append-tools-context',actions(evidence));move('sahdev-tools-meta',evidence);
        var status=move('sahdev-tools-status',tools);if(status){status.classList.add('sdv-operation-status');status.setAttribute('role','status');}
        // Keep the known container ID without retaining the obsolete nested layout.
        var container=document.getElementById('sahdev-tools-panel');if(container){container.replaceChildren();container.hidden=true;tools.appendChild(container);}
        old.forEach(function(el){if(el.parentNode===tools&&el!==container&&el!==status)el.remove();});
    }
    // Summary: readable document plus an inline control rail.
    var summary=document.getElementById('rm-pane-summary');
    if(summary&&!summary.dataset.sdvDisabled){
        var oldSummary=Array.from(summary.childNodes);header(summary,'SUMMARY / THIS TICKET','The case, condensed','Review the saved summary before using it as context for your next reply.');
        var sg=node('div',null,'sdv-desk-grid',summary),paper=card(sg,'Ticket brief','Saved summary for this conversation.'),rail=card(sg,'Summary controls','Generate after material changes so the brief stays useful.');
        readingEmpty('sahdev-summary-display',paper,'No saved summary yet','Generate a brief to bring the conversation into one readable view.');
        move('sahdev-summary-exists-badge',paper);move('sahdev-summary-active-badge',paper);
        move('btn-sahdev-generate-summary',actions(rail));move('btn-sahdev-delete-summary',actions(rail));move('sahdev-summary-meta',rail);
        var link=node('button','Review reply context options','btn btn-default',rail);link.type='button';link.onclick=function(){document.getElementById('rm-tab-analysis').click();document.getElementById('sahdev_use_summary').focus();};
        node('p','The Feed Summary option in Analysis controls its use when drafting.','sdv-desk-hint',rail);
        move('sahdev-summary-loading',paper);move('sahdev-summary-error',paper);oldSummary.forEach(function(el){el.remove();});
    }
    // Knowledge: searchable library with a full inline preview; insert remains explicit.
    var kb=document.getElementById('rm-pane-kb');
    if(kb&&!kb.dataset.sdvDisabled){
        var oldKb=Array.from(kb.childNodes);header(kb,'KNOWLEDGE / REUSABLE ANSWERS','Find a starting point','Search saved templates, predefined replies and knowledge base articles. Preview before inserting.');
        var search=card(kb,'Search your library');field('sahdev-canned-search','Search by topic or phrase',search);
        var kg=node('div',null,'sdv-desk-grid sdv-library-grid',kb),list=card(kg,'Search results'),preview=card(kg,'Content preview','Select Preview on a result to read its full content here.');
        move('sahdev-canned-results',list);preview.id='sdv-kb-preview';var previewText=node('div','No result selected.','sdv-reading sdv-empty',preview);previewText.id='sdv-kb-preview-text';
        $(kb).on('click','.sdv-preview-canned',function(){
            try{
                var content=decodeURIComponent(escape(atob(this.dataset.content)));
                // Parse inertly and display only text: no scripts, remote assets or HTML execution.
                var parsed=new DOMParser().parseFromString(content.replace(/<(?:br\s*\/?|\/p|\/div|\/li)>/gi,'\n'),'text/html');
                parsed.querySelectorAll('script,style,iframe,object').forEach(function(el){el.remove();});
                previewText.textContent=parsed.body.textContent.trim();previewText.classList.remove('sdv-empty');preview.querySelector('h3').textContent=this.dataset.title||'Content preview';
            }catch(e){previewText.textContent='This result could not be decoded. Search again.';}
        });
        $(kb).on('input','#sahdev-canned-search',function(){previewText.textContent='No result selected.';previewText.classList.add('sdv-empty');preview.querySelector('h3').textContent='Content preview';});
        oldKb.forEach(function(el){el.remove();});
    }
    var memory=document.getElementById('rm-pane-memory');
    if(memory&&!memory.dataset.sdvDisabled){
        var oldMemory=Array.from(memory.childNodes);header(memory,'MEMORY / CLIENT HISTORY','Continuity across tickets','Review recurring issues and saved client context separately from the current case.');
        var mg=node('div',null,'sdv-desk-grid',memory),history=card(mg,'Client context','Historical context can become stale. Verify it against the current ticket.'),controls=card(mg,'Memory controls','Choose how many past tickets to analyze.');
        readingEmpty('sahdev-history-display',history,'No client memory saved','Generate memory when previous support history would help this case.');
        field('sahdev_history_limit','Past tickets to analyze · 1–25',controls);
        move('btn-sahdev-generate-history',actions(controls));move('btn-sahdev-delete-history',actions(controls));
        var include=document.getElementById('sahdev_include_history');if(include){var label=include.closest('label');if(label){label.classList.add('sdv-option-line');controls.appendChild(label);}}
        move('sahdev-history-exists-badge',history);move('sahdev-history-active-badge',history);move('sahdev-history-meta',controls);move('sahdev-history-loading',history);move('sahdev-history-error',history);
        oldMemory.forEach(function(el){el.remove();});
    }
    ['sahdev-summary-error','sahdev-history-error','sahdev-rewrite-error'].forEach(function(id){var error=document.getElementById(id);if(error)error.setAttribute('role','alert');});
})();
