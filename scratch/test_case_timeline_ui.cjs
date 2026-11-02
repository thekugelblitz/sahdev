/* Uses actual hook markup and production transforms; all API responses are local fixtures. */
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm'),path=require('node:path');
const {chromium}=require(process.env.SAHDEV_PLAYWRIGHT||'playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const hooks=read('hooks.php');
const template=hooks.match(/\$htmlPanel = <<<HTML\r?\n([\s\S]*?)\r?\nHTML;/)[1];
new vm.Script(hooks.match(/\$jsContentMain = <<<'EOT'\r?\n([\s\S]*?)\r?\nEOT;/)[1]);
const css=['admin-theme.css','remastered.css','command-station.css','case-timeline.css'].map(p=>read('ui/'+p)).join('\n');
const modes=['case_timeline','timeline_plus','timeline_lanes','timeline_ledger','timeline_split','timeline_ribbon','timeline_journal','timeline_milestones','timeline_map'];
const sample={enabled:true,context:{status:'Open',priority:'High',hosting:{domain:'example.test',status:'Active',recorded_ip:'192.0.2.3'},server:{hostname:'srv.example.test',nameserver1:'ns1.example.test'}},events:[
 {at:'2026-11-02 09:10:00',kind:'client',source:'WHMCS ticket',status:'reported',summary:'The site has been unavailable since this morning.'},
 {at:'2026-11-02 09:15:00',kind:'tool',source:'diagnostics',status:'observed',summary:'A record: 192.0.2.3. This is a dated observation, not current availability.'},
 {at:'2026-11-02 09:20:00',kind:'staff',source:'WHMCS reply #20',status:'reported',summary:'Requested a browser error screenshot.'},
 {at:'2026-11-02 09:25:00',kind:'tool',source:'diagnostics',status:'failed',summary:'HTTP request timed out. No verified HTTP result.'},
]};
function html(layout,theme){
 const values={panelClass:'panel panel-info sahdev-remastered sdv-layout-'+layout,panelDensity:'comfortable',panelAccent:'teal',themeAttributes:' data-sahdev-theme="'+theme+'"',ticketId:'1',csrfToken:'fixture',defaultIntentVal:'escalate',
  intentButtonsHtml:['AI Auto Decides','Resolved Query','Checking Query','Need More Info','Guide To Solution','Escalate','Out Of Scope','Abuse Report','Duplicate Ticket','Handle It!','On Leave'].map(x=>'<button type="button" class="sahdev-intent-btn">'+x+'</button>').join(''),
 };
 return '<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{margin:20px;font-family:Arial}.row:after{content:"";display:table;clear:both}.col-md-6{float:left;width:50%}.col-md-12{width:100%}.form-control{display:block;width:100%}.btn{padding:8px 12px;border:1px solid #aaa;border-radius:6px}'+css+'</style>'+template.replace(/\{\$(\w+)\}/g,(_,k)=>values[k]||'')+'<textarea id="replymessage"></textarea>';
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SAHDEV_BROWSER_EXECUTABLE});
 try{
 const page=await browser.newPage({viewport:{width:1600,height:1050}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let content='',fail=false,disabled=false;
 await page.route('https://case.test/**',route=>route.request().method()==='POST'?route.fulfill({status:fail?500:200,contentType:'application/json',body:JSON.stringify({status:'success',data:disabled?{enabled:false}:sample})}):route.fulfill({contentType:'text/html',body:content}));
 async function load(layout,theme){
  content=html(layout,theme);await page.goto('https://case.test/ticket');await page.addScriptTag({path:process.env.SAHDEV_JQUERY||require.resolve('jquery/dist/jquery.min.js')});
  await page.evaluate(()=>{window.sahdevAjaxUrl='https://case.test/ajax';$('#btn-sahdev-analyze').on('click',()=>{window.analyzed=true;$('#sahdev-out-cause').text('The recorded A record matches the assigned hosting IP. The HTTP check timed out, so availability has not been verified.');$('#sahdev-out-plan').text('Review the HTTP result and request the exact browser error. Recheck before stating a root cause.');$('#sahdev-out-resp').text('Undetermined');$('#sahdev-out-risk').text('Medium');$('#sahdev-out-reply').text('Hello Sandeep,\n\nThe DNS observation points to the hosting IP recorded on your account. The website check did not return a verified result. Please share the exact error you see so we can investigate further.');$('#sahdev-results').show();});});
  await page.addScriptTag({content:read('ui/remastered.js')});await page.addScriptTag({content:read('ui/case-timeline.js')});
  await page.waitForFunction(()=>document.querySelector('.sdv-case-history').getAttribute('aria-busy')==='false');
 }
 for(const mode of modes)for(const theme of ['black','dark','white']){
  await load(mode,theme);
  assert.equal(await page.locator('.sdv-case-event').count(),4);
  assert.equal(await page.locator('#sahdev_instruction').evaluate(e=>e.tagName),'TEXTAREA');
  assert(await page.locator('#sahdev_technical_context').isVisible());
  assert.equal(await page.locator('#btn-sahdev-rewrite').count(),1);
  assert.equal(await page.locator('.sdv-case-polish textarea').count(),0);
  await page.locator('#btn-sahdev-analyze').click();assert(await page.evaluate(()=>window.analyzed));
  await page.locator('#rm-tab-tools').click();assert.equal(await page.locator('#rm-pane-analysis').isVisible(),false,mode+' hidden analysis');
  await page.locator('#rm-tab-analysis').click();assert(await page.locator('.sdv-case-history').isVisible());
  await page.locator('.sdv-case-filters button').filter({hasText:'Client'}).click();assert.equal(await page.locator('.sdv-case-event').count(),1);
  await page.locator('.sdv-case-filters button').filter({hasText:'All events'}).click();
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);assert(!overflow,mode+' desktop overflow');
  if(theme==='black')await page.screenshot({path:path.join(__dirname,'case-'+mode+'.png'),fullPage:true});
 }
 for(const mode of modes){await page.setViewportSize({width:390,height:844});await load(mode,'black');assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2)),mode+' mobile overflow');}
 fail=true;await load('timeline_plus','black');assert(await page.getByText('Retry loading timeline').isVisible());fail=false;await page.getByText('Retry loading timeline').click();await page.waitForSelector('.sdv-case-event');
 disabled=true;await load('timeline_plus','black');assert(await page.getByText('Timeline collection is disabled.',{exact:false}).isVisible());disabled=false;
 await page.addScriptTag({content:read('ui/rewrite-editor.js')});
 const rewrite=await page.evaluate(()=>{
  const api=window.SahdevRewriteEditor,field=document.getElementById('replymessage');
  let value='<p>Explain DNS propagation</p><p>Regards,<br>Support Team</p>';
  window.tinymce={get:()=>({})};const instance={getContent:opt=>opt?'Explain DNS propagation\nRegards,\nSupport Team':value,setContent:html=>{value=html;},fire:()=>{}};
  tinymce.get=id=>id==='replymessage'?instance:null;tinymce.activeEditor={getContent:()=>{throw Error('Wrong editor');}};
  const snap=api.capture('<p>Regards,<br>Support Team</p>');const draft=snap.draft;
  const inserted=api.insert('<p>Expanded reply</p>',snap);const final=value;
  const changed=api.capture('<p>Regards,<br>Support Team</p>');value='User newer draft';const protectedDraft=!api.insert('Overwrite',changed)&&value==='User newer draft';
  const only=api.split('Regards,\nSupport Team','<p>Regards,<br>Support Team</p>').draft;
  tinymce.get=()=>null;field.value='Short draft\nRegards,\nSupport Team';const fallback=api.capture('Regards,\nSupport Team');api.insert('<p>Expanded &amp; clear</p>',fallback);
  return {draft,inserted,final,protectedDraft,only,fallback:field.value};
 });
 assert.equal(rewrite.draft,'Explain DNS propagation');assert(rewrite.inserted);assert(rewrite.final.includes('Support Team'));assert(rewrite.protectedDraft);assert.equal(rewrite.only,'');assert.equal(rewrite.fallback,'Expanded & clear\n\nRegards,\nSupport Team');
 assert.deepEqual(errors,[]);
 console.log('PASS: 9 layouts × 3 themes, 9 mobile layouts, existing controls/tabs, filters, retry/disabled state, signature separation, draft race protection and textarea fallback.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
