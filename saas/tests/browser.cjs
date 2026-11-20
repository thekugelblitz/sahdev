const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.SAHDEV_PLAYWRIGHT || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.SAHDEV_PHP || 'php';
const database = path.join(root, 'storage/framework/saas_ui_test_' + process.pid + '.sqlite');
fs.mkdirSync(path.dirname(database), { recursive: true }); fs.writeFileSync(database, '');
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: 'base64:' + crypto.randomBytes(32).toString('base64'), APP_URL: 'http://127.0.0.1:8791', DB_CONNECTION: 'sqlite', DB_DATABASE: database, SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'database', MAIL_MAILER: 'log', REGISTRATION_ENABLED: 'true' };
const seeded = spawnSync(php, ['tests/browser-fixture.php'], { cwd: root, env, encoding: 'utf8' });
if (seeded.status !== 0) throw new Error(seeded.stderr + seeded.stdout);
const fixture = JSON.parse(seeded.stdout);
let stderr = '';
const server = spawn(php, ['-S', '127.0.0.1:8791', '-t', 'public', 'tests/server-router.php'], { cwd: root, env, stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
server.stderr.on('data', data => stderr += data);
let browser;
async function checkWhmcsCopilot(context) {
  const addon = path.resolve(root, '../connector/sahdev_cloud');
  const phpView = spawnSync(php, ['-r', 'require "src/CopilotPanel.php"; echo SahdevConnector\\CopilotPanel::render($argv[1],"fixture-csrf",true,true);', env.APP_URL], { cwd: addon, env, encoding: 'utf8' });
  assert.equal(phpView.status, 0, phpView.stderr);
  const page = await context.newPage();
  const fixtureErrors = [];
  page.on('pageerror', error => fixtureErrors.push(error.message));
  page.on('requestfailed', request => fixtureErrors.push(request.failure()?.errorText));
  try {
    await page.route('**/custom-admin/index.php', route => route.fulfill({
      contentType:'text/html',
      body:'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' + phpView.stdout
    }));
    await page.route('**/sahdev_cloud/panel.css', route => route.fulfill({path:path.join(addon,'panel.css'),contentType:'text/css'}));
    await page.route('**/sahdev_cloud/copilot.js', route => route.fulfill({path:path.join(addon,'copilot.js'),contentType:'application/javascript'}));
    await page.route('**/sahdev_cloud/bridge.php', async route => {
      const request = route.request().postDataJSON();
      assert.equal(request.csrf,'fixture-csrf');
      if (request.action === 'copilot_search') {
        const client = {name:'Fixture Client', company:'Hosting', status:'Active'};
        await route.fulfill({json:{clients:[{id:5,...client},{id:7,...client}],has_more:false}});
      } else if (request.client_id === 7 && request.resource === 'invoices') {
        await route.fulfill({json:{
          title:'Invoices', client:{text:'Fixture Client',record:{type:'client',id:7}},
          rows:[[{text:'INV-'+request.page,record:{type:'invoice',id:request.page}}]],
          columns:['Invoice'], page:request.page, has_more:request.page === 1
        }});
      } else {
        await route.fulfill({status:403,json:{error:'Required WHMCS permission: View Clients Products/Services.'}});
      }
    });
    await page.goto(env.APP_URL+'/custom-admin/index.php');
    await page.getByText('Sahdev Cloud Copilot',{exact:true}).click();
    await page.getByLabel('Client ID, full name or exact company name').fill('Fixture Client');
    await page.getByRole('button',{name:'Find client'}).click();
    const client = page.getByLabel('Client',{exact:true});
    await client.waitFor({timeout:5000});
    assert.equal(await client.inputValue(),'');
    await client.selectOption('7');
    await page.getByRole('button',{name:'Show records'}).click();
    const invoice = page.getByRole('link',{name:'INV-1',exact:true});
    assert.equal(await invoice.getAttribute('href'),env.APP_URL+'/custom-admin/invoices.php?action=view&id=1');
    assert.equal(await page.getByRole('link',{name:'Fixture Client',exact:true}).getAttribute('href'),env.APP_URL+'/custom-admin/clientssummary.php?userid=7');
    await page.getByRole('button',{name:'Next page'}).click();
    await page.getByRole('link',{name:'INV-2',exact:true}).waitFor();
    await page.getByLabel('Records',{exact:true}).selectOption('services');
    await page.getByRole('button',{name:'Show records'}).click();
    await page.getByText('Required WHMCS permission: View Clients Products/Services.',{exact:true}).waitFor();
    assert.equal(await page.getByRole('link',{name:'INV-2',exact:true}).count(),0);
  } catch (error) {
    const status = await page.locator('[data-sd-status]').textContent();
    throw new Error(`Copilot fixture failed. Status: ${status}. Browser errors: ${fixtureErrors.join(', ')}`, {cause:error});
  } finally {
    await page.close();
  }
}
(async () => {
  for (let i = 0; i < 50; i++) { try { const r = await fetch(env.APP_URL + '/login'); if (r.ok) break; } catch {} await new Promise(r => setTimeout(r, 100)); }
  browser = await chromium.launch({ headless: true, ...(process.env.SAHDEV_BROWSER_EXECUTABLE ? { executablePath: process.env.SAHDEV_BROWSER_EXECUTABLE } : {}) });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }); const page = await context.newPage(); const errors = [];
  await checkWhmcsCopilot(context);
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(env.APP_URL + '/command/login');
  await page.getByLabel('Email address').fill('operator@example.test'); await page.getByLabel('Password', { exact: true }).fill('Fixture-password-12345');
  await Promise.all([page.waitForURL('**/command'), page.getByRole('button', { name: 'Sign in →' }).click()]);
  assert(await page.getByRole('heading', { name: 'Platform control.' }).isVisible());
  const createProvider = page.locator('#providers details').last(); await createProvider.locator('summary').click();
  await createProvider.getByLabel('Name', { exact: true }).fill('Browser provider');
  await createProvider.getByLabel('API base URL or full endpoint').fill('https://openrouter.ai/api/v1');
  await createProvider.getByLabel('API key', { exact: true }).fill('fixture-browser-secret');
  await Promise.all([page.waitForURL('**/command?create=models&provider=*#models'), createProvider.getByRole('button', { name: 'Create', exact: true }).click()]);
  assert(await page.getByLabel('Provider model identifier', { exact: true }).isVisible());
  assert(await page.locator('#providers').getByText('Browser provider', { exact: false }).isVisible());
  assert(!(await page.content()).includes('fixture-browser-secret'));
  await page.goto(env.APP_URL + '/command/release');
  assert(await page.getByRole('heading', { name: 'Public release readiness.' }).isVisible());
  await page.getByLabel('Feature domain', { exact: true }).selectOption('safe_operations');
  await page.getByLabel('Search capabilities and flows').fill('rollback');
  await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Apply filters' }).click()]);
  assert.equal(await page.locator('.rr-domain').count(), 1);
  assert(await page.locator('.rr-capability').count() > 0);
  await page.getByRole('button', { name: 'Expand results' }).click();
  assert.equal(await page.locator('.rr-expandable:not([open])').count(), 0);
  await page.getByRole('button', { name: 'Collapse results' }).click();
  assert.equal(await page.locator('.rr-expandable[open]').count(), 0);
  await page.goto(env.APP_URL + '/command/release#capability-safe_operations-rollback');
  assert(await page.locator('#capability-safe_operations-rollback').isVisible());
  const reportResponse = await context.request.get(env.APP_URL + '/command/release/export');
  assert.equal(reportResponse.status(), 200);
  const report = await reportResponse.json();
  assert.equal(report.summary.capabilities, 176); assert.equal(report.summary.journeys, 31); assert.equal(report.ready, false);
  await page.setViewportSize({ width: 390, height: 844 });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Readiness page should fit mobile width');
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(env.APP_URL + '/dashboard');
  assert(await page.getByRole('heading', { name: 'HostingSpell workspace' }).isVisible());
  assert(await page.getByRole('heading', { name: 'CloudNest' }).isVisible());
  await page.locator('#new-brand summary').click();
  await page.getByLabel('Brand name', { exact: true }).fill('OrbitHost'); await page.getByLabel('WHMCS URL').fill('https://billing.orbithost.example.com');
  await page.getByLabel('Monthly budget in credits').fill('250');
  await Promise.all([page.waitForURL('**/brands/*'), page.getByRole('button', { name: 'Create brand', exact: true }).click()]);
  assert(await page.getByRole('heading', { name: 'OrbitHost', exact: true }).isVisible());
  assert(await page.getByText('No remote access to your WHMCS MySQL database is required.', { exact: false }).isVisible());
  await page.goto(env.APP_URL + '/brands/' + fixture.allocated);
  await page.getByLabel('Amount in credits', { exact: true }).fill('25');
  await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Transfer credits', exact: true }).click()]);
  assert(await page.getByText('Credit transfer recorded.').isVisible());
  await page.goto(env.APP_URL + '/billing'); assert(await page.getByRole('heading', { name: 'Credit ledger', exact: true }).isVisible());
  await page.goto(env.APP_URL + '/team'); assert(await page.getByRole('heading', { name: 'Add or update a team member' }).isVisible());
  const noCsrf = await context.request.post(env.APP_URL + '/brands', { form: { name: 'CSRF attempt' }, headers: { 'Sec-Fetch-Site': 'cross-site' } }); assert.equal(noCsrf.status(), 419);
  const badSignature = await context.request.get(env.APP_URL + '/api/v1/models'); assert.equal(badSignature.status(), 401);
  const folder = path.resolve(root, '../build/saas-ui'); fs.mkdirSync(folder, { recursive: true });
  await page.goto(env.APP_URL + '/dashboard'); await page.screenshot({ path: path.join(folder, 'desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 }); await page.screenshot({ path: path.join(folder, 'mobile.png'), fullPage: true });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Mobile page should not overflow horizontally');
  assert.deepEqual(errors, []);
  console.log('PASS browser: WHMCS Copilot lookup and links, operator login, provider CRUD/redaction, readiness, brands, allocation, billing, team, CSRF and signed auth.');
})().catch(error => { console.error(error); console.error(stderr.slice(-3000)); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close(); server.kill();
  await new Promise(resolve => setTimeout(resolve, 500));
  for (const file of [database, database + '-wal', database + '-shm']) { try { fs.unlinkSync(file); } catch {} }
});
