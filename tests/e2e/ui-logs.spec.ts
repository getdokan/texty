import { test, expect } from '@playwright/test';
import { gotoTextyHash } from './helpers/texty';

test.describe('Logs UI', () => {
    test('renders the table column headers or an empty state', async ({ page }) => {
        await gotoTextyHash(page, '/logs');
        await page.waitForLoadState('networkidle');

        const headers = page.getByText(/Type|Status|Details|Created/i).first();
        const empty = page.getByText(/No items found/i);
        await expect(headers.or(empty)).toBeVisible();
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
