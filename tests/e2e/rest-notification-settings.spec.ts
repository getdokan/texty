import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract + round-trip tests for /texty/v1/notification-settings.
 * Mutations are captured and restored so the suite stays idempotent.
 */
test.describe('REST: notification-settings', () => {
    let nonce = '';
    let original: Record<string, unknown> = {};

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, { nonce });
        const body = await res.json();
        original = body.settings ?? {};
    });

    test.afterEach(async ({ page }) => {
        // Restore whatever the global compliance settings were before the test.
        await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: {
                global_sender_id: String(original.global_sender_id ?? ''),
                pause_all: Boolean(original.pause_all),
                append_company_name: Boolean(original.append_company_name),
            },
        });
    });

    test('GET exposes the compliance settings shape', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, { nonce });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('settings');
        for (const key of ['global_sender_id', 'pause_all', 'append_company_name']) {
            expect(body.settings, `missing ${key}`).toHaveProperty(key);
        }
    });

    test('POST round-trips the sender id', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: { global_sender_id: 'TEXTY-QA' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.settings.global_sender_id).toBe('TEXTY-QA');
    });

    test('POST round-trips the boolean toggles', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: { pause_all: true, append_company_name: true },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.settings.pause_all).toBe(true);
        expect(body.settings.append_company_name).toBe(true);
    });

    test('POST supports partial updates without clobbering siblings', async ({ page }) => {
        // Seed a known sender id.
        await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: { global_sender_id: 'KEEPME' },
        });

        // Update only pause_all — sender id must survive.
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: { pause_all: true },
        });
        const body = await res.json();
        expect(body.settings.global_sender_id).toBe('KEEPME');
        expect(body.settings.pause_all).toBe(true);
    });

    test('sender id is sanitized (HTML stripped)', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: { global_sender_id: '<script>x</script>Brand' },
        });
        const body = await res.json();
        expect(body.settings.global_sender_id).not.toContain('<script>');
    });
});
