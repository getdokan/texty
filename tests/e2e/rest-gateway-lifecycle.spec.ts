import { test, expect } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Lifecycle tests for the activate / deactivate / disconnect endpoints.
 *
 * The `fake` gateway (registered only under WP_DEBUG) declares no credentials,
 * so it is the only gateway we can safely activate without real API keys.
 * The original active gateway is captured up front and restored at the end.
 */
test.describe('REST: gateway lifecycle', () => {
    let nonce = '';
    let originalActive = '';

    const readActive = async (request: APIRequestContext): Promise<string> => {
        const res = await restCall(request, `${REST_NS}/settings/schema`, { nonce });
        const body = await res.json();
        return String(body.active_gateway ?? '');
    };

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        originalActive = await readActive(page.request);
    });

    test.afterEach(async ({ page }) => {
        // Best-effort restore of the original active gateway.
        if (originalActive) {
            await restCall(page.request, `${REST_NS}/gateway/activate`, {
                nonce,
                method: 'POST',
                data: { gateway: originalActive },
            });
        } else {
            await restCall(page.request, `${REST_NS}/gateway/deactivate`, { nonce, method: 'POST' });
        }
    });

    test('activate without a gateway key returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: {},
        });
        expect(res.status()).toBe(400);
    });

    test('activate an unknown gateway returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: { gateway: 'pigeon_post' },
        });
        expect(res.status()).toBe(400);
        const body = await res.json();
        expect(body.code).toBe('texty_unknown_gateway');
    });

    test('activate a credential-requiring gateway with no saved creds returns 400', async ({ page }) => {
        // twilio needs credentials; only assert the 400 contract when it is not
        // already connected on this environment.
        const schema = await restCall(page.request, `${REST_NS}/settings/schema`, { nonce });
        const connected: string[] = (await schema.json()).connected_gateways ?? [];
        test.skip(connected.includes('twilio'), 'twilio already has saved credentials here');

        const res = await restCall(page.request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: { gateway: 'twilio' },
        });
        expect(res.status()).toBe(400);
        const body = await res.json();
        expect(body.code).toBe('texty_no_credentials');
    });

    test('activate the credential-free fake gateway succeeds', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: { gateway: 'fake' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        // Assert the operation's own (atomic) response — a global re-read here
        // would race the gateway-connection suite running on the other worker.
        expect(body.active_gateway).toBe('fake');
    });

    test('deactivate clears the active gateway', async ({ page }) => {
        // Ensure something is active first.
        await restCall(page.request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: { gateway: 'fake' },
        });

        const res = await restCall(page.request, `${REST_NS}/gateway/deactivate`, {
            nonce,
            method: 'POST',
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        // The deactivate response is authoritative for this operation; a global
        // re-read would race the parallel gateway-connection suite.
        expect(body.active_gateway).toBe('');
    });

    test('disconnect without a gateway key returns 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/gateway/disconnect`, {
            nonce,
            method: 'POST',
            data: {},
        });
        expect(res.status()).toBe(400);
    });

    test('disconnect the fake gateway succeeds and reports it', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/gateway/disconnect`, {
            nonce,
            method: 'POST',
            data: { gateway: 'fake' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        expect(body.disconnected).toBe('fake');
    });
});
