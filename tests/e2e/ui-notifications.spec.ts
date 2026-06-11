import { test, expect } from '@playwright/test';
import { gotoTextyHash, bootstrapNonce, restCall, REST_NS } from './helpers/texty';

test.describe('Notifications UI', () => {
    let nonce = '';
    let original: Record<string, unknown> = {};

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, { nonce });
        original = (await res.json()).settings ?? {};
    });

    test.afterEach(async ({ page }) => {
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

    test('User Events tab lists toggleable notifications', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');

        await page.getByRole('tab', { name: /User Events/i }).click();
        await expect(page.locator('body')).not.toContainText(/Loading…/);
        // At least one switch should be present in the group settings.
        await expect(page.getByRole('switch').first()).toBeVisible({ timeout: 10_000 });
    });

    test('Settings tab exposes the compliance form fields', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');

        await page.getByRole('tab', { name: /Settings/i }).click();

        await expect(page.getByText(/Global Sender ID/i)).toBeVisible();
        await expect(page.getByText(/Pause All Notifications/i)).toBeVisible();
        await expect(page.getByText(/Append Company Name/i)).toBeVisible();
        await expect(page.getByRole('button', { name: /Save Settings/i })).toBeVisible();
    });

    test('saving a sender id shows a success toast', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Settings/i }).click();

        // plugin-ui's <Input> renders a type-less <input>; target it by id.
        await page.locator('#texty-sender-id').fill('QA-SENDER');

        await page.getByRole('button', { name: /Save Settings/i }).click();
        await expect(page.getByText(/Settings saved|saved/i)).toBeVisible({ timeout: 10_000 });
    });
});
