import { test, expect } from '@playwright/test';
import { gotoTextyHash } from './helpers/texty';

test.describe('Logs UI', () => {
    test('renders the table column headers or an empty state', async ({ page }) => {
        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');

        // Scope to the SPA root so WP-admin chrome (e.g. the Appsero opt-in
        // notice, a <p class="description"> containing "…environment details…")
        // can't satisfy a broad getByText match. Empty copy is "No logs yet".
        const app = page.locator('#texty-app');
        const empty = app.getByText(/No logs yet|No items found/i);
        const headers = app.getByText(/Date & Time|Type|Details/i).first();
        await expect(empty.or(headers).first()).toBeVisible();
    });

    test('export CSV control is present', async ({ page }) => {
        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('button', { name: /Export/i })).toBeVisible();
    });

    test('opening a log row reveals the detail dialog (when rows exist)', async ({ page }) => {
        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');

        // "Details" is also a sortable column-header button, so match the row
        // action specifically by its "View Log" label.
        const detailTrigger = page.getByRole('button', { name: /View Log/i }).first();
        const count = await detailTrigger.count();
        test.skip(count === 0, 'no log rows present to drill into');

        await detailTrigger.click();
        await expect(page.getByText(/Log Details/i)).toBeVisible();
    });
});
