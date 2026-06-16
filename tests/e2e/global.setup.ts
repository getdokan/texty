import { test as setup, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const authFile = path.resolve(__dirname, 'auth/admin.json');

setup('authenticate as admin', async ({ page }) => {
    if (fs.existsSync(authFile) && !process.env.FORCE_AUTH) {
        setup.skip(true, `Auth state cached at ${authFile}. Delete file or set FORCE_AUTH=1 to re-login.`);
        return;
    }

    const username = process.env.WP_ADMIN_USER;
    const password = process.env.WP_ADMIN_PASSWORD;

    if (!username || !password) {
        throw new Error('WP_ADMIN_USER and WP_ADMIN_PASSWORD must be set in .env');
    }

    await page.goto('/wp-login.php');
    await page.fill('#user_login', username);
    await page.fill('#user_pass', password);
    await page.click('#wp-submit');

    // WordPress periodically interrupts login with the "Administration email
    // verification" screen (served from wp-login.php, not /wp-admin/). Dismiss
    // it via "Remind me later" so the session continues to the dashboard.
    await page.waitForLoadState('domcontentloaded');
    const remindLater = page.getByRole('link', { name: /Remind me later/i });
    if (await remindLater.count()) {
        await remindLater.first().click();
    }

    // Land on the dashboard regardless of any post-login interstitial.
    await page.goto('/wp-admin/');
    await expect(page.locator('#wpadminbar')).toBeVisible();

    fs.mkdirSync(path.dirname(authFile), { recursive: true });
    await page.context().storageState({ path: authFile });
});
