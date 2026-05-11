import { test, expect } from '@playwright/test';

test.describe('Texty admin SPA', () => {
    test('loads Texty page with mounted SPA root', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=texty');

        await expect(page.locator('#wpadminbar')).toBeVisible();
        await expect(page.locator('#texty-app')).toBeAttached();
        await expect(page.locator('#texty-app')).not.toBeEmpty();
    });
});
