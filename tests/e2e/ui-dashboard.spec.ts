import { test, expect } from '@playwright/test';
import { gotoTextyHash, waitForApi } from './helpers/texty';

test.describe('Dashboard UI interactions', () => {
    test('renders all four stat cards', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        await expect(page.getByText(/SMS Sent/i).first()).toBeVisible();
        await expect(page.getByText(/Delivered/i).first()).toBeVisible();
        await expect(page.getByText(/Failed/i).first()).toBeVisible();
        await expect(page.getByText(/Delivery Rate/i).first()).toBeVisible();
    });

    test('changing the volume period refetches metrics for that period', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        // Open the period <Select> and pick "Last 7 Days".
        const trigger = page.getByRole('combobox').first();
        await trigger.click();

        const apiPromise = waitForApi(page, '/texty/v1/metrics');
        await page.getByRole('option', { name: /Last 7 Days/i }).click();
        const res = await apiPromise;

        expect(res.status()).toBe(200);
        expect(res.url()).toContain('period=last_7_days');
    });

    test('quick-send button is gated on both phone and message', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        const sendBtn = page.getByRole('button', { name: /Send Message/i });
        await expect(sendBtn).toBeDisabled();

        // Typing only a message keeps it disabled (no phone yet).
        await page.getByPlaceholder(/Write here/i).fill('Hello from QA');
        await expect(sendBtn).toBeDisabled();

        // Add a phone number — now it should enable.
        await page.locator('input[name="phone"]').fill('15555550123');
        await expect(sendBtn).toBeEnabled();
    });

    test('message character counter reflects typed length', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        await page.getByPlaceholder(/Write here/i).fill('12345');
        await expect(page.getByText(/^5\//)).toBeVisible();
    });

    test('volume analytics renders the recharts line chart with axis ticks', async ({ page }) => {
        await gotoTextyHash(page, '/dashboard');
        await page.waitForLoadState('networkidle');

        const app = page.locator('#texty-app');
        await expect(app.getByText(/Volume/i).first()).toBeVisible();
        // recharts line chart: one SVG surface, ≥1 line curve, x-axis ticks.
        await expect(app.locator('svg.recharts-surface').first()).toBeVisible();
        expect(await app.locator('.recharts-curve').count()).toBeGreaterThan(0);
        expect(
            await app.locator('.recharts-xAxis .recharts-cartesian-axis-tick').count()
        ).toBeGreaterThan(0);
    });

    // Every period option must refetch /metrics with the matching `period` arg.
    // To guarantee a state change (so the same-value default still refetches),
    // each case first selects a different baseline period, then the target.
    const PERIODS: Array<{ label: string; param: string }> = [
        { label: 'This Month', param: 'this_month' },
        { label: 'Last Month', param: 'last_month' },
        { label: 'Last 7 Days', param: 'last_7_days' },
        { label: 'Last 30 Days', param: 'last_30_days' },
        { label: 'This Year', param: 'this_year' },
    ];

    for (const p of PERIODS) {
        test(`volume period filter "${p.label}" refetches metrics with period=${p.param}`, async ({
            page,
        }) => {
            await gotoTextyHash(page, '/dashboard');
            await page.waitForLoadState('networkidle');

            // Baseline must differ from BOTH the target and the dashboard's
            // default (This Month) — otherwise selecting it is a no-op and never
            // refetches. "Last 7 Days" is neither default nor a common target.
            const baseline = p.param === 'last_7_days' ? 'Last Month' : 'Last 7 Days';

            // Move off the target value first so selecting it is a real change.
            await page.getByRole('combobox').first().click();
            const baselineFetch = page.waitForResponse((r) =>
                r.url().includes('/texty/v1/metrics')
            );
            await page.getByRole('option', { name: new RegExp(`^${baseline}$`, 'i') }).click();
            await baselineFetch;

            // Now select the target period and assert the request carries it.
            await page.getByRole('combobox').first().click();
            const targetFetch = page.waitForResponse(
                (r) =>
                    r.url().includes('/texty/v1/metrics') &&
                    r.url().includes(`period=${p.param}`)
            );
            await page.getByRole('option', { name: new RegExp(`^${p.label}$`, 'i') }).click();
            const res = await targetFetch;
            expect(res.status()).toBe(200);
            const body = await res.json();
            expect(body.period).toBe(p.param);
        });
    }
});
