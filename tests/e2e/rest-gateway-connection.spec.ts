import { test, expect } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import {
    bootstrapNonce,
    restCall,
    REST_NS,
    getTwilioEnv,
    getClickatellKey,
} from './helpers/texty';

/**
 * Live gateway connection + validation, using real credentials from .env.
 *
 * Saving credentials goes through Api\Settings::update_items, which calls the
 * gateway's validate() before persisting:
 *   - Twilio::validate()     makes a real Accounts.json call (401 → reject).
 *   - Clickatell::validate() is a NO-OP — it returns the key unverified, so
 *     credentials are never checked at save time (only a real send validates).
 *
 * These tests mutate the active gateway + stored credentials, so the original
 * active gateway is captured up front and restored at the end. The saved creds
 * match what's already in .env, so re-saving is idempotent.
 */

const twilio = getTwilioEnv();
const clickatellKey = getClickatellKey();

test.describe('REST: live gateway connection', () => {
    let nonce = '';
    let originalActive = '';

    const readActive = async (request: APIRequestContext): Promise<string> => {
        const res = await restCall(request, `${REST_NS}/settings/schema`, { nonce });
        return String((await res.json()).active_gateway ?? '');
    };

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        originalActive = await readActive(page.request);
    });

    test.afterAll(async ({ browser }) => {
        // Restore the gateway that was active before this suite ran.
        const ctx = await browser.newContext({
            storageState: 'tests/e2e/auth/admin.json',
            ignoreHTTPSErrors: true,
        });
        if (originalActive) {
            await restCall(ctx.request, `${REST_NS}/gateway/activate`, {
                nonce,
                method: 'POST',
                data: { gateway: originalActive },
            });
        }
        await ctx.close();
    });

    test.describe('Twilio (validate() hits the live API)', () => {
        test.skip(!twilio, 'TWILIO_* not set in .env');

        test('valid credentials are accepted and the gateway is saved + activated', async ({ page }) => {
            const res = await restCall(page.request, `${REST_NS}/settings`, {
                nonce,
                method: 'POST',
                data: { gateway: 'twilio', twilio },
            });
            expect(res.status(), 'live Twilio credentials should validate').toBe(200);

            const body = await res.json();
            // The save response is authoritative: it activates twilio and echoes
            // the gateway's get_settings() with stored values. (A global re-read
            // would race the gateway-lifecycle suite on the other worker.)
            expect(body.gateway).toBe('twilio');
            expect(body.twilio?.sid?.value ?? body.twilio?.sid).toBeTruthy();
        });

        test('an invalid auth token is rejected by the live check', async ({ page }) => {
            const res = await restCall(page.request, `${REST_NS}/settings`, {
                nonce,
                method: 'POST',
                data: {
                    gateway: 'twilio',
                    twilio: { ...twilio, token: 'definitely-not-a-valid-token' },
                },
            });
            expect(res.status(), 'bad credentials must not return 200').not.toBe(200);
            const body = await res.json();
            expect(body.code, 'a WP_Error code should be surfaced').toBeTruthy();
        });

        test('missing the auth token returns the missing-credentials error', async ({ page }) => {
            const res = await restCall(page.request, `${REST_NS}/settings`, {
                nonce,
                method: 'POST',
                data: { gateway: 'twilio', twilio: { sid: twilio!.sid, from: twilio!.from } },
            });
            expect(res.status()).not.toBe(200);
            const body = await res.json();
            expect(body.code).toBe('texty_missing_credentials');
        });
    });

    test.describe('Clickatell (validate() is a no-op)', () => {
        test.skip(!clickatellKey, 'CLICKATELL_API_KEY not set in .env');

        test('saving the API key succeeds and connects the gateway', async ({ page }) => {
            const res = await restCall(page.request, `${REST_NS}/settings`, {
                nonce,
                method: 'POST',
                data: { gateway: 'clickatell', clickatell: { key: clickatellKey } },
            });
            expect(res.status()).toBe(200);
            const body = await res.json();
            expect(body.gateway).toBe('clickatell');

            const schema = await restCall(page.request, `${REST_NS}/settings/schema`, { nonce });
            const connected: string[] = (await schema.json()).connected_gateways ?? [];
            expect(connected).toContain('clickatell');
        });

        // Documents a real weakness: Clickatell::validate() never verifies the
        // key, so even an obviously bogus key "saves" successfully. A real
        // connection can only be validated by an actual send. When validate()
        // is hardened to check the key, this expectation flips — remove test.fail().
        test('a bogus key is (incorrectly) accepted because validate() does not verify it', async ({ page }) => {
            test.fail(true, 'Clickatell::validate() is a no-op; bogus keys are accepted at save time');
            const res = await restCall(page.request, `${REST_NS}/settings`, {
                nonce,
                method: 'POST',
                data: { gateway: 'clickatell', clickatell: { key: 'totally-bogus-key' } },
            });
            expect(res.status(), 'a hardened validate() would reject this').not.toBe(200);
        });
    });
});

/**
 * End-to-end send → SmsStat log pipeline, exercised with the live Twilio
 * gateway. A real SMS is sent to the configured Twilio number, then the logs
 * endpoint is checked for the resulting row (the Dispatcher writes an SmsStat
 * on `texty_after_send_sms`). One real send only — guarded by env presence.
 */
test.describe('E2E: live send writes a log row', () => {
    test.skip(!twilio, 'TWILIO_* not set in .env');

    let nonce = '';
    let originalActive = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
        const ctx = await browser.newContext({
            storageState: 'tests/e2e/auth/admin.json',
            ignoreHTTPSErrors: true,
        });
        const schema = await restCall(ctx.request, `${REST_NS}/settings/schema`, { nonce });
        originalActive = String((await schema.json()).active_gateway ?? '');
        // Ensure Twilio is the active gateway for the send.
        await restCall(ctx.request, `${REST_NS}/settings`, {
            nonce,
            method: 'POST',
            data: { gateway: 'twilio', twilio },
        });
        await ctx.close();
    });

    test.afterAll(async ({ browser }) => {
        const ctx = await browser.newContext({
            storageState: 'tests/e2e/auth/admin.json',
            ignoreHTTPSErrors: true,
        });
        if (originalActive && originalActive !== 'twilio') {
            await restCall(ctx.request, `${REST_NS}/gateway/activate`, {
                nonce,
                method: 'POST',
                data: { gateway: originalActive },
            });
        }
        await ctx.close();
    });

    test('a tools/test send is recorded in the logs (sent or failed)', async ({ page }) => {
        const before = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { per_page: 1 } });
        const beforeTotal = (await before.json()).total as number;

        const send = await restCall(page.request, `${REST_NS}/tools/test`, {
            nonce,
            method: 'POST',
            data: { to: twilio!.from },
        });
        expect(send.status()).toBe(200);
        const sendBody = await send.json();
        expect(sendBody).toHaveProperty('success');

        // The send → SmsStat logging pipeline records every attempt regardless of
        // the provider's delivery outcome (sending to one's own Twilio number is
        // rejected as To==From, but the attempt is still logged as "failed").
        const after = await restCall(page.request, `${REST_NS}/logs`, { nonce, params: { per_page: 1 } });
        const afterBody = await after.json();
        expect(afterBody.total, 'the send attempt should append a log row').toBeGreaterThan(beforeTotal);

        const newest = afterBody.items[0];
        expect(['sent', 'failed']).toContain(newest.status);
        // When the provider accepted it, the row must be "sent".
        if (sendBody.success === true) {
            expect(newest.status).toBe('sent');
        }
    });
});
