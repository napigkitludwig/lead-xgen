/**
 * Opens a visible browser window so the user can log in to their own Facebook
 * account, then saves that session for the scraper.
 *
 * Usage: node facebook-login.mjs <session-file>
 * Prints {"id": "...", "name": "..."} to stdout on success.
 *
 * Automated access can get a Facebook account restricted, so use an account you
 * are comfortable using for this.
 */
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { chromium } from 'playwright';
import { loggedInUserId, readAccountName } from './facebook-session.mjs';

const output = process.argv[2] ? resolve(process.argv[2]) : null;

if (!output) {
    process.stderr.write('Usage: node facebook-login.mjs <session-file>\n');
    process.exit(2);
}

mkdirSync(dirname(output), { recursive: true });

const LOGIN_TIMEOUT_MS = 5 * 60 * 1000;
const browser = await chromium.launch({ headless: false });

try {
    const context = await browser.newContext({ locale: 'en-US', viewport: null });
    const page = await context.newPage();

    await page.goto('https://www.facebook.com/login');

    const deadline = Date.now() + LOGIN_TIMEOUT_MS;
    let id = null;

    while (!id && Date.now() < deadline) {
        if (page.isClosed()) {
            throw new Error('The Facebook login window was closed before logging in.');
        }

        id = await loggedInUserId(context).catch(() => null);

        if (!id) {
            await new Promise((resolve) => setTimeout(resolve, 2000));
        }
    }

    if (!id) {
        throw new Error('Timed out waiting for the Facebook login.');
    }

    await page.waitForTimeout(3000);

    const name = await readAccountName(page);

    await context.storageState({ path: output });
    await new Promise((resolve) => process.stdout.write(JSON.stringify({ id, name }), resolve));
} catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 2;
} finally {
    await Promise.race([browser.close().catch(() => {}), new Promise((resolve) => setTimeout(resolve, 10000))]);
}

process.exit(process.exitCode ?? 0);
