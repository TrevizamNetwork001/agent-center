'use strict';
const { chromium } = require('/opt/browser/node_modules/playwright');
const fs = require('fs');
(async () => {
  const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
  const errors = [];
  let screenshots = 0;
  for (const width of [390, 900, 1440]) for (const theme of ['light', 'dark']) {
    const page = await browser.newPage({viewport: {width, height: 1100}, colorScheme: theme});
    page.on('pageerror', error => errors.push(error.message));
    const html = fs.readFileSync('/fixture/observability.html', 'utf8').replaceAll('data-live-panel', 'data-visual-panel');
    await page.route('**/*', route => {
      const path = new URL(route.request().url()).pathname;
      if (path === '/agent-center.css' || path === '/agent-center.js') return route.fulfill({contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript', body: fs.readFileSync('/assets'+path)});
      return route.fulfill({contentType: 'text/html', body: html});
    });
    await page.goto('https://observability.test/agents/lab');
    const section = page.locator('#agent-observability');
    await section.scrollIntoViewIfNeeded();
    if (await section.locator('select[name=capability] option').count() !== 7) throw Error('Missing existing capabilities');
    if (await section.locator('summary').count() !== 7) throw Error('Missing compact sections');
    if (!(await section.textContent()).includes('INDISPONÍVEL')) throw Error('Unknown state hidden');
    await section.locator('summary').filter({hasText: /^Políticas$/}).click();
    if (!(await section.textContent()).includes('B. Teto administrativo')) throw Error('Missing ceiling layer');
    if (await section.locator('form').getAttribute('method') !== 'post') throw Error('Missing CSRF protected form');
    await page.evaluate(t => { document.documentElement.dataset.theme = t; }, theme);
    if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) throw Error('Horizontal overflow at '+width);
    await page.evaluate(() => { if (document.activeElement instanceof HTMLElement) document.activeElement.blur(); });
    const box = await section.boundingBox();
    await page.setViewportSize({width, height: Math.ceil(box.height) + 80});
    await section.scrollIntoViewIfNeeded();
    await section.screenshot({style: '.skip-link { visibility: hidden !important; }', path: `/evidence/observability-${width}-${theme}.png`});
    screenshots++;
    await page.close();
  }
  await browser.close();
  if (errors.length) throw Error(errors.join('\n'));
  console.log(JSON.stringify({screenshots, sections: 7, policyLayers: 'PASS', pageErrors: errors}));
})().catch(error => { console.error(error); process.exit(1); });
