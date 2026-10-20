---
name: texty-e2e
description: |
  MUST invoke before reading or writing any Texty end-to-end (Playwright) test. Encodes this suite's auth model (cached admin storage state + the WP email-verification interstitial), the `helpers/texty.ts` client API, the REST-contract + idempotency patterns, and the plugin-ui/base-ui selector gotchas that have already cost real debugging time. Load it first so the very first spec follows the conventions, not the third one.

  TRIGGER (any of these = invoke immediately, before tools):
  - File path matches `tests/e2e/**`, `**/*.spec.ts`, `tests/e2e/helpers/**`, `tests/e2e/global.setup.ts`, `playwright.config.ts`, or `tests/tsconfig.json`
  - Task involves: e2e test, end-to-end, Playwright, Playwright MCP, `@playwright/test`, spec file, `test.describe`, REST contract test, API contract test, auth/storage state, test coverage, COVERAGE matrix, `npm run test:e2e`, flaky test, selector/locator for the admin SPA under test
  - User says: "write an e2e test", "add a spec", "test this page/endpoint", "give full test coverage", "run the e2e tests", "act as an SDET", "fix this flaky test", "why does this Playwright test fail"

  SKIP (do NOT invoke):
  - Production frontend edits under `src/**` (use `frontend-dev`)
  - Production PHP edits under `includes/**` / `texty.php` (use `backend-dev`)
  - PHP-side unit tests (there is no PHP test suite; this skill is Playwright-only)
  - Reviewing a diff for merge-readiness (use `code-review`)

  IMPORTANT: When this skill applies, invoke it BEFORE the first Read or Edit on a test file. The auth + selector conventions need to be in your head before you write the first `test(...)`, or you will reproduce failures this skill already documents (type-less inputs, sortable-header buttons, the email-verification interstitial, non-idempotent mutations).
---

# Texty e2e conventions

The Texty e2e suite drives a **live WordPress install** with Playwright, logged in as an administrator. It tests two surfaces: the `texty/v1` **REST API** (contract-level) and the **React admin SPA** (`#texty-app`, hash-routed). Tests live in `tests/e2e/`, TypeScript only, Chromium only.

> There is **no** `wp-env`, no mock server, no PHP test suite. Tests hit a real DB and real options, so **every mutating test must restore state** (see Idempotency). The target site, admin user, and password come from `.env`.

## Before you start

```bash
# tests/e2e/.env-derived config (see .env / .env.example):
#   WP_BASE_URL, WP_ADMIN_USER, WP_ADMIN_PASSWORD

# One-time (or after a creds change): build the cached admin storage state.
FORCE_AUTH=1 npx playwright test --project=setup

npm run test:e2e            # full suite, headless
npm run test:e2e:ui         # interactive runner
npm run test:e2e:headed     # headed
npm run test:e2e:report     # open the last HTML report
npx playwright test rest-   # filter by filename prefix (REST suites only)
npx playwright test ui-dashboard rest-logs   # specific specs
```

Per `CLAUDE.md`, **do not auto-run** `npm run test:e2e` after editing a spec unless the user asked you to run it, or the task is explicitly "run the tests". Writing/​editing a spec ends the turn at the edit.

> **Author selectors against the live UI with the Playwright MCP — don't guess them.** See [Authoring with the Playwright MCP](#authoring-with-the-playwright-mcp) below.

## Architecture (`playwright.config.ts`)

- `testDir: ./tests/e2e`, `fullyParallel: false`, `workers: 2`, `timeout: 30s`, `expect.timeout: 10s`.
- Two projects: **`setup`** (runs `global.setup.ts`) → **`chromium`** (`dependencies: ['setup']`, `storageState: 'tests/e2e/auth/admin.json'`).
- `baseURL` = `WP_BASE_URL` (env), so navigations and `request` calls use root-relative paths (`/wp-admin/...`, `/wp-json/...`).
- Artifacts: `trace: 'on-first-retry'`, `screenshot/video` only on failure; report → `tests/e2e/playwright-report`.

## Auth model — cached admin storage state

`global.setup.ts` logs in once and writes `tests/e2e/auth/admin.json`; every `chromium` test reuses it via `storageState`. The setup **skips itself** if `admin.json` already exists unless `FORCE_AUTH=1` is set.

Two non-obvious rules this setup already encodes — keep them:

1. **The admin-email-verification interstitial.** WordPress periodically interrupts login with an "Administration email verification" screen served from `wp-login.php` (NOT under `/wp-admin/`), so a naïve `waitForURL('**/wp-admin/**')` times out even though login succeeded. The setup clicks **"Remind me later"** when present, then explicitly `page.goto('/wp-admin/')` and asserts `#wpadminbar`. Don't revert to URL-waiting.
2. **Creds live in `.env`.** If auth fails with "password incorrect", the `.env` user/password is wrong — surface it to the user; **never reset a WP account's password** to make tests pass (it's an account-security change outside the test scope, and the harness will block it). Ask the user to set the password.

## The helper client — `tests/e2e/helpers/texty.ts`

Always go through these; don't hand-roll navigation, nonces, or fetches.

| Export | Use |
|---|---|
| `TEXTY_BASE` | `'/wp-admin/admin.php?page=texty'` — the SPA mount URL |
| `REST_NS` | `'/wp-json/texty/v1'` — REST namespace prefix |
| `ADMIN_STORAGE` | `'tests/e2e/auth/admin.json'` — storage-state path |
| `gotoTextyHash(page, hash='/dashboard')` | Navigate to `#<hash>` and wait for `#texty-app` attached |
| `waitForApi(page, urlFragment)` | `waitForResponse` where url includes fragment and status < 500 |
| `getRestNonce(page)` | Read `wpApiSettings.nonce` (with a profile-page fallback) |
| `bootstrapNonce(browser)` | Open an admin context once and return its REST nonce |
| `restRequest(request, nonce, path)` | Simple authed GET (legacy helper) |
| `restCall(request, path, { method, nonce, data, params })` | **Primary** REST client: any verb, JSON body, query params, sends `X-WP-Nonce` |

`restCall` is the default for all REST work. Build query strings via its `params` option, not by hand.

## REST contract tests (`rest-*.spec.ts`)

Pattern: grab the nonce once in `beforeAll`, then assert status + body shape with `restCall`.

```ts
import { test, expect } from '@playwright/test';
import { bootstrapNonce, restCall, REST_NS } from './helpers/texty';

test.describe('REST: <thing> contract', () => {
  let nonce = '';
  test.beforeAll(async ({ browser }) => { nonce = await bootstrapNonce(browser); });

  test('returns the documented shape', async ({ page }) => {
    const res = await restCall(page.request, `${REST_NS}/metrics`, { nonce });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body).toHaveProperty('delivery_rate');
  });
});
```

WordPress REST validation facts this suite relies on (assert these contracts):

- **Out-of-`enum` / wrong-type arg → `400` `rest_invalid_param`.** Args registered with `type`/`enum` get auto-validated (e.g. `metrics?period=bogus`, `logs?status=bogus`, `logs?order=sideways`).
- **Missing `required` arg → `400` `rest_missing_callback_param`** (e.g. `POST /send` with no `to`/`message`).
- **Unknown method on a route → `404` `rest_no_route`** (assert `[404, 405]` to be safe).
- **Route regex matters:** `/logs/(?P<id>\d+)` — a non-numeric id (`/logs/abc`) is a route miss → `404`, distinct from a valid-but-missing id.
- **Permission:** every controller gates on `manage_options` via `admin_permissions_check`. An unauthenticated context (no cookies/nonce) gets `401`/`403`; **admin cookies WITHOUT a nonce also fail** a write (`rest_cookie_invalid_nonce`). Cover both. Build the unauth context fresh: `browser.newContext({ ignoreHTTPSErrors: true })` and use `ctx.request` with no nonce.

Endpoint catalogue (keep `COVERAGE.md` in sync when you add cases): `metrics`, `logs` + `logs/{id}`, `settings` (+ `?context=edit`), `settings/schema`, `notifications` + `notifications/schema?group=`, `notification-settings` (GET/POST), `gateway/activate|deactivate|disconnect`, `send`, `tools/test`, `status`.

## Idempotency — mandatory for any mutating test

Tests run against real options/DB. Any test that POSTs **must** capture prior state and restore it, so the suite is re-runnable and order-independent.

```ts
test.beforeEach(async ({ page }) => {
  const res = await restCall(page.request, `${REST_NS}/notification-settings`, { nonce });
  original = (await res.json()).settings ?? {};
});
test.afterEach(async ({ page }) => {
  await restCall(page.request, `${REST_NS}/notification-settings`, {
    nonce, method: 'POST',
    data: {
      global_sender_id: String(original.global_sender_id ?? ''),
      pause_all: Boolean(original.pause_all),
      append_company_name: Boolean(original.append_company_name),
    },
  });
});
```

**Cross-file shared-state races (`workers: 2`).** `fullyParallel` is false (tests within a file run serially) but **two spec files run on two workers concurrently**. Files that mutate the *same* global server state (`texty_settings` active gateway — e.g. `rest-gateway-lifecycle` and `rest-gateway-connection`) will race. **Assert on the mutating endpoint's own response body, which the server computes atomically within that request (`activate`→`active_gateway:'fake'`, `deactivate`→`''`), NOT on a follow-up global re-read** (`GET /settings/schema` after the write) — the re-read can observe the other worker's write and flake. Reserve global reads for `beforeEach`/`afterEach` state capture/restore.

**Gateway lifecycle:** capture the active gateway from `GET /settings/schema` (`active_gateway`) in `beforeEach`; restore in `afterEach` (re-`activate` it, or `deactivate` if none). Only ever **activate/disconnect the `fake` gateway** — it's registered solely under `WP_DEBUG` and declares no credentials, so it's the only gateway you can activate without real API keys. Never disconnect a real gateway (`twilio`/`vonage`/`plivo`/`clickatell`) — that wipes the user's saved creds. When asserting the "needs credentials" 400, first read `connected_gateways` and `test.skip(...)` if that gateway already has creds on this environment.

## Authoring with the Playwright MCP

When writing or fixing a **UI** spec, drive the real admin SPA with the **Playwright MCP plugin** (`mcp__plugin_playwright_playwright__*`) first, then translate what you observe into the test. The selector gotchas below (type-less inputs, header `<button>`s, base-ui roles, env-dependent state) are exactly the things you cannot get right by guessing — read them off the live page instead.

Loop:

1. `browser_navigate` to `<WP_BASE_URL>/wp-admin/admin.php?page=texty#/<route>` (the `TEXTY_BASE` + hash you'd pass to `gotoTextyHash`). If the context isn't already authed, log in once through `wp-login.php` — same flow `global.setup.ts` encodes, including clicking **"Remind me later"** on the email-verification interstitial.
2. `browser_snapshot` to get the accessibility tree. **Read roles/names off this tree** and copy them verbatim into role/text/label locators — it's the ground truth for `getByRole('combobox')`, `getByRole('option', { name: ... })`, header-vs-row `<button>` disambiguation, and whether an `<input>` is type-less (target it by `id`).
3. `browser_click` / `browser_type` / `browser_select_option` to confirm the interaction works and the locator is unambiguous before committing it to a spec. Use `browser_network_requests` to capture the exact request URL + params a control fires (e.g. `period=last_7_days`), so your `waitForApi` fragment and param assertions match reality.
4. Note **environment-dependent state** you see live (a gateway already Connected, an empty logs table) and encode it with `.or()` / `test.skip(...)` rather than asserting one fixed state.

The MCP is for **exploration and selector verification only** — the committed spec must use `@playwright/test` + the `helpers/texty.ts` client (`gotoTextyHash`, `restCall`, `waitForApi`), never MCP calls. Close the browser (`browser_close`) when done. Don't run mutating flows (gateway connect/disconnect, settings POST) through the MCP without the same backup/restore discipline specs use — you're driving the live DB.

## UI tests (`ui-*.spec.ts`) — selector rules that bite

The SPA is React + `@wedevs/plugin-ui` (base-ui, **not** Radix). These cost real debugging — internalize them:

- **plugin-ui `<Input>` renders a type-less `<input>`.** CSS selectors like `input[type="text"]` match **nothing**. Target inputs by `id` (`page.locator('#texty-sender-id')`) or a scoped `input:not([type="search"])`.
- **Sortable column headers are `<button>`s.** `getByRole('button', { name: /Details/i })` matches the *header sort button*, not the row action. Match row actions by their specific label (`/View Log/i`), and use a precise enough regex that it can't collide with a header.
- **base-ui `Select`** exposes `role="combobox"` for the trigger and `role="option"` for items. Open with `getByRole('combobox').click()`, choose with `getByRole('option', { name: /Last 7 Days/i })`. Pair the option click with `waitForApi` when it triggers a refetch, and assert the request URL carries the expected param (`period=last_7_days`).
- **Phone input** (`react-phone-input-2`) exposes `input[name="phone"]`. Send buttons are commonly gated on both phone AND message — assert disabled → fill one → still disabled → fill the other → enabled.
- **Toasts** are sonner via plugin-ui; assert with `getByText(/Settings saved/i)` (they portal into `.pui-root`, still queryable).
- **Environment-dependent UI state.** A gateway may already be **Connected/Activated** on the target site, so its detail pane shows Disconnect/Deactivate instead of Connect, and credential inputs are pre-filled. Write resilient assertions with a broad action regex (`/Save|Connect|Activate|Disconnect|Deactivate/i`). **Collapse `.or()` with a trailing `.first()`** — a connected gateway shows BOTH credential fields and lifecycle buttons, so `credInput.or(actionBtn)` matches 2+ elements and `toBeVisible()` throws a strict-mode violation: write `expect(credInput.or(actionBtn).first()).toBeVisible()`.
- **Empty data is normal.** The logs table may have zero rows. Use `.or()` for "headers OR empty state", and `test.skip(count === 0, ...)` before drilling into a row that may not exist.

Prefer **role/text/label** locators over CSS/XPath. After `gotoTextyHash`, `await page.waitForLoadState('networkidle')` before interacting with data-driven UI.

## Console health (`console-health.spec.ts`)

Every route must mount with no uncaught page errors and no `ErrorBoundary` ("Something went wrong") fallback. Filter known WP-admin noise (favicon, React DevTools banner, wp-emoji, heartbeat, `net::ERR_`). For 404s, the browser logs a bare "Failed to load resource" **without the URL** — capture URLs via `page.on('response', r => r.status() === 404 && ...)` and **only fail on plugin-owned 404s** (`/texty|/wp-json/texty/`); WP/theme asset 404s are environment noise, not plugin bugs.

## When a test catches a real product bug

As an SDET, surfacing the defect is the deliverable — don't bend the test to the buggy behavior. Keep the assertion targeting **correct** behavior and mark it `test.fail(true, '<why>')`:

```ts
// KNOWN BUG: Logs::get_item() passes int to BaseDataStore::read(ModelInterface) → 500, not 404.
test('GET /logs/{id} on a non-existent id returns 404', async ({ page }) => {
  test.fail(true, 'get_item() fatals (int → ModelInterface); returns 500');
  const res = await restCall(page.request, `${REST_NS}/logs/99999999`, { nonce });
  expect(res.status()).toBe(404);
});
```

`test.fail()` keeps the suite green while the bug exists **and flips it red the moment someone fixes it** (an unexpected pass), prompting removal of the marker. Record the bug in `COVERAGE.md`'s "Bugs found by this suite" table. Don't edit `includes/**` to fix it from this skill — that's a `backend-dev` task; report it and let the user decide.

## File & naming conventions

- `rest-<area>.spec.ts` for API contract suites; `ui-<page>.spec.ts` for SPA interaction; topic specs (`routing`, `console-health`) named for what they assert.
- One `test.describe` per file, named `REST: <area>` or `<Page> UI`.
- Shared logic goes in `helpers/texty.ts` — extend it (e.g. widen `restCall`) rather than duplicating fetch/nonce code in specs.
- Keep `tests/e2e/COVERAGE.md` current: it's the SDET coverage matrix (endpoint × cases, UI pages × cases, known bugs, known gaps). Update it in the same change as new specs.

## Common pitfalls

- **Guessing UI selectors** — drive the live page with the Playwright MCP, read roles/names off `browser_snapshot`, then commit them. Don't author locators blind.
- **URL-waiting after login** — handle the email-verification interstitial; goto `/wp-admin/` and assert `#wpadminbar` instead. Auth setup.
- **`input[type="text"]` finds nothing** — plugin-ui inputs are type-less; target by `id`.
- **Generic `/Details/` regex clicks the sort header** — match row actions by exact label.
- **Mutating test leaves residue** — back up in `beforeEach`, restore in `afterEach`; only touch the `fake` gateway.
- **Asserting a gateway is "not connected"** without checking `connected_gateways` first — `test.skip` when the env already has creds.
- **Flaky on empty data** — `.or()` + `test.skip(count === 0)`.
- **console-health failing on WP asset 404s** — filter to plugin-owned URLs only.
- **Bending a test to a 500/buggy response** — assert the correct contract, mark `test.fail()`, log it in `COVERAGE.md`.
- **Resetting WP creds to fix auth** — don't; it's blocked and out of scope. Fix `.env` / ask the user.
- **Auto-running the suite after an edit** — only run when asked (per `CLAUDE.md`).
