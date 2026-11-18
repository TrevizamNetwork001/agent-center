// Read-only browser audit of HTML rendered from transactional Laravel fixtures.
// Run in agent-center-browser:recovery4 with networking disabled and readonly
// mounts /snapshots (HTML), /public (app/public), plus writable /evidence.
const {chromium} = require('/opt/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    const results = [], errors = [], findings = [];
    const pages = fs.readdirSync('/snapshots').filter(name => name.endsWith('.html'));
    const mime = {'.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml'};
    try {
        const context = await browser.newContext({reducedMotion: 'reduce'});
        await context.route('**/*', async route => {
            const url = new URL(route.request().url());
            const name = path.basename(url.pathname);
            if (pages.includes(name)) {
                return route.fulfill({contentType: 'text/html', body: fs.readFileSync('/snapshots/' + name)});
            }
            const file = path.resolve('/public', '.' + url.pathname);
            if (file.startsWith('/public/') && fs.existsSync(file) && fs.statSync(file).isFile()) {
                return route.fulfill({contentType: mime[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
            }
            return route.fulfill({status: 503, body: 'Offline fixture audit: network disabled'});
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        for (const width of [360, 768, 1366, 1920]) {
            await page.setViewportSize({width, height: 900});
            for (const theme of ['light', 'dark']) {
                await page.emulateMedia({colorScheme: theme});
                for (const name of pages) {
                    await page.goto('http://layout.test/' + name);
                    await page.evaluate(t => {
                        const key = 'agent-center.theme.' + document.body.dataset.themeUser;
                        localStorage.setItem(key, t);
                        document.documentElement.dataset.theme = t;
                    }, theme);
                    if (name === 'dashboard.html') await page.waitForSelector('[data-react-dashboard="ready"]');
                    const result = await page.evaluate(() => {
                        const visible = element => element.checkVisibility({visibilityProperty: true});
                        const rgb = value => (value.match(/[\d.]+/g) || []).map(Number);
                        const lum = c => c.slice(0, 3).map(v => {v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4;}).reduce((s, v, i) => s + v * [.2126, .7152, .0722][i], 0);
                        const contrast = [];
                        for (const element of document.querySelectorAll('a,button,label,p,td,th,small,h1,h2,h3,summary,.badge,.react-badge')) {
                            if (!visible(element) || !element.textContent.trim()) continue;
                            const style = getComputedStyle(element);
                            let parent = element, background;
                            while (parent) {
                                background = rgb(getComputedStyle(parent).backgroundColor);
                                if (background.length === 3 || background[3] === 1) break;
                                parent = parent.parentElement;
                            }
                            const foreground = rgb(style.color);
                            if (!parent || foreground.length > 3 && foreground[3] !== 1) continue;
                            const a = lum(foreground), b = lum(background);
                            const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
                            const large = parseFloat(style.fontSize) >= 24 || parseFloat(style.fontSize) >= 18.66 && parseInt(style.fontWeight) >= 700;
                            if (ratio < (large ? 3 : 4.5)) contrast.push({tag: element.tagName, class: element.className, ratio: +ratio.toFixed(2)});
                        }
                        return {
                            overflow: document.documentElement.scrollWidth > innerWidth,
                            h1: document.querySelectorAll('h1').length,
                            title: document.title,
                            unnamedFields: [...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e => visible(e) && !e.labels?.length && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby')).map(e => e.name),
                            contrast,
                        };
                    });
                    results.push({page: name, width, theme, ...result});
                    if ([360, 1366].includes(width) && ['dashboard.html', 'users.html', 'tenants.html', 'login.html', 'releases.html'].includes(name)) {
                        await page.screenshot({path: `/evidence/${width}-${theme}-${name}.png`, fullPage: true});
                    }
                }
            }
        }
        await page.setViewportSize({width: 360, height: 900});
        await page.goto('http://layout.test/dashboard.html');
        const toggle = page.locator('[data-nav-toggle]');
        await toggle.click();
        findings.push({name: 'mobile_nav_moves_focus_inside', pass: await page.evaluate(() => document.querySelector('#sidebar').contains(document.activeElement))});
        findings.push({name: 'mobile_nav_background_inert', pass: await page.locator('main').evaluate(e => e.inert)});
        const lastLink = page.locator('#sidebar a[href]').last();
        await lastLink.focus();
        await page.keyboard.press('Tab');
        findings.push({name: 'mobile_nav_tab_to_close', pass: await toggle.evaluate(e => e === document.activeElement)});
        await page.keyboard.press('Tab');
        findings.push({name: 'mobile_nav_tab_wraps', pass: await page.locator('#sidebar a[href]').first().evaluate(e => e === document.activeElement)});
        await page.keyboard.press('Shift+Tab');
        findings.push({name: 'mobile_nav_reverse_tab_wraps', pass: await toggle.evaluate(e => e === document.activeElement)});
        await page.keyboard.press('Escape');
        findings.push({name: 'mobile_nav_escape_returns_focus', pass: await toggle.evaluate(e => e === document.activeElement) && await toggle.getAttribute('aria-expanded') === 'false'});
        findings.push({name: 'mobile_nav_restores_background', pass: await page.locator('main').evaluate(e => !e.inert)});
        await toggle.click();
        await page.setViewportSize({width: 1366, height: 900});
        findings.push({name: 'desktop_resize_closes_mobile_nav', pass: await toggle.getAttribute('aria-expanded') === 'false' && await page.locator('main').evaluate(e => !e.inert)});
        await page.locator('.skip-link').focus();
        await page.keyboard.press('Enter');
        findings.push({name: 'skip_link', pass: await page.locator('#main').evaluate(e => e === document.activeElement)});
        for (const width of [360, 768, 1024, 1366]) {
            await page.setViewportSize({width, height: 900});
            await page.goto('http://layout.test/users.html');
            await page.evaluate(() => {
                document.querySelector('.tenant-context').textContent = 'TENANT · Provedor de Telecomunicações com identificação muito longa';
                document.querySelectorAll('.user-menu strong').forEach(e => e.textContent = 'Administrador com nome extenso para validar o cabeçalho');
            });
            findings.push({name: `long_header_fits_${width}`, pass: await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)});
            if (width === 768) await page.screenshot({path: '/evidence/header-long-768.png', fullPage: true});
        }
        await page.setViewportSize({width: 768, height: 900});
        await page.goto('http://layout.test/users.html');
        const tableRegion = page.getByRole('region', {name: 'Lista de usuários'});
        await tableRegion.focus();
        await page.keyboard.press('ArrowRight');
        await page.waitForTimeout(150);
        findings.push({name: 'tablet_table_keyboard_scroll', pass: await tableRegion.evaluate(e => e.scrollLeft > 0)});
        findings.push({name: 'tablet_status_word_fits', pass: await page.locator('.table .badge').first().evaluate(e => e.getBoundingClientRect().height < 40)});
        const summary = {scope: 'offline Chromium; transactional synthetic Laravel HTML; no external requests or operational submissions',
            pages: pages.length, viewports: [360, 768, 1366, 1920], themes: ['light', 'dark'],
            checks: results.length, overflow: results.filter(r => r.overflow).length,
            missingLabels: results.filter(r => r.unnamedFields.length).length,
            contrastFindings: results.reduce((n, r) => n + r.contrast.length, 0), errors, keyboard: findings, results};
        fs.writeFileSync('/evidence/layout-review.json', JSON.stringify(summary, null, 2));
        console.log(JSON.stringify({...summary, results: undefined}));
        if (summary.overflow || summary.missingLabels || errors.length || findings.some(f => !f.pass)) process.exitCode = 1;
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error.message); process.exitCode = 1;});
