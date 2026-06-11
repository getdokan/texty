import { test, expect } from '@playwright/test';
import { gotoTextyHash, waitForApi } from './helpers/texty';

test.describe('Dashboard UI interactions', () => {
    test('renders all four stat cards', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText(/SMS Sent/i).first()).toBeVisible();
        await expect(page.getByText(/Delivered/i).first()).toBeVisible();
        await expect(page.getByText(/Failed/i).first()).toBeVisible();
        await expect(page.getByText(/Delivery Rate/i).first()).toBeVisible();
    });

    test('changing the volume period refetches metrics for that period', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        // Open the period <Select> and pick "Last 7 Days".
        const trigger = page.getByRole('combobox').first();
        await trigger.click();

        const apiPromise = waitForApi(page, '/texty/v1/metrics');
        await page.getByRole('option', { name: /Last 7 Days/i }).click();
        const res = await apiPromise;

        expect(res.status()).toBe(200);
        expect(res.url()).toContain('period=last_7_days');
    });

    test('quick-send button is gated on both phone and message', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        const sendBtn = page.getByRole('button', { name: /Send Message/i });
        await expect(sendBtn).toBeDisabled();

        // Typing only a message keeps it disabled (no phone yet).
        await page.getByPlaceholder(/Write here/i).fill('Hello from QA');
        await expect(sendBtn).toBeDisabled();

        // Add a phone number — now it should enable.
        await page.locator('input[name="phone"]').fill('15555550123');
        await expect(sendBtn).toBeEnabled();
    });

    test('message character counter reflects typed length', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        await page.getByPlaceholder(/Write here/i).fill('12345');
        await expect(page.getByText(/^5\//)).toBeVisible();
    });
});
