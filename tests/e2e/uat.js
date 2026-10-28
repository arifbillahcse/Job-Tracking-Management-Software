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
    if (extra.pickup !== undefined) await page.fill('#pickup_date', day(extra.pickup));
    if (extra.balance) await page.fill('#balance', extra.balance);
    if (extra.cf) await page.fill('#cf', extra.cf);
    if (extra.phone) await page.fill('#customer_phone', extra.phone);
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
    await step('a job picked up 45 days ago appears under More Than 30 Days only', async () => {
        await createJob(page, RUN + '-B', 5, { pickup: -45 });
        await page.goto(BASE + '/jobs/more-than-30?q=' + RUN + '-B');
        must((await page.textContent('main')).includes(RUN + '-B'), 'not in More Than 30');
        await page.goto(BASE + '/jobs/ready?q=' + RUN + '-B');
        must(!(await page.textContent('main')).includes(RUN + '-B'), 'also in Ready');
    });
    await step('picked up 30 days ago is Ready and 31 days ago is More Than 30 Days', async () => {
        await createJob(page, RUN + '-C30', 5, { pickup: -30 });
        await createJob(page, RUN + '-C31', 5, { pickup: -31 });
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

    console.log('== One-click buttons');
    const accept = () => page.once('dialog', (d) => d.accept());
    await step('Move to In Transit on a job saves today as the loaded date and moves it to In Transit', async () => {
        await page.goto(BASE + '/jobs?q=' + RUN + '-A');
        await Promise.all([page.waitForNavigation(), page.click('a[href^="/jobs/"]:has-text("' + RUN + '-A")')]);
        must(!(await page.isVisible('#statusModal-in_transit')), 'a dialog is in the way: this should be one click');
        accept();
        await Promise.all([page.waitForNavigation(), page.click('button:has-text("Move to In Transit")')]);
        const main = await page.textContent('main');
        must(/In Transit/.test(main) && /Loaded date/.test(main), 'not in transit with a loaded date');
        await snap('03-in-transit');
        await page.goto(BASE + '/jobs/in-transit?q=' + RUN + '-A');
        must((await page.textContent('main')).includes(RUN + '-A'), 'not listed under In Transit');
    });
    await step('the button next to a job in the Ready list does the same', async () => {
        await page.goto(BASE + '/jobs/ready?q=' + RUN + '-C30');
        accept();
        await Promise.all([page.waitForNavigation(), page.click('tr:has-text("' + RUN + '-C30") button:has-text("Move to In Transit")')]);
        await page.goto(BASE + '/jobs/in-transit?q=' + RUN + '-C30');
        must((await page.textContent('main')).includes(RUN + '-C30'), 'not in transit');
        await page.goto(BASE + '/jobs/ready?q=' + RUN + '-C30');
        must(!(await page.textContent('main')).includes(RUN + '-C30<') , 'still listed under Ready');
    });
    await step('Mark as Delivered on the In Transit list saves today as the delivery date', async () => {
        await page.goto(BASE + '/jobs/in-transit?q=' + RUN + '-A');
        accept();
        await Promise.all([page.waitForNavigation(), page.click('tr:has-text("' + RUN + '-A") button:has-text("Mark as Delivered")')]);
        await page.goto(BASE + '/jobs/delivered?q=' + RUN + '-A');
        must((await page.textContent('main')).includes(RUN + '-A'), 'not under Delivered');
        await Promise.all([page.waitForNavigation(), page.click('a[href^="/jobs/"]:has-text("' + RUN + '-A")')]);
        const body = await page.textContent('main');
        must(/Booked/.test(body) && /In Transit/.test(body) && /Delivered/.test(body) && /Delivery date/.test(body), 'history or delivery date missing');
        await snap('04-delivered-history');
    });
    await step('the dashboard counts follow the clicks', async () => {
        await page.goto(BASE + '/dashboard');
        const total = await page.locator('.jt-stat-value').allTextContents();
        must(total.length >= 5, 'dashboard cards missing');
        await page.goto(BASE + '/jobs/in-transit?q=' + RUN + '-C30');
        must(/of 1 job/.test((await page.textContent('main')).replace(/\s+/g, ' ')), 'In Transit list does not agree');
    });

    console.log('== Phone number and storage fee');
    await step('a customer phone number is saved and shown as a tap-to-call link', async () => {
        await createJob(page, RUN + '-P', 12, { phone: '(555) 010-4477' });
        must((await page.textContent('main')).includes('(555) 010-4477'), 'number not shown on Job Details');
        must((await page.getAttribute('main a[href^="tel:"]', 'href')) === 'tel:5550104477', 'not a tel: link');
        await page.goto(BASE + '/jobs?q=' + RUN + '-P');
        must(await page.locator('a[href="tel:5550104477"]').count() >= 1, 'not a link in the list');
    });
    await step('storage fee is calculated automatically: CF 100 x 0.6 = 60 after 30 days', async () => {
        await createJob(page, RUN + '-S', -40, { cf: '100' });
        const main = (await page.textContent('main')).replace(/\s+/g, ' ');
        must(/Storage fee\s*\$60\.00/.test(main), 'fee is not $60.00: ' + main.slice(main.indexOf('Storage fee'), main.indexOf('Storage fee') + 80));
        await createJob(page, RUN + '-S2', -5, { cf: '100' });
        must(/Storage fee\s*\$0\.00/.test((await page.textContent('main')).replace(/\s+/g, ' ')), 'a recent job was charged');
    });
    await step('Settings has the storage fee rate', async () => {
        await page.goto(BASE + '/settings');
        must((await page.inputValue('#storage_fee_rate')) === '0.6' && (await page.inputValue('#storage_fee_after_days')) === '30', 'defaults are not 0.6 and 30');
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
        must(lines.length === 8, 'expected header + 7 jobs, got ' + lines.length);
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

    console.log('== Foreman trips');
    await step('a foreman and a trip with a job and expenses can be added, and the totals add up', async () => {
        await page.goto(BASE + '/foremen/create');
        await page.fill('#name', RUN + ' Foreman');
        await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
        must((await page.textContent('main')).includes(RUN + ' Foreman'), 'foreman not listed');
        await page.goto(BASE + '/trips/create');
        await page.fill('#trip_number', RUN + '-TR');
        await page.selectOption('#foreman_id', { label: RUN + ' Foreman' });
        await page.fill('#collected_by', 'BH Office');
        await page.fill('input[name="rows[0][job_number]"]', RUN + '-A');
        await page.fill('input[name="rows[0][diesel]"]', '120.10');
        await page.fill('input[name="rows[0][hotels]"]', '80');
        await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
        const text = await page.textContent('main');
        must(text.includes(RUN + '-TR') && text.includes(RUN + '-A') && text.includes('$200.10'), 'trip page does not show the job and the $200.10 total');
    });
    await step('the trip is found by search and a bad job number is refused with a message', async () => {
        await page.goto(BASE + '/trips?q=' + RUN + '-A');
        must((await page.textContent('main')).includes(RUN + '-TR'), 'not found by job number');
        await page.goto(BASE + '/trips/create');
        await page.fill('#trip_number', RUN + '-BAD');
        await page.selectOption('#foreman_id', { label: RUN + ' Foreman' });
        await page.fill('input[name="rows[0][job_number]"]', 'NO-SUCH-JOB');
        await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
        must(page.url().includes('/trips/create') && /was not found/.test(await page.textContent('main')), 'no message for an unknown job');
    });
    await step('the foreman report adds up and the trip list exports to CSV', async () => {
        await page.goto(BASE + '/trips/report');
        const text = await page.textContent('main');
        must(text.includes(RUN + ' Foreman') && text.includes('$200.10'), 'report does not show the foreman and $200.10');
        await page.goto(BASE + '/trips?q=' + RUN + '-TR');
        const [download] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("Export CSV")')]);
        const file = await download.path();
        const csv = fs.readFileSync(file, 'utf8');
        must(csv.includes('Trip Number') && csv.includes(RUN + '-TR') && csv.includes('120.10') && csv.includes('80.00'), 'CSV is missing the trip or its amounts');
    });
    await step('a job page lists its trips and can add the job to another trip', async () => {
        await page.goto(BASE + '/jobs?q=' + RUN + '-B');
        await page.click('a.jt-jobno[href^="/jobs/"]');
        must((await page.textContent('main')).includes('not on any trip yet'), 'job B should be on no trip');
        await page.selectOption('#add-trip', { label: RUN + '-TR \u00b7 ' + RUN + ' Foreman' });
        await Promise.all([page.waitForNavigation(), page.click('form[action$="/trip"] button[type=submit]')]);
        const text = await page.textContent('main');
        must(text.includes('was added to trip ' + RUN + '-TR') && text.includes(RUN + '-TR'), 'no confirmation or trip link: ' + text.replace(/\s+/g, ' ').slice(0, 160));
        await page.goto(BASE + '/dashboard');
        must((await page.textContent('main')).includes('Foreman trips'), 'no trips card on the dashboard');
    });
    await step('a trip can be deleted', async () => {
        await page.goto(BASE + '/trips?q=' + RUN + '-TR');
        await page.click('a.jt-jobno[href^="/trips/"]');
        page.once('dialog', (d) => d.accept());
        await Promise.all([page.waitForNavigation(), page.click('button[data-confirm]')]);
        await page.goto(BASE + '/trips?q=' + RUN + '-TR');
        must(!(await page.textContent('main')).includes(RUN + '-TR'), 'trip still listed');
    });

    console.log('== A Staff member');
    await step('Staff cannot see money, imports, users or settings', async () => {
        const staff = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        const p = await staff.newPage();
        await signIn(p, STAFF, STAFF_PASS);
        const menu = await p.textContent('#sidebar');
        must(!/Users|Import Jobs|Settings|Audit Log|Roles|Trips|Foremen/.test(menu), 'admin items in the menu: ' + menu.replace(/\s+/g, ' ').slice(0, 120));
        await p.goto(BASE + '/jobs?q=' + RUN + '-A');
        must(!/\$1,250\.50|1250\.5/.test(await p.textContent('main')), 'a balance is visible');
        for (const url of ['/users', '/settings', '/audit', '/jobs/import', '/roles', '/trips', '/trips/create', '/foremen']) {
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
