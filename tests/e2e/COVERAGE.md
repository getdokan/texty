# Texty — E2E Test Coverage Matrix

Playwright e2e suite for the Texty SMS plugin. Tests run against a live WP
install (`WP_BASE_URL`) authenticated as an administrator via cached storage
state (`tests/e2e/global.setup.ts`).

## How to run

```bash
# One-time: create the admin storage state (uses WP_ADMIN_USER / WP_ADMIN_PASSWORD from .env)
FORCE_AUTH=1 npx playwright test --project=setup

npm run test:e2e            # full suite, headless
npm run test:e2e:ui         # interactive runner
npm run test:e2e:headed     # headed
npm run test:e2e:report     # open last HTML report
npx playwright test rest-   # only the REST contract suites
```

> All write/mutating tests capture the prior state and restore it in
> `afterEach`/`afterAll`, so the suite is **idempotent** and safe to re-run.

## Layers

| Layer | Purpose | Auth |
|---|---|---|
| REST contract (`rest-*.spec.ts`) | Verb/route/param/shape/permission contract of `texty/v1` | nonce + admin cookies |
| UI (`ui-*.spec.ts`, `*.spec.ts`) | SPA rendering, routing, forms, interactions | admin storage state |
| Health (`console-health.spec.ts`) | No console/page errors, no ErrorBoundary | admin storage state |

## REST API coverage (`includes/Api/*`)

| Endpoint | Method | Cases | Spec |
|---|---|---|---|
| `/metrics` | GET | full payload shape; invariants (delivered+failed ≤ sent; 0≤rate≤100); 5 period enums echoed; invalid period → 400; default `this_month`; DELETE → 404/405 | `rest-metrics` |
| `/logs` | GET | paginated envelope; `per_page` honoured; clamp to MAX 100; status enum (sent/failed/pending/empty); invalid status → 400; invalid order → 400 | `rest-logs` |
| `/logs/{id}` | GET | non-existent id → 404 `texty_log_not_found`; non-numeric → 404 (route miss) | `rest-logs` |
| `/logs/export` | GET | CSV attachment + header row; status filter; bogus status → 400; unauth → 401/403 | `rest-logs-export` |
| `/settings` | GET | stored option shape; `context=edit` catalogue (twilio/vonage/clickatell/plivo name+logo+description) | `rest-settings` |
| `/settings/schema` | GET | schema/values/active_gateway/connected_gateways; a page per built-in gateway | `rest-settings` |
| `/notifications` | GET | 200 | `rest-notifications` |
| `/notifications/schema` | GET | `group=wp` schema+values (page/section + registration bool); unknown group → 400; missing group → 400 | `rest-notifications` |
| `/notification-settings` | GET/POST | shape; sender-id round-trip; boolean toggles round-trip; partial update preserves siblings; sender-id sanitized | `rest-notification-settings` |
| `/gateway/activate` | POST | missing key → 400; unknown → 400 `texty_unknown_gateway`; needs-creds w/o creds → 400 `texty_no_credentials`; fake → success | `rest-gateway-lifecycle` |
| `/gateway/deactivate` | POST | clears active gateway | `rest-gateway-lifecycle` |
| `/gateway/disconnect` | POST | missing key → 400; fake → success+`disconnected` | `rest-gateway-lifecycle` |
| `/send` | POST | missing params → 400 `rest_missing_callback_param`; partial → 400; full → success/message envelope | `rest-send-tools-status` |
| `/send` (fake gateway) | POST | single send → logged `sent`/`fake_*`; bulk varied payloads (unicode/emoji/long/specials/newlines/no-+); repeated sends → distinct rows; tools/test logged; sends move `sms_sent`/`delivered` + volume-chart buckets (buckets sum == `delivered`); **Pause All blocks the send** (success=false + paused msg, no `sent` row); **Append Company Name appends a `from <store>` footer** to the logged message | `rest-send-fake-gateway` |
| `/tools/test` | POST | missing `to` → 400; with `to` → envelope | `rest-send-tools-status` |
| `/status` | GET | boolean `success` flag | `rest-send-tools-status` |
| **All routes** | GET/POST | unauthenticated → 401/403 (8 reads + 7 writes); admin cookies w/o nonce → blocked | `rest-auth-matrix` |

## Live gateway connection (real credentials from `.env`)

Skips cleanly when the env keys are absent. Mutating tests restore the original active gateway.

| Gateway | Cases | Spec |
|---|---|---|
| Twilio | `validate()` hits the live API: valid creds → 200 + saved/activated; invalid token → rejected w/ error code; missing token → `texty_missing_credentials` | `rest-gateway-connection` |
| Clickatell | saving the key → 200 + connected; **bogus key still accepted** (`validate()` is a no-op — `test.fail()`) | `rest-gateway-connection` |
| Send → log pipeline | live `tools/test` send via Twilio appends an SmsStat row (sent or failed); `success===true` ⇒ row is `sent` | `rest-gateway-connection` |

## Release readiness (`release-readiness.spec.ts`)

| Gate | Assertion |
|---|---|
| REST registration | `GET /texty/v1` namespace index lists all 14 core routes |
| Admin menu | Texty top-level menu + `dashboard`/`gateway`/`notifications`/`logs` submenu hash links present |
| No fatal load | admin screen returns <400, HTML free of "critical error"/"Fatal error"/"Parse error"; `#texty-app` mounts |
| Bootstrap config | `window.texty` localized with `nonce`, `rest_url`, `version.lite` |
| SPA bundle | enqueued `dist/index.js` resolves to HTTP 200 |

## UI coverage (`src/pages/*`)

| Page | Cases | Spec |
|---|---|---|
| Admin shell | Texty page loads, SPA root mounts non-empty | `texty-admin` |
| Dashboard | root→`/dashboard` redirect; metrics endpoint + stat cards; welcome/gateway block; volume chart; quick-send card | `dashboard` |
| Dashboard (deep) | 4 stat cards; send button gated on phone+message; char counter; volume chart renders (recharts surface + line curve + x-axis ticks); **all 5 period filters** (This/Last Month, Last 7/30 Days, This Year) refetch with the matching `period=` arg + echo it back | `ui-dashboard` |
| Quick Send (UI) | dashboard send via fake → "Message has been sent" toast → message logged `sent`/`fake` → `sms_sent`/`delivered` move; sent message findable in Logs UI search + readable in the View-Log detail dialog | `ui-quick-send` |
| Gateway | schema endpoint hit; sidebar list + search; detail pane; search filter; gateway select updates detail; no-match empty; credential inputs/connect action | `gateway`, `ui-gateway` |
| Logs | logs endpoint + table; export button; status filter; column headers/empty; export CSV; row → detail dialog | `logs`, `ui-logs` |
| Notifications | 3 tabs; integrations cards; settings endpoint; integration detail route; user-events switches; compliance form fields; save → success toast | `notifications`, `ui-notifications` |
| Notifications (deep) | **Settings:** sender-id round-trips + persists, Append Company Name toggle persists, Pause All confirm dialog → "Yes, Pause" persists. **User Events:** toggling a switch flips exactly one stored `enabled`; editing a "Message Content" textarea persists exactly that message (both restored after). **Integrations:** tab lists WC/Dokan (or empty); "Configure" opens the integration detail route + its group settings (switch + Save Changes) | `ui-notifications` |
| Routing | hash nav dashboard↔notifications; unknown route → 404; Back to Dashboard; every route renders w/o ErrorBoundary | `routing` |
| Health | every route mounts with zero console/page errors | `console-health` |

## 🐞 Bugs found by this suite

| Severity | Endpoint | Symptom | Root cause |
|---|---|---|---|
| High | `GET /texty/v1/logs/{id}` | Returns **HTTP 500** (fatal `TypeError`) for *every* id, not a 404 for missing rows | `Texty\Api\Logs::get_item()` calls `$store->read( $id )` passing an `int`, but `WeDevs\WPKit\DataLayer\DataStore\BaseDataStore::read()` is typed `read( ModelInterface &$model )`. The single-row drill-in endpoint can never succeed. Confirmed live in `wp-content/debug.log`. Marked `test.fail()` in `rest-logs.spec.ts` until fixed. |
| Medium | `POST /texty/v1/settings` (clickatell) | A **bogus Clickatell API key is accepted** and saved as if valid | `Texty\Gateways\Clickatell::validate()` is a no-op — it returns `['key' => …]` without calling the API, unlike `Twilio::validate()`. The UI reports "connected" for credentials that will fail at send time. Marked `test.fail()` in `rest-gateway-connection.spec.ts` until `validate()` verifies the key. |

## Known gaps / future work

- **Vonage / Plivo** gateways have no live-credential coverage (only Twilio +
  Clickatell keys are wired via `.env`). Their `validate()`/`send()` paths are
  unverified end-to-end; add keys to extend `rest-gateway-connection.spec.ts`.
- **Deliverable-destination send.** The live Twilio send targets the account's
  own number (To==From), which the provider rejects — so the *delivery* success
  path (`status === 'sent'`) is exercised only opportunistically. A verified
  recipient number in `.env` would make it deterministic.
- **Non-admin (subscriber) authorization** is asserted only via the
  unauthenticated path. A seeded subscriber storage state would let us assert
  `manage_options` rejection for a logged-in low-privilege user.
- **WooCommerce / Dokan integration notifications** (order status → SMS) need
  order fixtures; not covered here.
- **CSV export contents** (the `handleExport` download) assert presence only,
  not the generated file body.
