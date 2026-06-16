import { test, expect } from '@playwright/test';
import { gotoTextyHash, waitForApi } from './helpers/texty';

test.describe('Dashboard', () => {
    test('redirects root hash to /dashboard and renders SPA shell', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=texty');
        await expect(page.locator('#texty-app')).not.toBeEmpty({ timeout: 15_000 });
        await expect(page).toHaveURL(/#\/dashboard$/, { timeout: 10_000 });
    });

    test('loads metrics endpoint + renders stat cards', async ({ page }) => {
        const metricsResponse = waitForApi(page, '/texty/v1/metrics');
        await gotoTextyHash(page, '/dashboard');
        const res = await metricsResponse;
        expect(res.status()).toBe(200);

        await expect(page.getByText(/SMS Sent|Delivered|Failed|Delivery Rate/i).first()).toBeVisible();
    });

    test('shows welcome banner OR gateway status block', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        const welcome = page.getByRole('heading', { name: /Welcome to Texty/i });
        const gatewayLabel = page.getByText('Gateway Status', { exact: true });

        await expect(welcome.or(gatewayLabel)).toBeVisible();
    });

    test('volume analytics chart container is present', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');
        await expect(
            page.getByText(/Volume|Last 7 days|Last 30 days/i).first()
        ).toBeVisible();
    });

    test('quick send card visible', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');
        await expect(page.getByText(/Quick Send|Send SMS/i).first()).toBeVisible();
    });
});
