#!/usr/bin/env node
/**
 * Accessibility check: runs axe-core (WCAG 2.0/2.1, levels A and AA) over every SPA screen as each
 * demo role, in light and dark mode, at desktop and phone widths. Exits 1 on any violation.
 *
 * Needs the app running with the demo data (`./dev.sh fresh`, or migrate --seed + DemoSeeder):
 *   A11Y_BASE=http://localhost:8010 CHROME=/usr/bin/google-chrome node tests/a11y/axe.cjs
 * Options: A11Y_THEMES=light,dark  A11Y_VIEWPORTS=desktop,phone  A11Y_JSON=path/to/report.json
 * The roles and screens are listed in tests/e2e/pages.cjs (shared with the smoke test).
 */
const fs = require('fs');
const puppeteer = require('puppeteer-core');

const { BASE, CHROME, ROLES, signIn, resolvePages } = require('../e2e/pages.cjs');

const THEMES = (process.env.A11Y_THEMES || 'light,dark').split(',');
const VIEWPORTS = {
    desktop: { width: 1366, height: 900 },
    phone: { width: 390, height: 844, isMobile: true },
};
const viewports = (process.env.A11Y_VIEWPORTS || 'desktop,phone').split(',');
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

async function main() {
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
    const report = [];
    try {
        for (const role of ROLES) {
            const ctx = await browser.createBrowserContext();
            const page = await ctx.newPage();
            if (role.email) await signIn(page, role.email);
            else await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

            const [pages] = await resolvePages(page, role);

            for (const vp of viewports) {
                await page.setViewport(VIEWPORTS[vp]);
                for (const theme of THEMES) {
                    await page.evaluateOnNewDocument((t) => { try { localStorage.setItem('ntrec.theme', t); } catch { /* ignore */ } }, theme);
                    for (const path of pages) {
                        await page.goto(BASE + path, { waitUntil: 'networkidle0' });
                        await new Promise((r) => setTimeout(r, 600)); // let the fade-in finish: axe measures rendered colours
                        await page.evaluate(axeSource);
                        const violations = await page.evaluate(async () => {
                            const res = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }, resultTypes: ['violations'] });
                            return res.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 200), summary: n.failureSummary })) }));
                        });
                        report.push({ role: role.name, viewport: vp, theme, path, violations });
                    }
                }
            }
            await ctx.close();
        }
    } finally {
        await browser.close();
    }

    if (process.env.A11Y_JSON) fs.writeFileSync(process.env.A11Y_JSON, JSON.stringify(report, null, 1));
    const failed = report.filter((r) => r.violations.length);
    console.log(`Scanned ${report.length} page views; ${failed.length} with violations.`);
    for (const r of failed) {
        console.log(`\n✗ ${r.path}  [${r.role}, ${r.viewport}, ${r.theme}]`);
        for (const v of r.violations) {
            console.log(`  ${v.impact}: ${v.id} — ${v.help} (${v.nodes.length})`);
            for (const n of v.nodes.slice(0, 3)) console.log(`    ${n.target}\n      ${n.html}`);
        }
    }
    process.exit(failed.length ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
