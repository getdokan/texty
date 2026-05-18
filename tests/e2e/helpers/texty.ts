import type { Page, APIRequestContext } from '@playwright/test';

export const TEXTY_BASE = '/wp-admin/admin.php?page=texty';

export const gotoTextyHash = async (page: Page, hash: string = '/dashboard'): Promise<void> => {
    const target: string = `${TEXTY_BASE}#${hash}`;
    await page.goto(target);
    await page.locator('#texty-app').waitFor({ state: 'attached' });
};

export const waitForApi = (page: Page, urlFragment: string) =>
    page.waitForResponse((res) => res.url().includes(urlFragment) && res.status() < 500);

export const getRestNonce = async (page: Page): Promise<string> => {
    await page.goto('/wp-admin/');
    const nonce: string = await page.evaluate(
        () => (window as unknown as { wpApiSettings?: { nonce?: string } }).wpApiSettings?.nonce ?? ''
    );
    if (nonce) return nonce;

    await page.goto('/wp-admin/profile.php');
    const fallback: string = await page.evaluate(() => {
        const root = document.documentElement.outerHTML;
        const m = root.match(/"nonce":"([a-f0-9]{10,})"/);
        return m ? m[1] : '';
    });
    return fallback;
};

export const restRequest = async (
    request: APIRequestContext,
    nonce: string,
    path: string
) => request.get(path, { headers: { 'X-WP-Nonce': nonce } });
