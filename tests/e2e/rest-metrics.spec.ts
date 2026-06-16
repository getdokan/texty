import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract tests for GET /texty/v1/metrics.
 * Verifies the response shape, the `period` enum, and method guarding.
 */
test.describe('REST: metrics contract', () => {
    let nonce = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('returns the full metrics payload shape', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/metrics`, { nonce });
        expect(res.status()).toBe(200);

        const body = await res.json();
        for (const key of [
            'period',
            'gateway_status',
            'gateway_name',
            'sms_sent',
            'delivered',
            'failed',
            'delivery_rate',
            'volume_chart',
        ]) {
            expect(body, `missing key: ${key}`).toHaveProperty(key);
        }

        expect(typeof body.gateway_status).toBe('boolean');
        expect(typeof body.sms_sent).toBe('number');
        expect(typeof body.delivered).toBe('number');
        expect(typeof body.failed).toBe('number');
        expect(typeof body.delivery_rate).toBe('number');
        expect(Array.isArray(body.volume_chart)).toBeTruthy();
    });

    test('delivered + failed never exceed sms_sent', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/metrics`, { nonce });
        const body = await res.json();
        expect(body.delivered + body.failed).toBeLessThanOrEqual(body.sms_sent);
        expect(body.delivery_rate).toBeGreaterThanOrEqual(0);
        expect(body.delivery_rate).toBeLessThanOrEqual(100);
    });

    const periods = ['this_month', 'last_month', 'last_7_days', 'last_30_days', 'this_year'];
    for (const period of periods) {
        test(`accepts period=${period} and echoes it back`, async ({ page }) => {
            const res = await restCall(page.request, `${REST_NS}/metrics`, { nonce, params: { period } });
            expect(res.status()).toBe(200);
            const body = await res.json();
            expect(body.period).toBe(period);
        });
    }

    test('rejects an invalid period with 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/metrics`, {
            nonce,
            params: { period: 'last_century' },
        });
        expect(res.status()).toBe(400);
        const body = await res.json();
        expect(body.code).toBe('rest_invalid_param');
    });

    test('defaults to this_month when period is omitted', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/metrics`, { nonce });
        const body = await res.json();
        expect(body.period).toBe('this_month');
    });

    test('DELETE on metrics is not a registered route', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/metrics`, { nonce, method: 'DELETE' });
        expect([404, 405]).toContain(res.status());
    });
});
