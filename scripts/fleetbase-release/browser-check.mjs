// Signs in to the built console in headless Chrome and opens the QuickBooks pages, so the
// engine is loaded and its components render against the running API. Fails on an uncaught
// page error, a failed engine asset, or a QuickBooks page that does not finish loading.
// Env: RELEASE_CHECK_EMAIL, RELEASE_CHECK_PASSWORD, RELEASE_CHECK_CONSOLE_URL
// (default http://localhost:4200), CHROME_PATH (optional browser binary),
// RELEASE_CHECK_SCREENSHOTS (optional directory for a screenshot of each page).
import { createRequire } from 'node:module';
import { join } from 'node:path';

const require = createRequire(join(process.cwd(), 'noop.js'));
let playwright;
for (const name of ['playwright-core', 'playwright']) {
    try {
        playwright = require(name);
        break;
    } catch {
        // try the next package
    }
}
if (!playwright) {
    console.error('Install playwright-core first.');
    process.exit(1);
}

const base = (process.env.RELEASE_CHECK_CONSOLE_URL || 'http://localhost:4200').replace(/\/$/, '');
const email = process.env.RELEASE_CHECK_EMAIL;
const password = process.env.RELEASE_CHECK_PASSWORD;
const shots = process.env.RELEASE_CHECK_SCREENSHOTS;
if (!email || !password) {
    console.error('Set RELEASE_CHECK_EMAIL and RELEASE_CHECK_PASSWORD.');
    process.exit(1);
}

const ENGINE = '@unchartedwaters/quickbooks-engine';
const PAGES = [
    {
        name: 'QuickBooks Setup',
        path: '/settings/quickbooks-setup?view=index',
        ready: '[data-test-connection-state]:not([data-test-connection-state="loading"])',
        broken: '[data-test-connection-state="unavailable"], [data-test-settings-unavailable]',
    },
    {
        name: 'QuickBooks Activity',
        path: '/settings/quickbooks-activity?view=index',
        ready: '[data-test-activity-empty], [data-test-activity-results]',
        broken: '[data-test-activity-load-failed], [data-test-activity-error]',
    },
];

const problems = [];
const browser = await playwright.chromium.launch({ executablePath: process.env.CHROME_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('pageerror', (error) => problems.push(`page error: ${error.message}`));
page.on('requestfailed', (request) => {
    if (request.url().includes(ENGINE)) {
        problems.push(`engine asset failed: ${request.url()}`);
    }
});
page.on('response', (response) => {
    if (response.url().includes(ENGINE) && response.status() >= 400) {
        problems.push(`engine asset ${response.status()}: ${response.url()}`);
    }
});

async function signIn() {
    await page.goto(`${base}/auth`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/auth'), { timeout: 60000 });
}

async function open(check) {
    await page.goto(`${base}${check.path}`, { waitUntil: 'domcontentloaded' });
    const outcome = await Promise.race([
        page.waitForSelector(check.ready, { timeout: 90000 }).then(() => 'ready'),
        page.waitForSelector(check.broken, { timeout: 90000 }).then(() => 'broken'),
    ]).catch(() => 'timeout');
    if (shots) {
        await page.screenshot({ path: join(shots, `${check.name.replace(/\W+/g, '-')}.png`), fullPage: true });
    }
    if (outcome !== 'ready') {
        problems.push(`${check.name}: ${outcome}`);
    }
    console.log(`${outcome === 'ready' ? 'ok  ' : 'FAIL'} ${check.name} renders`);
}

try {
    await signIn();
    console.log('ok   signed in to the console');
    for (const check of PAGES) {
        await open(check);
    }
} catch (error) {
    problems.push(`stopped: ${error.message}`);
} finally {
    await browser.close();
}

for (const problem of problems) {
    console.log(`FAIL ${problem}`);
}
process.exit(problems.length === 0 ? 0 : 1);
