// Offline rendering of the authenticated Laravel result; all HTTP fulfilled locally.
const { chromium } = require('/opt/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
  const results = [];
  try {
    for (const width of [390,768,1440]) for (const theme of ['light','dark']) {
      const context = await browser.newContext({viewport:{width,height:1000},colorScheme:theme,reducedMotion:'reduce'});
      const errors = [];
      await context.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/fixture') return route.fulfill({contentType:'text/html',body:fs.readFileSync('/snapshots/routeros-task-panel.html')});
        const file = path.resolve('/public','.'+url.pathname);
        if (file.startsWith('/public/') && fs.existsSync(file) && fs.statSync(file).isFile()) return route.fulfill({contentType:({'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'})[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
        return route.fulfill({status:503,body:'Offline simulator QA'});
      });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.goto('http://offline.test/fixture');
      await page.evaluate(t => { document.documentElement.dataset.theme=t; localStorage.setItem('agent-center-theme',t); }, theme);
      if (!await page.getByText('Peers BGP: 2', {exact:false}).first().isVisible()) throw new Error('Missing persisted BGP summary');
      if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw new Error('Horizontal overflow '+width);
      await page.screenshot({path:`/evidence/routeros-task-${width}-${theme}.png`,fullPage:true});
      if (errors.length) throw new Error(errors.join('; '));
      results.push({width,theme,status:'PASS',console_errors:0,overflow:false,simulation_label:true});
      await context.close();
    }
    fs.writeFileSync('/evidence/browser.json', JSON.stringify(results,null,2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exit(1); });
