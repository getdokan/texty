import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract tests for the settings surfaces:
 *   GET /texty/v1/settings                  — raw stored option
 *   GET /texty/v1/settings?context=edit     — gateway catalogue + per-gateway creds
 *   GET /texty/v1/settings/schema           — plugin-ui schema + active/connected
 */
test.describe('REST: settings contract', () => {
    let nonce = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('GET /settings returns the stored option object', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/settings`, { nonce });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('gateway');
    });

    test('GET /settings?context=edit exposes the gateway catalogue', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/settings`, {
            nonce,
            params: { context: 'edit' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();

        expect(body).toHaveProperty('gateway');
        expect(body).toHaveProperty('gateways');

        // The built-in gateways must each be described with name/logo/description.
        for (const key of ['twilio', 'vonage', 'clickatell', 'plivo']) {
            expect(body.gateways, `catalogue missing ${key}`).toHaveProperty(key);
            expect(body.gateways[key]).toHaveProperty('name');
            expect(body.gateways[key]).toHaveProperty('logo');
            expect(body.gateways[key]).toHaveProperty('description');
        }
    });

    test('GET /settings/schema returns schema, values, active + connected', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/settings/schema`, { nonce });
        expect(res.status()).toBe(200);
        const body = await res.json();

        expect(body).toHaveProperty('schema');
        expect(Array.isArray(body.schema)).toBeTruthy();
        expect(body).toHaveProperty('active_gateway');
        expect(body).toHaveProperty('connected_gateways');
        expect(Array.isArray(body.connected_gateways)).toBeTruthy();

        // Every built-in gateway should surface as a "page" element in the schema.
        const pageIds = body.schema
            .filter((el: { type?: string }) => el.type === 'page')
            .map((el: { id?: string }) => el.id);
        for (const key of ['twilio', 'vonage', 'clickatell', 'plivo']) {
            expect(pageIds, `schema missing page for ${key}`).toContain(key);
        }
    });
});
