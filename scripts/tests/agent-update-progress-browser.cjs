const {chromium} = require('/opt/browser/node_modules/playwright');
const fs = require('fs');
const assert = require('assert/strict');
const root = process.argv[2] || '/source';
const script = fs.readFileSync(root + '/app/public/agent-center.js', 'utf8');
const css = fs.readFileSync(root + '/app/public/agent-center.css', 'utf8');
const origin = 'https://agent-ui.test/agents/fixture';
const fragment = (state, percent) => `<a href="#agent-update">Acompanhar atualização</a><div id="agent-update" class="agent-update-progress" data-agent-update-progress data-operation="fixture-operation" data-state="${state}" data-percent="${percent}"><span data-update-percent>${percent}%</span><div class="agent-update-progress-track" role="progressbar" aria-valuenow="${percent}"><span data-update-fill style="width:${percent}%"></span></div></div>`;

(async () => {
    const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
    try {
        async function scenario(initial, steps, tenant = '1') {
            const page = await browser.newPage();
            let navigations = 0, polls = 0;
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.origin !== new URL(origin).origin) return route.abort();
                if (url.searchParams.has('fragment')) {
                    const state = steps[Math.min(polls++, steps.length - 1)];
                    return route.fulfill({status: 200, headers: {'Content-Type': 'text/html', 'X-AC-Fragment': 'agent-detail', 'X-AC-Tenant': tenant}, body: fragment(...state)});
                }
                const state = navigations++ ? steps[steps.length - 1] : initial;
                return route.fulfill({status: 200, contentType: 'text/html', body: `<html><head><style>${css}</style></head><body><aside class="sidebar" style="height:120px;overflow-y:auto"><nav style="min-height:900px">Navigation</nav></aside><main><div id="agent-detail" data-live-panel data-interval="250" data-tenant="1">${fragment(...state)}</div></main><script>${script}</script></body></html>`});
            });
            await page.goto(origin);
            return {page, errors, navigations: () => navigations};
        }

        const flow = await scenario(['PRECHECK_REQUESTED', 0], [['PLANNED', 25], ['APPROVED', 50], ['RUNNING', 50], ['COMPLETED', 100]]);
        await flow.page.getByRole('link', {name: 'Acompanhar atualização'}).focus();
        await flow.page.waitForFunction(() => document.querySelector('[data-update-percent]').textContent === '25%');
        await flow.page.waitForFunction(() => document.querySelector('[data-update-fill]').style.width === '25%');
        assert.equal(await flow.page.locator('[data-update-fill]').evaluate(el => getComputedStyle(el).transitionDuration), '0.8s');
        await flow.page.waitForFunction(() => document.querySelector('[data-update-percent]').textContent === '50%');
        await flow.page.waitForFunction(() => document.querySelector('[data-update-percent]').textContent === '100%');
        assert.equal(await flow.page.getByRole('progressbar').getAttribute('aria-valuenow'), '100');
        await flow.page.waitForFunction(() => performance.getEntriesByType('navigation')[0].type === 'reload');
        await flow.page.waitForTimeout(1900);
        assert.equal(flow.navigations(), 2, 'completion reloads exactly once');
        assert.deepEqual(flow.errors, []);
        await flow.page.close();

        const idle = await scenario(['PRECHECK_REQUESTED', 0], [['PRECHECK_REQUESTED', 0]]);
        await idle.page.waitForTimeout(1000);
        assert.equal(await idle.page.locator('[data-update-percent]').textContent(), '0%');
        assert.equal(idle.navigations(), 1, 'waiting never fabricates progress or reloads');
        assert.equal(await idle.page.locator('.sidebar').evaluate(el => { el.scrollTop = 50; return el.scrollTop > 0 && getComputedStyle(el).scrollbarWidth === 'none'; }), true, 'hidden scrollbar preserves scrolling');
        assert.deepEqual(idle.errors, []);
        await idle.page.close();

        const mismatch = await scenario(['RUNNING', 50], [['COMPLETED', 100]], '2');
        await mismatch.page.waitForTimeout(1800);
        assert.equal(await mismatch.page.locator('[data-update-percent]').textContent(), '50%');
        assert.equal(mismatch.navigations(), 1, 'other tenant cannot update progress or trigger reload');
        assert.deepEqual(mismatch.errors, []);
        await mismatch.page.close();
        console.log('PASS: confirmed percentages, one completion reload, no reload loop, waiting state, tenant guard, hidden scrollbar with scrolling');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
