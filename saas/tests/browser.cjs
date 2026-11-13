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
function totp(secret) {
  let bits = ''; for (const c of secret) bits += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2))); const step = Buffer.alloc(8); step.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto.createHmac('sha1', key).update(step).digest(); const offset = h[19] & 15; return ((h.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
let browser;
(async () => {
  for (let i = 0; i < 50; i++) { try { const r = await fetch(env.APP_URL + '/login'); if (r.ok) break; } catch {} await new Promise(r => setTimeout(r, 100)); }
  browser = await chromium.launch({ headless: true, ...(process.env.SAHDEV_BROWSER_EXECUTABLE ? { executablePath: process.env.SAHDEV_BROWSER_EXECUTABLE } : {}) });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } }); const page = await context.newPage(); const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(env.APP_URL + '/command/login');
  await page.getByLabel('Email address').fill('operator@example.test'); await page.getByLabel('Password', { exact: true }).fill('Fixture-password-12345');
  await Promise.all([page.waitForURL('**/command/security'), page.getByRole('button', { name: 'Sign in →' }).click()]);
  await page.getByLabel('Account password').fill('Fixture-password-12345'); await page.getByLabel('Authenticator code').fill(totp('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'));
  await Promise.all([page.waitForURL('**/command'), page.getByRole('button', { name: 'Verify and open Command Station' }).click()]);
  assert(await page.getByRole('heading', { name: 'Platform control.' }).isVisible());
  const createProvider = page.locator('#providers details').last(); await createProvider.locator('summary').click();
  await createProvider.getByLabel('Name', { exact: true }).fill('Browser provider');
  await createProvider.getByLabel('Full API endpoint').fill('https://openrouter.ai/api/v1/chat/completions');
  await createProvider.getByLabel('API key', { exact: true }).fill('fixture-browser-secret');
  await Promise.all([page.waitForURL('**/command#providers'), createProvider.getByRole('button', { name: 'Create', exact: true }).click()]);
  assert(await page.locator('#providers').getByText('Browser provider', { exact: false }).isVisible());
  assert(!(await page.content()).includes('fixture-browser-secret'));
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
  console.log('PASS browser: login, MFA, provider CRUD/redaction, brand creation, allocation, billing, team, CSRF, signed auth and mobile layout.');
})().catch(error => { console.error(error); console.error(stderr.slice(-3000)); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close(); server.kill();
  await new Promise(resolve => setTimeout(resolve, 500));
  for (const file of [database, database + '-wal', database + '-shm']) { try { fs.unlinkSync(file); } catch {} }
});
