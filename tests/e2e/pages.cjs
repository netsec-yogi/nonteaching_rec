/**
 * Shared by the browser checks (tests/a11y/axe.cjs, tests/e2e/smoke.cjs): the demo roles, every
 * SPA screen each one can open, and sign-in. Needs the app running with DemoSeeder data.
 */
const BASE = process.env.A11Y_BASE || process.env.E2E_BASE || 'http://localhost:8010';
const CHROME = process.env.CHROME || '/usr/bin/google-chrome';
const PASSWORD = 'Password@123'; // demo accounts only (DemoSeeder)

const ROLES = [
    { name: 'guest', pages: ['/', '/login', '/register', '/forgot-password', '/privacy', '/advertisements/{ad}', '/referee/not-a-valid-token', '/no-such-page'] },
    { name: 'applicant', email: 'applicant@example.com', pages: ['/dashboard', '/account', '/applications/{app}'] },
    { name: 'applicant (draft)', email: 'applicant2@example.com', wizard: true, pages: ['/dashboard', '/applications/{app}/review'] },
    {
        name: 'super admin', email: 'superadmin@iiitm.ac.in', config: true,
        pages: ['/admin', '/admin/applications', '/admin/applications/{staffApp}', '/scrutiny', '/scrutiny/{staffApp}', '/committee', '/committee/{staffApp}',
            '/admin/reports', '/admin/logs/audit', '/admin/logs/emails', '/admin/logs/access', '/admin/logs/exports',
            '/admin/advertisements', '/admin/advertisements/new', '/admin/advertisements/{adminAd}', '/admin/cycles',
            '/admin/checklists', '/admin/checklists/{checklist}', '/admin/document-types', '/admin/forms/referee', '/admin/forms/evaluation',
            '/admin/email-templates', '/admin/settings', '/admin/users', '/admin/permissions', '/account'],
    },
    { name: 'recruitment admin', email: 'recruitment@iiitm.ac.in', pages: ['/admin', '/admin/applications', '/admin/applications/{staffApp}', '/admin/reports', '/admin/logs/emails'] },
    { name: 'scrutiny', email: 'scrutiny1@iiitm.ac.in', pages: ['/admin', '/scrutiny'] },
    { name: 'committee', email: 'committee1@iiitm.ac.in', pages: ['/admin', '/committee'] },
];

// Pages that are expected to answer 404 (not bugs).
// Expected non-2xx answers (not bugs): invalid referee link, staff probing the applicant dashboard,
// and the committee workspace for an application that has not been forwarded (409 with a message).
const EXPECTED_404 = [/\/api\/referee\/not-a-valid-token/, /\/api\/applicant\/dashboard$/, /\/committee$/];

async function signIn(page, email) {
    await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
    await page.type('#login-email', email);
    await page.type('#login-password', PASSWORD);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}), page.click('button[type=submit]')]);
    if (page.url().includes('/login')) throw new Error(`Could not sign in as ${email} — is the demo data seeded?`);
}

const UUID = /"uuid":"([0-9a-f-]{36})"/;
const ID = /"id":(\d+)/;

/** Resolve the {placeholders} in a role's page list from the live API. Returns [pages, vars]. */
async function resolvePages(page, role) {
    const api = (path) => page.evaluate(async (p) => (await fetch(p, { headers: { Accept: 'application/json' } })).text(), path);
    const pick = (text, re) => (text.match(re) || [])[1];
    const vars = { ad: pick(await api('/api/public/advertisements'), ID) };
    const pages = [...role.pages];
    if (role.name.startsWith('applicant')) vars.app = pick(await api('/api/applicant/dashboard'), UUID);
    if (role.wizard && vars.app) {
        const body = await api('/api/applications/' + vars.app);
        vars.steps = [...new Set([...body.matchAll(/"key":"([a-z_]+)"/g)].map((m) => m[1]))];
        pages.push(...vars.steps.map((s) => `/applications/{app}/edit/${s}`));
    }
    if (role.email && role.email.endsWith('@iiitm.ac.in')) vars.staffApp = pick(await api('/api/admin/applications'), UUID);
    if (role.config) {
        vars.adminAd = pick(await api('/api/admin/advertisements'), ID);
        vars.checklist = pick(await api('/api/admin/checklists'), ID);
    }
    const resolved = pages.map((tpl) => tpl.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? ''));
    const broken = resolved.filter((p) => /\/\/|\/$/.test(p.slice(1)));
    if (broken.length) throw new Error(`No demo data for ${broken.join(', ')} (${role.name})`);
    return [resolved, vars];
}

module.exports = { BASE, CHROME, PASSWORD, ROLES, EXPECTED_404, signIn, resolvePages };
