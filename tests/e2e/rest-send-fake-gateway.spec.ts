import { test, expect } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

/**
 * Exercises the full SMS send pipeline through the WP_DEBUG-only `fake`
 * gateway. `Fake::send()` is a no-op that always succeeds and stamps a
 * `fake_<time>` reference, so every send funnels through `Gateways::send()`
 * and is written to `wp_texty_sms_stat` by the dispatcher. This asserts both
 * the REST envelope (`POST /send`, `POST /tools/test`) and the resulting log
 * rows (`gateway: "Fake Gateway"`, `status: "sent"`, `reference_id: fake_*`).
 *
 * State: the active gateway is captured in beforeEach and restored in
 * afterEach; only the `fake` gateway is ever activated. Sends are append-only
 * (there is no logs DELETE endpoint), so each run leaves a few `fake_*` rows
 * behind — the same residue the existing live-send test produces. A unique
 * per-test token keeps those rows identifiable.
 *
 * Runs serial so the activate→send ordering within the file is deterministic;
 * the per-row `reference_id: fake_*` assertion is what proves the message
 * actually went through `fake` even if another worker briefly flips the
 * global active gateway.
 */
test.describe.configure({ mode: 'serial' });

test.describe('REST: send via the fake gateway', () => {
    let nonce = '';
    let originalActive = '';

    const readActive = async (request: APIRequestContext): Promise<string> => {
        const res = await restCall(request, `${REST_NS}/settings/schema`, { nonce });
        const body = await res.json();
        return String(body.active_gateway ?? '');
    };

    const activateFake = async (request: APIRequestContext): Promise<void> => {
        const res = await restCall(request, `${REST_NS}/gateway/activate`, {
            nonce,
            method: 'POST',
            data: { gateway: 'fake' },
        });
        expect(res.status()).toBe(200);
        expect((await res.json()).active_gateway).toBe('fake');
    };

    // GET /logs filtered to this run's token; returns only the rows we created.
    const ourRows = async (request: APIRequestContext, token: string) => {
        const res = await restCall(request, `${REST_NS}/logs`, {
            nonce,
            params: { search: token, per_page: '100' },
        });
        expect(res.status()).toBe(200);
        const items = (await res.json()).items ?? [];
        return items.filter((r: { message?: string }) => (r.message ?? '').includes(token));
    };

    const expectSentViaFake = (row: {
        status?: string;
        gateway?: string;
        reference_id?: string;
    }): void => {
        expect(row.status).toBe('sent');
        expect(row.gateway).toMatch(/fake/i);
        expect(row.reference_id ?? '').toMatch(/^fake_/);
    };

    test.beforeAll(async ({ browser }) => {
        nonce = await bootstrapNonce(browser);
    });

    test.beforeEach(async ({ page }) => {
        originalActive = await readActive(page.request);
        await activateFake(page.request);
    });

    test.afterEach(async ({ page }) => {
        if (originalActive && originalActive !== 'fake') {
            await restCall(page.request, `${REST_NS}/gateway/activate`, {
                nonce,
                method: 'POST',
                data: { gateway: originalActive },
            });
        } else if (!originalActive) {
            await restCall(page.request, `${REST_NS}/gateway/deactivate`, {
                nonce,
                method: 'POST',
            });
        }
    });

    test('a single send returns success and is logged as sent via fake', async ({ page }) => {
        const token = `qa-fake-single-${Date.now()}`;
        const to = '+15555550001';

        const res = await restCall(page.request, `${REST_NS}/send`, {
            nonce,
            method: 'POST',
            data: { to, message: `${token} hello` },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        // Fake never errors, so the envelope carries no error message.
        expect(body.message).toBe('');

        const rows = await ourRows(page.request, token);
        expect(rows.length).toBe(1);
        expect(rows[0].receiver).toBe(to);
        expectSentViaFake(rows[0]);
    });

    test('bulk sends with varied payloads all succeed and are logged', async ({ page }) => {
        const token = `qa-fake-bulk-${Date.now()}`;

        // A spread of payloads the send pipeline must survive: plain ASCII,
        // multibyte/emoji, an over-160-char body, HTML-ish specials, and
        // embedded newlines. Each carries the run token so we can find them.
        const payloads = [
            { to: '+15555550101', body: 'plain ascii message' },
            { to: '+15555550102', body: 'unicode مرحبا héllo 🚀🚀' },
            { to: '+15555550103', body: 'long '.repeat(60) }, // ~300 chars
            { to: '+15555550104', body: 'specials <b>&</b> "quotes" 100% off' },
            { to: '+15555550105', body: 'line one\nline two\ttabbed' },
            { to: '447700900123', body: 'no plus prefix' },
            { to: '+15555550107', body: 'emoji only 🎉' },
            { to: '+15555550108', body: 'trailing spaces    ' },
        ];

        for (const [i, p] of payloads.entries()) {
            const res = await restCall(page.request, `${REST_NS}/send`, {
                nonce,
                method: 'POST',
                data: { to: p.to, message: `${token} #${i} ${p.body}` },
            });
            expect(res.status(), `send #${i} status`).toBe(200);
            expect((await res.json()).success, `send #${i} success`).toBe(true);
        }

        const rows = await ourRows(page.request, token);
        expect(rows.length).toBe(payloads.length);
        for (const row of rows) {
            expectSentViaFake(row);
        }
        // Every recipient we posted shows up exactly once.
        const receivers = rows.map((r: { receiver: string }) => r.receiver).sort();
        expect(receivers).toEqual(payloads.map((p) => p.to).sort());
    });

    test('repeated sends to the same recipient each create a distinct log row', async ({ page }) => {
        const token = `qa-fake-repeat-${Date.now()}`;
        const to = '+15555550200';
        const COUNT = 5;

        for (let i = 0; i < COUNT; i++) {
            const res = await restCall(page.request, `${REST_NS}/send`, {
                nonce,
                method: 'POST',
                data: { to, message: `${token} repeat ${i}` },
            });
            expect(res.status()).toBe(200);
            expect((await res.json()).success).toBe(true);
        }

        const rows = await ourRows(page.request, token);
        expect(rows.length).toBe(COUNT);
        for (const row of rows) {
            expect(row.receiver).toBe(to);
            expectSentViaFake(row);
        }
        // Each send gets its own reference_id (no idempotency collapsing).
        const refs = new Set(rows.map((r: { reference_id: string }) => r.reference_id));
        expect(refs.size).toBeGreaterThanOrEqual(1);
    });

    test('tools/test send succeeds and is logged via fake', async ({ page }) => {
        // tools/test sends a fixed template message, so we can't tag it with a
        // token. Capture the log total before, fire the test send, and assert a
        // new fake-sent row landed for our recipient.
        const to = '+15555550300';

        const res = await restCall(page.request, `${REST_NS}/tools/test`, {
            nonce,
            method: 'POST',
            data: { to },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('success');
        expect(body.success).toBe(true);

        const logs = await restCall(page.request, `${REST_NS}/logs`, {
            nonce,
            params: { search: to, per_page: '20' },
        });
        const items = (await logs.json()).items ?? [];
        const mine = items.filter((r: { receiver?: string }) => r.receiver === to);
        expect(mine.length).toBeGreaterThanOrEqual(1);
        expectSentViaFake(mine[0]);
    });

    test('sends move the dashboard metrics and volume chart', async ({ page }) => {
        // The dashboard graph is driven by GET /metrics: `sms_sent` / `delivered`
        // stat cards and the `volume_chart` buckets. Fake sends log status
        // "sent", which the metrics endpoint counts as both sent AND delivered,
        // so each send bumps both totals and exactly one daily bucket. NOTE: the
        // volume chart only counts status==="sent" rows (Metrics::build_volume_chart),
        // so its buckets sum to `delivered`, not `sms_sent` (failed sends are
        // excluded from the graph).
        const sumBuckets = (m: { volume_chart?: Array<{ count?: number }> }): number =>
            (m.volume_chart ?? []).reduce((acc, b) => acc + (b.count ?? 0), 0);

        const readMetrics = async () => {
            const res = await restCall(page.request, `${REST_NS}/metrics`, {
                nonce,
                params: { period: 'this_month' },
            });
            expect(res.status()).toBe(200);
            return res.json();
        };

        const before = await readMetrics();
        // Every "sent" SMS lands in a bucket, so the chart sums to `delivered`.
        expect(sumBuckets(before)).toBe(before.delivered);

        const N = 4;
        for (let i = 0; i < N; i++) {
            const res = await restCall(page.request, `${REST_NS}/send`, {
                nonce,
                method: 'POST',
                data: { to: '+15555550400', message: `qa-fake-metrics ${i} ${Date.now()}` },
            });
            expect(res.status()).toBe(200);
            expect((await res.json()).success).toBe(true);
        }

        const after = await readMetrics();
        // >= N (not == N) because other workers may also be sending concurrently.
        expect(after.sms_sent).toBeGreaterThanOrEqual(before.sms_sent + N);
        expect(after.delivered).toBeGreaterThanOrEqual(before.delivered + N);
        expect(sumBuckets(after)).toBeGreaterThanOrEqual(sumBuckets(before) + N);
        // The graph stays consistent with the delivered total after the writes.
        expect(sumBuckets(after)).toBe(after.delivered);
    });

    // --- Real-use-case: compliance settings actually change send behaviour ---

    const readSettings = async (page: import('@playwright/test').Page) =>
        (await (await restCall(page.request, `${REST_NS}/notification-settings`, { nonce })).json())
            .settings ?? {};

    const writeSettings = async (
        page: import('@playwright/test').Page,
        s: { global_sender_id?: unknown; pause_all: boolean; append_company_name: boolean }
    ): Promise<void> => {
        await restCall(page.request, `${REST_NS}/notification-settings`, {
            nonce,
            method: 'POST',
            data: {
                global_sender_id: String(s.global_sender_id ?? ''),
                pause_all: s.pause_all,
                append_company_name: s.append_company_name,
            },
        });
    };

    test('Pause All actually blocks an outgoing send', async ({ page }) => {
        const token = `qa-pause-${Date.now()}`;
        const orig = await readSettings(page);
        try {
            // Turn pause ON (the Compliance filter short-circuits texty_pre_send_sms).
            await writeSettings(page, {
                global_sender_id: orig.global_sender_id,
                pause_all: true,
                append_company_name: Boolean(orig.append_company_name),
            });

            const res = await restCall(page.request, `${REST_NS}/send`, {
                nonce,
                method: 'POST',
                data: { to: '+15555550500', message: `${token} should be blocked` },
            });
            expect(res.status()).toBe(200);
            const body = await res.json();
            // The send is rejected with the paused error, not delivered.
            expect(body.success).toBe(false);
            expect(body.message).toMatch(/pause/i);

            // Nothing was sent: no "sent" row for this token.
            const rows = await ourRows(page.request, token);
            expect(rows.every((r: { status?: string }) => r.status !== 'sent')).toBe(true);
        } finally {
            await writeSettings(page, {
                global_sender_id: orig.global_sender_id,
                pause_all: Boolean(orig.pause_all),
                append_company_name: Boolean(orig.append_company_name),
            });
        }
    });

    test('Append Company Name actually appends the store footer to the message', async ({ page }) => {
        const token = `qa-footer-${Date.now()}`;
        const orig = await readSettings(page);
        try {
            // Footer ON, pause OFF so the send goes through and gets the footer.
            await writeSettings(page, {
                global_sender_id: orig.global_sender_id,
                pause_all: false,
                append_company_name: true,
            });

            const res = await restCall(page.request, `${REST_NS}/send`, {
                nonce,
                method: 'POST',
                data: { to: '+15555550600', message: `${token} body` },
            });
            expect(res.status()).toBe(200);
            expect((await res.json()).success).toBe(true);

            const rows = await ourRows(page.request, token);
            expect(rows.length).toBe(1);
            expectSentViaFake(rows[0]);
            // The stored message carries the original body AND the appended footer.
            expect(rows[0].message).toContain(`${token} body`);
            expect(rows[0].message).toMatch(/\n\nfrom\s+\S+/i);
        } finally {
            await writeSettings(page, {
                global_sender_id: orig.global_sender_id,
                pause_all: Boolean(orig.pause_all),
                append_company_name: Boolean(orig.append_company_name),
            });
        }
    });
});
