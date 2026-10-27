/**
 * Helpers shared by the Facebook login and session-check scripts.
 */

/**
 * Whether the browser context holds a logged-in Facebook session.
 */
export async function loggedInUserId(context) {
    const cookies = await context.cookies('https://www.facebook.com');

    return cookies.find((cookie) => cookie.name === 'c_user')?.value ?? null;
}

/**
 * Best-effort name of the logged-in account.
 */
export async function readAccountName(page) {
    try {
        await page.goto('https://www.facebook.com/me', { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page.waitForSelector('h1', { timeout: 10000 });

        const name = (await page.locator('h1').first().innerText()).trim();

        return name || null;
    } catch {
        return null;
    }
}
