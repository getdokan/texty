import { test, expect } from '@playwright/test';
import { gotoTextyHash } from './helpers/texty';

test.describe('Hash routing + error states', () => {
    test('navigates between dashboard and notifications via hash', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await expect(page).toHaveURL(/#\/dashboard$/, { timeout: 10_000 });

        await page.evaluate(() => {
            window.location.hash = '#/notifications';
        });
        await expect(page).toHaveURL(/#\/notifications$/, { timeout: 10_000 });
        await expect(page.getByRole('tab', { name: /User Events/i })).toBeVisible({ timeout: 10_000 });
    });

    test('unknown hash route renders NotFound', async ({ page }) => {
        await gotoTextyHash(page, '/this-route-does-not-exist');
        await expect(page.getByText('404', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: /Page not found/i })).toBeVisible();
        await expect(page.getByRole('button', { name: /Back to Dashboard/i })).toBeVisible();
    });

    test('NotFound "Back to Dashboard" navigates to /dashboard', async ({ page }) => {
        await gotoTextyHash(page, '/nope');
        await page.getByRole('button', { name: /Back to Dashboard/i }).click();
        await expect(page).toHaveURL(/#\/dashboard$/);
    });

    test('every defined route renders without ErrorBoundary fallback', async ({ page }) => {
        const routes = ['/dashboard', '/notifications', '/logs', '/gateway'];

        for (const route of routes) {
            await gotoTextyHash(page, route);
            await page.waitForLoadState('networkidle');

            await expect(
                page.getByText(/Something went wrong/i),
                `ErrorBoundary triggered on ${route}`
            ).not.toBeVisible();
            await expect(page.locator('#texty-app')).not.toBeEmpty();
        }
    });
});
