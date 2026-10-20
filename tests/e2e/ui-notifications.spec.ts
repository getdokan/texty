import { test, expect } from '@playwright/test';
import { gotoTextyHash, bootstrapNonce, restCall, REST_NS } from './helpers/texty';

test.describe('Notifications UI', () => {
    let nonce = '';
    let original: Record<string, unknown> = {};

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/notification-settings`, { nonce });
        original = (await res.json()).settings ?? {};
    });

    test.afterEach(async ({ page }) => {
        await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: {
                global_sender_id: String(original.global_sender_id ?? ''),
                pause_all: Boolean(original.pause_all),
                append_company_name: Boolean(original.append_company_name),
            },
        });
    });

    test('User Events tab lists toggleable notifications', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');

        await page.getByRole('tab', { name: /User Events/i }).click();
        await expect(page.locator('body')).not.toContainText(/Loading…/);
        // At least one switch should be present in the group settings.
        await expect(page.getByRole('switch').first()).toBeVisible({ timeout: 10_000 });
    });

    test('Settings tab exposes the compliance form fields', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');

        await page.getByRole('tab', { name: /Settings/i }).click();

        await expect(page.getByText(/Global Sender ID/i)).toBeVisible();
        await expect(page.getByText(/Pause All Notifications/i)).toBeVisible();
        await expect(page.getByText(/Append Company Name/i)).toBeVisible();
        await expect(page.getByRole('button', { name: /Save Settings/i })).toBeVisible();
    });

    test('saving a sender id shows a success toast', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Settings/i }).click();

        // plugin-ui's <Input> renders a type-less <input>; target it by id.
        await page.locator('#texty-sender-id').fill('QA-SENDER');

        await page.getByRole('button', { name: /Save Settings/i }).click();
        await expect(page.getByText(/Settings saved|saved/i)).toBeVisible({ timeout: 10_000 });
    });

    test('the sender id round-trips through the UI and persists', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Settings/i }).click();

        const value = `QA-${Date.now()}`.slice(0, 11);
        await page.locator('#texty-sender-id').fill(value);
        await page.getByRole('button', { name: /Save Settings/i }).click();
        await expect(page.getByText(/Settings saved|saved/i).first()).toBeVisible({ timeout: 10_000 });

        const settings = (
            await (await restCall(page.request, `${REST_NS}/notification-settings`, { nonce })).json()
        ).settings ?? {};
        expect(String(settings.global_sender_id)).toBe(value);
    });

    test('Append Company Name toggles and persists', async ({ page }) => {
        // Start from a known OFF state so the toggle deterministically turns on.
        await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: {
                global_sender_id: String(original.global_sender_id ?? ''),
                pause_all: Boolean(original.pause_all),
                append_company_name: false,
            },
        });

        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Settings/i }).click();

        await page.getByRole('switch', { name: /Append Company Name/i }).click();
        await page.getByRole('button', { name: /Save Settings/i }).click();
        await expect(page.getByText(/Settings saved|saved/i).first()).toBeVisible({ timeout: 10_000 });

        const settings = (
            await (await restCall(page.request, `${REST_NS}/notification-settings`, { nonce })).json()
        ).settings ?? {};
        expect(Boolean(settings.append_company_name)).toBe(true);
    });

    test('Pause All shows a confirm dialog and persists when confirmed', async ({ page }) => {
        // Ensure it starts OFF so toggling triggers the confirm path (the switch
        // only opens the dialog when turning ON).
        await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: {
                global_sender_id: String(original.global_sender_id ?? ''),
                pause_all: false,
                append_company_name: Boolean(original.append_company_name),
            },
        });

        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Settings/i }).click();

        await page.getByRole('switch', { name: /Pause All Notifications/i }).click();
        await expect(page.getByText(/Are you sure to Pause All/i)).toBeVisible();
        await page.getByRole('button', { name: /Yes, Pause/i }).click();

        await page.getByRole('button', { name: /Save Settings/i }).click();
        await expect(page.getByText(/Settings saved|saved/i).first()).toBeVisible({ timeout: 10_000 });

        const settings = (
            await (await restCall(page.request, `${REST_NS}/notification-settings`, { nonce })).json()
        ).settings ?? {};
        expect(Boolean(settings.pause_all)).toBe(true);
    });

    test('toggling a User Events notification persists exactly that change', async ({ page }) => {
        const enabledMap = (vals: Record<string, unknown>): Record<string, boolean> =>
            Object.fromEntries(
                Object.entries(vals ?? {}).filter(([, v]) => typeof v === 'boolean')
            ) as Record<string, boolean>;

        const readWp = async () =>
            (
                await restCall(page.request, `${REST_NS}/notifications/schema`, {
                    nonce,
                    params: { group: 'wp' },
                })
            ).json();

        const beforeSchema = await readWp();
        const before = enabledMap(beforeSchema.values);
        expect(Object.keys(before).length).toBeGreaterThan(0);

        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /User Events/i }).click();

        const firstSwitch = page.getByRole('switch').first();
        await expect(firstSwitch).toBeVisible({ timeout: 10_000 });
        await firstSwitch.click();
        await page.getByRole('button', { name: /Save Changes/i }).click();
        await expect(page.getByText(/Changes saved|saved/i).first()).toBeVisible({ timeout: 10_000 });

        const afterSchema = await readWp();
        const after = enabledMap(afterSchema.values);
        const changed = Object.keys(before).filter((k) => before[k] !== after[k]);
        // Flipping exactly one UI switch must flip exactly one stored enabled flag.
        expect(changed.length).toBe(1);

        // Restore the single flipped notification to its original full entry.
        const id = changed[0];
        const msg = beforeSchema.values?.[`${id}_message`];
        const rec = beforeSchema.values?.[`${id}_recipients`];
        const entry: { enabled: boolean; message: string; recipients?: string[] } = {
            enabled: before[id],
            message: typeof msg === 'string' ? msg : '',
        };
        if (Array.isArray(rec)) {
            entry.recipients = rec as string[];
        }
        await restCall(page.request, `${REST_NS}/notifications`, {
            nonce,
            method: 'POST',
            data: { [id]: entry },
        });
    });

    test('editing a User Events message template persists exactly that change', async ({ page }) => {
        const readWp = async () =>
            (
                await restCall(page.request, `${REST_NS}/notifications/schema`, {
                    nonce,
                    params: { group: 'wp' },
                })
            ).json();

        const beforeSchema = await readWp();

        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /User Events/i }).click();

        // Each notification row expands via its "Toggle details" button to reveal
        // a "Message Content" textarea (plugin-ui renders it type-less, no id).
        await page.getByRole('button', { name: /Toggle details/i }).first().click();
        const textarea = page.locator('#texty-app textarea').first();
        await expect(textarea).toBeVisible({ timeout: 10_000 });

        const newMessage = `QA edit ${Date.now()} hello`;
        await textarea.fill(newMessage);
        await page.getByRole('button', { name: /Save Changes/i }).click();
        await expect(page.getByText(/Changes saved|saved/i).first()).toBeVisible({ timeout: 10_000 });

        const afterSchema = await readWp();
        const msgKeys = Object.keys(afterSchema.values ?? {}).filter((k) => k.endsWith('_message'));
        const changed = msgKeys.filter(
            (k) => afterSchema.values[k] !== beforeSchema.values[k]
        );
        // Editing one row's textarea must change exactly one stored message.
        expect(changed.length).toBe(1);
        expect(afterSchema.values[changed[0]]).toBe(newMessage);

        // Restore the edited notification to its original full entry.
        const id = changed[0].replace(/_message$/, '');
        const rec = beforeSchema.values?.[`${id}_recipients`];
        const entry: { enabled: boolean; message: string; recipients?: string[] } = {
            enabled: Boolean(beforeSchema.values?.[id]),
            message: String(beforeSchema.values?.[`${id}_message`] ?? ''),
        };
        if (Array.isArray(rec)) {
            entry.recipients = rec as string[];
        }
        await restCall(page.request, `${REST_NS}/notifications`, {
            nonce,
            method: 'POST',
            data: { [id]: entry },
        });
    });

    test('Integrations tab lists integrations or an empty state', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Integrations/i }).click();

        // WC/Dokan cards when their plugins are active, otherwise an empty state.
        const cards = page.getByText(/WooCommerce|Dokan/i).first();
        const empty = page.getByText(/No integrations|not active|not installed|install/i).first();
        await expect(cards.or(empty).first()).toBeVisible({ timeout: 10_000 });
    });

    test('Integrations "Configure" opens the integration notification settings', async ({ page }) => {
        await gotoTextyHash(page, '/notifications');
        await page.waitForLoadState('networkidle');
        await page.getByRole('tab', { name: /Integrations/i }).click();

        // "Configure" only appears for an ACTIVE integration (WC/Dokan present).
        // On a bare WP those plugins aren't installed, so the tab shows no
        // configurable integration — gate on the button itself, not the card text
        // (which can render for non-active/installable entries too).
        const configure = page.getByRole('button', { name: /Configure/i }).first();
        const hasConfigurable = await configure
            .waitFor({ state: 'visible', timeout: 8_000 })
            .then(() => true)
            .catch(() => false);
        test.skip(!hasConfigurable, 'no active (configurable) integrations on this site');

        await configure.click();
        // Navigates to the integration detail route and renders that group's
        // notification settings (the same group-settings UI as User Events).
        await expect(page).toHaveURL(/#\/notifications\/integrations\//, { timeout: 10_000 });
        await expect(page.getByRole('button', { name: /Save Changes/i })).toBeVisible({
            timeout: 10_000,
        });
        await expect(page.getByRole('switch').first()).toBeVisible({ timeout: 10_000 });
    });
});
