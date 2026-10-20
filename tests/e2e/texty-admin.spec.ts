import { test, expect } from '@playwright/test';

test.describe('Texty admin SPA', () => {
    test('loads Texty page with mounted SPA root', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });
        page.on('pageerror', (err) => consoleErrors.push(`pageerror: ${err.message}`));

        await page.goto('/wp-admin/admin.php?page=texty');

        await expect(page.locator('#wpadminbar')).toBeVisible();
        await expect(page.locator('#texty-app')).toBeAttached();

        try {
            await expect(page.locator('#texty-app')).not.toBeEmpty({ timeout: 15_000 });
        } catch (err) {
            console.log('Console errors during SPA mount:', consoleErrors);
            throw err;
        }
    });
});
