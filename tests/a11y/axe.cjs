#!/usr/bin/env node
/**
 * Accessibility check: runs axe-core (WCAG 2.0/2.1, levels A and AA) over every SPA screen as each
 * demo role, in light and dark mode, at desktop and phone widths. Exits 1 on any violation.
 *
 * Needs the app running with the demo data (`./dev.sh fresh`, or migrate --seed + DemoSeeder):
 *   A11Y_BASE=http://localhost:8010 CHROME=/usr/bin/google-chrome node tests/a11y/axe.cjs
 * Options: A11Y_THEMES=light,dark  A11Y_VIEWPORTS=desktop,phone  A11Y_JSON=path/to/report.json
 */
const fs = require('fs');
const puppeteer = require('puppeteer-core');

const BASE = process.env.A11Y_BASE || 'http://localhost:8010';
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';
const PASSWORD = 'Password@123'; // demo accounts only (DemoSeeder)
const THEMES = (process.env.A11Y_THEMES || 'light,dark').split(',');
const VIEWPORTS = {
    desktop: { width: 1366, height: 900 },
    phone: { width: 390, height: 844, isMobile: true },
};
const viewports = (process.env.A11Y_VIEWPORTS || 'desktop,phone').split(',');
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const UUID = /"uuid":"([0-9a-f-]{36})"/;
const ID = /"id":(\d+)/;

const ROLES = [
    { name: 'guest', pages: ['/', '/login', '/register', '/forgot-password', '/privacy', '/advertisements/{ad}', '/referee/not-a-valid-token', '/no-such-page'] },
    { name: 'applicant', email: 'applicant@example.com', pages: ['/dashboard', '/account', '/applications/{app}'] },
    { name: 'applicant (draft)', email: 'applicant2@example.com', wizard: true, pages: ['/dashboard', '/applications/{app}/review'] },
    {
        name: 'super admin', email: 'superadmin@iiitm.ac.in',
        pages: ['/admin', '/admin/applications', '/admin/applications/{staffApp}', '/scrutiny', '/scrutiny/{staffApp}', '/committee', '/committee/{staffApp}',
            '/admin/reports', '/admin/logs/audit', '/admin/logs/emails', '/admin/logs/access', '/admin/logs/exports',
            '/admin/advertisements', '/admin/advertisements/new', '/admin/advertisements/{adminAd}', '/admin/cycles',
            '/admin/checklists', '/admin/checklists/{checklist}', '/admin/document-types', '/admin/forms/referee', '/admin/forms/evaluation',
            '/admin/email-templates', '/admin/settings', '/admin/users', '/admin/permissions', '/account'],
    },
    { name: 'scrutiny', email: 'scrutiny1@iiitm.ac.in', pages: ['/admin', '/scrutiny'] },
    { name: 'committee', email: 'committee1@iiitm.ac.in', pages: ['/admin', '/committee'] },
];

async function signIn(page, email) {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('#login-email', email);
    await page.type('#login-password', PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}), page.click('button[type=submit]')]);
    if (page.url().includes('/login')) throw new Error(`Could not sign in as ${email} — is the demo data seeded?`);
}

async function main() {
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox'] });
    const report = [];
    try {
        for (const role of ROLES) {
            const ctx = await browser.createBrowserContext();
            const page = await ctx.newPage();
            if (role.email) await signIn(page, role.email);
            else await page.goto(BASE + '/', { waitUntil: 'networkidle0' });

            const api = (path) => page.evaluate(async (p) => (await fetch(p, { headers: { Accept: 'application/json' } })).text(), path);
            const pick = (text, re) => (text.match(re) || [])[1];
            const vars = { ad: pick(await api('/api/public/advertisements'), ID) };
            const pages = [...role.pages];
            if (role.name.startsWith('applicant')) vars.app = pick(await api('/api/applicant/dashboard'), UUID);
            if (role.wizard && vars.app) {
                const body = await api('/api/applications/' + vars.app);
                const steps = [...new Set([...body.matchAll(/"key":"([a-z_]+)"/g)].map((m) => m[1]))];
                pages.push(...steps.map((s) => `/applications/{app}/edit/${s}`));
            }
            if (role.name === 'super admin') {
                vars.staffApp = pick(await api('/api/admin/applications'), UUID);
                vars.adminAd = pick(await api('/api/admin/advertisements'), ID);
                vars.checklist = pick(await api('/api/admin/checklists'), ID);
            }

            for (const vp of viewports) {
                await page.setViewport(VIEWPORTS[vp]);
                for (const theme of THEMES) {
                    await page.evaluateOnNewDocument((t) => { try { localStorage.setItem('ntrec.theme', t); } catch { /* ignore */ } }, theme);
                    for (const tpl of pages) {
                        const path = tpl.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? '');
                        if (/\/\/|\/$/.test(path.slice(1)) || path.includes('{')) throw new Error(`No demo data for ${tpl} (${role.name})`);
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
