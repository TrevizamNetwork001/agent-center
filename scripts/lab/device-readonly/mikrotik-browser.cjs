// Offline visual review of an authenticated Laravel fixture response. No live server.
const { chromium } = require('/opt/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
    const results = [];
    try {
        for (const width of [390, 768, 1440]) {
            for (const theme of ['light', 'dark']) {
                const context = await browser.newContext({viewport:{width,height:1000},colorScheme:theme,reducedMotion:'reduce'});
                const errors = [];
                await context.route('**/*', async route => {
                    const url = new URL(route.request().url());
                    if (url.pathname === '/fixture') return route.fulfill({contentType:'text/html',body:fs.readFileSync('/snapshots/mikrotik-protocol.html')});
                    const file = path.resolve('/public','.'+url.pathname);
                    if (file.startsWith('/public/') && fs.existsSync(file) && fs.statSync(file).isFile()) {
                        const mime = {'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'};
                        return route.fulfill({contentType:mime[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
                    }
                    return route.fulfill({status:503,body:'Offline laboratory'});
                });
                const page = await context.newPage();
                page.on('pageerror', e => errors.push(e.message));
                await page.goto('http://offline.test/fixture');
                const lab = page.locator('section[aria-labelledby="routeros-protocol-title"]');
                if (!await lab.isVisible()) throw new Error('RouterOS laboratory panel missing');
                if (await lab.locator('form').count() !== 6) throw new Error('Six typed operations missing');
                if (await lab.getByLabel('Destino IPv4 do cenário').inputValue() !== '8.8.8.8') throw new Error('Missing typed destination');
                await lab.scrollIntoViewIfNeeded();
                if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) throw new Error('Horizontal overflow');
                await page.screenshot({path:`/evidence/routeros-panel-${width}-${theme}.png`});
                const article = page.locator('article').filter({hasText:'RouterOS 7 · LAB / SIMULADO'}).first();
                await article.scrollIntoViewIfNeeded();
                await article.locator('details').evaluate(el => {el.open=true;});
                await page.screenshot({path:`/evidence/routeros-result-${width}-${theme}.png`});
                if (!await article.getByText('SIMULATED / LAB', {exact:false}).count()) throw new Error('Simulation label missing');
                await lab.getByLabel('Destino IPv4 do cenário').focus();
                if (!await lab.getByLabel('Destino IPv4 do cenário').evaluate(el=>el===document.activeElement)) throw new Error('Keyboard focus unavailable');
                if (errors.length) throw new Error('Browser runtime errors');
                results.push({width,theme,typedForms:6,simulated:true,overflow:false,keyboardFocus:true,errors:0});
                await context.close();
            }
        }
        fs.writeFileSync('/evidence/browser-results.json', JSON.stringify({passed:true,network:'none; local route fulfillment only',results},null,2)+'\n');
    } finally {await browser.close();}
})().catch(e => {console.error(e.message);process.exit(1);});
