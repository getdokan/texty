import { defineConfig, devices } from '@playwright/test';
import * as dotenv from 'dotenv';
import * as path from 'path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

const baseURL = process.env.WP_BASE_URL || 'http://dokan.test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 1,
    workers: 2,
    reporter: [['html', { outputFolder: 'tests/e2e/playwright-report', open: 'never' }]],
    outputDir: 'tests/e2e/test-results',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    use: {
        // Playwright no longer supports top-level `headless`; set it inside `use`.
        headless: false,
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },

    projects: [
        {
            name: 'setup',
            testMatch: /global\.setup\.ts/,
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'tests/e2e/auth/admin.json',
            },
            dependencies: ['setup'],
        },
    ],
});
