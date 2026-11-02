const {chromium}=require(process.env.SAHDEV_PLAYWRIGHT);
const {pathToFileURL}=require('node:url');
const path=require('node:path');
const fs=require('node:fs');
const crypto=require('node:crypto');
const assert=require('node:assert/strict');
(async()=>{
 const root=__dirname;
 const selection=JSON.parse(fs.readFileSync(path.join(root,'shortlisted/selection.json'),'utf8').replace(/^\uFEFF/,''));
 const hash=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'shortlisted/case-timeline-original.html'))).digest('hex');
 assert.equal(hash.toUpperCase(),selection.sha256,'preserved baseline changed');
 const browser=await chromium.launch({headless:true,executablePath:'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 try{
  const page=await browser.newPage({viewport:{width:1600,height:1050}});
  const errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>requests.push(r.url()));
  await page.goto(pathToFileURL(path.join(root,'sahdev-timeline-refinements.html')).href);
  assert.equal(await page.locator('.timeline-gallery .concept-card').count(),8);
  for(const theme of ['black','dark','white']){
   await page.locator('.labbar [data-pref=theme]').selectOption(theme);
   for(let i=0;i<8;i++){
    await page.evaluate(i=>{timelineTab='Analysis';detail(i)},i);
    for(const f of ['instruction','technical','tone','depth','model','summary','notes','evidence','reply','editor'])assert(await page.locator(`#stage [data-field="${f}"]`).isVisible(),`hidden ${f} in ${i}`);
    assert.equal(await page.locator('#stage [data-field="rough"]').count(),0,'Polish must not have a separate draft input');
    assert.equal(await page.locator('#stage [data-intent]').count(),11);
    assert.equal(await page.locator('#stage details').count(),0,'controls hidden in disclosure');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`desktop overflow: ${theme}, ${i}`);
   }
  }
  await page.locator('.labbar [data-pref=theme]').selectOption('black');
  await page.evaluate(()=>detail(0));
  await page.locator('#stage [data-field=instruction]').fill('Keep my instruction while I switch tabs.');
  await page.locator('#stage [data-field=technical]').fill('Verified custom context.');
  for(const tab of ['Tools','Summary','KB','Memory','Analysis']){
   await page.locator(`#stage [data-refinement-tab="${tab}"]`).click();
   assert.equal(await page.locator('#stage [role=tabpanel]').getAttribute('aria-label'),tab);
   assert.equal(await page.locator('#drawer').isVisible(),false,'drawer opened');
  }
  assert.equal(await page.locator('#stage [data-field=instruction]').inputValue(),'Keep my instruction while I switch tabs.');
  assert.equal(await page.locator('#stage [data-field=technical]').inputValue(),'Verified custom context.');
  await page.locator('#stage [data-refinement-tab=Analysis]').focus();await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#stage [role=tabpanel]').getAttribute('aria-label'),'Tools');
  await page.locator('#stage [data-field=toolError]').check();await page.locator('#stage [data-action=run-tool]').click();
  await page.waitForFunction(()=>timelineEvents.length===7);
  assert.equal(await page.locator('#drawer').isVisible(),false);
  await page.locator('#stage [data-refinement-tab=Analysis]').click();
  assert((await page.locator('#stage .case-log').innerText()).includes('timed out'));
  await page.locator('#stage [data-action=insert]').click();
  assert((await page.locator('#stage [data-field=editor]').inputValue()).includes('Hello Sandeep'));
  await page.locator('#detail-view [data-refinement-shortlist]').click();
  await page.locator('#detail-view [data-compare-original]').click();
  assert.equal(await page.locator('.timeline-comparison>article').count(),2);
  await page.frameLocator('[data-original-frame]').locator('#stage .timeline-layout').waitFor();
  await page.evaluate(()=>originalView());
  await page.frameLocator('[data-original-frame]').locator('#stage .timeline-layout').waitFor();
  await page.reload();
  assert.equal(await page.locator('.timeline-gallery .favorite[aria-pressed=true]').count(),1);
  await page.setViewportSize({width:390,height:844});
  for(let i=0;i<8;i++){
   await page.evaluate(i=>detail(i),i);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`mobile overflow: ${i}`);
  }
  await page.evaluate(()=>gallery());
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mobile gallery overflow');
  assert.deepEqual(errors,[]);
  assert(requests.every(u=>u.startsWith('file:')||u==='about:srcdoc'),'external dependency');
  console.log('PASS: baseline checksum; 8 layouts × 3 themes; visible controls; inline tabs; state retention; timeout timeline; insertion; persisted shortlist; original comparison; mobile layout; no external assets.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
