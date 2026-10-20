import { test, expect } from '@playwright/test';
import { gotoTextyHash, waitForApi } from './helpers/texty';

test.describe('Notifications page', () => {
    test('renders three tabs (User Events / Integrations / Settings)', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('tab', { name: /User Events/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /Integrations/i })).toBeVisible();
        await expect(page.getByRole('tab', { name: /Settings/i })).toBeVisible();
    });

    test('Integrations tab loads integration cards', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');

        await page.getByRole('tab', { name: /Integrations/i }).click();
        await expect(page.locator('body')).not.toContainText(/Loading…/);
    });

    test('Settings tab loads notification-settings endpoint', async ({ page }) => {
        const apiPromise = waitForApi(page, '/texty/v1/notification-settings');
        await gotoTextyHash(page, '/notifications');
        await page.getByRole('tab', { name: /Settings/i }).click();
        const res = await apiPromise;
        expect(res.status()).toBe(200);
    });

    test('integration detail route loads', async ({ page }) => {
        await gotoTextyHash(page, '/notifications/integrations/wp');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#texty-app')).not.toBeEmpty();
    });
});
