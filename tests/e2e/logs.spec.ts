import { test, expect } from '@playwright/test';
import { gotoTextyHash, waitForApi } from './helpers/texty';

test.describe('Logs page', () => {
    test('loads logs endpoint and renders table', async ({ page }) => {
        const apiPromise = waitForApi(page, '/texty/v1/logs');
        await gotoTextyHash(page, '/logs');
        const res = await apiPromise;
        expect(res.status()).toBe(200);

        // Scope to the SPA root: WP-admin notices (e.g. Appsero's opt-in
        // <p class="description">) otherwise match a broad page-wide getByText
        // and resolve to a hidden node. Empty-state copy is "No logs yet".
        const app = page.locator('#texty-app');
        const empty = app.getByText(/No logs yet|No items found/i);
        const headers = app.getByText(/Date & Time|Type|Details/i).first();
        await expect(empty.or(headers).first()).toBeVisible();
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

        // The status filter (All / Sent / Failed / Pending) always renders,
        // even with no rows. Scope to the SPA root to avoid WP-admin chrome.
        const app = page.locator('#texty-app');
        await expect(app.getByText(/Sent|Failed|Pending/i).first()).toBeVisible();
    });
});
