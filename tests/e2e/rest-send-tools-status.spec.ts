import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract tests for the action endpoints:
 *   POST /texty/v1/send        — { to, message }
 *   POST /texty/v1/tools/test  — { to }
 *   GET  /texty/v1/status      — active-gateway boolean
 */
test.describe('REST: send / tools / status', () => {
    let nonce = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('send without required params returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/send`, {
            nonce,
            method: 'POST',
            data: {},
        });
        expect(res.status()).toBe(400);
        const body = await res.json();
        expect(body.code).toBe('rest_missing_callback_param');
    });

    test('send with only "to" still 400 (message required)', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/send`, {
            nonce,
            method: 'POST',
            data: { to: '+15555550123' },
        });
        expect(res.status()).toBe(400);
    });

    test('send with both params returns the success/message envelope', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/send`, {
            nonce,
            method: 'POST',
            data: { to: '+15555550123', message: 'QA smoke message' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('success');
        expect(body).toHaveProperty('message');
        expect(typeof body.success).toBe('boolean');
    });

    test('tools/test without "to" returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/tools/test`, {
            nonce,
            method: 'POST',
            data: {},
        });
        expect(res.status()).toBe(400);
    });

    test('tools/test with "to" returns the success/message envelope', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/tools/test`, {
            nonce,
            method: 'POST',
            data: { to: '+15555550123' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('success');
        expect(body).toHaveProperty('message');
    });

    test('status returns a boolean success flag', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/status`, { nonce });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('success');
        expect(typeof body.success).toBe('boolean');
    });
});
