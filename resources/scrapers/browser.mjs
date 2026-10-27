/**
 * One small browser API for the scrapers, backed by Playwright or Puppeteer.
 *
 * The scrapers only talk to this module, so the same script runs on either
 * engine. Choose the engine with `method: 'playwright' | 'puppeteer'`.
 * Saved sessions use the Playwright "storage state" file format for both engines,
 * so a login made with one engine can be used by the other.
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const OPERATION_TIMEOUT_MS = 10000;

/** Resolve to `fallback` if the operation throws or takes too long, so one stuck page cannot hang a scrape. */
async function bounded(operation, fallback, ms = OPERATION_TIMEOUT_MS) {
    try {
        return await Promise.race([operation(), sleep(ms).then(() => fallback)]);
    } catch {
        return fallback;
    }
}

const VIEWPORT = { width: 1280, height: 900 };
const SAME_SITE = ['Strict', 'Lax', 'None'];

function normalizeCookie(cookie) {
    return {
        name: cookie.name,
        value: cookie.value,
        domain: cookie.domain,
        path: cookie.path ?? '/',
        expires: cookie.session || cookie.expires === undefined ? -1 : cookie.expires,
        httpOnly: Boolean(cookie.httpOnly),
        secure: Boolean(cookie.secure),
        sameSite: SAME_SITE.includes(cookie.sameSite) ? cookie.sameSite : 'Lax',
    };
}

function readSavedCookies(storageState) {
    if (!storageState || !existsSync(storageState)) {
        return [];
    }

    return (JSON.parse(readFileSync(storageState, 'utf8')).cookies ?? []).map(normalizeCookie);
}

/**
 * A page with the handful of operations the scrapers need. Every method either
 * returns a plain value or resolves to a safe fallback instead of throwing.
 */
class BrowserPage {
    constructor(raw, method) {
        this.raw = raw;
        this.method = method;
    }

    /** Navigate and resolve to whether the response was successful. */
    goto(url, { timeout = 30000 } = {}) {
        return bounded(
            async () => {
                const response = await this.raw.goto(url, { waitUntil: 'domcontentloaded', timeout });

                return response ? response.ok() : false;
            },
            false,
            timeout + 5000,
        );
    }

    url() {
        return this.raw.url();
    }

    isClosed() {
        return this.raw.isClosed();
    }

    /** Wait until an element is visible. Resolves to false on timeout. */
    async waitFor(selector, timeout = 15000) {
        try {
            await this.raw.waitForSelector(selector, this.method === 'puppeteer' ? { visible: true, timeout } : { state: 'visible', timeout });

            return true;
        } catch {
            return false;
        }
    }

    exists(selector) {
        return bounded(async () => (await this.raw.$(selector)) !== null, false);
    }

    /** Run a function against the first match. Resolves to null when nothing matches. */
    first(selector, fn, arg) {
        return bounded(() => this.raw.$eval(selector, fn, arg), null);
    }

    async text(selector) {
        const text = await this.first(selector, (el) => el.innerText);

        return text ? text.trim() : null;
    }

    attr(selector, name) {
        return this.first(selector, (el, attribute) => el.getAttribute(attribute), name);
    }

    /** href of every match. */
    hrefs(selector) {
        return bounded(() => this.raw.$$eval(selector, (elements) => elements.map((el) => el.href)), []);
    }

    async bodyText() {
        return (await bounded(() => this.raw.evaluate(() => document.body.innerText), '')) ?? '';
    }

    /** Page HTML. Pages that redirect while loading get one retry after settling. */
    async html() {
        const read = () => bounded(() => this.raw.content(), null);

        return (await read()) ?? (await sleep(1500), await read()) ?? '';
    }

    scrollToEnd(selector) {
        return this.first(selector, (el) => el.scrollBy(0, el.scrollHeight));
    }

    wheel(deltaY) {
        return this.method === 'puppeteer' ? this.raw.mouse.wheel({ deltaY }) : this.raw.mouse.wheel(0, deltaY);
    }

    /** Click the first visible button whose text contains any of the labels. */
    async clickButtonWithText(labels, timeout = 3000) {
        const deadline = Date.now() + timeout;

        while (Date.now() < deadline) {
            const clicked = await this.raw
                .evaluate((wanted) => {
                    const button = [...document.querySelectorAll('button')].find((el) => el.offsetParent !== null && wanted.some((label) => (el.innerText || '').includes(label)));

                    button?.click();

                    return Boolean(button);
                }, labels)
                .catch(() => false);

            if (clicked) {
                return true;
            }

            await sleep(250);
        }

        return false;
    }

    close() {
        return bounded(() => this.raw.close(), undefined, 5000);
    }
}

async function launchPlaywright({ headless, storageState, locale }) {
    const { chromium } = await import('playwright');
    const browser = await chromium.launch({ headless });
    const context = await browser.newContext({
        ...(storageState ? { storageState } : {}),
        locale,
        viewport: headless ? VIEWPORT : null,
    });

    return {
        method: 'playwright',
        newPage: async () => new BrowserPage(await context.newPage(), 'playwright'),
        cookies: () => context.cookies(),
        saveState: (path) => context.storageState({ path }),
        close: () => browser.close(),
    };
}

async function launchPuppeteer({ headless, storageState, locale }) {
    const { default: puppeteer } = await import('puppeteer');
    const browser = await puppeteer.launch({
        headless,
        defaultViewport: headless ? VIEWPORT : null,
        args: [`--lang=${locale}`],
    });

    const saved = readSavedCookies(storageState);

    if (saved.length > 0) {
        await browser.setCookie(...saved);
    }

    return {
        method: 'puppeteer',
        newPage: async () => {
            const page = await browser.newPage();

            await page.setExtraHTTPHeaders({ 'Accept-Language': locale });

            return new BrowserPage(page, 'puppeteer');
        },
        cookies: () => browser.cookies(),
        saveState: async (path) => {
            const cookies = (await browser.cookies()).map(normalizeCookie);

            writeFileSync(path, JSON.stringify({ cookies, origins: [] }));
        },
        close: () => browser.close(),
    };
}

/**
 * @param {{ method?: 'playwright' | 'puppeteer', headless?: boolean, storageState?: string | null, locale?: string }} options
 */
export function launchBrowser({ method = 'playwright', headless = true, storageState = null, locale = 'en-US' } = {}) {
    const options = { headless, storageState, locale };

    return method === 'puppeteer' ? launchPuppeteer(options) : launchPlaywright(options);
}

/** Close the browser, but never wait more than a few seconds for a stuck one. */
/** Run an operation but give up after `ms`, resolving to `fallback`. */
export const withTimeout = bounded;

export async function closeBrowser(session) {
    await Promise.race([session.close().catch(() => {}), sleep(10000)]);
}
