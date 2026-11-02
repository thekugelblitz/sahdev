(function () {
    'use strict';
    var panel=document.getElementById('sahdev-ai-panel'), pane=document.getElementById('rm-pane-analysis');
    if(!panel||!pane)return;
    var names=['case_timeline','timeline_plus','timeline_lanes','timeline_ledger','timeline_split','timeline_ribbon','timeline_journal','timeline_milestones','timeline_map'];
    var mode=names.find(function(n){return panel.classList.contains('sdv-layout-'+n);});if(!mode)return;
    pane.classList.add('sdv-case-workspace');
    // Move existing controls, retaining IDs, event handlers and feature flags.
    var form=document.getElementById('sahdev-ai-form'),results=document.getElementById('sahdev-results');
    if(!form)return;
    form.classList.add('sdv-case-brief');
    var briefTitle=document.createElement('h3');briefTitle.textContent='Response brief';form.prepend(briefTitle);
    var tech=panel.querySelector('.sahdev-rm-tech-wrap');if(tech)tech.style.display='block';
    var toggle=panel.querySelector('.sahdev-rm-tech-toggle');if(toggle)toggle.remove();
    var rewrite=document.getElementById('btn-sahdev-rewrite');
    if(rewrite){var block=rewrite.parentElement.parentElement;block.classList.add('sdv-case-polish');pane.appendChild(block);rewrite.innerHTML='Rewrite / Polish';}
    var instruction=document.getElementById('sahdev_instruction');
    if(instruction&&instruction.tagName==='INPUT'){var area=document.createElement('textarea');Array.from(instruction.attributes).forEach(function(a){if(a.name!=='type')area.setAttribute(a.name,a.value);});area.value=instruction.value;area.rows=3;instruction.replaceWith(area);instruction=area;}
    var history=document.createElement('section');history.className='sdv-case-history';
    var context=document.createElement('section');context.className='sdv-case-context';
    pane.insertBefore(context,form);pane.insertBefore(history,context);
    var findings=document.createElement('section');findings.className='sdv-case-findings';
    el('h3','Current findings',findings);el('p','AI interpretation — review against the dated evidence before replying.',findings);
    var analysis=results&&results.querySelector('.row > .col-md-6');
    var empty=el('p','Generate an analysis to populate root cause, responsibility, risk and internal action plan.',findings);
    if(analysis){findings.appendChild(analysis);new MutationObserver(function(){empty.hidden=!!analysis.textContent.trim().replace(/ROOT CAUSE:|RESPONSIBILITY:|RISK LEVEL:|ACTION PLAN \(INTERNAL\):/gi,'').trim();}).observe(analysis,{childList:true,subtree:true,characterData:true});}
    pane.insertBefore(findings,form);
    var busy=false,lastData=null,filter='all';
    function el(tag,text,parent){var node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(parent)parent.appendChild(node);return node;}
    function render(data){
        lastData=data;history.replaceChildren();context.replaceChildren();el('h3','Case timeline',history);el('h3','Case & service context',context);
        var button=el('button','Refresh observations',history);button.type='button';button.className='btn btn-default btn-xs';button.onclick=load;
        if(!data.enabled){el('p','Timeline collection is disabled. Enable a policy in Case Timeline & context settings.',history);return;}
        if(data.notice)el('p',data.notice,history);
        var filters=el('div',undefined,history);filters.className='sdv-case-filters';filters.setAttribute('aria-label','Filter case events');
        [['all','All events'],['client','Client'],['staff','Staff'],['tool','Tools']].forEach(function(pair){var b=el('button',pair[1],filters);b.type='button';b.setAttribute('aria-pressed',String(filter===pair[0]));b.onclick=function(){filter=pair[0];render(lastData);};});
        var events=(data.events||[]).filter(function(e){return filter==='all'||e.kind===filter;});
        if(!events.length)el('p','No observations match this view.',history);
        var eventList=el('div',undefined,history);eventList.className='sdv-case-event-list';
        var groups={};
        if(mode==='timeline_milestones'){
            [['reported','01 · Reported'],['checked','02 · Checked'],['review','03 · Review next']].forEach(function(pair){var group=el('section',undefined,eventList);el('h4',pair[1],group);groups[pair[0]]=group;});
            el('p','Use the current findings and response brief below. A proposed reply is not a completed action.',groups.review);
            var review=el('button','Go to response brief',groups.review);review.type='button';review.onclick=function(){form.scrollIntoView({behavior:'smooth',block:'start'});instruction.focus();};
        }
        if(mode==='timeline_ledger'||mode==='timeline_map'){
            var headings=el('div',undefined,eventList);headings.className='sdv-case-column-heads';
            (mode==='timeline_map'?['Observed at','Evidence / report','Review action']:['Observed at','Source','State','Observation']).forEach(function(label){el('strong',label,headings);});
        }
        events.forEach(function(e){
            var parent=mode==='timeline_milestones'?groups[e.kind==='tool'?'checked':'reported']:eventList;
            var item=el('article',undefined,parent);item.className='sdv-case-event';item.dataset.kind=e.kind||'report';
            var time=el('time',e.at,item);time.dateTime=String(e.at||'').replace(' ','T');
            if(mode==='timeline_map'){
                var evidence=el('div',undefined,item);el('strong',e.source,evidence);el('span',e.status,evidence);el('p',e.summary,evidence);
                var action=el('button',e.kind==='tool'?'Review / recheck in Tools':'Review response brief',item);action.type='button';action.onclick=function(){if(e.kind==='tool')document.getElementById('rm-tab-tools').click();else{form.scrollIntoView({behavior:'smooth',block:'start'});instruction.focus();}};
            }else{el('strong',e.source,item);el('span',e.status,item);el('p',e.summary,item);}
        });
        function fields(obj,parent){Object.keys(obj||{}).forEach(function(k){var val=obj[k];if(val&&typeof val==='object'){el('h4',k.replace(/_/g,' '),parent);fields(val,parent);}else{var row=el('div',undefined,parent);row.className='sdv-case-fact';el('small',k.replace(/_/g,' '),row);el('b',String(val==null?'Unknown':val),row);}});}
        fields(data.context,context);el('p','Account facts describe configuration, not live availability. Compare observation times before interpreting changes.',context);
    }
    function failed(){history.replaceChildren();el('h3','Case timeline',history);el('p','Timeline unavailable. Existing ticket controls remain available.',history);var retry=el('button','Retry loading timeline',history);retry.type='button';retry.onclick=load;}
    function load(){if(busy)return;busy=true;history.setAttribute('aria-busy','true');var token=$('#sahdev-ai-form input[name="token"]').val()||$('input[name="token"]').first().val();
        $.ajax({url:sahdevAjaxUrl,type:'POST',dataType:'json',data:{action:'get_case_timeline',ticket_id:$('#sahdev_ticket_id').val(),token:token},timeout:15000}).done(function(r){if(r&&r.status==='success'&&r.data)render(r.data);else failed();}).fail(failed).always(function(){busy=false;history.setAttribute('aria-busy','false');});
    }
    // Observe completed tool/analysis requests; no polling and no recursive refresh requests.
    $(document).ajaxComplete(function(event,xhr,settings){var data=typeof settings.data==='string'?settings.data:'';if(/action=(run_manual_tool|run_tools_for_ticket|save_response)(?:&|$)/.test(data))load();});
    el('h3','Case timeline',history);el('p','Loading dated observations…',history);load();
})();
