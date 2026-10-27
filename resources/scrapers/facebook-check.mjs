/**
 * Checks whether a saved Facebook session is still logged in.
 *
 * Usage: node facebook-check.mjs <session-file> [playwright|puppeteer]
 * Prints {"loggedIn": bool, "id": ?string, "name": ?string} to stdout.
 */
import { existsSync } from 'node:fs';
import { closeBrowser, launchBrowser, sleep } from './browser.mjs';
import { loggedInUserId, readAccountName } from './facebook-session.mjs';

const sessionFile = process.argv[2];
const print = (data) => new Promise((resolve) => process.stdout.write(JSON.stringify(data), resolve));

if (!sessionFile || !existsSync(sessionFile)) {
    await print({ loggedIn: false, id: null, name: null });
    process.exit(0);
}

const session = await launchBrowser({ method: process.argv[3], storageState: sessionFile });

try {
    const page = await session.newPage();

    await page.goto('https://www.facebook.com/', { timeout: 30000 });
    await sleep(2500);

    const id = await loggedInUserId(session);
    const blocked = page.url().includes('/login') || page.url().includes('/checkpoint') || (await page.exists('input[name="pass"]'));

    if (!id || blocked) {
        await print({ loggedIn: false, id: null, name: null });
    } else {
        await print({ loggedIn: true, id, name: await readAccountName(page) });
    }
} catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 2;
} finally {
    await closeBrowser(session);
}

process.exit(process.exitCode ?? 0);
