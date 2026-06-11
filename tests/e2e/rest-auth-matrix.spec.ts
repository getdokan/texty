import { test, expect } from '@playwright/test';
import { REST_NS } from './helpers/texty';

/**
 * Security matrix — every Texty route is gated behind `manage_options`.
 * An unauthenticated client (no cookies, no nonce) must be rejected on every
 * read and write endpoint. WordPress answers either 401 (rest_not_logged_in)
 * or 403 (rest_forbidden / rest_cookie_invalid_nonce).
 */

const READ_ENDPOINTS = [
    '/metrics',
    '/logs',
    '/logs/1',
    '/settings',
    '/settings/schema',
    '/notifications',
    '/notification-settings',
    '/status',
];

const WRITE_ENDPOINTS: Array<{ path: string; data: Record<string, unknown> }> = [
    { path: '/send', data: { to: '+15555550123', message: 'x' } },
    { path: '/tools/test', data: { to: '+15555550123' } },
    { path: '/settings', data: { gateway: 'twilio' } },
    { path: '/notification-settings', data: { pause_all: true } },
    { path: '/gateway/activate', data: { gateway: 'fake' } },
    { path: '/gateway/deactivate', data: {} },
    { path: '/gateway/disconnect', data: { gateway: 'fake' } },
];

test.describe('REST: unauthenticated access is rejected', () => {
    for (const path of READ_ENDPOINTS) {
        test(`GET ${path} unauthenticated → 401/403`, async ({ browser }) => {
            const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
            const res = await ctx.request.get(`${REST_NS}${path}`);
            expect(
                [401, 403].includes(res.status()),
                `${path} returned ${res.status()}`
            ).toBeTruthy();
            await ctx.close();
        });
    }

    for (const { path, data } of WRITE_ENDPOINTS) {
        test(`POST ${path} unauthenticated → 401/403`, async ({ browser }) => {
            const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
            const res = await ctx.request.post(`${REST_NS}${path}`, { data });
            expect(
                [401, 403].includes(res.status()),
                `${path} returned ${res.status()}`
            ).toBeTruthy();
            await ctx.close();
        });
    }

    test('a write without a nonce (but with admin cookies) is blocked', async ({ browser }) => {
        // Cookies alone are not enough — WP requires a valid X-WP-Nonce for
        // cookie-authenticated writes. Omitting it must fail.
        const ctx = await browser.newContext({
            storageState: 'tests/e2e/auth/admin.json',
            ignoreHTTPSErrors: true,
        });
        const res = await ctx.request.post(`${REST_NS}/notification-settings`, {
            data: { pause_all: true },
        });
        expect([401, 403]).toContain(res.status());
        await ctx.close();
    });
});
