/* Native WHMCS reply controls, with fixture-only network responses and editors. */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require(process.env.SAHDEV_PLAYWRIGHT || 'playwright');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'ui/autopilot-drafts.js'), 'utf8');
const marker = '[Sahdev Autopilot Draft] Review before sending - Draft Mode is ON';
const markdown = 'Hello **client**,\n\n## Findings\n\n- `dig example.test`\n- [Details](https://example.test)\n\n```sh\necho "hello"\n```';
const rendered = '<p>Hello <strong>client</strong>,</p><h2>Findings</h2><ul><li><code>dig example.test</code></li><li><a href="https://example.test">Details</a></li></ul><pre><code>echo "hello"</code></pre>';
function button(type, text, handler) {
    return type === 'input' ? `<input type="button" class="btn btn-xs btn-small btn-default" value="${text}" onclick="${handler}">`
        : `<button type="button" class="btn btn-default rounded-pill" onclick="${handler}">${text}</button>`;
}
function card(id, note, type, draft = true) {
    return `<div class="reply ${note ? 'note' : 'staff'}" data-fixture="${note ? 'note' : 'reply'}-${id}">
      <div class="tools"><div class="editbtnsr${id}"><img class="saveSpinner" style="display:none">
        ${note ? '' : button(type,'Edit',`editTicket('r${id}')`)}${button(type,'Delete',`${note ? 'doDeleteNote' : 'doDeleteReply'}('${id}')`)}
      </div><div class="editbtnsr${id}" style="display:none"><img class="saveSpinner" style="display:none">
        ${button(type,'Save',`editTicketSave('r${id}')`)}${button(type,'Cancel',`editTicketCancel('r${id}')`)}
      </div></div><input type="checkbox" name="${note ? 'nids' : 'rids'}[]" value="${id}">
      <div class="msgwrap" id="contentr${id}"><div class="message markdown-content">${draft ? `<p>${marker}</p><hr>${rendered}` : 'Ordinary note'}</div><a class="attachment">attachment.pdf</a></div></div>`;
}
(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.SAHDEV_BROWSER_EXECUTABLE});
    try {
        const page = await browser.newPage({viewport: {width: 1400, height: 1000}}), errors = [], requests = [];
        page.on('pageerror', error => errors.push(error.message));
        let body = markdown, revision = '1', failRead = false, failSave = false, gate = null;
        await page.route('https://draft.test/**', async route => {
            if (route.request().method() !== 'POST') return route.fulfill({body: ''});
            const data = new URLSearchParams(route.request().postData());
            requests.push(Object.fromEntries(data));
            assert.equal(data.get('token'),'fixture-token'); assert.equal(data.get('ticket_id'),'1'); assert.equal(data.get('note_id'),'10');
            if (gate) await gate;
            if (failRead || (data.get('action') === 'save_autopilot_draft' && failSave)) {
                return route.fulfill({status: 409, contentType:'application/json', body:JSON.stringify({status:'error',message:'Another administrator changed this draft.'})});
            }
            if (data.get('action') === 'save_autopilot_draft') { body = data.get('body'); revision = String(Number(revision)+1); }
            await route.fulfill({contentType:'application/json', body:JSON.stringify({status:'success',data:{note_id:10,body,revision,html:rendered + '<script>window.pwned=true</script><img src=x onerror="window.pwned=true"><a href="javascript:alert(1)">bad link</a>'}})});
        });
        async function load(type = 'input', value = '\n\nRegards,\nSupport **Team**') {
            await page.goto('https://draft.test/ticket');
            await page.setContent(`<!doctype html><style>body{font-family:Arial}.reply{border:1px solid #ddd;margin:15px;padding:15px}.note{background:#fff8db}.tools{display:flex;justify-content:end}.btn{margin:3px;padding:6px 16px}.msgwrap{background:#fff;padding:15px}textarea{width:95%}</style><a href="#tab0" data-toggle="tab">Add Reply</a><div class="tab-pane" id="tab0"><form id="frmAddTicketReply"><input name="token" type="hidden" value="fixture-token"><textarea id="replymessage"></textarea></form></div><textarea id="replynote">Private composer stays intact</textarea><div id="ticketreplies">${card(10,false,type,false)}${card(10,true,type)}${card(11,true,type,false)}</div>`);
            await page.addScriptTag({path:process.env.SAHDEV_JQUERY || require.resolve('jquery/dist/jquery.min.js')});
            await page.evaluate(value => {
                document.getElementById('replymessage').value = value;
                window.sahdevAutopilotDraftConfig={ticketId:1,ajaxUrl:'https://draft.test/ajax'};
                window.submissions=0; $('form').on('submit',event=>{event.preventDefault();window.submissions++;});
                window.editTicket = id => {window.nativeEdited=id;};
                window.editTicketSave = window.editTicketCancel = () => {throw Error('Public reply handler must not edit private note');};
            }, value);
            await page.addScriptTag({content:source});
            await page.waitForSelector('.sahdev-draft-edit');
        }
        const note = () => page.locator('[data-fixture="note-10"]');
        const action = name => note().locator('.sahdev-draft-'+name);
        const status = () => note().locator('.sahdev-draft-status');
        async function finished() { await page.waitForFunction(()=>!document.querySelector('.sahdev-draft-edit').disabled); }
        for (const type of ['input','button']) {
            body=markdown; revision='1'; await load(type);
            assert.equal(await page.locator('.sahdev-draft-edit').count(),1,'Only marked private notes gain controls');
            await page.addScriptTag({content:source}); assert.equal(await page.locator('.sahdev-draft-edit').count(),1,'Idempotent init');
            const native = page.locator('[data-fixture="reply-10"] [onclick^="editTicket("]');
            await native.click(); assert.equal(await page.evaluate(()=>window.nativeEdited),'r10');
            assert.equal(await page.locator('#contentr10').count(),1,'Private note does not collide with public reply IDs');
            await action('edit').click(); await page.waitForSelector('.sahdev-draft-text'); await finished();
            assert.equal(await action('text').inputValue(),markdown,'Editor gets exact source Markdown without warning');
            await action('text').fill('Cancel this edit'); await action('cancel').click();
            assert.equal(body,markdown); assert.equal(await action('text').count(),0);
            await action('edit').click(); await page.waitForSelector('.sahdev-draft-text'); await finished();
            await action('text').fill(markdown+'\n\nUpdated draft'); failSave=true; await action('save').click(); await finished();
            assert(await status().textContent().then(text=>text.includes('Another administrator')));
            assert.equal(await action('text').inputValue(),markdown+'\n\nUpdated draft','Failed save retains unsaved work');
            failSave=false; await action('save').click(); await finished();
            assert.equal(await action('text').count(),0); assert.equal(await note().locator('.attachment').count(),1);
            assert.equal(await note().locator('.message script,.message img,.message [href^="javascript:"]').count(),0);
            assert(!await page.evaluate(()=>window.pwned));
            await action('insert').click(); await finished();
            assert.equal(await page.locator('#replymessage').inputValue(),body+'\n\nRegards,\nSupport **Team**');
            assert(!await page.locator('#replymessage').inputValue().then(text=>text.includes(marker)));
            await action('insert').click(); await finished();
            assert.equal(await page.locator('#replymessage').inputValue(),body+'\n\nRegards,\nSupport **Team**','Repeat click does not duplicate');
            assert.equal(await page.locator('#replynote').inputValue(),'Private composer stays intact');
            assert.equal(await page.evaluate(()=>window.submissions),0);
        }
        await load('button','Existing reply\n\nRegards,\nSupport Team');
        await page.evaluate(()=>{
            const field=document.getElementById('replymessage');
            window.markdownState=field.value;
            $('#replymessage').data('markdown',{getContent:()=>window.markdownState,setContent:value=>{window.markdownState=value;}});
        });
        await action('insert').click(); await finished();
        assert.equal(await page.evaluate(()=>window.markdownState),body+'\n\nExisting reply\n\nRegards,\nSupport Team');
        await load('button');
        await page.evaluate(()=>{
            window.richValue='<p>Existing reply</p><p>Regards,<br><strong>Support Team</strong></p>';
            window.changes=0; window.saved=false;
            window.replyEditor={getContent:()=>window.richValue,setContent:value=>{window.richValue=value;},
                save:()=>{window.saved=true;},fire:()=>{window.changes++;},focus:()=>{},undoManager:{transact:fn=>fn()}};
            window.tinymce={get:id=>id==='replymessage'?window.replyEditor:null,activeEditor:{setContent:()=>{throw Error('Wrong active editor');}}};
        });
        await action('insert').click(); await finished();
        const rich=await page.evaluate(()=>({value:richValue,changes,saved}));
        assert(rich.value.includes('<strong>client</strong>'));assert(rich.value.includes('<pre><code>'));assert(rich.value.endsWith('<p>Regards,<br><strong>Support Team</strong></p>'));assert(!rich.value.includes('<script'));assert(!rich.value.includes('javascript:'));assert(rich.changes&&rich.saved);
        await load(); let release; gate=new Promise(resolve=>{release=resolve;});
        await action('insert').click(); await page.locator('#replymessage').fill('Newer manual reply'); release();gate=null;await finished();
        assert.equal(await page.locator('#replymessage').inputValue(),'Newer manual reply');assert((await status().textContent()).includes('changed while'));
        failRead=true;await action('insert').click();await finished();assert.equal(await page.locator('#replymessage').inputValue(),'Newer manual reply');failRead=false;
        await page.evaluate(html=>document.getElementById('ticketreplies').insertAdjacentHTML('beforeend',html),card(12,true,'button'));
        await page.waitForFunction(()=>document.querySelectorAll('.sahdev-draft-edit').length===2);
        assert.deepEqual(errors,[]);
        const screenshot=process.env.SAHDEV_DRAFT_SCREENSHOT;
        if(screenshot) await page.screenshot({path:screenshot,fullPage:true});
        console.log('PASS: native input/button controls, edit/save/cancel, conflicts, exact Markdown, TinyMCE, Bootstrap Markdown, signatures, attachments, ID collisions, dynamic notes, duplicate clicks and no ticket submission.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
