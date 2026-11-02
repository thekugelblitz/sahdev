/* Real ticket handlers + actual markup, with isolated API responses. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.SAHDEV_PLAYWRIGHT||'playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const hook=read('hooks.php'),template=hook.match(/\$htmlPanel = <<<HTML\r?\n([\s\S]*?)\r?\nHTML;/)[1],main=hook.match(/\$jsContentMain = <<<'EOT'\r?\n([\s\S]*?)\r?\nEOT;/)[1];
const css=['admin-theme','remastered','command-station','command-workspace','case-timeline'].map(n=>read('ui/'+n+'.css')).join('\n');
const result={ROOT_CAUSE:'A withdrawal decision is awaiting the client’s response.',RESPONSIBILITY:'Billing review',RISK_LEVEL:'Medium',INTERNAL_ACTION_PLAN:'Verify the client’s preference and review the commission entries before proceeding.',CLIENT_REPLY:'Please confirm whether you prefer account credit with the offered bonus or a standard withdrawal.'};
const timeline={enabled:true,presentation:'highlights',context:{status:'Customer-Reply',priority:'Medium'},events:[
 {at:'2026-10-31 15:20:00',source:'WHMCS ticket',kind:'client',status:'reported',title:'Request opened',highlights:['Affiliate withdrawal requested.','Balance reported: 2900.84.']},
 {at:'2026-10-31 16:23:39',source:'WHMCS reply #128219',kind:'staff',status:'reported',title:'Escalation update',highlights:['I have escalated your affiliate withdrawal request to our billing team.'],excerpt:'I have escalated your affiliate withdrawal request to our billing team.'},
 {at:'2026-10-31 19:48:24',source:'WHMCS reply #128227',kind:'staff',status:'reported',title:'Options & next steps',highlights:['Commission entries are being revalidated.','Account credit was offered with a 30% bonus.','Please confirm credit balance or standard withdrawal.'],excerpt:'Account credit was offered with a 30% bonus. Please confirm credit balance or standard withdrawal.'}
]};
function html(theme){
 const values={panelClass:'panel panel-info sahdev-remastered sdv-layout-timeline_plus',themeAttributes:' data-sahdev-theme="'+theme+'"',panelDensity:'comfortable',panelAccent:'teal',ticketId:'42',csrfToken:'<input type="hidden" name="token" value="fixture">',isOpenContextMode:'false',defaultIntentVal:'AUTO',modelSelectHtml:'<select id="sahdev_override_provider"><option value="0">Configured ticket provider</option></select>',intentButtonsHtml:['AUTO','RESOLVE','INVESTIGATE','MORE_INFO','GUIDE','ESCALATE','OUT_OF_SCOPE','ABUSE','DUPLICATE','HANDLE','LEAVE'].map(i=>'<button type="button" class="sahdev-intent-btn" data-intent="'+i+'">'+i+'</button>').join('')};
 return '<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{margin:20px;font-family:Arial}.row:after{content:"";display:table;clear:both}.col-md-6{float:left;width:50%}.form-control{display:block;width:100%}.btn{padding:8px 12px;border:1px solid #aaa;border-radius:6px}'+css+'</style>'+template.replace(/\{\$(\w+)\}/g,(_,k)=>values[k]||'')+'<div id="replyticket"><textarea id="replymessage"></textarea></div>';
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.SAHDEV_BROWSER_EXECUTABLE});
 try{
  const page=await browser.newPage({viewport:{width:1480,height:1000}});page.setDefaultTimeout(10000);
  const errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  let theme='black',failure='',rejected='',cached=false,summary='',memory='',features={},runCount=0;
  await page.route('https://workspace.test/**',async route=>{
   if(route.request().method()==='GET')return route.fulfill({contentType:'text/html',body:html(theme)});
   const fields=Object.fromEntries(new URLSearchParams(route.request().postData())),a=fields.action;requests.push(fields);
   if(['get_payload','analyze_ticket','generate_summary','generate_historical_context','run_manual_tool','run_tools_for_ticket','rewrite_reply'].includes(a))await new Promise(r=>setTimeout(r,350));
   if(a===failure)return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({status:'error',message:'Test provider unavailable'})});
   if(a===rejected)return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'error',message:'Test operation rejected'})});
   let body={status:'success'};
   switch(a){
    case 'get_case_timeline':body.data=timeline;break;
    case 'get_payload':body.cached=cached;if(cached)body.data=result;break;
    case 'analyze_ticket':body.data=result;body.tokens_used=200;break;
    case 'get_tools_operations':body.operations=[{method:'GET',path:'/dns/{domain}'}];body.smart={default_domain:'example.test'};break;
    case 'run_manual_tool':case 'run_tools_for_ticket':runCount++;body.summary={status:'ok',runs:[{path:'/dns/example.test',status:'ok',normalized_summary:'A record: 192.0.2.3'}]};break;
    case 'get_tools_ticket_status':body.summary=runCount?{status:'ok',runs:[{path:'/dns/example.test',status:'ok',normalized_summary:'A record: 192.0.2.3'}]}:null;break;
    case 'generate_summary':summary='Withdrawal requested. Billing is reviewing the commission entries. The client has been offered a choice of account credit or standard withdrawal.';body.summary=summary;break;
    case 'get_summary':body.summary=summary;break;
    case 'delete_summary':summary='';break;
    case 'generate_historical_context':memory='The client’s previous tickets concerned account credit. Verify current preference before proceeding.';body.tickets_analyzed=7;break;
    case 'get_historical_context':body.historical_context=memory;break;
    case 'delete_historical_context':memory='';break;
    case 'search_canned_responses':body.results=[{title:fields.query+' guide',source:'whmcs_kb',content:'Review the current evidence before replying.\nNever treat an offer as a completed payout.'}];break;
    case 'rewrite_reply':body.reply='Please confirm which payout option you would prefer so our billing team can continue reviewing the request.';break;
   }
   return route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
  });
  async function load(){
   await page.goto('https://workspace.test/ticket');await page.addScriptTag({path:process.env.SAHDEV_JQUERY||require.resolve('jquery/dist/jquery.min.js')});
   await page.evaluate(f=>{window.sahdevAjaxUrl='https://workspace.test/ajax';window.sahdevAutoAnalyze=false;window.sahdevQualityScorer=false;window.sahdevCurrentTheme='black';window.currentAdminSignature='Regards,\nSupport Team';window.sahdevWorkspaceFeatures=f;},features);
   for(const name of ['rewrite-editor','command-activity'])await page.addScriptTag({content:read('ui/'+name+'.js')});
   await page.addScriptTag({content:main});
   for(const name of ['remastered','case-timeline','command-workspace'])await page.addScriptTag({content:read('ui/'+name+'.js')});
   await page.waitForSelector('.sdv-case-event');
  }
  async function tab(name){await page.locator('#rm-tab-'+name).click();}
  await load();
  assert.equal(await page.getByText('Expand & Polish My Draft Reply',{exact:false}).count(),0);
  assert.equal(await page.locator('.sdv-case-polish textarea').count(),0);
  await page.locator('#btn-sahdev-analyze').click();
  await page.waitForFunction(()=>document.getElementById('btn-sahdev-analyze').classList.contains('sdv-busy'));
  assert(await page.locator('#btn-sahdev-analyze').isDisabled());
  assert.equal(await page.locator('#btn-sahdev-analyze').evaluate(e=>getComputedStyle(e,'::before').animationName),'sdv-request-spin');
  await page.waitForFunction(()=>!document.getElementById('btn-sahdev-analyze').disabled);
  assert((await page.locator('#sahdev-out-reply').innerText()).includes('account credit'));
  cached=true;await page.locator('#btn-sahdev-regenerate').click();await page.waitForFunction(()=>!document.getElementById('btn-sahdev-regenerate').disabled);assert(!(await page.locator('#btn-sahdev-analyze').isDisabled()));cached=false;
  failure='analyze_ticket';await page.locator('#btn-sahdev-analyze').click();await page.waitForSelector('#sahdev-error:visible');assert(!(await page.locator('#btn-sahdev-analyze').isDisabled()));failure='';
  await tab('tools');assert(await page.getByRole('heading',{name:'Diagnostics desk'}).isVisible());await page.locator('#sahdev-manual-tool-op').selectOption('GET /dns/{domain}');
  await page.locator('#btn-sahdev-manual-tool-run').click();await page.waitForFunction(()=>document.getElementById('btn-sahdev-manual-tool-run').classList.contains('sdv-busy'));await page.waitForFunction(()=>!document.getElementById('btn-sahdev-manual-tool-run').disabled);
  await page.waitForFunction(()=>document.getElementById('sahdev-tools-output-edit').value.includes('192.0.2.3'));await page.locator('#btn-sahdev-append-tools-context').click();assert((await page.locator('#sahdev_technical_context').inputValue()).includes('192.0.2.3'));
  assert.equal(requests.filter(r=>r.action==='run_manual_tool').at(-1).path_params,'{"domain":"example.test"}');
  await tab('summary');await page.locator('#btn-sahdev-generate-summary').click();await page.waitForFunction(()=>document.getElementById('btn-sahdev-generate-summary').classList.contains('sdv-busy'));await page.waitForSelector('#sahdev-summary-display:visible');assert((await page.locator('#sahdev-summary-display').innerText()).includes('Withdrawal'));
  rejected='delete_summary';await page.locator('#btn-sahdev-delete-summary').click();await page.waitForSelector('#sahdev-summary-error:visible');assert(await page.locator('#sahdev-summary-display').isVisible());rejected='';
  await page.locator('#btn-sahdev-delete-summary').click();await page.waitForFunction(()=>document.getElementById('sahdev-summary-display').style.display==='none');assert(await page.getByText('No saved summary yet',{exact:true}).isVisible());
  await tab('memory');await page.locator('#btn-sahdev-generate-history').click();await page.waitForSelector('#sahdev-history-display:visible');assert(!(await page.locator('#sahdev_include_history').isDisabled()));await page.locator('#sahdev_include_history').check();failure='delete_historical_context';await page.locator('#btn-sahdev-delete-history').click();await page.waitForSelector('#sahdev-history-error:visible');assert(await page.locator('#sahdev-history-display').isVisible());failure='';await page.locator('#btn-sahdev-delete-history').click();await page.waitForFunction(()=>document.getElementById('sahdev_include_history').disabled);assert(!(await page.locator('#sahdev_include_history').isChecked()));
  await tab('kb');await page.locator('#sahdev-canned-search').fill('withdrawal');await page.waitForSelector('.sdv-preview-canned');await page.locator('.sdv-preview-canned').click();assert((await page.locator('#sdv-kb-preview-text').innerText()).includes('completed payout'));await page.locator('.sahdev-insert-canned').click();assert((await page.locator('#replymessage').inputValue()).includes('current evidence'));
  await tab('analysis');await page.locator('#replymessage').fill('Ask for payout preference\nRegards,\nSupport Team');await page.locator('#btn-sahdev-rewrite').click();await page.waitForFunction(()=>document.getElementById('replymessage').value.includes('Please confirm which'));assert.equal(requests.filter(r=>r.action==='rewrite_reply').at(-1).draft_text,'Ask for payout preference');assert((await page.locator('#replymessage').inputValue()).endsWith('Regards,\nSupport Team'));
  summary='Withdrawal requested. Billing is reviewing commission entries. The client has been offered a choice of account credit with a 30% bonus or standard withdrawal.';memory='Earlier conversations concerned account credit. This is historical context: verify the current preference before proceeding.';
  for(theme of ['black','dark','white']){await load();await page.locator('#btn-sahdev-analyze').click();await page.waitForFunction(()=>!document.getElementById('btn-sahdev-analyze').disabled);await tab('kb');await page.locator('#sahdev-canned-search').fill('withdrawal');await page.waitForSelector('.sdv-preview-canned');await page.locator('.sdv-preview-canned').click();for(const name of ['analysis','tools','summary','kb','memory']){await tab(name);assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2)),theme+' '+name+' overflow');if(theme==='black')await page.screenshot({path:path.join(__dirname,'workspace-'+name+'.png'),fullPage:true});}}
  await page.setViewportSize({width:390,height:844});for(const name of ['analysis','tools','summary','kb','memory']){await tab(name);assert(!(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2)),'mobile '+name+' overflow');}
  features={tools:false,summary:false,kb:false,memory:false};await load();for(const name of ['tools','summary','kb','memory']){await tab(name);assert(await page.getByRole('heading',{name:'This feature is disabled'}).last().isVisible());assert.equal(await page.locator('#rm-pane-'+name+' input, #rm-pane-'+name+' button').count(),0);}
  assert.deepEqual(errors,[]);console.log('PASS: real generation/loading/cache/error handlers, manual tools/evidence, summary CRUD, memory CRUD and inclusion, KB preview/insert, editor polishing, five workspaces × three themes, mobile and permission states.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
