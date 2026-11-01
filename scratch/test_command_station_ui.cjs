/* SAHDEV_PLAYWRIGHT=/path/to/playwright node scratch/test_command_station_ui.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.SAHDEV_PLAYWRIGHT || 'playwright');

const root = path.join(__dirname, '..');
const css = ['admin-theme.css', 'remastered.css', 'command-station.css']
  .map(name => fs.readFileSync(path.join(root, 'ui', name), 'utf8')).join('\n');
const hook = fs.readFileSync(path.join(root, 'hooks.php'), 'utf8');
const listCss = hook.slice(hook.indexOf('function sahdev_render_ticket_list_insights'))
  .match(/<style>([\s\S]*?)<\/style>/)[1];

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.SAHDEV_BROWSER_EXECUTABLE || undefined });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    for (const layout of ['focus', 'canvas', 'console']) {
      for (const mode of ['white', 'dark', 'black']) {
        await page.setContent(`<style>${css}\n${listCss}</style>
          <div id="sahdev-ai-panel" class="sahdev-ui panel sahdev-remastered sdv-layout-${layout}"
               data-sahdev-theme="${mode}" data-sdv-density="comfortable" data-sdv-accent="teal">
            <div class="panel-heading">Command Station</div>
            <div id="sahdev-ai-body" class="panel-body">
              <div class="sahdev-rm-tabs"><button class="sahdev-rm-tab active">Analysis</button><button class="sahdev-rm-tab">Tools</button></div>
              <div class="sahdev-rm-pane active" id="rm-pane-analysis">
                <form id="sahdev-ai-form">Inputs</form>
                <div id="sahdev-results" style="display:block"><div class="row"><div class="col-md-6"><div class="well">Evidence</div></div><div class="col-md-6"><div class="panel"><div class="panel-body">Reply</div></div></div></div></div>
              </div>
            </div>
          </div>
          <div class="sdv-list-toolbar ${mode === 'white' ? '' : 'sdv-theme-dark'}"><span class="sdv-sort-wrap"><select class="sdv-sort-select"><option>WHMCS order (original)</option></select></span></div>`);
        const layoutStyle = await page.evaluate(() => {
          const panel = document.querySelector('#sahdev-ai-panel');
          return {
            body: getComputedStyle(panel.querySelector('#sahdev-ai-body')).display,
            analysis: getComputedStyle(panel.querySelector('#rm-pane-analysis')).display,
            direction: getComputedStyle(panel.querySelector('#sahdev-results > .row')).flexDirection,
            accent: getComputedStyle(panel).getPropertyValue('--sdv-command-accent').trim(),
            selectAppearance: getComputedStyle(document.querySelector('.sdv-sort-select')).appearance,
          };
        });
        assert.equal(layoutStyle.accent, '#0d9488');
        assert.equal(layoutStyle.selectAppearance, 'none');
        if (layout === 'focus') assert.equal(layoutStyle.direction, 'row-reverse');
        if (layout === 'canvas') assert.equal(layoutStyle.analysis, 'grid');
        if (layout === 'console') assert.equal(layoutStyle.body, 'grid');
      }
    }
    await page.setViewportSize({width: 700, height: 900});
    await page.setContent(`<style>${css}</style><div id="sahdev-ai-panel" class="sahdev-ui sahdev-remastered sdv-layout-canvas" data-sahdev-theme="black"><div id="sahdev-ai-body"><div id="rm-pane-analysis" class="sahdev-rm-pane active"><form id="sahdev-ai-form"></form></div></div></div>`);
    assert.equal(await page.locator('#rm-pane-analysis').evaluate(el => getComputedStyle(el).display), 'block');
    console.log('PASS: three layouts in white/dark/black, native select reset, and mobile Canvas stack.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
