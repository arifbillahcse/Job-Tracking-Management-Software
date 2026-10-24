// Browser walkthrough of the client's workflow, as a person would do it. Needs Playwright (a development tool, not an app dependency):
//   npm i --no-save playwright && npx playwright install chromium
//   BASE=http://127.0.0.1:8099 ADMIN=admin ADMIN_PASS=... STAFF=staff STAFF_PASS=... node tests/e2e/uat.js [screenshots-dir]
// Creates jobs named UAT<timestamp>-* and soft-deletes them at the end. Use a development database.
const fs = require('fs');
const os = require('os');
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = require('/opt/node22/lib/node_modules/playwright')); }

const BASE = process.env.BASE || 'http://127.0.0.1:8099';
const ADMIN = process.env.ADMIN, ADMIN_PASS = process.env.ADMIN_PASS, STAFF = process.env.STAFF, STAFF_PASS = process.env.STAFF_PASS;
if (!ADMIN || !ADMIN_PASS || !STAFF || !STAFF_PASS) { console.error('Set ADMIN, ADMIN_PASS, STAFF and STAFF_PASS.'); process.exit(2); }
const shots = process.argv[2];
const RUN = 'UAT' + Date.now();
const day = (offset) => new Date(Date.now() + offset * 864e5).toISOString().slice(0, 10);

let passed = 0, failed = 0;
const step = async (name, fn) => {
    try { await fn(); passed++; console.log('  ok   ' + name); }
    catch (e) { failed++; console.log('  FAIL ' + name + '\n         ' + String(e.message).split('\n')[0]); }
};
const must = (cond, msg) => { if (!cond) throw new Error(msg || 'assertion failed'); };

async function signIn(page, username, password) {
    await page.goto(BASE + '/login');
    await page.fill('#username', username);
    await page.fill('#password', password);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
}

async function createJob(page, number, availableOffset, extra = {}) {
    await page.goto(BASE + '/jobs/create');
    await page.fill('#job_number', number);
    await page.fill('#customer_first_name', extra.first || 'Ada');
    await page.fill('#customer_last_name', extra.last || 'Lovelace');
    await page.fill('#from_location', 'Austin, TX');
    await page.fill('#to_location', 'Denver, CO');
    await page.fill('#first_available_date', day(availableOffset));
    if (extra.balance) await page.fill('#balance', extra.balance);
    await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
}

(async () => {
    if (shots) fs.mkdirSync(shots, { recursive: true });
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, acceptDownloads: true });
    const page = await ctx.newPage();
    const problems = [];
    page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
    page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
    const snap = async (name) => { if (shots) await page.screenshot({ path: path.join(shots, name + '.png'), fullPage: true }); };

    console.log('== Sign in');
    await step('a wrong password is refused with a generic message', async () => {
        await page.goto(BASE + '/login');
        await page.fill('#username', ADMIN); await page.fill('#password', 'not the password');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        must(/Invalid username or password/.test(await page.textContent('body')), 'no error message');
        must(page.url().includes('/login'), 'left the sign-in page');
    });
    await step('the right password opens the dashboard', async () => {
        await signIn(page, ADMIN, ADMIN_PASS);
        must(page.url().endsWith('/dashboard'), 'not on the dashboard: ' + page.url());
        for (const label of ['Total jobs', 'Ready for delivery', 'More than 30 days', 'In transit', 'Delivered']) must((await page.textContent('main')).includes(label), 'missing card: ' + label);
        await snap('01-dashboard');
    });

    console.log('== Jobs move between sections by their available date');
    await step('a job available in 10 days appears under Ready for Delivery', async () => {
        await createJob(page, RUN + '-A', 10, { balance: '1250.50' });
        must(/created/i.test(await page.textContent('main')), 'no confirmation');
        await page.goto(BASE + '/jobs/ready?q=' + RUN + '-A');
        must((await page.textContent('main')).includes(RUN + '-A'), 'not in Ready');
        await page.goto(BASE + '/jobs/more-than-30?q=' + RUN + '-A');
        must(!(await page.textContent('main')).includes(RUN + '-A'), 'also in More Than 30');
    });
    await step('a job available in 45 days appears under More Than 30 Days only', async () => {
        await createJob(page, RUN + '-B', 45);
        await page.goto(BASE + '/jobs/more-than-30?q=' + RUN + '-B');
        must((await page.textContent('main')).includes(RUN + '-B'), 'not in More Than 30');
        await page.goto(BASE + '/jobs/ready?q=' + RUN + '-B');
        must(!(await page.textContent('main')).includes(RUN + '-B'), 'also in Ready');
    });
    await step('day 30 is Ready and day 31 is More Than 30 Days', async () => {
        await createJob(page, RUN + '-C30', 30);
        await createJob(page, RUN + '-C31', 31);
        await page.goto(BASE + '/jobs/ready?q=' + RUN + '-C');
        const ready = await page.textContent('main');
        must(ready.includes(RUN + '-C30') && !ready.includes(RUN + '-C31'), 'wrong side of the boundary in Ready');
        await page.goto(BASE + '/jobs/more-than-30?q=' + RUN + '-C');
        const later = await page.textContent('main');
        must(later.includes(RUN + '-C31') && !later.includes(RUN + '-C30'), 'wrong side of the boundary in More Than 30');
    });
    await step('a duplicate job number is refused with a message next to the field', async () => {
        await createJob(page, RUN + '-A', 10);
        must(page.url().includes('/jobs/create'), 'was not sent back to the form');
        must(/already in use/.test(await page.textContent('main')), 'no duplicate message');
        must((await page.inputValue('#customer_first_name')) === 'Ada', 'typed values were lost');
    });
    await step('money must be a plain amount', async () => {
        await page.goto(BASE + '/jobs/create');
        await page.fill('#job_number', RUN + '-M'); await page.fill('#customer_first_name', 'A'); await page.fill('#customer_last_name', 'B');
        await page.fill('#from_location', 'X'); await page.fill('#to_location', 'Y'); await page.fill('#first_available_date', day(5));
        await page.fill('#balance', '12.345');
        await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
        must(/at most two decimals/.test(await page.textContent('main')), 'three decimals were accepted');
        await snap('02-validation');
    });

    console.log('== Dispatch and deliver');
    await step('dispatching asks for carrier, driver and phone, then moves the job to In Transit', async () => {
        await page.goto(BASE + '/jobs?q=' + RUN + '-A');
        await Promise.all([page.waitForNavigation(), page.click('a[href^="/jobs/"]:has-text("' + RUN + '-A")')]);
        await page.click('button[data-bs-target="#statusModal-in_transit"]');
        await page.waitForSelector('#statusModal-in_transit.show');
        await page.click('#statusModal-in_transit button[type=submit]');
        await page.waitForSelector('#statusModal-in_transit.show');
        must((await page.textContent('#statusModal-in_transit')).match(/required|must/i), 'empty dispatch was accepted');
        await page.fill('#statusModal-in_transit-carrier', 'Blue Ridge Haulers');
        await page.fill('#statusModal-in_transit-driver', 'Marcus Reed');
        await page.fill('#statusModal-in_transit-phone', '(555) 010-2231');
        await Promise.all([page.waitForNavigation(), page.click('#statusModal-in_transit button[type=submit]')]);
        must(/In Transit/.test(await page.textContent('main')) && (await page.textContent('main')).includes('Marcus Reed'), 'not in transit with the driver shown');
        await snap('03-in-transit');
        await page.goto(BASE + '/jobs/in-transit?q=' + RUN + '-A');
        must((await page.textContent('main')).includes(RUN + '-A'), 'not listed under In Transit');
    });
    await step('marking delivered moves it to Delivered, and the history shows both steps', async () => {
        await page.goto(BASE + '/jobs/in-transit?q=' + RUN + '-A');
        await Promise.all([page.waitForNavigation(), page.click('a[href^="/jobs/"]:has-text("' + RUN + '-A")')]);
        await page.click('button[data-bs-target="#statusModal-delivered"]');
        await page.waitForSelector('#statusModal-delivered.show');
        await Promise.all([page.waitForNavigation(), page.click('#statusModal-delivered button[type=submit]')]);
        await page.goto(BASE + '/jobs/delivered?q=' + RUN + '-A');
        must((await page.textContent('main')).includes(RUN + '-A'), 'not under Delivered');
        await Promise.all([page.waitForNavigation(), page.click('a[href^="/jobs/"]:has-text("' + RUN + '-A")')]);
        const body = await page.textContent('main');
        must(/Booked/.test(body) && /In Transit/.test(body) && /Delivered/.test(body), 'history is incomplete');
        await snap('04-delivered-history');
    });

    console.log('== Search, edit, export');
    await step('search finds a job by customer name and by job number', async () => {
        await page.goto(BASE + '/jobs?q=Lovelace');
        must((await page.textContent('main')).includes(RUN), 'not found by name');
        await page.goto(BASE + '/jobs?q=' + RUN + '-B');
        must((await page.textContent('main')).includes(RUN + '-B'), 'not found by number');
    });
    await step('editing a job saves the change and shows it', async () => {
        await page.goto(BASE + '/jobs?q=' + RUN + '-B');
        await Promise.all([page.waitForNavigation(), page.click('a[href^="/jobs/"]:has-text("' + RUN + '-B")')]);
        await page.click('a[href$="/edit"]');
        await page.fill('#notes', 'Call before arrival ' + RUN);
        await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
        must((await page.textContent('main')).includes('Call before arrival ' + RUN), 'note not shown');
    });
    await step('leaving a changed form asks for confirmation', async () => {
        await page.goto(BASE + '/jobs/create');
        await page.fill('#job_number', 'WILL-NOT-SAVE');
        let asked = false;
        page.once('dialog', async (d) => { asked = d.type() === 'beforeunload'; await d.dismiss(); });
        await page.goto(BASE + '/jobs', { waitUntil: 'commit' }).catch(() => {});
        await page.waitForTimeout(300);
        must(asked, 'no warning');
    });
    await step('exporting the current search downloads a CSV with exactly those jobs', async () => {
        await page.goto(BASE + '/jobs?q=' + RUN);
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.goto(BASE + '/jobs/export/all?format=csv&q=' + RUN).catch(() => {}),
        ]);
        const file = path.join(os.tmpdir(), RUN + '.csv');
        await download.saveAs(file);
        const text = fs.readFileSync(file, 'utf8');
        fs.unlinkSync(file);
        must(text.startsWith('﻿'), 'no BOM (Excel would garble accents)');
        const lines = text.trim().split('\n');
        must(lines.length === 5, 'expected header + 4 jobs, got ' + lines.length);
        must(text.includes(RUN + '-A') && text.includes('1250.5'), 'job or balance missing');
    });

    console.log('== Import');
    await step('uploading a CSV shows a preview with the problem row, then imports the valid ones', async () => {
        const file = path.join(os.tmpdir(), RUN + '-import.csv');
        fs.writeFileSync(file, [
            'Job #,Customer First Name,Customer Last Name,Pickup Date,1st Available Date,From,To,CF,Balance',
            `${RUN}-I1,Zoë,Müller,${day(1)},${day(12)},"Austin, TX","Denver, CO",900,"$1,200.50"`,
            `${RUN}-I2,Li,Wei,,${day(60)},Miami,Boston,,`,
            `${RUN}-I3,Bad,Date,,not-a-date,Miami,Boston,,`,
        ].join('\n'));
        await page.goto(BASE + '/jobs/import');
        await page.setInputFiles('input[type=file]', file);
        await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
        const preview = await page.textContent('main');
        must(/not a valid date/i.test(preview), 'the bad date is not explained');
        must(preview.includes(RUN + '-I3') || /Row 4/i.test(preview), 'the problem row is not identified');
        await snap('05-import-preview');
        await Promise.all([page.waitForNavigation(), page.click('button:has-text("Import")')]);
        await page.goto(BASE + '/jobs?q=' + RUN + '-I');
        const list = await page.textContent('main');
        must(list.includes(RUN + '-I1') && list.includes(RUN + '-I2') && !list.includes(RUN + '-I3'), 'wrong rows imported');
        must(list.includes('Zoë Müller') || list.includes('Müller'), 'accents were lost');
        fs.unlinkSync(file);
    });

    console.log('== On a phone');
    await step('the list becomes cards and the menu opens from the top bar', async () => {
        const phone = await browser.newContext({ viewport: { width: 375, height: 720 }, hasTouch: true, isMobile: true });
        const p = await phone.newPage();
        await signIn(p, ADMIN, ADMIN_PASS);
        await p.goto(BASE + '/jobs');
        must(!(await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)), 'the page scrolls sideways');
        must(!(await p.isVisible('thead')), 'the table header is still showing');
        await p.click('button[aria-label="Open menu"]');
        await p.waitForSelector('#sidebar.show');
        must(await p.isVisible('#sidebar >> text=Ready for Delivery'), 'the menu is empty');
        if (shots) await p.screenshot({ path: path.join(shots, '06-phone-menu.png') });
        await phone.close();
    });

    console.log('== A Staff member');
    await step('Staff cannot see money, imports, users or settings', async () => {
        const staff = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        const p = await staff.newPage();
        await signIn(p, STAFF, STAFF_PASS);
        const menu = await p.textContent('#sidebar');
        must(!/Users|Import Jobs|Settings|Audit Log|Roles/.test(menu), 'admin items in the menu: ' + menu.replace(/\s+/g, ' ').slice(0, 120));
        await p.goto(BASE + '/jobs?q=' + RUN + '-A');
        must(!/\$1,250\.50|1250\.5/.test(await p.textContent('main')), 'a balance is visible');
        for (const url of ['/users', '/settings', '/audit', '/jobs/import', '/roles']) {
            const r = await p.goto(BASE + url);
            must(r.status() === 403, url + ' returned ' + r.status());
        }
        await staff.close();
    });

    console.log('== Sign out');
    await step('signing out closes the session, and Back does not reopen it', async () => {
        await page.goto(BASE + '/dashboard');
        await page.click('[data-bs-toggle="dropdown"]');
        await Promise.all([page.waitForNavigation(), page.click('form[action$="/logout"] button')]);
        must(page.url().includes('/login'), 'not on the sign-in page');
        await page.goBack();
        await page.waitForTimeout(300);
        must(!(await page.content()).includes('Total jobs'), 'private page visible after Back');
    });

    console.log('== Clean up');
    const cleanup = await ctx.newPage();
    await signIn(cleanup, ADMIN, ADMIN_PASS);
    await cleanup.goto(BASE + '/jobs?q=' + RUN + '&per_page=50');
    const ids = await cleanup.$$eval('a[href^="/jobs/"]', (as) => [...new Set(as.map((a) => a.getAttribute('href')).filter((h) => /^\/jobs\/\d+$/.test(h)))]);
    for (const href of ids) {
        await cleanup.goto(BASE + href);
        await cleanup.click('button[data-bs-target="#deleteJobModal"]');
        await cleanup.waitForSelector('#deleteJobModal.show');
        await Promise.all([cleanup.waitForNavigation(), cleanup.click('#deleteJobModal button[type=submit]')]);
    }
    await step('the test jobs are removed from the list', async () => {
        await cleanup.goto(BASE + '/jobs?q=' + RUN);
        must(!(await cleanup.textContent('main')).includes(RUN + '-'), 'jobs remain');
    });

    await step('no script errors or console errors appeared anywhere', async () => { must(problems.length === 0, problems.slice(0, 3).join(' | ')); });

    await browser.close();
    console.log(`\n${passed} passed, ${failed} failed`);
    process.exit(failed === 0 ? 0 : 1);
})();
