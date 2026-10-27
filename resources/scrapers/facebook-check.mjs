/**
 * Checks whether a saved Facebook session is still logged in.
 *
 * Usage: node facebook-check.mjs <session-file>
 * Prints {"loggedIn": bool, "id": ?string, "name": ?string} to stdout.
 */
import { existsSync } from 'node:fs';
import { chromium } from 'playwright';
import { loggedInUserId, readAccountName } from './facebook-session.mjs';

const sessionFile = process.argv[2];
const print = (data) => new Promise((resolve) => process.stdout.write(JSON.stringify(data), resolve));

if (!sessionFile || !existsSync(sessionFile)) {
    await print({ loggedIn: false, id: null, name: null });
    process.exit(0);
}

const browser = await chromium.launch({ headless: true });

try {
    const context = await browser.newContext({ storageState: sessionFile, locale: 'en-US' });
    const page = await context.newPage();

    await page.goto('https://www.facebook.com/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(2500);

    const id = await loggedInUserId(context);
    const blocked = page.url().includes('/login') || page.url().includes('/checkpoint') || (await page.locator('input[name="pass"]').count()) > 0;

    if (!id || blocked) {
        await print({ loggedIn: false, id: null, name: null });
    } else {
        await print({ loggedIn: true, id, name: await readAccountName(page) });
    }
} catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 2;
} finally {
    await Promise.race([browser.close().catch(() => {}), new Promise((resolve) => setTimeout(resolve, 10000))]);
}

process.exit(process.exitCode ?? 0);
