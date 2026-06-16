import { test, expect } from '@playwright/test';
import { getRestNonce, restRequest } from './helpers/texty';

const ENDPOINTS = [
    '/wp-json/texty/v1/metrics',
    '/wp-json/texty/v1/gateway',
    '/wp-json/texty/v1/logs',
    '/wp-json/texty/v1/notification-settings',
    '/wp-json/texty/v1/settings',
    '/wp-json/texty/v1/notifications',
    '/wp-json/texty/v1/status',
    '/wp-json/texty/v1/tools',
];

test.describe('REST API smoke', () => {
    let nonce: string = '';

    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext({
            storageState: 'tests/e2e/auth/admin.json',
            ignoreHTTPSErrors: true,
        });
        const page = await ctx.newPage();
        nonce = await getRestNonce(page);
        await ctx.close();
    });

    for (const endpoint of ENDPOINTS) {
        test(`GET ${endpoint} returns non-5xx for admin`, async ({ page }) => {
            const res = await restRequest(page.request, nonce, endpoint);
            expect(res.status(), `${endpoint} returned ${res.status()}`).toBeLessThan(500);
            expect(
                [200, 201, 204, 400, 404].includes(res.status()),
                `${endpoint} returned ${res.status()}`
            ).toBeTruthy();
        });
    }

    test('unauthenticated request to gateway is rejected', async ({ browser }) => {
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
        const res = await ctx.request.get('/wp-json/texty/v1/gateway');
        expect([401, 403, 404]).toContain(res.status());
        await ctx.close();
    });

    test('unauthenticated request to logs is rejected', async ({ browser }) => {
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
        const res = await ctx.request.get('/wp-json/texty/v1/logs');
        expect([401, 403]).toContain(res.status());
        await ctx.close();
    });

    test('unauthenticated request to metrics is rejected', async ({ browser }) => {
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
        const res = await ctx.request.get('/wp-json/texty/v1/metrics');
        expect([401, 403]).toContain(res.status());
        await ctx.close();
    });
});
