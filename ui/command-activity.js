/* Real request state; no artificial percentage or completion timer. */
(function () {
    'use strict';
    var states=new Map();
    function set(selector,busy,label){
        document.querySelectorAll(selector).forEach(function(button){
            button.classList.toggle('sdv-busy',busy);
            button.setAttribute('aria-busy',String(busy));
            if(busy){button.dataset.busyLabel=label||'Working…';button.setAttribute('aria-label',button.dataset.busyLabel);button.disabled=true;}
            else{button.removeAttribute('aria-label');delete button.dataset.busyLabel;button.disabled=false;}
        });
    }
    window.SahdevActivity={
        generation:function(busy,label){
            set('#btn-sahdev-analyze, #btn-sahdev-regenerate',busy,label||'Analyzing & drafting…');
            var findings=document.querySelector('.sdv-case-findings');
            if(findings){findings.classList.toggle('sdv-updating',busy);findings.setAttribute('aria-busy',String(busy));}
            var status=document.getElementById('sdv-generation-status');
            if(status){status.hidden=!busy;status.textContent=busy?(label||'Analyzing the ticket and preparing a reply…'):'';}
        }
    };
    var actions={
        generate_summary:['#btn-sahdev-generate-summary','Summarizing…'],
        delete_summary:['#btn-sahdev-delete-summary','Deleting…'],
        generate_historical_context:['#btn-sahdev-generate-history','Reading client history…'],
        delete_historical_context:['#btn-sahdev-delete-history','Deleting…'],
        run_manual_tool:['#btn-sahdev-manual-tool-run, #btn-sahdev-run-tools, #btn-sahdev-rerun-tools','Running check…'],
        run_tools_for_ticket:['#btn-sahdev-manual-tool-run, #btn-sahdev-run-tools, #btn-sahdev-rerun-tools','Checking ticket…'],
        get_rewrite_payload:['#btn-sahdev-rewrite','Preparing polish…'],
        rewrite_reply:['#btn-sahdev-rewrite','Polishing editor draft…'],
        score_reply:['#btn-sahdev-score-draft','Scoring draft…']
    };
    function action(settings){return new URLSearchParams(typeof settings.data==='string'?settings.data:'').get('action');}
    function ours(settings){return typeof sahdevAjaxUrl!=='undefined'&&settings.url.split('?')[0]===sahdevAjaxUrl.split('?')[0];}
    $.ajaxPrefilter(function(settings){if(ours(settings)&&!settings.timeout)settings.timeout=180000;});
    $(document).ajaxSend(function(event,xhr,settings){
        if(!ours(settings))return;var a=actions[action(settings)];if(!a)return;
        states.set(a[0],(states.get(a[0])||0)+1);set(a[0],true,a[1]);
    });
    $(document).ajaxComplete(function(event,xhr,settings){
        if(!ours(settings))return;var a=actions[action(settings)];if(!a)return;
        var count=Math.max(0,(states.get(a[0])||1)-1);states.set(a[0],count);if(!count)set(a[0],false);
    });
})();
