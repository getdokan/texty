import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Release gate — the things that must be true for the plugin to ship:
 * every REST route registers, the admin menu is wired, the admin screen loads
 * without a PHP fatal, and the SPA bootstrap config + bundle are present.
 */

const CORE_ROUTES = [
    '/texty/v1/metrics',
    '/texty/v1/logs',
    '/texty/v1/logs/(?P<id>\\d+)',
    '/texty/v1/settings',
    '/texty/v1/settings/schema',
    '/texty/v1/notifications',
    '/texty/v1/notifications/schema',
    '/texty/v1/notification-settings',
    '/texty/v1/gateway/activate',
    '/texty/v1/gateway/deactivate',
    '/texty/v1/gateway/disconnect',
    '/texty/v1/send',
    '/texty/v1/tools/test',
    '/texty/v1/status',
];

test.describe('Release: REST surface registration', () => {
    let nonce = '';
    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('the texty/v1 namespace registers every core route', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}`, { nonce });
        expect(res.status()).toBe(200);
        const routes = Object.keys((await res.json()).routes ?? {});
        for (const route of CORE_ROUTES) {
            expect(routes, `route not registered: ${route}`).toContain(route);
        }
    });
});

test.describe('Release: admin integration', () => {
    test('the Texty top-level menu and every submenu link are present', async ({ page }) => {
        await page.goto('/wp-admin/');
        await expect(page.locator('#adminmenu')).toBeVisible();

        // Top-level menu item links to the Texty page.
        await expect(page.locator('#adminmenu a[href*="page=texty"]').first()).toBeAttached();

        // Each submenu deep-links to its hash route. (WordPress points the
        // top-level "Texty" link at the first submenu, so #/dashboard legitimately
        // appears twice — assert presence, not an exact count.)
        for (const path of ['dashboard', 'gateway', 'notifications', 'logs']) {
            await expect(
                page.locator(`#adminmenu a[href*="page=texty#/${path}"]`).first(),
                `submenu link missing: ${path}`
            ).toBeAttached();
        }
    });

    test('the Texty admin screen loads without a PHP fatal or critical error', async ({ page }) => {
        const resp = await page.goto('/wp-admin/admin.php?page=texty');
        expect(resp?.status()).toBeLessThan(400);

        const html = await page.content();
        expect(html).not.toContain('There has been a critical error');
        expect(html).not.toMatch(/Fatal error/i);
        expect(html).not.toMatch(/Parse error/i);

        // The SPA mount node ships in the page markup.
        await expect(page.locator('#texty-app')).toBeAttached();
    });

    test('the SPA bootstrap config (window.texty) is localized correctly', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=texty');

        const cfg = await page.evaluate(
            () =>
                (window as unknown as {
                    texty?: {
                        nonce?: string;
                        rest_url?: string;
                        version?: { lite?: string };
                    };
                }).texty ?? null
        );

        expect(cfg, 'window.texty must be localized').not.toBeNull();
        expect(cfg?.nonce, 'a REST nonce must be present').toBeTruthy();
        expect(cfg?.rest_url, 'rest_url must be present').toContain('/wp-json/');
        expect(cfg?.version?.lite, 'plugin version must be exposed').toBeTruthy();
    });

    test('the compiled SPA bundle is served (200)', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=texty');
        // The enqueued script handle resolves to dist/index.js.
        const bundle = page.locator('script[src*="/texty/dist/index.js"]');
        const src = await bundle.first().getAttribute('src');
        expect(src, 'dist/index.js should be enqueued').toBeTruthy();

        const res = await page.request.get(src as string);
        expect(res.status()).toBe(200);
    });
});
