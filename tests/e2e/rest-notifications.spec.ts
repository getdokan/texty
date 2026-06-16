import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract tests for:
 *   GET /texty/v1/notifications
 *   GET /texty/v1/notifications/schema?group=...
 */
test.describe('REST: notifications contract', () => {
    let nonce = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('GET /notifications returns 200', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notifications`, { nonce });
        expect(res.status()).toBe(200);
    });

    test('schema for the wp group returns schema + values', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notifications/schema`, {
            nonce,
            params: { group: 'wp' },
        });
        expect(res.status()).toBe(200);

        const body = await res.json();
        expect(body).toHaveProperty('schema');
        expect(body).toHaveProperty('values');
        expect(Array.isArray(body.schema)).toBeTruthy();

        // The page + section scaffolding must be present.
        const types = body.schema.map((el: { type?: string }) => el.type);
        expect(types).toContain('page');
        expect(types).toContain('section');

        // Built-in WP notifications (registration, comment) appear as fields with a boolean value.
        expect(body.values).toHaveProperty('registration');
        expect(typeof body.values.registration).toBe('boolean');
    });

    test('schema for an unknown group returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notifications/schema`, {
            nonce,
            params: { group: 'martian' },
        });
        expect(res.status()).toBe(400);
        const body = await res.json();
        expect(body.code).toBe('texty_invalid_group');
    });

    test('schema without the required group param returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notifications/schema`, { nonce });
        expect(res.status()).toBe(400);
    });
});
