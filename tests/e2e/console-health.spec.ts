import { test, expect } from '@playwright/test';
import { gotoTextyHash } from './helpers/texty';

/**
 * Every SPA route must mount cleanly — no uncaught page errors and no React
 * ErrorBoundary fallback. Console "error" entries are collected and asserted
 * empty, with known-noisy WP admin messages filtered out.
 */
const ROUTES = ['/dashboard', '/notifications', '/logs', '/gateway'];

// WP admin / third-party noise we don't want to fail the suite on.
const IGNORE = [
    /favicon/i,
    /Download the React DevTools/i,
    /wp-emoji/i,
    /net::ERR_/i,
    /heartbeat/i,
];

for (const route of ROUTES) {
    test(`${route} mounts without console errors or pageerrors`, async ({ page }) => {
        const errors: string[] = [];
        const failed404: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() !== 'error') return;
            const text = msg.text();
            if (IGNORE.some((re) => re.test(text))) return;
            // The browser logs bare "Failed to load resource" without the URL;
            // pair it with the captured 404 list below for a useful message.
            if (/Failed to load resource/i.test(text)) return;
            errors.push(text);
        });
        page.on('pageerror', (err) => errors.push(`pageerror: ${err.message}`));
        page.on('response', (res) => {
            if (res.status() === 404) failed404.push(res.url());
        });

        await gotoTextyHash(page, route);
        await page.waitForLoadState('networkidle');

        // 404s for non-plugin assets are WP/theme noise; only fail on 404s of
        // the plugin's own bundle or REST endpoints.
        const pluginFailures = failed404.filter((u) => /texty|\/wp-json\/texty/i.test(u));
        expect(pluginFailures, `plugin 404s on ${route}:\n${pluginFailures.join('\n')}`).toEqual([]);

        await expect(page.locator('#texty-app')).not.toBeEmpty();
        await expect(
            page.getByText(/Something went wrong/i),
            `ErrorBoundary triggered on ${route}`
        ).not.toBeVisible();

        expect(errors, `console errors on ${route}:\n${errors.join('\n')}`).toEqual([]);
    });
}
