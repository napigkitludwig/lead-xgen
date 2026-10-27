/**
 * One-time helper: opens a visible browser so you can log in to Facebook,
 * then saves the session for the scraper.
 *
 * Usage: node resources/scrapers/facebook-login.mjs [output-file]
 * Default output: storage/app/private/facebook-session.json
 *
 * Use a dedicated Facebook account. Automated access can get an account restricted.
 */
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { chromium } from 'playwright';

const output = resolve(process.argv[2] ?? 'storage/app/private/facebook-session.json');
mkdirSync(dirname(output), { recursive: true });

const browser = await chromium.launch({ headless: false });
const context = await browser.newContext({ locale: 'en-US' });
const page = await context.newPage();

await page.goto('https://www.facebook.com/login');
console.log('Log in to Facebook in the opened window (you have 5 minutes)...');

await page.waitForURL((url) => !/login|checkpoint|two_step/.test(url.pathname), { timeout: 5 * 60 * 1000 });
await page.waitForTimeout(3000);

await context.storageState({ path: output });
console.log(`Session saved to ${output}`);

await browser.close();
