/**
 * Facebook Pages lead scraper (Playwright).
 *
 * Usage: node facebook.mjs '<json job>'
 *   job: { searchUrl: string, maxResults: number, enrichWebsites: boolean, storageState: string }
 *
 * Facebook only shows page search results to logged-in visitors, so a saved
 * session is required. Create one with: node resources/scrapers/facebook-login.mjs
 *
 * Prints a JSON array of leads to stdout. Progress and errors go to stderr.
 */
import { existsSync } from 'node:fs';
import { chromium } from 'playwright';

const job = JSON.parse(process.argv[2] ?? '{}');
const maxResults = Math.min(Math.max(Number(job.maxResults) || 20, 1), 60);

const log = (message) => process.stderr.write(`${message}\n`);
const fail = (message) => {
    log(message);
    process.exit(2);
};
const pause = (min, max) => new Promise((resolve) => setTimeout(resolve, min + Math.random() * (max - min)));

const EMAIL_PATTERN = /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/gi;
const IGNORED_EMAIL = /(\.(png|jpe?g|gif|svg|webp)$|example\.|sentry|wixpress|domain\.com|yourname)/i;
const PHONE_PATTERN = /(\+?\d[\d\s().-]{6,}\d)/;
const NAME_PATTERN = /\bDr\.?\s+([A-Z][a-z'’-]+)\s+([A-Z][a-z'’-]+)/;
const RESERVED_PATHS = /^\/(search|login|help|groups|events|watch|marketplace|photo|photos|reel|reels|share|policies|privacy|pages|public|hashtag|stories|gaming|ads|business|recover|checkpoint)\b/i;

if (!job.storageState || !existsSync(job.storageState)) {
    fail('Facebook session not found. Run "node resources/scrapers/facebook-login.mjs" once to log in and save a session.');
}

function pageUrl(href) {
    try {
        const url = new URL(href);

        if (!/(^|\.)facebook\.com$/.test(url.hostname) || RESERVED_PATHS.test(url.pathname)) {
            return null;
        }

        if (url.pathname === '/profile.php') {
            const id = url.searchParams.get('id');

            return id ? `https://www.facebook.com/profile.php?id=${id}` : null;
        }

        const slug = url.pathname.split('/').filter(Boolean)[0];

        return slug ? `https://www.facebook.com/${slug}` : null;
    } catch {
        return null;
    }
}

function unwrapRedirect(href) {
    try {
        const url = new URL(href);

        return url.hostname === 'l.facebook.com' ? url.searchParams.get('u') : href;
    } catch {
        return null;
    }
}

async function assertLoggedIn(page) {
    const blocked = page.url().includes('/login') || page.url().includes('/checkpoint');

    if (blocked || (await page.locator('input[name="pass"]').count()) > 0) {
        fail('Facebook session expired or blocked. Run "node resources/scrapers/facebook-login.mjs" again.');
    }
}

async function collectPageUrls(page) {
    const urls = new Set();
    let stale = 0;

    while (urls.size < maxResults && stale < 4) {
        const hrefs = await page.$$eval('div[role="feed"] a[role="link"], div[role="article"] a[role="link"]', (anchors) => anchors.map((a) => a.href));
        const before = urls.size;

        hrefs.map(pageUrl).filter(Boolean).forEach((url) => urls.add(url));
        stale = urls.size === before ? stale + 1 : 0;

        await page.mouse.wheel(0, 3000);
        await pause(1200, 2200);
    }

    return [...urls].slice(0, maxResults);
}

async function readPage(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('h1', { timeout: 15000 });
    await pause(1200, 2200);

    const text = await page.innerText('body');
    const email = (text.match(EMAIL_PATTERN) ?? []).find((value) => !IGNORED_EMAIL.test(value));
    const phone = (text.match(PHONE_PATTERN) ?? [null])[0];
    const links = await page.$$eval('a[href]', (anchors) => anchors.map((a) => a.href));
    const website = links.map(unwrapRedirect).find((href) => href && /^https?:\/\//.test(href) && !/facebook\.com|fb\.com|instagram\.com|messenger\.com/.test(href));
    const name = text.match(NAME_PATTERN);

    return {
        business_name: (await page.locator('h1').first().innerText()).trim(),
        category: null,
        location: null,
        phone: phone ? phone.trim() : null,
        website: website ?? null,
        email: email ? email.toLowerCase() : null,
        first_name: name ? name[1] : null,
        last_name: name ? name[2] : null,
        source_url: url,
    };
}

const browser = await chromium.launch({ headless: true });

try {
    const context = await browser.newContext({ storageState: job.storageState, locale: 'en-US', viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();

    await page.goto(job.searchUrl, { waitUntil: 'domcontentloaded' });
    await pause(2500, 4000);
    await assertLoggedIn(page);

    const urls = await collectPageUrls(page);
    log(`Found ${urls.length} pages`);

    const leads = [];

    for (const url of urls) {
        try {
            leads.push(await readPage(page, url));
        } catch (error) {
            log(`Skipped ${url}: ${error.message}`);
        }
    }

    process.stdout.write(JSON.stringify(leads));
} finally {
    await browser.close();
}
