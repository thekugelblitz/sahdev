/* SAHDEV_PLAYWRIGHT=/path/to/playwright node scratch/test_copilot_ui.cjs */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const { chromium } = require(process.env.SAHDEV_PLAYWRIGHT || 'playwright');
const root = path.join(__dirname, '..');
const render = tab => execFileSync(process.env.SAHDEV_PHP || 'php', [path.join(__dirname, 'render_copilot_fixture.php'), tab], { encoding: 'utf8', cwd: root });
const proposal = {
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
    checks += 3;

    await page.evaluate(() => {
      const card = renderActionCardHtml({ action_key: 'invoice_mark_paid', title: 'Mark invoice paid', risk_tier: 3, requires_password: true, params: { invoice_id: 12 }, diff: {} });
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

    await page.addInitScript(() => Object.defineProperty(window, 'sessionStorage', { get() { throw new DOMException('Storage disabled', 'SecurityError'); } }));
    await load('console');
    await page.locator('#sdv-copilot-launcher').click();
    await page.waitForFunction(() => document.getElementById('sdv-copilot-drawer').classList.contains('sdv-open'));
    checks++;
    assert.deepEqual(errors, [], 'No browser runtime errors');
    console.log(`PASS: ${checks} Copilot browser checks (drawer, shortcuts, threads, messages, CSRF errors, proposals, passwords, rollback, takeover, blocked storage).`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
