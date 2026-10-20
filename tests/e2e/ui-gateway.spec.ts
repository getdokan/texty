import { test, expect } from '@playwright/test';
import { gotoTextyHash } from './helpers/texty';

test.describe('Gateway UI', () => {
    test('selecting a gateway from the sidebar updates the detail pane', async ({ page }) => {
        await gotoTextyHash(page, '/gateway');
        await page.waitForLoadState('networkidle');

        // Click the Twilio entry in the sidebar list.
        await page.getByText(/twilio/i).first().click();

        // The detail pane should reflect the selected gateway (logo + name heading).
        await expect(page.getByRole('heading', { name: /twilio/i }).first()).toBeVisible({
            timeout: 10_000,
        });
    });

    test('search with no matches shows an empty list', async ({ page }) => {
        await gotoTextyHash(page, '/gateway');
        await page.waitForLoadState('networkidle');

        await page.getByRole('searchbox').fill('zzz-no-such-gateway');
        // The known gateways should no longer be listed in the sidebar.
        await expect(page.getByText('Vonage', { exact: true })).toHaveCount(0);
    });

    test('detail pane renders credential inputs or a lifecycle action', async ({ page }) => {
        await gotoTextyHash(page, '/gateway');
        await page.waitForLoadState('networkidle');

        await page.getByText(/twilio/i).first().click();

        // Depending on whether the gateway is already connected, the pane shows
        // credential fields (plugin-ui inputs expose role="textbox") and/or one
        // of the lifecycle actions. Accept any of them.
        const credInput = page.getByRole('textbox').first();
        const actionBtn = page
            .getByRole('button', { name: /Save|Connect|Activate|Disconnect|Deactivate/i })
            .first();
        // A connected gateway shows BOTH fields and lifecycle buttons, so the
        // combined locator can match 2+ elements — collapse with .first() to
        // avoid a strict-mode violation.
        await expect(credInput.or(actionBtn).first()).toBeVisible({ timeout: 15_000 });
    });
});
