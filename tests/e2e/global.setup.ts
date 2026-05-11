import { test as setup, expect } from '@playwright/test';
import * as path from 'path';

const authFile = path.resolve(__dirname, 'auth/admin.json');

setup('authenticate as admin', async ({ page }) => {
    const username = process.env.WP_ADMIN_USER;
    const password = process.env.WP_ADMIN_PASSWORD;

    if (!username || !password) {
        throw new Error('WP_ADMIN_USER and WP_ADMIN_PASSWORD must be set in .env');
    }

    await page.goto('/wp-login.php');
    await page.fill('#user_login', username);
    await page.fill('#user_pass', password);
    await page.click('#wp-submit');

    await page.waitForURL('**/wp-admin/**');
    await expect(page.locator('#wpadminbar')).toBeVisible();

    await page.context().storageState({ path: authFile });
});
