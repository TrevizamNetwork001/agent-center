// Offline visual QA of authenticated Laravel responses rendered by the test suite.
const { chromium } = require('/opt/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
    const results = [];
    try {
        for (const width of [390, 1440]) {
            for (const theme of ['light', 'dark']) {
                const context = await browser.newContext({viewport:{width,height:1000},colorScheme:theme,reducedMotion:'reduce'});
                const errors = [];
                await context.route('**/*', async route => {
                    const url = new URL(route.request().url());
                    const basename = path.basename(url.pathname);
                    if (['ne8000.html','routeros7.html','noc-form-error.html'].includes(basename)) {
                        return route.fulfill({contentType:'text/html',body:fs.readFileSync('/snapshots/'+basename)});
                    }
                    const file = path.resolve('/public','.'+url.pathname);
                    if (file.startsWith('/public/') && fs.existsSync(file) && fs.statSync(file).isFile()) {
                        const mime = {'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'};
                        return route.fulfill({contentType:mime[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
                    }
                    return route.fulfill({status:503,body:'Offline laboratory visual QA'});
                });
                const page = await context.newPage();
                page.on('pageerror',e=>errors.push(e.message));
                for (const device of ['ne8000','routeros7']) {
                    await page.goto('http://offline.test/'+device+'.html');
                    const lab = page.locator('#readonly-lab-title');
                    await page.locator('section[aria-labelledby="readonly-lab-title"]').evaluate(el=>el.scrollIntoView({block:'start'}));
                    if (!await lab.isVisible() || !await page.getByText('SSH de laboratório', {exact:false}).first().count()) throw new Error('Missing lab result');
                    const overflow = await page.evaluate(()=>document.documentElement.scrollWidth > innerWidth);
                    if (overflow) throw new Error('Horizontal overflow '+device+' '+width);
                    await page.screenshot({path:`/evidence/${device}-${width}-${theme}.png`});
                    const evidence = page.locator('section.card').filter({has:page.getByRole('heading',{name:'Simulação controlada',exact:true})});
                    await evidence.evaluate(el=>el.scrollIntoView({block:'start'}));
                    await evidence.locator('article').first().locator('details').evaluate(el=>{el.open=true;});
                    await page.screenshot({path:`/evidence/${device}-result-${width}-${theme}.png`});
                    results.push({device,width,theme,overflow:false,simulated:true});
                }
                await page.goto('http://offline.test/noc-form-error.html');
                const hostname = page.locator('#noc-hostname');
                if (await hostname.getAttribute('aria-invalid') !== 'true') throw new Error('Missing inline field error');
                if (!await page.locator('#noc-hostname-error').isVisible()) throw new Error('Error not visible');
                if (await page.locator('#noc-management_ip').inputValue() !== '192.0.2.10') throw new Error('Input lost');
                if (!await page.getByRole('button',{name:'Fechar aviso de validação'}).count()) throw new Error('Missing dismiss button');
                await page.screenshot({path:`/evidence/noc-form-error-${width}-${theme}.png`});
                await page.getByRole('button',{name:'Fechar aviso de validação'}).focus();
                await page.keyboard.press('Enter');
                if (await page.locator('#main > .feedback.error').isVisible()) throw new Error('Notice did not dismiss');
                if (!await page.locator('#noc-hostname-error').isVisible()) throw new Error('Inline error removed');
                // Verify the automatic timeout independently of manual dismissal.
                await page.mouse.move(-10, -10);
                await page.clock.install();
                await page.reload();
                await page.clock.fastForward(11000);
                if (await page.locator('#main > .feedback.error').isVisible()) throw new Error('Notice timeout failed');
                results.push({form:true,width,theme,preserved:true,inlineError:true,dismiss:true,autoDismiss:true});
                if (errors.length) throw new Error('Browser errors: '+errors.join(';'));
                await context.close();
            }
        }
        fs.writeFileSync('/evidence/browser-results.json',JSON.stringify({passed:true,network:'none; all routes fulfilled from local fixtures',results},null,2)+'\n');
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1);});
