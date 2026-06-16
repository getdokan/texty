import { defineConfig, devices } from '@playwright/test';
import type { ReporterDescription } from '@playwright/test';
import * as dotenv from 'dotenv';
import * as path from 'path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

// Accept either BASE_URL (local Docker .env) or WP_BASE_URL (CI workflow).
const baseURL = process.env.BASE_URL || process.env.WP_BASE_URL || 'http://dokan.test';

// `list` streams a line per test as it runs (the readable live output); add
// GitHub annotations in CI; `html` is always kept for the report artifact.
const reporters: ReporterDescription[] = [
    ['list'],
    ['html', { outputFolder: 'tests/e2e/playwright-report', open: 'never' }],
];
if (process.env.CI) {
    reporters.splice(1, 0, ['github']);
}

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 1,
    workers: 2,
    reporter: reporters,
    outputDir: 'tests/e2e/test-results',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    use: {
        // Playwright no longer supports top-level `headless`; set it inside `use`.
        // HEADLESS=false (local .env) runs headed; unset/anything else stays headless (CI).
        headless: process.env.HEADLESS !== 'false',
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
