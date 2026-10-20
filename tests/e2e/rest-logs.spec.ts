import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract tests for GET /texty/v1/logs and GET /texty/v1/logs/{id}.
 * Covers the paginated envelope, parameter clamping/validation and 404 paths.
 */
test.describe('REST: logs contract', () => {
    let nonce = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('returns a paginated envelope', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs`, { nonce });
        expect(res.status()).toBe(200);

        const body = await res.json();
        for (const key of ['items', 'total', 'per_page', 'current_page', 'total_pages']) {
            expect(body, `missing key: ${key}`).toHaveProperty(key);
        }
        expect(Array.isArray(body.items)).toBeTruthy();
        expect(typeof body.total).toBe('number');
        expect(body.current_page).toBe(1);
    });

    test('honours per_page', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { per_page: 5 } });
        const body = await res.json();
        expect(body.per_page).toBe(5);
        expect(body.items.length).toBeLessThanOrEqual(5);
    });

    test('clamps per_page above the MAX_PER_PAGE ceiling (100)', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { per_page: 9999 } });
        const body = await res.json();
        expect(body.per_page).toBeLessThanOrEqual(100);
    });

    test('accepts the empty status filter', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { status: '' } });
        expect(res.status()).toBe(200);
    });

    for (const status of ['sent', 'failed', 'pending']) {
        test(`accepts status=${status}`, async ({ page }) => {
            const res = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { status } });
            expect(res.status()).toBe(200);
        });
    }

    test('rejects an out-of-enum status with 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { status: 'exploded' } });
        expect(res.status()).toBe(400);
    });

    test('rejects an invalid order direction with 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { order: 'sideways' } });
        expect(res.status()).toBe(400);
    });

    // Regression: Logs::get_item() previously passed an int to
    // BaseDataStore::read() (typed `read( ModelInterface &$model )`, throws on a
    // missing row), throwing a fatal 500 on every /logs/{id} call. It now
    // resolves the row via query(), so a missing id returns a clean 404.
    test('GET /logs/{id} on a non-existent id returns 404', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs/99999999`, { nonce });
        expect(res.status()).toBe(404);
        const body = await res.json();
        expect(body.code).toBe('texty_log_not_found');
    });

    test('GET /logs/{non-numeric} does not match the route (404)', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs/abc`, { nonce });
        expect(res.status()).toBe(404);
    });
});
