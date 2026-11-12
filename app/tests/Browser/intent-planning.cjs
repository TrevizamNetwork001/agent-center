const {chromium} = require('/opt/browser/node_modules/playwright');
const fs = require('fs');
const assert = require('assert');
const origin = process.env.INTENT_BROWSER_ORIGIN || 'http://127.0.0.1:18820';
const demo = JSON.parse(fs.readFileSync('/lab/demo.json'));
const password = fs.readFileSync('/lab/password', 'utf8').trim();
(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    const errors = [], results = [];
    for (const width of [390, 768, 1440]) for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({viewport: {width, height: 1000}, colorScheme: theme});
        await context.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/login');
        await page.locator('[name=email]').fill('admin-a@intent.test');
        await page.locator('[name=password]').fill(password);
        await page.getByRole('button', {name: 'Entrar', exact: true}).click();
        await page.waitForURL('**/dashboard');
        for (const [name, path, expected] of [
            ['route', '/diagnostics/' + demo.diagnostic, ['Classificação técnica dos testes', 'NORMAL', 'Análise de rota', 'EXECUTADA', 'Conclusão da solicitação']],
            ['mismatch', '/diagnostics/' + demo.mismatch, ['NORMAL', 'Análise de rota', 'NÃO EXECUTADA', 'INCONCLUSIVO']],
            ['confirmation', '/conversations/' + demo.route, ['caminho de rede até 8.8.8.8', 'Escopo: Caminho MTR', 'Classificação técnica: NORMAL']],
            ['ambiguous', '/conversations/' + demo.ambiguous, ['Um IP sozinho não define um site HTTPS']],
            ['blocked', '/conversations/' + demo.blocked, ['helper MTR não está saudável', 'Nenhuma tarefa foi criada']],
        ]) {
            const response = await page.goto(origin + path);
            assert.equal(response.status(), 200);
            await page.evaluate(t => document.documentElement.dataset.theme = t, theme);
            const text = await page.locator('main').innerText();
            for (const value of expected) assert(text.includes(value), name + ' missing ' + value);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
            assert(!overflow, name + ' overflow ' + width);
            assert(await page.locator('h1').count() === 1);
            await page.keyboard.press('Tab');
            if (width !== 768 || name === 'mismatch') await page.screenshot({path: `/evidence/${name}-${width}-${theme}.png`, fullPage: true});
            results.push({name, width, theme, status: response.status(), overflow});
        }
        await context.close();
    }
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(origin + '/login');
    await page.locator('[name=email]').fill('admin-b@intent.test');
    await page.locator('[name=password]').fill(password);
    await page.getByRole('button', {name: 'Entrar', exact: true}).click();
    await page.waitForURL('**/dashboard');
    const cross = await page.goto(origin + '/diagnostics/' + demo.diagnostic);
    assert.equal(cross.status(), 404);
    assert.equal(errors.length, 0, errors.join('\n'));
    fs.writeFileSync('/evidence/browser.json', JSON.stringify({status: 'PASS', results, crossTenant: cross.status(), errors, fixtures: 'synthetic only; no network probes'}, null, 2));
    await context.close();
    await browser.close();
    console.log('Intent planning browser PASS: 30 page/theme/viewport checks, cross-tenant 404, no JS errors.');
})().catch(error => { console.error(error.stack); process.exit(1); });
