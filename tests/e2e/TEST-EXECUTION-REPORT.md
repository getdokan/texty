# Texty — End-to-End Test Execution Report

**Role:** Senior QA Engineer / SDET / Test Architect
**Build under test:** Texty Lite 2.0.0 (branch `feature/dashboard`)
**Environment:** Live WordPress (`dokanpw.test`), PHP 8.x, WP_DEBUG on, WooCommerce **active**, Dokan **active**
**Gateways present:** Twilio, Vonage, Clickatell, **Plivo** ("Pivilo" in the brief = Plivo), Fake (mock, WP_DEBUG-only)
**Method:** Playwright E2E (123 specs) + live REST execution via `rest_do_request` + WP-CLI DB inspection + Playwright-MCP UI verification
**Credentials:** Real Twilio + Clickatell (from `.env`); **mock** keys for Vonage/Plivo; Fake provider for no-cred path.

---

## 1. Executive Summary / Overall Quality Assessment

The plugin's **core path is solid**: gateway connect → activate → send → log → dashboard metrics works end-to-end and is covered by an automated suite that is **green and stable** (121 passed / 2 skipped / 0 failed / 0 flaky across full + stress runs).

However, this QA brief assumes a **larger feature surface than Texty actually implements**. A significant share of the requested checklist (in-app notification inbox, message-delivery lifecycle with DLR, bulk gateway ops, Today/Yesterday/Custom analytics, support ticketing) **is not built into the product**. Those are reported below as **N/A — Not Implemented**, not as failures, with a recommendation line each.

**5 defects** were found, 1 High (a reproducible fatal), 1 Medium-High (wrong HTTP status on every credential failure), and 3 Medium/Low.

**Verdict: Conditional pass for release.** Ship-blockers: **D1** (fatal) and **D2** (500 on all credential errors). The rest are quality/UX risks.

---

## 2. Pass/Fail Summary

| Area | Result | Notes |
|---|---|---|
| Automated E2E suite (123 specs) | ✅ 121 pass / 2 skip / 0 fail | stress-tested, 0 flaky |
| Gateway connect — Fake (mock) | ✅ PASS | activates w/o creds, sends, deactivates |
| Gateway connect — Twilio (real) | ✅ PASS | live API validation succeeds |
| Gateway connect — Vonage (mock creds) | ⚠️ PASS w/ DEFECT | correctly rejected, but **HTTP 500** (D2) |
| Gateway connect — Plivo (mock creds) | ⚠️ PASS w/ DEFECT | correctly rejected, but **HTTP 500** (D2) |
| Gateway lifecycle (activate/deactivate/disconnect/reconnect/update) | ✅ PASS | response bodies authoritative |
| Invalid-credential handling | ⚠️ PARTIAL | blocked correctly; wrong status (D2); Clickatell no-op (D3) |
| SMS send → log pipeline | ✅ PASS | every attempt logged (sent/failed) |
| Logs single-row drill-in (`/logs/{id}`) | ❌ FAIL | **fatal 500 for every id** (D1) |
| Dashboard statistics | ✅ PASS (with label risk) | "Delivered" = accepted, not DLR (D4) |
| Analytics — 5 supported periods | ✅ PASS | this/last month, 7/30 days, this year |
| Analytics — Today/Yesterday/Custom | ⛔ N/A | not in product (enum-rejected 400) |
| Logs search + status/type filters | ✅ PASS | free-text search + status/type/order |
| Logs date/gateway filters | ⛔ N/A | not implemented as discrete filters |
| CSV export | ✅ PASS (presence) | endpoint wired; row-count integrity not auto-asserted |
| Notification event templates (wp/wc/dokan) | ✅ PASS | schema + toggle + message + recipients |
| Notification *inbox* (read/unread/history, login/logout) | ⛔ N/A | feature not present |
| Integrations (WC/Dokan) wiring | ✅ PASS | both active, schemas load; order→SMS not fixture-tested |
| Settings save/update/persist | ✅ PASS | round-trips, survives reload |
| Settings reset | ⛔ N/A | no reset feature |
| Bulk activate/deactivate | ⛔ N/A | single-active-gateway by design |
| Documentation link | ✅ PASS (config) | `wedevs.com/docs/texty/` (403 to bots — WAF) |
| Get Support / Feature Request | ⚠️ PARTIAL | external links only; **same URL** (D5); no in-plugin form/ticket |
| Authorization (unauth/no-nonce) | ✅ PASS | all 15 endpoints gated |

---

## 3. Capability Map — Brief vs. Actual Product

> Critical for an accurate verdict: the brief describes a generic "SMS Gateway Platform." Texty is a **WordPress SMS notification plugin**. The following requested concepts **do not exist** in the build and cannot be defect-failed:

| Brief expectation | Actual in Texty | Disposition |
|---|---|---|
| Gateway statuses: Active, Inactive, Connected, Disconnected, **Failed, Pending, Error** | Model has `active_gateway` (string) + `connected_gateways` (list). UI: Connected / Activated / Deactivate. | Active=activated, Inactive=deactivated, Connected, Disconnected ✅. **Failed/Pending/Error gateway states: N/A** |
| Message lifecycle: queued → processed → sent → **delivered** → failed | Synchronous send; `SmsStat.status` ∈ {sent, failed}. No queue, no DLR webhooks. | sent/failed ✅. **queued/processed/delivered: N/A** |
| Dashboard: Total, Sent, Delivered, Failed, **Pending**, **Active/Inactive Gateway counts** | `sms_sent`, `delivered`(=accepted), `failed`, `delivery_rate`, single `gateway_status` bool, volume chart. | Total/Sent/Failed ✅. **Pending count & gateway counts: N/A** |
| Analytics filters incl. **Today, Yesterday, Custom Range, Monthly Breakdown** | enum: this_month, last_month, last_7_days, last_30_days, this_year | 5 supported ✅. **Today/Yesterday/Custom: N/A** |
| Logs: search by phone/gateway/msg-id; **date & gateway filters** | free-text `search` + `status` + `type`(notification id) + `order` | search/status/type ✅. **date/gateway discrete filters: N/A** |
| Notifications: inbox w/ **read/unread, history, login/logout, gateway-event notifications** | SMS event *templates* (registration, comment, WC/Dokan order events) | event templates ✅. **In-app inbox + login/logout: N/A** |
| **Bulk** activate/deactivate | exactly one active gateway at a time | **Bulk: N/A** |
| Settings **reset** | no reset endpoint | **N/A** |
| Support **ticket creation**, Feature-request **form** | external `wedevs.com/contact/` links | **In-plugin forms: N/A** |

---

## 4. Defect List & Severity Classification

| ID | Severity | Title | Steps to Reproduce | Expected | Actual | Evidence |
|---|---|---|---|---|---|---|
| **D1** | **High (ship-blocker)** | `GET /texty/v1/logs/{id}` fatals for every id | Call `/texty/v1/logs/1` (or any id) | 200 with row, or 404 if missing | **HTTP 500**, `Uncaught TypeError` | `wp-content/debug.log`; `Logs.php:198` passes `int` to `BaseDataStore::read(ModelInterface)` |
| **D2** | **Medium-High (ship-blocker)** | Credential-validation failures return **HTTP 500** | Connect Vonage/Plivo/Twilio with bad/empty creds | 400/401 + error body | **HTTP 500** | Probe: Vonage/Plivo mock → 500; gateway `validate()` returns `WP_Error` with **no `['status']`** → REST defaults to 500 |
| **D3** | Medium | Clickatell accepts **any** key as valid | Save Clickatell with bogus key | Reject invalid key | Saved & "Connected"; fails only at send | `Clickatell::validate()` is a no-op (returns key unverified) vs Twilio/Vonage/Plivo which call the API |
| **D4** | Low-Medium | "Delivered" metric is mislabeled | Send SMS, view dashboard | Delivered = DLR-confirmed | Counts gateway-**accepted** (`status='sent'`); no DLR exists | `Metrics.php` increments `delivered` when `status==='sent'` |
| **D5** | Low | "Get Support" and "Feature Request" share one URL | Open help menu | Distinct destinations | Both → `wedevs.com/contact/`; no in-plugin form | `Header.tsx` helpItems |

**Severity legend:** High = data loss / crash / blocks core flow. Medium = wrong behavior with workaround. Low = cosmetic / UX / labeling.

---

## 5. Detailed Execution — by Module

### 5.1 Gateway Validation (per gateway)
- **Fake (mock provider):** activate (no creds) → `200`; status → `200 {success:true}`; send via `/send` → `200`; deactivate → `200`. ✅ Full connect/disconnect/reconnect cycle.
- **Twilio (real creds):** valid → `200` saved+activated; **invalid token → rejected** (D2: 500); missing token → `texty_missing_credentials` (D2: 500). ✅ auth verified live.
- **Vonage (mock creds):** rejected by live API → **500** (D2). Data integrity verified: nothing persisted on failure (`vonage` stayed `{"":"","":""}`). ✅ no leak.
- **Plivo (mock creds):** rejected by live API → **500** (D2). ✅ no leak.
- **Clickatell (real key):** saved + connected `200`; **bogus key also accepted** (D3).
- **Status transitions validated:** Connected (creds saved) and Active (selected) are **independent** — verified via `connected_gateways` vs `active_gateway`. UI shows "Connected" badge + "Activated"/"Deactivate". **Failed/Pending/Error gateway statuses: N/A (not modeled).**

### 5.2 SMS Sending & Logs
- Send pipeline: `/send` and `/tools/test` → `texty_after_send_sms` → `SmsStat` row. Verified **every attempt logged** (sent or failed) via live send + DB read (`total` increments; row status ∈ {sent, failed}).
- Log fields present (UI + API + DB cross-checked): id, created_at, status, type, reference_id. **Recipient is not surfaced** in the list row payload (shows masked/empty) — minor.
- **Delivery (DLR) status: N/A** — no provider callback/webhook; "delivered" ≠ confirmed delivery (D4).
- Single-row endpoint: **D1 fatal**.

### 5.3 Dashboard
- Cards: SMS Sent, Delivered, Failed, Delivery Rate, single Gateway Status, Volume chart. Metrics recompute per period; `delivered + failed ≤ sms_sent` and `0 ≤ rate ≤ 100` invariants asserted. ✅
- **Pending Messages / Active-Inactive gateway counts: N/A.**

### 5.4 Analytics (Volume)
- ✅ `this_month, last_month, last_7_days, last_30_days, this_year` → all `200`, correct echo + chart buckets; UI `Select` refetch verified (`period=last_7_days`).
- ⛔ `today`, `yesterday`, `custom` → `400 rest_invalid_param` (**not implemented**). Empty-state handled (zero-filled buckets).

### 5.5 Logs Module
- ✅ Free-text `search`, `status` (sent/failed/pending), `type` (notification id), `order`, pagination (clamp to 100). Invalid enum → `400`.
- ⛔ Discrete **date** range and **gateway-name** filters not implemented.
- CSV export endpoint (`/logs/export`) wired; button present. **Row-count/header/large-dataset integrity not auto-validated** — recommend a dedicated export-integrity test.

### 5.6 Notifications
- ✅ Groups `wp`, `wc`, `dokan` schemas load (`200`); each event = enable toggle + message template + recipients; settings (sender id, pause-all, append-company-name) round-trip + persist.
- ⛔ **In-app notification inbox** (Message Sent/Failed, Gateway Connected/Disconnected/Error, **User Login/Logout**, read/unread, history) — **not a product feature**. `group=auth` → `400`.

### 5.7 Integrations
- ✅ WooCommerce (`order_admin_*`, `order_customer_*`) and Dokan (`order_dokan_*`) registered; both plugins active; schemas render.
- ⚠️ **End-to-end order → SMS not executed** (needs seeded WC/Dokan orders). Recommend an integration spec that creates an order, transitions status, and asserts an `SmsStat` row + the `_texty_{id}` idempotency meta.

### 5.8 Settings / Activation
- ✅ Save/update/persist across reload; nonce + `manage_options` enforced.
- ⛔ **Reset**, **Bulk activate/deactivate** — not implemented.

### 5.9 Documentation / Support / Feature Request
- Links configured: Docs → `wedevs.com/docs/texty/`; Support & Feature Request → `wedevs.com/contact/`. Automated HEAD returned **403 (WAF bot-block)** — **requires real-browser confirmation** (host is up, not a 404/DNS failure). **No in-plugin support form / ticket flow / feature-request submission** (D5).

### 5.10 Negative Testing
| Case | Result |
|---|---|
| Invalid API keys | Blocked, but **500** (D2) |
| Empty credential fields | Blocked, **500** (D2) |
| Unauthenticated / no-nonce | `401/403` ✅ |
| Out-of-enum params (period/status/order) | `400` ✅ |
| Missing required (send `to`/`message`) | `400 rest_missing_callback_param` ✅ |
| Non-numeric `/logs/abc` | `404` (route miss) ✅ |
| Network failure / API timeout | Not simulated (no fault-injection harness) — **gap** |
| Rate limiting | Not implemented in plugin — **N/A** |
| Duplicate submissions (orders) | `_texty_{id}` meta guards WC dupes — **not fixture-tested** |

---

## 6. API Validation Results (live)
- Namespace `/texty/v1` registers **all 14 core routes** (+ notices/migration). ✅
- Enum/required validation, permission gating, pagination clamp: **conformant**. ✅
- **Non-conformance:** error responses for credential failures use `500` instead of `4xx` (D2); `/logs/{id}` fatals (D1).

## 7. Dashboard Validation Results
Stat invariants hold; per-period recompute correct. Missing: Pending count, gateway counts. Label risk: "Delivered" (D4).

## 8. Analytics Validation Results
5/8 requested ranges supported and correct; 3 (Today/Yesterday/Custom) absent. Charts + filter persistence (UI select) verified.

## 9. Logs Validation Results
Search + status/type/order + pagination correct & consistent (UI/API/DB). Date & gateway filters absent. Export presence-only.

## 10. Screenshots Required (capture checklist)
> Capturable via Playwright-MCP (`browser_take_screenshot`) on `#/dashboard`, `#/gateway`, `#/logs`, `#/notifications`.
1. Dashboard with stat cards + volume chart (per period).
2. Gateway list showing Connected/Activated badges (Twilio/Clickatell connected).
3. Gateway detail pane — credential fields + Connect/Activate/Disconnect/Deactivate.
4. **D2 repro:** invalid-credential save → error toast (capture HTTP 500 in devtools network).
5. Logs table + status filter + "View Log" (note: dialog uses row data; `/logs/{id}` API itself fatals — D1).
6. Notifications: User Events list, Integrations cards, Settings form.
7. Help menu (Documentation / Get Support / Feature Request) — D5.

---

## 11. Recommendations & Risk Assessment

**Must-fix before release**
1. **D1** — `Logs::get_item()`: load the model via `find($id)`/instantiate-then-`read()` so `/logs/{id}` returns 200/404, never a fatal. (1-line class of fix; backend-dev.)
2. **D2** — Add `['status' => 401]` (or 400) to every gateway `validate()` `WP_Error`, and to `Settings::update_items` error passthrough, so credential failures are `4xx`. Prevents false fatal-error alerts and gives the UI a correct error class.

**Should-fix**
3. **D3** — Make `Clickatell::validate()` actually verify the key (a balance/identity GET), matching Twilio/Vonage/Plivo. Otherwise the UI lies about "Connected".
4. **D4** — Rename "Delivered" → "Accepted/Sent", or implement provider DLR webhooks before claiming delivery. Delivery-rate currently measures gateway acceptance.

**Quality / coverage gaps to close**
5. **Integration coverage:** add a WC/Dokan order→SMS fixture test (status change → `SmsStat` + idempotency meta).
6. **CSV export integrity:** assert headers, row count vs `total`, and a large-dataset export.
7. **Fault injection:** add network-failure/timeout simulation (mock the gateway HTTP layer) to validate retry/error messaging — **note: the plugin currently has no retry mechanism**; if retries are a product requirement, they are **missing** and should be specced.
8. **Non-admin authz:** seed a subscriber session to assert `manage_options` rejection for a logged-in low-priv user (currently only unauth is covered).

**Product gaps vs. the brief (decisions for PM, not bugs)**
- Message **delivery lifecycle + DLR**, **pending/queued** states, **bulk gateway ops**, **Today/Yesterday/Custom** analytics, **logs date/gateway filters**, **in-app notification inbox** (incl. login/logout, read/unread), **settings reset**, **in-plugin support/feature forms** — all **absent**. If any are committed scope, they are net-new features, not defects.

**Risk rating:** **Medium.** Core flow is reliable and well-covered; the two ship-blockers are small, well-localized fixes. Primary residual risk is the **gap between the brief's expected feature set and the shipped product** — align scope before sign-off.

---

*Automated suite to reproduce: `npm run test:e2e`. Coverage matrix: `tests/e2e/COVERAGE.md`. Live probes executed via `wp eval-file` against `texty/v1` (non-destructive; gateway state snapshotted & restored).*
