import { test, expect } from '@playwright/test';
import { gotoTextyHash, bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Drives the dashboard Quick Send card end-to-end through the WP_DEBUG-only
 * `fake` gateway, then validates the message landed in the Logs UI and moved
 * the dashboard metrics. The prior active gateway is captured and restored;
 * only `fake` is ever activated. Sends are append-only (no logs DELETE), so a
 * couple of `fake_*` rows are left behind — identifiable by a per-test token.
 *
 * Serial so the activate→send ordering is deterministic within the file.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Quick Send (UI) → logs + dashboard', () => {
    let nonce = '';
    let originalActive = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        const schema = await (
            await restCall(page.request, `${REST_NS}/settings/schema`, { nonce })
        ).json();
        originalActive = String(schema.active_gateway ?? '');
        await restCall(page.request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: { gateway: 'fake' },
        });
    });

    test.afterEach(async ({ page }) => {
        if (originalActive && originalActive !== 'fake') {
            await restCall(page.request, `${REST_NS}/gateway/activate`, {
                nonce,
                method: 'POST',
                data: { gateway: originalActive },
            });
        } else if (!originalActive) {
            await restCall(page.request, `${REST_NS}/gateway/deactivate`, { nonce, method: 'POST' });
        }
    });

    // Fill the Quick Send card and submit; returns once POST /send resolves.
    const sendFromDashboard = async (
        page: import('@playwright/test').Page,
        phone: string,
        message: string
    ): Promise<void> => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        // react-phone-input-2 exposes input[name="phone"]; plugin-ui textarea by placeholder.
        await page.locator('input[name="phone"]').fill(phone);
        await page.getByPlaceholder(/Write here/i).fill(message);

        const sendResp = page.waitForResponse(
            (r) => r.url().includes('/texty/v1/send') && r.request().method() === 'POST'
        );
        await page.getByRole('button', { name: /Send Message/i }).click();
        await sendResp;
    };

    test('sending from the dashboard shows a success toast and moves the metrics', async ({ page }) => {
        const token = `qa-uisend-${Date.now()}`;

        const before = await (
            await restCall(page.request, `${REST_NS}/metrics`, { nonce, params: { period: 'this_month' } })
        ).json();

        await sendFromDashboard(page, '15555551234', `${token} hello from QA`);

        await expect(page.getByText(/Message has been sent/i).first()).toBeVisible({ timeout: 10_000 });

        // The exact message is logged through the fake gateway as "sent".
        const logs = await (
            await restCall(page.request, `${REST_NS}/logs`, {
                nonce,
                params: { search: token, per_page: '20' },
            })
        ).json();
        const rows = (logs.items ?? []).filter((r: { message?: string }) =>
            (r.message ?? '').includes(token)
        );
        expect(rows.length).toBe(1);
        expect(rows[0].status).toBe('sent');
        expect(rows[0].gateway).toMatch(/fake/i);

        // The dashboard's headline total reflects the new send.
        const after = await (
            await restCall(page.request, `${REST_NS}/metrics`, { nonce, params: { period: 'this_month' } })
        ).json();
        expect(after.sms_sent).toBeGreaterThanOrEqual(before.sms_sent + 1);
        expect(after.delivered).toBeGreaterThanOrEqual(before.delivered + 1);
    });

    test('a message sent from the dashboard is findable and readable in the Logs UI', async ({ page }) => {
        const token = `qa-uilogs-${Date.now()}`;

        await sendFromDashboard(page, '15555554321', `${token} visible in logs`);
        await expect(page.getByText(/Message has been sent/i).first()).toBeVisible({ timeout: 10_000 });

        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');

        // Filter the table to our row via the search box (searches the message).
        const search = page.getByPlaceholder(/Search logs/i);
        const logsRefetch = page.waitForResponse(
            (r) => r.url().includes('/texty/v1/logs') && r.url().includes('search=')
        );
        await search.fill(token);
        await logsRefetch;

        // Open the row's detail dialog and confirm the message body is shown.
        const viewLog = page.getByRole('button', { name: /View Log/i }).first();
        await expect(viewLog).toBeVisible({ timeout: 10_000 });
        await viewLog.click();
        await expect(page.getByText(/Log Details/i)).toBeVisible();
        await expect(page.getByText(new RegExp(token))).toBeVisible();
    });
});
