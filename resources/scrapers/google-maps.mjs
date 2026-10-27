/**
 * Google Maps lead scraper (Playwright).
 *
 * Usage: node google-maps.mjs '<json job>'
 *   job: { searchUrl: string, maxResults: number, enrichWebsites: boolean }
 *
 * Prints a JSON array of leads to stdout. Progress and errors go to stderr.
 */
import { chromium } from 'playwright';

const job = JSON.parse(process.argv[2] ?? '{}');
const maxResults = Math.min(Math.max(Number(job.maxResults) || 20, 1), 120);
const enrichWebsites = job.enrichWebsites !== false;

const log = (message) => process.stderr.write(`${message}\n`);
const pause = (min, max) => new Promise((resolve) => setTimeout(resolve, min + Math.random() * (max - min)));

const EMAIL_PATTERN = /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/gi;
const IGNORED_EMAIL = /(\.(png|jpe?g|gif|svg|webp)$|example\.|sentry|wixpress|domain\.com|yourname)/i;
const NAME_PATTERN = /\bDr\.?\s+([A-Z][a-z'’-]+)\s+([A-Z][a-z'’-]+)/;

async function acceptConsent(page) {
    const button = page.locator('button:has-text("Accept all"), button:has-text("Reject all")').first();

    const hasConsent = await button.waitFor({ state: 'visible', timeout: 3000 }).then(() => true, () => false);

    if (hasConsent) {
        await button.click().catch(() => {});
    }
}

async function collectPlaceUrls(page) {
    const feed = page.locator('div[role="feed"]');

    const hasFeed = await feed.waitFor({ state: 'visible', timeout: 15000 }).then(() => true, () => false);

    if (!hasFeed) {
        // A single result opens the place page directly.
        return page.url().includes('/maps/place/') ? [page.url()] : [];
    }

    let urls = new Set();
    let stale = 0;

    while (urls.size < maxResults && stale < 4) {
        const found = await page.$$eval('a[href*="/maps/place/"]', (anchors) => anchors.map((a) => a.href));
        const before = urls.size;
        found.forEach((url) => urls.add(url));
        stale = urls.size === before ? stale + 1 : 0;

        if (await page.locator('text=You\'ve reached the end of the list').isVisible().catch(() => false)) {
            break;
        }

        await feed.evaluate((el) => el.scrollBy(0, el.scrollHeight));
        await pause(900, 1600);
    }

    return [...urls].slice(0, maxResults);
}

async function readText(page, selector) {
    const element = page.locator(selector).first();

    if (!(await element.count())) {
        return null;
    }

    const text = (await element.getAttribute('aria-label').catch(() => null)) ?? (await element.innerText().catch(() => null));

    return text ? text.replace(/^(Address|Phone|Website):\s*/i, '').trim() : null;
}

async function readPlace(page, url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('h1', { timeout: 15000 });
    await pause(600, 1200);

    const website = await page.locator('a[data-item-id="authority"]').first().getAttribute('href').catch(() => null);

    return {
        business_name: (await page.locator('h1').first().innerText()).trim(),
        category: await readText(page, 'button[jsaction*="category"]'),
        location: await readText(page, 'button[data-item-id="address"]'),
        phone: await readText(page, 'button[data-item-id^="phone"]'),
        website,
        source_url: url,
        email: null,
        first_name: null,
        last_name: null,
    };
}

async function enrichFromWebsite(context, lead) {
    if (!lead.website) {
        return;
    }

    const page = await context.newPage();
    const base = new URL(lead.website);
    const candidates = [base.href, new URL('/contact', base).href, new URL('/about', base).href];

    try {
        for (const candidate of candidates) {
            const response = await page.goto(candidate, { waitUntil: 'domcontentloaded', timeout: 12000 }).catch(() => null);

            if (!response || !response.ok()) {
                continue;
            }

            const html = await page.content();

            if (!lead.email) {
                const email = (html.match(EMAIL_PATTERN) ?? []).find((value) => !IGNORED_EMAIL.test(value));
                lead.email = email ? email.toLowerCase() : null;
            }

            if (!lead.first_name) {
                const name = (await page.innerText('body').catch(() => '')).match(NAME_PATTERN);
                [lead.first_name, lead.last_name] = name ? [name[1], name[2]] : [null, null];
            }

            if (lead.email && lead.first_name) {
                break;
            }
        }
    } finally {
        await page.close();
    }
}

const browser = await chromium.launch({ headless: true });

try {
    const context = await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();

    await page.goto(job.searchUrl, { waitUntil: 'domcontentloaded' });
    await acceptConsent(page);

    const urls = await collectPlaceUrls(page);
    log(`Found ${urls.length} places`);

    const leads = [];

    for (const url of urls) {
        try {
            const lead = await readPlace(page, url);

            if (enrichWebsites) {
                await enrichFromWebsite(context, lead).catch((error) => log(`Enrich failed: ${error.message}`));
            }

            leads.push(lead);
        } catch (error) {
            log(`Skipped ${url}: ${error.message}`);
        }
    }

    process.stdout.write(JSON.stringify(leads));
} finally {
    await browser.close();
}
