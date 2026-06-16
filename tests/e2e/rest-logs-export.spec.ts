import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Contract tests for GET /texty/v1/logs/export — the CSV download.
 * The endpoint streams a file (Content-Disposition: attachment) and exits.
 */
test.describe('REST: logs CSV export', () => {
    let nonce = '';

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test('returns a CSV attachment with the documented header row', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs/export`, { nonce });
        expect(res.status()).toBe(200);

        const headers = res.headers();
        expect(headers['content-type']).toContain('text/csv');
        expect(headers['content-disposition']).toContain('attachment');
        expect(headers['content-disposition']).toMatch(/texty-sms-logs-.*\.csv/);

        const body = await res.text();
        // Strip the UTF-8 BOM before asserting the header columns.
        const firstLine = body.replace(/^﻿/, '').split('\n')[0];
        for (const col of ['ID', 'Date', 'Type', 'Gateway', 'Recipient', 'Message', 'Status']) {
            expect(firstLine, `CSV header missing column: ${col}`).toContain(col);
        }
    });

    test('honours the status filter', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs/export`, {
            nonce,
            params: { status: 'failed' },
        });
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type']).toContain('text/csv');
    });

    test('rejects an out-of-enum status with 400', async ({ page }) => {
        const res = await restCall(page.request, `${REST_NS}/logs/export`, {
            nonce,
            params: { status: 'bogus' },
        });
        expect(res.status()).toBe(400);
    });

    test('is gated — unauthenticated request is rejected', async ({ browser }) => {
        const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
        const res = await ctx.request.get(`${REST_NS}/logs/export`);
        expect([401, 403]).toContain(res.status());
        await ctx.close();
    });
});
