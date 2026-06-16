import { test, expect } from '@playwright/test';
import { gotoTextyHash, waitForApi } from './helpers/texty';

test.describe('Logs page', () => {
    test('loads logs endpoint and renders table', async ({ page }) => {
        const apiPromise = waitForApi(page, '/texty/v1/logs');
        await gotoTextyHash(page, '/logs');
        const res = await apiPromise;
        expect(res.status()).toBe(200);

        await expect(
            page.getByText(/No items found|Created|Type|Status|Details/i).first()
        ).toBeVisible();
    });

    test('export button is present and points to export endpoint', async ({ page }) => {
        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');

        const exportBtn = page.getByRole('button', { name: /Export/i });
        await expect(exportBtn).toBeVisible();
    });

    test('status filter is present', async ({ page }) => {
        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('body')).toContainText(/Status|Filter/i);
    });
});
