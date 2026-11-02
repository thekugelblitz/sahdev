/* Single source of truth for the WHMCS reply editor. Never read another active editor. */
(function () {
    'use strict';
    function text(html) {
        var node=document.createElement('div');
        node.innerHTML=String(html).replace(/<br\s*\/?>|<\/(?:p|div|li)>/gi,'\n');
        return node.textContent.replace(/\u00a0/g,' ').replace(/\n{3,}/g,'\n\n').trim();
    }
    function split(value,signature) {
        var normalized=text(signature), parts=normalized.split(/\s+/).filter(Boolean);
        if(!parts.length)return {draft:value.trim(),signature:''};
        var expression=parts.map(function(part){return part.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}).join('\\s+');
        var match=new RegExp('(?:^|\\s)'+expression+'\\s*$').exec(value.replace(/\u00a0/g,' '));
        return match ? {draft:value.slice(0,match.index).trim(),signature:signature} : {draft:value.trim(),signature:''};
    }
    function editor() {return typeof tinymce!=='undefined' && typeof tinymce.get==='function' ? tinymce.get('replymessage') : null;}
    window.SahdevRewriteEditor={
        split:split,
        capture:function(signature){
            var instance=editor(),field=document.getElementById('replymessage');
            var original=instance?instance.getContent():field?field.value:'';
            var result=split(instance?instance.getContent({format:'text'}):original,signature||'');
            result.original=original;result.editor=instance;result.field=field;
            return result;
        },
        insert:function(html,snapshot){
            if(!snapshot)return false;
            var instance=editor(),field=document.getElementById('replymessage');
            // A draft typed during the request wins; never overwrite a newer edit.
            if(instance!==snapshot.editor || field!==snapshot.field || (instance?instance.getContent():field?field.value:'')!==snapshot.original)return false;
            if(snapshot.signature)html+='<p><br></p>'+snapshot.signature.replace(/\r?\n/g,'<br>');
            if(instance){instance.setContent(html);instance.fire('change');}
            else if(field){field.value=text(html);field.dispatchEvent(new Event('input',{bubbles:true}));}
            else return false;
            return true;
        }
    };
})();
