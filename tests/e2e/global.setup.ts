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

    await page.waitForURL('**/wp-admin/**');
    await expect(page.locator('#wpadminbar')).toBeVisible();

    fs.mkdirSync(path.dirname(authFile), { recursive: true });
    await page.context().storageState({ path: authFile });
});
