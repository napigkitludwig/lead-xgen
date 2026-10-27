/**
 * Helpers shared by the Facebook login and session-check scripts.
 */

/**
 * Id of the logged-in Facebook account, or null when there is no logged-in session.
 */
export async function loggedInUserId(session) {
    const cookies = await session.cookies();

    return cookies.find((cookie) => cookie.name === 'c_user' && cookie.domain.includes('facebook.com'))?.value ?? null;
}

/**
 * Best-effort name of the logged-in account.
 */
export async function readAccountName(page) {
    await page.goto('https://www.facebook.com/me', { timeout: 20000 });

    return (await page.waitFor('h1', 10000)) ? page.text('h1') : null;
}
