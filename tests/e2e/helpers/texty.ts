import type { Page, APIRequestContext, Browser, APIResponse } from '@playwright/test';

export const TEXTY_BASE = '/wp-admin/admin.php?page=texty';

/** Storage-state file produced by global.setup.ts (logged-in admin). */
export const ADMIN_STORAGE = 'tests/e2e/auth/admin.json';

/** Every REST route the plugin registers, grouped by HTTP verb. */
export const REST_NS = '/wp-json/texty/v1';

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

/** Convenience: open an admin-authenticated page and read its REST nonce once. */
export const bootstrapNonce = async (browser: Browser): Promise<string> => {
    const ctx = await browser.newContext({ storageState: ADMIN_STORAGE, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const nonce = await getRestNonce(page);
    await ctx.close();
    return nonce;
};

export const restRequest = async (
    request: APIRequestContext,
    nonce: string,
    path: string
): Promise<APIResponse> => request.get(path, { headers: { 'X-WP-Nonce': nonce } });

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

interface RestCallOptions {
    method?: HttpMethod;
    nonce?: string;
    data?: Record<string, unknown>;
    params?: Record<string, string | number>;
}

/**
 * Flexible REST client used by the contract suites. Sends the nonce header so
 * cookie-authenticated write requests pass WP's nonce check.
 */
export const restCall = async (
    request: APIRequestContext,
    path: string,
    options: RestCallOptions = {}
): Promise<APIResponse> => {
    const { method = 'GET', nonce, data, params } = options;
    const headers: Record<string, string> = nonce ? { 'X-WP-Nonce': nonce } : {};

    let url = path;
    if (params) {
        const qs = new URLSearchParams(
            Object.entries(params).map(([k, v]) => [k, String(v)])
        ).toString();
        url = `${path}${path.includes('?') ? '&' : '?'}${qs}`;
    }

    return request.fetch(url, {
        method,
        headers,
        ...(data ? { data } : {}),
    });
};

export interface TwilioEnv {
    sid: string;
    token: string;
    from: string;
}

/**
 * Read live Twilio credentials from the environment (.env). Returns null when
 * any required value is missing so connection suites can `test.skip` cleanly
 * on a machine without secrets (e.g. CI without the gateway keys).
 */
export const getTwilioEnv = (): TwilioEnv | null => {
    const sid = process.env.TWILIO_ACCOUNT_SID ?? '';
    const token = process.env.TWILIO_AUTH_TOKEN ?? '';
    const from = process.env.TWILIO_PHONE_NUMBER ?? '';
    if (!sid || !token || !from) {
        return null;
    }
    return { sid, token, from };
};

/** Read the live Clickatell API key from the environment (.env), or null. */
export const getClickatellKey = (): string | null => {
    const key = process.env.CLICKATELL_API_KEY ?? '';
    return key || null;
};
