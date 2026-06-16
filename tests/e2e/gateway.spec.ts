import { test, expect } from '@playwright/test';
import { gotoTextyHash } from './helpers/texty';

test.describe('Gateway page', () => {
    test('loads schema endpoint and mounts page', async ({ page }) => {
        const responses: string[] = [];
        page.on('response', (res) => {
            if (res.url().includes('/texty/v1/settings/schema')) {
                responses.push(`${res.status()} ${res.url()}`);
            }
        });

        await gotoTextyHash(page, '/gateway');
        await expect(page.getByRole('heading', { name: /Available Gateways/i })).toBeVisible({
            timeout: 15_000,
        });
        expect(responses.length, 'schema endpoint should be hit').toBeGreaterThan(0);
        expect(responses[0]).toMatch(/^2\d\d /);
    });

    test('renders sidebar list of available gateways', async ({ page }) => {
        await gotoTextyHash(page, '/gateway');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('heading', { name: /Available Gateways/i })).toBeVisible();
        await expect(page.getByRole('searchbox')).toBeVisible();
    });

    test('detail pane renders with at least one gateway selected', async ({ page }) => {
        await gotoTextyHash(page, '/gateway');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('img[alt]').first()).toBeVisible({ timeout: 10_000 });
    });

    test('search filters gateway list', async ({ page }) => {
        await gotoTextyHash(page, '/gateway');
        await page.waitForLoadState('networkidle');

        const search = page.getByRole('searchbox');
        await search.fill('twilio');
        await expect(page.getByText(/twilio/i).first()).toBeVisible();
    });
});
