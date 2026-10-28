#!/usr/bin/env node
/**
 * Browser smoke test: opens every SPA screen as every demo role and fails on anything a user would
 * hit as a defect — JavaScript errors, API responses of 500 (or unexpected 4xx), the SPA's error or
 * not-found state, and leaked placeholders ("undefined", "NaN", "[object Object]") in visible text.
 * As the draft applicant it also walks the wizard: on each step it presses "Save and continue" and
 * records whether the step saved or which validation messages the candidate sees.
 *
 *   E2E_BASE=http://localhost:8010 CHROME=/usr/bin/google-chrome node tests/e2e/smoke.cjs
 */
const puppeteer = require('puppeteer-core');
const { BASE, CHROME, ROLES, EXPECTED_404, signIn, resolvePages } = require('./pages.cjs');

const PLACEHOLDER = /\b(undefined|NaN|\[object Object\]|null)\b/;

async function main() {
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
    const problems = [];
    const wizard = [];
    let visited = 0;
    try {
        for (const role of ROLES) {
            const ctx = await browser.createBrowserContext();
            const page = await ctx.newPage();
            await page.setViewport({ width: 1366, height: 900 });
            let current = '';
            const note = (kind, detail) => problems.push({ role: role.name, path: current, kind, detail });
            page.on('pageerror', (e) => note('js-error', String(e.message || e).slice(0, 300)));
            // Network failures are recorded from the response itself; the console echo of them is noise.
            page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) note('console-error', m.text().slice(0, 300)); });
            page.on('response', (r) => {
                const s = r.status(), u = r.url();
                if (s >= 500) note('http-5xx', `${s} ${r.request().method()} ${u}`);
                else if (s === 422 && /\/sections\//.test(u)) { /* validation of an incomplete step: reported in the wizard walk */ }
                else if (s >= 400 && s !== 401 && u.includes('/api/') && !EXPECTED_404.some((re) => re.test(u))) note('http-4xx', `${s} ${r.request().method()} ${u}`);
            });

            if (role.email) await signIn(page, role.email);
            else await page.goto(BASE + '/', { waitUntil: 'networkidle0' });
            const [pages, vars] = await resolvePages(page, role);

            for (const path of pages) {
                current = path;
                await page.goto(BASE + path, { waitUntil: 'networkidle0' });
                await new Promise((r) => setTimeout(r, 400));
                visited++;
                const state = await page.evaluate(() => {
                    const main = document.getElementById('main') || document.body;
                    const text = main.innerText || '';
                    return {
                        title: (document.querySelector('.page-title, h1')?.textContent || '').trim().slice(0, 60),
                        text: text.slice(0, 20000),
                        empty: text.trim().length < 20,
                        notFound: /page (was )?not found|404/i.test(text.slice(0, 400)),
                        errorState: /something went wrong|could not load|failed to load|unauthorized/i.test(text),
                    };
                });
                if (state.empty) note('blank-page', 'main content is empty');
                if (state.notFound && path !== '/no-such-page') note('not-found', `rendered the not-found page (title "${state.title}")`);
                if (state.errorState && !/not been forwarded/.test(state.text)) note('error-state', 'rendered an error state');
                const m = state.text.match(PLACEHOLDER);
                if (m) note('placeholder-text', `"${m[0]}" visible near: …${state.text.slice(Math.max(0, m.index - 60), m.index + 40).replace(/\s+/g, ' ')}…`);
            }

            // Wizard walk: press "Save and continue" on every step and record the outcome.
            if (role.wizard) {
                for (const step of vars.steps) {
                    current = `/applications/${vars.app}/edit/${step}`;
                    await page.goto(BASE + current, { waitUntil: 'networkidle0' });
                    const hasSubmit = await page.$('#main form button[type=submit]');
                    if (!hasSubmit) { wizard.push({ step, outcome: 'read-only or no form' }); continue; }
                    const saved = page.waitForResponse((r) => r.url().includes('/sections/') && r.request().method() === 'PUT', { timeout: 15000 }).catch(() => null);
                    await page.click('#main form button[type=submit]');
                    const res = await saved;
                    await new Promise((r) => setTimeout(r, 500));
                    const shown = await page.evaluate(() => [...document.querySelectorAll('.invalid-feedback, .alert-danger li, .alert-danger')].map((e) => e.textContent.trim()).filter(Boolean).slice(0, 8));
                    let issues = [];
                    if (res && res.ok()) {
                        try { const body = await res.json(); issues = (body.steps || []).find((s) => s.key === step)?.issues ?? []; } catch { /* ignore */ }
                    }
                    wizard.push({ step, outcome: res ? `${res.status()}` : 'no request', validation: shown, issues });
                }
            }
            await ctx.close();
        }
    } finally {
        await browser.close();
    }

    console.log(`Visited ${visited} screens.`);
    if (wizard.length) {
        console.log('\nWizard walk (draft applicant):');
        for (const w of wizard) console.log(`  ${w.step.padEnd(14)} ${w.outcome.padEnd(22)} ${w.validation?.length ? 'validation: ' + w.validation.join(' | ') : ''}${w.issues?.length ? ' issues: ' + w.issues.join(' | ') : ''}`);
    }
    if (problems.length) {
        console.log(`\n${problems.length} problem(s):`);
        for (const p of problems) console.log(`  [${p.kind}] ${p.role} ${p.path}\n      ${p.detail}`);
    }
    process.exit(problems.length ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
