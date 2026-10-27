/**
 * Facebook Pages lead scraper (Playwright or Puppeteer).
 *
 * Usage: node facebook.mjs '<json job>'
 *   job: { searchUrl, maxResults, stopAfter?, requireContact?, storageState, method? }
 *   method is "playwright" (default) or "puppeteer".
 *
 * Facebook only shows page search results to logged-in visitors, so a saved
 * session is required. Each user connects their own account from the app (Generate Leads > Facebook).
 *
 * Prints a JSON array of leads to stdout. Progress and errors go to stderr.
 */
import { existsSync } from 'node:fs';
import { closeBrowser, launchBrowser, sleep } from './browser.mjs';

const job = JSON.parse(process.argv[2] ?? '{}');
const maxResults = Math.min(Math.max(Number(job.maxResults) || 20, 1), 60);
const stopAfter = Number(job.stopAfter) > 0 ? Number(job.stopAfter) : Infinity;
const requireContact = job.requireContact === true;

const log = (message) => process.stderr.write(`${message}\n`);
const fail = (message) => {
    log(message);
    process.exit(2);
};
const pause = (min, max) => sleep(min + Math.random() * (max - min));

const EMAIL_PATTERN = /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/gi;
const IGNORED_EMAIL = /(\.(png|jpe?g|gif|svg|webp)$|example\.|sentry|wixpress|domain\.com|yourname)/i;
const PHONE_PATTERN = /(\+?\d[\d\s().-]{6,}\d)/;
const NAME_PATTERN = /\bDr\.?\s+([A-Z][a-z'’-]+)\s+([A-Z][a-z'’-]+)/;
const RESERVED_PATHS = /^\/(search|login|help|groups|events|watch|marketplace|photo|photos|reel|reels|share|policies|privacy|pages|public|hashtag|stories|gaming|ads|business|recover|checkpoint)\b/i;

if (!job.storageState || !existsSync(job.storageState)) {
    fail('No Facebook account is connected. Open Generate Leads, choose Facebook and click "Log in to Facebook".');
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

    if (blocked || (await page.exists('input[name="pass"]'))) {
        fail('The Facebook session expired or was blocked. Open Generate Leads, choose Facebook and log in again.');
    }
}

async function collectPageUrls(page) {
    const urls = new Set();
    let stale = 0;

    while (urls.size < maxResults && stale < 4) {
        const hrefs = await page.hrefs('div[role="feed"] a[role="link"], div[role="article"] a[role="link"]');
        const before = urls.size;

        hrefs.map(pageUrl).filter(Boolean).forEach((url) => urls.add(url));
        stale = urls.size === before ? stale + 1 : 0;

        await page.wheel(3000);
        await pause(1200, 2200);
    }

    return [...urls].slice(0, maxResults);
}

async function readPage(page, url) {
    await page.goto(url);

    if (!(await page.waitFor('h1', 15000))) {
        throw new Error('The page did not load');
    }

    await pause(1200, 2200);

    const text = await page.bodyText();
    const email = (text.match(EMAIL_PATTERN) ?? []).find((value) => !IGNORED_EMAIL.test(value));
    const phone = (text.match(PHONE_PATTERN) ?? [null])[0];
    const links = await page.hrefs('a[href]');
    const website = links.map(unwrapRedirect).find((href) => href && /^https?:\/\//.test(href) && !/facebook\.com|fb\.com|instagram\.com|messenger\.com/.test(href));
    const name = text.match(NAME_PATTERN);

    return {
        business_name: await page.text('h1'),
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

const session = await launchBrowser({ method: job.method, storageState: job.storageState });

try {
    log(`Using ${session.method}`);

    const page = await session.newPage();

    await page.goto(job.searchUrl);
    await pause(2500, 4000);
    await assertLoggedIn(page);

    const urls = await collectPageUrls(page);
    log(`Found ${urls.length} pages`);

    const leads = [];

    for (const url of urls) {
        if (leads.length >= stopAfter) {
            log(`Reached ${stopAfter} leads, stopping`);
            break;
        }

        try {
            const lead = await readPage(page, url);

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
