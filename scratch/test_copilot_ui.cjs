/* SAHDEV_PLAYWRIGHT=/path/to/playwright node scratch/test_copilot_ui.cjs */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const { chromium } = require(process.env.SAHDEV_PLAYWRIGHT || 'playwright');
const root = path.join(__dirname, '..');
const render = tab => execFileSync(process.env.SAHDEV_PHP || 'php', [path.join(__dirname, 'render_copilot_fixture.php'), tab], { encoding: 'utf8', cwd: root });
const proposal = {
  status: 'pending', can_execute: true, proposal_id: 'prop_fixture_saved', expires_at: '2026-11-02 12:30:00',
  plan: {changes: [{kind: 'update', label: 'Client #12', before: {notes: ''}, after: {notes: `Customer's note <img src=x onerror=alert(1)>`}}]},
  action_key: 'client_update_notes', title: 'Update <client> notes', risk_tier: 2, session_id: 1,
  params: { client_id: 12, notes: `Customer's "quoted" note <img src=x onerror=alert(1)>` },
  diff: { notes: { before: '', after: `Customer's note <img src=x onerror=alert(1)>` } }
};

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.SAHDEV_BROWSER_EXECUTABLE || undefined });
  let checks = 0;
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.setDefaultTimeout(10000);
    const errors = [], requests = [], responses = [];
    page.on('pageerror', error => { errors.push(error.message); console.error('Browser error:', error.message); });
    page.on('dialog', dialog => dialog.accept());
    let currentHtml = render('console');
    for (const match of currentHtml.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)) new vm.Script(match[1]);
    await page.route('https://whmcs.example.test/**', async route => {
      const request = route.request();
      if (request.method() === 'GET') return route.fulfill({ contentType: 'text/html', body: currentHtml });
      const contentType = request.headers()['content-type'] || '';
      let fields;
      if (contentType.includes('multipart/form-data')) {
        fields = {};
        for (const match of request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)) fields[match[1]] = match[2];
      } else fields = Object.fromEntries(new URLSearchParams(request.postData()));
      requests.push(fields);
      assert.equal(fields.token, 'fixture-csrf', fields.action + ' must send a CSRF token');
      assert.ok(fields.action, 'action travels in the POST body');
      const response = responses.shift() || { success: true };
      await route.fulfill({ status: response.httpStatus || 200, contentType: 'application/json', body: JSON.stringify(response) });
    });
    const load = async tab => {
      currentHtml = render(tab);
      await page.goto('https://whmcs.example.test/admin/addonmodules.php?module=sahdev&action=admin_copilot');
    };
    const waitReady = async id => page.locator(id).waitFor({ state: 'visible' });
    await load('drawer');
    await page.locator('#sdv-copilot-launcher').click();
    await page.waitForFunction(() => document.getElementById('sdv-copilot-drawer').classList.contains('sdv-open'));
    await page.locator('#sdv-copilot-close').click();
    assert.equal(await page.locator('#sdv-copilot-drawer').evaluate(el => el.classList.contains('sdv-open')), false);
    await page.keyboard.press('Control+Space');
    await page.waitForFunction(() => document.getElementById('sdv-copilot-drawer').classList.contains('sdv-open'));
    checks += 3;

    responses.push({ success: true, session_uuid: 'copilot_server_session', reply: 'First reply <img src=x onerror=alert(1)>' });
    await page.locator('#sdv-copilot-input').fill('First message');
    await page.locator('#sdv-copilot-send').click();
    await page.waitForFunction(() => document.querySelector('#sdv-copilot-msgs').textContent.includes('First reply'));
    assert.equal(await page.locator('#sdv-copilot-msgs img').count(), 0);
    responses.push({ success: true, session_uuid: 'copilot_server_session', reply: 'Second reply' });
    await page.locator('#sdv-copilot-input').fill('Follow up');
    await page.locator('#sdv-copilot-input').press('Enter');
    await page.waitForFunction(() => document.querySelector('#sdv-copilot-msgs').textContent.includes('Second reply'));
    assert.equal(requests.at(-1).session_uuid, 'copilot_server_session');
    checks += 2;

    await page.keyboard.press('Escape');
    await page.keyboard.press('Alt+k');
    await waitReady('#sahdevQuickJumpInput');
    await page.locator('#sahdevQuickJumpInput').fill('copilot');
    assert.ok((await page.locator('#sahdevQuickJumpResults a').first().getAttribute('href')).includes('action=admin_copilot'));
    await page.keyboard.press('Escape');
    checks++;

    await load('console');
    responses.push({ success: true, reply: 'MRR from tool: 100 USD' });
    await page.locator('#copilot-input').fill("Show today's MRR and revenue status");
    await page.locator('#copilot-send-btn').click();
    await page.waitForFunction(() => document.querySelector('#copilot-messages-container').textContent.includes('MRR from tool'));
    assert.ok(requests.at(-1).session_uuid.startsWith('copilot_'));
    responses.push({ httpStatus: 403, status: 'error', message: 'Invalid CSRF token. Please refresh the page and try again.' });
    await page.locator('#copilot-input').fill('Expired session');
    await page.locator('#copilot-input').press('Control+Enter');
    await page.waitForFunction(() => document.querySelector('#copilot-messages-container').textContent.includes('Invalid CSRF token'));
    assert.equal(await page.locator('#copilot-send-btn').isDisabled(), false);
    checks += 2;

    responses.push({ success: true, reply: 'Review this proposal', action_cards: [proposal] });
    await page.locator('#copilot-input').fill('Update notes');
    await page.locator('#copilot-send-btn').click();
    await waitReady('.copilot-confirm-action');
    assert.equal(await page.locator('.action-card img').count(), 0);
    responses.push({ success: true, journal_id: 42 });
    await page.locator('.copilot-confirm-action').click();
    await page.waitForFunction(() => document.querySelector('.action-card').textContent.includes('Journal #42'));
    assert.deepEqual(JSON.parse(requests.at(-1).params), proposal.params);
    assert.equal(requests.at(-1).session_id, '1');
    assert.equal(requests.at(-1).action, 'copilot_execute_op');
    assert.equal(requests.at(-1).proposal_id, 'prop_fixture_saved');
    checks += 3;

    await page.evaluate(() => {
      const card = SahdevCopilot.action(document.getElementById('copilot-messages-container'), { status: 'pending', can_execute: true, proposal_id: 'prop_delete_fixture', session_id: 1, action_key: 'database_plan', title: 'Delete note', risk_tier: 3, requires_password: true, params: { resource: 'client_notes', operation: 'delete', record_id: 12 }, plan: {changes:[{kind:'delete',label:'Note #12',before:{id:12,note:'Old note'},after:null}]} }, copilotOptions);
      card.id = 'destructive-card';
      document.getElementById('copilot-messages-container').appendChild(card);
    });
    const beforePassword = requests.length;
    await page.locator('#destructive-card .copilot-confirm-action').click();
    assert.equal(requests.length, beforePassword);
    responses.push({ success: true, journal_id: 43 });
    await page.locator('#destructive-card input').fill('fixture-password');
    await page.locator('#destructive-card .copilot-confirm-action').click();
    await page.waitForFunction(() => document.getElementById('destructive-card').textContent.includes('Journal #43'));
    assert.equal(requests.at(-1).admin_password, 'fixture-password');
    checks += 2;

    await load('journal');
    responses.push({ status: 'error', message: 'Rollback fixture response' });
    await page.evaluate(() => { const btn = document.createElement('button'); document.body.appendChild(btn); triggerRollback(42, btn); });
    await page.waitForFunction(() => Array.from(document.querySelectorAll('button')).some(el => el.textContent.includes('Rollback') && !el.disabled));
    assert.equal(requests.at(-1).action, 'copilot_rollback_op');
    assert.equal(requests.at(-1).journal_id, '42');
    checks++;

    await load('takeover');
    responses.push({ status: 'error', message: 'Takeover fixture response' });
    const takeoverResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.request().postData().includes('action=chat_takeover'));
    await page.evaluate(() => claimChatTakeover('visitor-fixture'));
    await takeoverResponse;
    assert.equal(requests.at(-1).action, 'chat_takeover');
    checks++;


    await load('history');
    assert.equal(await page.evaluate(() => { const el = document.createElement('div'); SahdevCopilot.markdown(el, '- \n1. \n* \n- Valid item'); return el.querySelectorAll('li').length; }), 1, 'Empty Markdown list markers cannot freeze rendering');
    checks++;
    assert.equal(await page.locator('#copilot-messages-container h3').first().textContent(), 'Invoices — Dhruv Joshi');
    assert.equal(await page.locator('#copilot-messages-container .sdv-copilot-markdown strong').first().textContent(), 'Two invoices');
    assert.equal(await page.locator('#copilot-messages-container .sdv-copilot-markdown table tbody tr').count(), 1);
    assert.equal(await page.locator('#copilot-messages-container .sdv-copilot-markdown li').count(), 2);
    assert.equal(await page.locator('#copilot-messages-container a[href^="javascript:"]').count(), 0);
    assert.equal(await page.evaluate(() => window.bad), undefined);
    assert.equal(await page.locator('.sdv-copilot-records a[href*="invoices.php?action=edit&id="]').count(), 2);
    assert.equal(await page.locator('.sdv-copilot-records a[href*="clientssummary.php?userid=12"]').count(), 3);
    assert.equal(await page.locator('.sdv-copilot-records details').count(), 1);
    assert.equal(await page.locator('#copilot-messages-container .copilot-confirm-action').count(), 0);
    assert.ok((await page.locator('#copilot-messages-container').textContent()).includes('expired'));
    checks += 11;
    responses.push({success:false, error:'Record changed since execution; rollback refused.'});
    await page.locator('#copilot-messages-container .sdv-btn-rollback').click();
    await page.waitForFunction(() => document.querySelector('#copilot-messages-container').textContent.includes('rollback refused'));
    assert.equal(await page.locator('#copilot-messages-container .sdv-btn-rollback').isDisabled(), false);
    assert.ok(await page.locator('#copilot-messages-container .diff-table').count() > 0);
    checks += 2;
    await page.setViewportSize({width:860,height:1000});
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1), false);
    checks++;
    await page.setViewportSize({width:1440,height:1000});
    await page.evaluate(()=>{document.getElementById('copilot-messages-container').scrollTop=0;});
    if (process.env.SAHDEV_COPILOT_SCREENSHOT) await page.screenshot({path:process.env.SAHDEV_COPILOT_SCREENSHOT,fullPage:true});

    await load('drawer');
    await page.locator('#sdv-copilot-launcher').click();
    const recordCard = {kind:'records', title:'Invoices', subtitle:'Showing 1–2 of 3 permitted records', columns:['Invoice','Client','Status'], rows:[
      {cells:[{text:'#75992',url:'invoices.php?action=edit&id=75992'},{text:'Dhruv Joshi',url:'clientssummary.php?userid=12'},{text:'Paid',badge:true}]},
      {cells:[{text:'#70049',url:'invoices.php?action=edit&id=70049'},{text:'Dhruv Joshi',url:'clientssummary.php?userid=12'},{text:'Unpaid',badge:true}]}
    ],next_prompt:'Show invoices for client #12, page 2'};
    responses.push({success:true, reply:'## Invoices\n**Open a record** below. <img src=x onerror=alert(1)>', result_cards:[recordCard], action_cards:[proposal]});
    await page.locator('#sdv-copilot-input').fill('Show invoices');await page.locator('#sdv-copilot-send').click();
    await waitReady('#sdv-copilot-msgs .sdv-copilot-records');
    assert.equal(await page.locator('#sdv-copilot-msgs img').count(),0);
    assert.equal(await page.locator('#sdv-copilot-msgs .sdv-copilot-markdown h3').last().textContent(),'Invoices');
    assert.equal(await page.locator('#sdv-copilot-msgs .sdv-copilot-records a[href*="invoices.php"]').count(),2);
    responses.push({success:true,reply:'Final page'});
    await page.locator('#sdv-copilot-msgs .sdv-copilot-records button').click();
    await page.waitForFunction(()=>document.querySelector('#sdv-copilot-msgs').textContent.includes('Final page'));
    assert.equal(requests.at(-1).message,recordCard.next_prompt);
    const beforeApproval=requests.length;
    responses.push({success:true,journal_id:88,can_rollback:true});
    await page.evaluate(()=>{const btn=document.querySelector('#sdv-copilot-msgs .sdv-btn-confirm');btn.click();btn.click();});
    await page.waitForFunction(()=>document.querySelector('#sdv-copilot-msgs').textContent.includes('Journal #88'));
    assert.equal(requests.length,beforeApproval+1);
    assert.equal(requests.at(-1).proposal_id,proposal.proposal_id);
    responses.push({success:true,message:'All saved database changes were rolled back.'});
    await page.locator('#sdv-copilot-msgs .sdv-btn-rollback').click();
    await page.waitForFunction(()=>document.querySelector('#sdv-copilot-msgs').textContent.includes('All saved database changes were rolled back.'));
    assert.equal(await page.locator('#sdv-copilot-msgs .sdv-btn-rollback').count(),0);
    checks += 7;

    await load('access');
    assert.equal(await page.locator('input[name="rules[invoices][operations][select]"]').count(),1);
    assert.equal(await page.locator('input[name="rules[client_notes][operations][delete]"]').count(),1);
    assert.equal(await page.locator('select[name="rules[clients][write_fields][]"] option[value="password"]').count(),0);
    assert.equal(await page.locator('input[name="revision"]').getAttribute('value').then(v=>v.length),64);
    checks += 4;
    await page.addInitScript(() => Object.defineProperty(window, 'sessionStorage', { get() { throw new DOMException('Storage disabled', 'SecurityError'); } }));
    await load('console');
    await page.locator('#sdv-copilot-launcher').click();
    await page.waitForFunction(() => document.getElementById('sdv-copilot-drawer').classList.contains('sdv-open'));
    checks++;
    assert.deepEqual(errors, [], 'No browser runtime errors');
    console.log(`PASS: ${checks} Copilot browser checks (drawer, shortcuts, threads, messages, CSRF errors, proposals, passwords, rollback, history, Markdown, record links, pagination, policy settings, takeover, blocked storage).`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
