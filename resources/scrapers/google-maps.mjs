/**
 * Google Maps lead scraper (Playwright or Puppeteer).
 *
 * Usage: node google-maps.mjs '<json job>'
 *   job: { searchUrl, maxResults, enrichWebsites, stopAfter?, requireContact?, method? }
 *   method is "playwright" (default) or "puppeteer".
 *   Stops as soon as `stopAfter` leads are collected (only leads with both an
 *   email and a phone number when `requireContact` is true).
 *
 * Prints a JSON array of leads to stdout. Progress and errors go to stderr.
 */
import { closeBrowser, launchBrowser, sleep, withTimeout } from './browser.mjs';

const job = JSON.parse(process.argv[2] ?? '{}');
const maxResults = Math.min(Math.max(Number(job.maxResults) || 20, 1), 120);
const enrichWebsites = job.enrichWebsites !== false;
const stopAfter = Number(job.stopAfter) > 0 ? Number(job.stopAfter) : Infinity;
const requireContact = job.requireContact === true;

const log = (message) => process.stderr.write(`${message}\n`);
const pause = (min, max) => sleep(min + Math.random() * (max - min));

const EMAIL_PATTERN = /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/gi;
const IGNORED_EMAIL = /(\.(png|jpe?g|gif|svg|webp)$|example\.|sentry|wixpress|domain\.com|yourname)/i;
const NAME_PATTERN = /\bDr\.?\s+([A-Z][a-z'’-]+)\s+([A-Z][a-z'’-]+)/;

// Give up on a slow or stuck website instead of letting it hold the whole run.
const ENRICH_TIMEOUT_MS = 40000;

async function acceptConsent(page) {
    await page.clickButtonWithText(['Accept all', 'Reject all'], 3000);
}

async function collectPlaceUrls(page) {
    const feed = 'div[role="feed"]';

    if (!(await page.waitFor(feed, 15000))) {
        // A single result opens the place page directly.
        return page.url().includes('/maps/place/') ? [page.url()] : [];
    }

    const urls = new Set();
    let stale = 0;

    while (urls.size < maxResults && stale < 4) {
        const before = urls.size;

        (await page.hrefs('a[href*="/maps/place/"]')).forEach((url) => urls.add(url));
        stale = urls.size === before ? stale + 1 : 0;

        if ((await page.bodyText()).includes("You've reached the end of the list")) {
            break;
        }

        await page.scrollToEnd(feed);
        await pause(900, 1600);
    }

    return [...urls].slice(0, maxResults);
}

async function readLabel(page, selector) {
    const text = await page.first(selector, (el) => el.getAttribute('aria-label') ?? el.innerText);

    return text ? text.replace(/^(Address|Phone|Website):\s*/i, '').trim() : null;
}

async function readPlace(page, url) {
    await page.goto(url);

    if (!(await page.waitFor('h1', 15000))) {
        throw new Error('The place page did not load');
    }

    await pause(600, 1200);

    return {
        business_name: await page.text('h1'),
        category: await readLabel(page, 'button[jsaction*="category"]'),
        location: await readLabel(page, 'button[data-item-id="address"]'),
        phone: await readLabel(page, 'button[data-item-id^="phone"]'),
        website: await page.attr('a[data-item-id="authority"]', 'href'),
        source_url: url,
        email: null,
        first_name: null,
        last_name: null,
    };
}

async function enrichFromWebsite(session, lead) {
    if (!lead.website) {
        return;
    }

    const page = await session.newPage();
    const base = new URL(lead.website);
    const candidates = [base.href, new URL('/contact', base).href, new URL('/about', base).href];

    try {
        for (const candidate of candidates) {
            if (!(await page.goto(candidate, { timeout: 12000 }))) {
                continue;
            }

            const html = await page.html();

            if (!lead.email) {
                const email = (html.match(EMAIL_PATTERN) ?? []).find((value) => !IGNORED_EMAIL.test(value));
                lead.email = email ? email.toLowerCase() : null;
            }

            if (!lead.first_name) {
                const name = (await page.bodyText()).match(NAME_PATTERN);
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

const session = await launchBrowser({ method: job.method });

try {
    log(`Using ${session.method}`);

    const page = await session.newPage();

    if (!(await page.goto(job.searchUrl))) {
        throw new Error('Google Maps did not load');
    }

    await acceptConsent(page);

    const urls = await collectPlaceUrls(page);
    log(`Found ${urls.length} places`);

    const leads = [];

    for (const url of urls) {
        if (leads.length >= stopAfter) {
            log(`Reached ${stopAfter} leads, stopping`);
            break;
        }

        try {
            const lead = await readPlace(page, url);
            log(`Read ${lead.business_name}`);

            if (enrichWebsites) {
                await withTimeout(() => enrichFromWebsite(session, lead), undefined, ENRICH_TIMEOUT_MS);
            }

            if (!requireContact || (lead.email && lead.phone)) {
                leads.push(lead);
            }
        } catch (error) {
            log(`Skipped ${url}: ${error.message}`);
        }
    }

    await new Promise((resolve) => process.stdout.write(JSON.stringify(leads), resolve));
} finally {
    // Never let a stuck browser keep the job running once the leads are collected.
    await closeBrowser(session);
}

process.exit(0);
