/* Run with Playwright installed outside production: SAHDEV_PLAYWRIGHT=/path/to/playwright node scratch/test_settings_ui.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.SAHDEV_PLAYWRIGHT || 'playwright');
const html = execFileSync('php', [path.join(__dirname, 'render_settings_fixture.php')], { encoding: 'utf8' });
let posts = [];
let failTheme = false;
let browser;
const server = http.createServer((request, response) => {
    if (request.method === 'POST') {
        let body = '';
        request.on('data', chunk => body += chunk);
        request.on('end', () => {
            const values = new URLSearchParams(body);
            posts.push(values);
            if (values.get('action') === 'set_theme_mode') {
                response.setHeader('Content-Type', 'application/json');
                response.end(JSON.stringify(failTheme ? { status: 'error' } : { status: 'success', theme_mode: values.get('theme_mode') }));
            } else {
                response.setHeader('Content-Type', 'text/html');
                response.end(html);
            }
        });
    } else { response.setHeader('Content-Type', 'text/html'); response.end(html); }
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = `http://127.0.0.1:${server.address().port}/addonmodules.php?module=sahdev`;
    browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1600, height: 1050 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    const visiblePanes = () => page.locator('.sdv-settings-pane:visible').evaluateAll(nodes => nodes.map(node => node.id));
    const theme = () => page.locator('.sahdev-ui').getAttribute('data-sahdev-theme');
    await page.goto(url);
    assert.deepEqual(await visiblePanes(), ['tab-appearance']);
    const tabs = await page.locator('[data-tab]').evaluateAll(nodes => nodes.map(node => node.dataset.tab));
    assert.equal(tabs.length, 8);
    const fields = await page.locator('#sdvSettingsForm [name]').count();
    for (const id of tabs) {
        await page.locator(`[data-tab="${id}"]`).click();
        assert.deepEqual(await visiblePanes(), [id]);
        assert.equal(await page.locator(`[data-tab="${id}"]`).getAttribute('aria-current'), 'true');
    }
    assert.equal(await page.locator('#sdvSettingsForm [name]').count(), fields);
    await page.evaluate(() => location.hash = 'invalid-section');
    assert.deepEqual(await visiblePanes(), [tabs.at(-1)]);
    await page.evaluate(() => location.hash = 'tab-ticket');
    await page.waitForFunction(() => document.querySelector('#tab-ticket-ai').classList.contains('active'));
    await page.locator('#sdv-settings-search').fill('max response tokens');
    assert.deepEqual(await visiblePanes(), ['tab-ai-engine']);
    await page.locator('#sdv-settings-search').press('Enter');
    assert.equal(posts.length, 0, 'Searching must not submit settings');
    await page.locator('#sdv-settings-search').fill('nothing-matches-this');
    assert.equal(await page.locator('#sdv-settings-empty').isVisible(), true);
    await page.locator('#sdv-settings-search').fill('');
    assert.equal((await visiblePanes()).length, 1);
    await page.locator('#sdv-menu-search').fill('role permissions');
    assert.equal(await page.locator('.sdv-nav-group a:visible').count(), 1);
    await page.locator('#sdv-menu-search').fill('');
    await page.locator('[data-tab="tab-appearance"]').click();
    const output = process.env.SAHDEV_SCREENSHOTS;
    if (output) fs.mkdirSync(output, { recursive: true });
    for (const [mode, canvas, surface] of [
        ['white', 'rgb(244, 244, 245)', 'rgb(255, 255, 255)'],
        ['dark', 'rgb(21, 24, 29)', 'rgb(36, 41, 48)'],
        ['black', 'rgb(0, 0, 0)', 'rgb(34, 34, 34)'],
    ]) {
        await page.locator(`input[name="ui_theme_mode"][value="${mode}"]`).check();
        assert.equal(await theme(), mode);
        assert.equal(await page.locator('.sahdev-ui').evaluate(node => getComputedStyle(node).backgroundColor), canvas);
        assert.equal(await page.locator('#tab-appearance .sdv-card-body').evaluate(node => getComputedStyle(node).backgroundColor), surface);
        if (output) await page.screenshot({ path: path.join(output, `${mode}.png`), fullPage: true });
    }
    assert.equal(posts.length, 0, 'Preview must not write to server');
    assert.equal(await page.locator('html[data-sahdev-theme],body[data-sahdev-theme]').count(), 0);
    await page.locator('[data-tab="tab-ai-engine"]').click();
    await page.locator('[name="temperature"]').fill('2');
    await page.locator('[data-tab="tab-appearance"]').click();
    await page.keyboard.press('Control+s');
    assert.equal(posts.length, 0, 'Invalid form must not submit');
    assert.deepEqual(await visiblePanes(), ['tab-ai-engine']);
    assert.equal(await page.locator('#sdv-save-status').textContent(), 'Unsaved changes');
    await page.locator('#sdv-settings-reset').click();
    await page.waitForFunction(() => document.querySelector('#sdv-save-status').textContent === 'All changes saved');
    assert.equal(await page.locator('[name="temperature"]').inputValue(), '0.7');
    assert.equal(await page.locator('#sdv-save-status').textContent(), 'All changes saved');
    await page.locator('[name="temperature"]').fill('0.2');
    await page.locator('[data-tab="tab-livechat-alerts"]').click();
    await page.locator('[name="client_chat_notify_new_visitor"]').uncheck();
    await page.locator('[data-tab="tab-appearance"]').click();
    await page.locator('input[name="ui_theme_mode"][value="white"]').check();
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Control+s')]);
    assert.equal(posts[0].get('temperature'), '0.2');
    assert.equal(posts[0].get('ui_theme_mode'), 'white');
    assert.equal(posts[0].has('client_chat_notify_new_visitor'), false);
    assert.equal(posts[0].get('save_settings'), '1');
    await page.evaluate(() => window.sahdevGlobalSetTheme('dark'));
    await page.waitForFunction(() => document.querySelector('#sahdev-theme-status')?.textContent.includes('saved for'));
    assert.equal(posts.at(-1).get('token'), 'fixture-csrf');
    assert.equal(await theme(), 'dark');
    failTheme = true;
    await page.evaluate(() => window.sahdevGlobalSetTheme('white'));
    await page.waitForFunction(() => document.querySelector('#sahdev-theme-status')?.textContent.includes('not saved'));
    assert.equal(await theme(), 'dark', 'Failed persistence rolls back');
    await page.evaluate(() => localStorage.setItem('sahdev_theme_mode', 'white'));
    await page.reload();
    assert.equal(await theme(), 'black', 'Stale browser cache cannot override server');
    for (const width of [1100, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const id of tabs) {
            await page.locator(`[data-tab="${id}"]`).click();
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${id} overflows at ${width}`);
        }
    }
    await page.locator('[data-tab="tab-appearance"]').click();
    if (output) await page.screenshot({ path: path.join(output, 'mobile.png'), fullPage: true });
    await page.keyboard.press('Alt+k');
    assert.equal(await page.locator('.sdv-menu-toggle').getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('#sdv-menu-search').evaluate(node => node === document.activeElement), true);
    // The ticket stylesheet may be emitted after shared assets by WHMCS hooks.
    await page.addStyleTag({ content: fs.readFileSync(path.join(__dirname, '../ui/remastered.css'), 'utf8') });
    await page.evaluate(() => {
        const panel = document.createElement('div');
        panel.id = 'sahdev-ai-panel';
        panel.className = 'panel panel-info sahdev-ui';
        panel.dataset.sahdevTheme = 'black';
        panel.innerHTML = '<div class="panel-heading">Ticket assistance</div><div id="sahdev-ai-body" style="background:#ffffff"><input class="form-control" value="Ticket field"><div id="sahdev-results"><div class="well" style="border-left: 4px solid #d9534f"><strong>Root cause</strong><p>Analysis result</p></div><div class="panel"><div class="panel-body">Generated response</div></div></div></div>';
        document.body.append(panel);
    });
    for (const layout of ['', 'sahdev-remastered']) {
        await page.locator('#sahdev-ai-panel').evaluate((node, mode) => node.className = 'panel panel-info sahdev-ui ' + mode, layout);
        for (const [mode, surface] of [['black', 'rgb(34, 34, 34)'], ['dark', 'rgb(36, 41, 48)'], ['white', 'rgb(255, 255, 255)']]) {
            await page.evaluate(mode => window.sahdevGlobalSetTheme(mode, false), mode);
            for (const selector of ['#sahdev-ai-body', '#sahdev-results .well', '#sahdev-results .panel-body']) {
                await page.waitForFunction(({ selector, surface }) => getComputedStyle(document.querySelector(selector)).backgroundColor === surface, { selector, surface });
                assert.equal(await page.locator(selector).evaluate(node => getComputedStyle(node).backgroundColor), surface, `${layout} ${mode} ${selector}`);
            }
        }
    }
    const noJs = await browser.newPage({ javaScriptEnabled: false });
    await noJs.goto(url);
    assert.equal(await noJs.locator('.sdv-settings-pane:visible').count(), 8, 'No-JS fallback keeps every control accessible');
    assert.deepEqual(errors, []);
    await browser.close();
    console.log('PASS: 8 sections, search, hashes, all palettes, scoped styles, preview, discard, validated shortcut, full POST, CSRF, save failure, cache, 3 responsive widths, no-JS fallback.');
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => { if (browser) await browser.close(); server.close(); });
