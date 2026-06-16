# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Texty is a WordPress SMS notification plugin: PHP plugin under `Texty\` (`includes/`) plus a React admin SPA (`src/`). Targets PHP 7.4+, WP 6.8+.

> **Pick the right skill before you start:**
>
> | Skill | When to invoke |
> |---|---|
> | `frontend-dev` | Any read/edit under `src/**` — React, TypeScript, Tailwind, plugin-ui, charts, REST calls, routing. |
> | `backend-dev` | Any read/edit under `includes/**` or `texty.php` — PHP classes, REST controllers, gateways, notifications, integrations, hooks, DataLayer. |
> | `code-review` | Reviewing code — single file, diff, branch, or full PR. **Both "pr review" and "code review" map to this skill.** Covers WPCS/security violations, the 5 frontend rules, backward-compatibility breaks, fatal-error risks, and PR-level merge readiness. |
>
> Invoke before the first tool call so the rules are in your head as you write/review the first line, not the third.

> **After edits, do not auto-run verification commands** (`npm run typecheck`, `npm run build`, `composer phpcs`, `php -l`, etc.). Stop after the Edit/Write. Two contexts where they DO run automatically: (1) inside `code-review` / PR review intent — those skills' "Pre-merge gates" require running them as part of the verdict; (2) when the user explicitly asks ("check types", "build it", "run phpcs"). Outside those two, end the turn after the edit.

## Common commands

```bash
# Frontend (wp-scripts / webpack, output to dist/)
# All commands below are USER-TRIGGERED — never auto-run after an edit.
npm run start            # dev build with watch
npm run typecheck        # tsc --noEmit (strict mode, src/** only)
npm run build            # production build
npm run makepot          # regenerate languages/texty.pot
npm run pot2json         # POT → JSON for JS i18n

# PHP
composer install         # also runs Mozart in dev mode (post-install-cmd)
composer phpcs           # WP coding standard (phpcs.xml) — excludes src/, dist/, vendor/
composer phpcbf          # auto-fix

# Release zip
bin/build.sh             # produces build/texty.zip (uses yarn under the hood)
```

There is no PHP test suite. To verify a single PHP file: `vendor/bin/phpcs -p -s includes/Path/To/File.php`.

## Architecture

### Bootstrap
`texty.php` defines the `Texty` singleton (accessed via the global `texty()` helper). On `plugins_loaded` it instantiates three top-level objects:
- `Texty\Admin` (only when `is_admin()`) — registers the admin menu and enqueues `dist/index.js`.
- `Texty\Api` — registers REST controllers under namespace `texty/v1` on `rest_api_init`.
- `Texty\Dispatcher` — wires WP/WC/Dokan events to notification classes and registers integrations conditionally on `class_exists()` checks.

The singleton lazily exposes three registries: `texty()->gateways()`, `texty()->settings()`, `texty()->notifications()`. Always go through these — they own the option-key constants and the registration lifecycle.

### Send pipeline (`Texty\Gateways::send`)
Every SMS — whether from an integration, the REST `Send` endpoint, or a notification — funnels through this method, which fires this exact sequence of hooks: `texty_sms_to` → `texty_sms_message` → `texty_pre_send_sms` (short-circuits if non-null) → `texty_before_send_sms` → gateway `send()` → `texty_after_send_sms` → `texty_send_sms_failed` (only on `WP_Error`). The dispatcher subscribes to `texty_after_send_sms` to write an `SmsStat` row, so any new send path automatically gets logged.

### Gateways (`includes/Gateways/`)
Gateways implement `GatewayInterface` (`send`, `name`, `logo`, `description`, `get_settings`, `validate`). Built-ins (Twilio, Vonage, Plivo, Clickatell, plus `Fake` when `WP_DEBUG`) are registered lazily inside `Gateways::all()`; third parties register on the `texty_register_gateways` action. The active gateway key lives in the `texty_settings` option under `gateway`, with credentials stored under a sub-key matching that name.

### Notifications (`includes/Notifications/`)
`Notification` (abstract) is the base for every event type. Subclasses set `$id`, `$group` (`wp` / `wc` / `dokan`), `$default` message, `$default_recipients`, and override `replacement_keys()` to map `{token}` → object method. The `Notifications` registry maps an event ID (e.g. `registration`, `order_admin_processing`) to a class. Per-notification user config (enabled, message template, recipients) lives in the `texty_notifications` option, keyed by ID.

`Notification::send()` is the standard flow (enabled check → recipient/message filters → loop). **`WC\Base` overrides `send()` and uses an `_texty_{id}` order meta as an idempotency flag** — modify cautiously; that flag is what prevents duplicate sends when an order status changes more than once.

### Integrations (`includes/Integrations/`)
WooCommerce and Dokan are only constructed if their main class exists. Each integration:
1. Hooks `texty_register_notifications` to add its event IDs to the registry.
2. Subscribes to the platform's status hook (e.g. `woocommerce_order_status_changed`) and dispatches the right notification class.

To add a new integration: hook `texty_register_integrations` (called from `Dispatcher::register_integrations`).

### Persistence (DataLayer)
`SmsStat` extends `WeDevs\WPKit\DataLayer\Model\BaseModel` and is paired with `SmsStatStore`. The factory is bootstrapped in `Texty::init_datalayer()` with prefix `texty`; queries go through `DataLayerFactory::make_store(SmsStat::class)`. The custom table `{prefix}texty_sms_stat` is created on activation by `Install::run()` via `dbDelta`.

Other DB locations: `texty_settings` (gateway + credentials), `texty_notifications` (per-notification config), `texty_installed`, `texty_version`, user meta `texty_phone` (Dokan vendors), order meta `_texty_{id}` (WC idempotency).

### REST API (`includes/Api/`)
All controllers extend `Api\Base`, which gates access on `manage_options`. The `Api` class enumerates them in a fixed list — adding a new controller means appending to that array. Settings updates go through each gateway's `validate()` method before being merged into the `texty_settings` option, so credential validation is the gateway's responsibility, not the API's.

### Mozart-prefixed dependencies
Only Appsero packages are namespaced into `Texty\Dependencies\` via Mozart (`composer.json` `extra.mozart`). The composer post-install hook runs Mozart only in dev mode; `bin/build.sh` runs `composer install --no-dev` after copying so the production zip ships clean autoloads. Don't add new packages to the Mozart `packages` list unless they actually need prefixing.

### Frontend (`src/`)
- Entry `src/index.tsx` mounts a `ThemeProvider` (from `@wedevs/plugin-ui`) + `App` into `#texty-app`, then runs `menuFix('texty')` to keep the WP submenu highlight in sync with the hash route.
- `App.tsx` builds a `createHashRouter` so navigation lives entirely in `#/...` — the WP admin URL stays at `admin.php?page=texty`. Submenus in `Admin\Menu::register_menu` link directly to those hashes.
- Routes are defined in `src/routing/routes.tsx` and exposed through `getRoutes()` which runs them through `applyFilters('texty.routes', routes)` — extensions can add or remove routes via `@wordpress/hooks`.
- `withRouter` HOC in `src/routing/index.tsx` injects react-router props into either an element or a component (mirrors dokan-lite's API for extension parity).
- Path alias `@/*` → `src/*` (configured in both `webpack.config.js` and `tsconfig.json`).
- **`@wedevs/plugin-ui` is a `file:../plugin-ui` link** — that sibling directory must exist for `npm install` to succeed.

### Code style notes
- `phpcs.xml` disables many WordPress-Core strictness rules (Yoda, escape-output, file-name) but **enforces strict comparisons** (`Universal.Operators.StrictComparisons`, `WordPress.PHP.StrictInArray.MissingTrueStrict`) as errors. Use `===` and pass `true` to `in_array`.
- TypeScript runs in strict mode; JS files in `src/` are accepted (`allowJs: true`) but not type-checked (`checkJs: false`).
- Text domain is `texty` (enforced by phpcs).

## Adding features

### Adding a gateway
1. New class in `includes/Gateways/<Name>.php` implementing `GatewayInterface` (`send`, `name`, `logo`, `description`, `get_settings`, `validate`).
2. Register on the `texty_register_gateways` action, or for a built-in append to the array in `Gateways::all()`.
3. Credentials are stored as a sub-array of the `texty_settings` option keyed by the gateway's registry key; `Api\Settings::update_items` calls your `validate()` before persisting, so do credential checks there (return a `WP_Error` to block save).
4. Logging is automatic — `Dispatcher::log_sms` listens on `texty_after_send_sms` and writes an `SmsStat`. Return an array containing one of `sid`, `message-id`, `message_uuid`, `apiMsgId`, or `reference_id` and it will be captured as the row's `reference_id`.

### Adding a notification
1. New class in `includes/Notifications/<Group>/<Name>.php` extending `Texty\Notifications\Notification`. Set `$id`, `$group` (`wp` / `wc` / `dokan` / custom), `$title`, `$default` message template with `{token}` placeholders, `$default_recipients`, and override `replacement_keys()` to map tokens to method names on the bound object (user/order/etc.).
2. Register on `texty_register_notifications` (the manager is passed in): `$notifications->register('your_event_id', YourClass::class)`.
3. Trigger from a `Dispatcher` method or an integration: instantiate, call `set_*` to bind context, then `->send()`.
4. WC subclasses should extend `WC\Base` to inherit the `_texty_{id}` order-meta idempotency flag — don't override `send()` without re-applying that guard, or status changes will fire duplicate SMSs.

### Adding an integration
1. New class in `includes/Integrations/<Name>.php`. In its constructor, `add_action('texty_register_notifications', ...)` to add notifications, and subscribe to the platform's status hook to fan out to those notifications.
2. Wire it from `Dispatcher::register_integrations` behind a `class_exists()` guard on the host plugin's main class — never assume the host plugin is loaded.
3. Add a new group to `Notifications::get_groups()` if the UI should display it as a tab.

### Adding a REST endpoint
1. New controller in `includes/Api/<Name>.php` extending `Api\Base`. Set `$this->namespace = 'texty/v1'` and `$this->rest_base = '...'` in the constructor; implement `register_routes()` and use `[$this, 'admin_permissions_check']` as the permission callback.
2. Append the FQCN to the array in `Api::__construct` — that's the only place controllers are wired in.
3. From the frontend, call via `apiFetch<TResponse>({ path: '/texty/v1/...', method: 'GET' })`. The minimal `apiFetch` types live in `src/types/assets.d.ts`; widen them if you need more options.

### Adding an admin page (route)
1. Build the page in `src/pages/<name>/` (lowercase, hyphenated for multi-word — e.g. `src/pages/dashboard/`, `src/pages/quick-send/`). The page entry is `index.tsx`; co-locate page-only components beside it (this is the convention `pages/dashboard/` follows). Reach for `src/components/` only when something is truly cross-page.
2. Add a `TextyRoute` entry to `src/routing/routes.tsx` — `path` is the hash fragment (`/foo`), `id` and `title` drive the admin submenu.
3. Add the matching submenu entry in `Admin\Menu::register_menu`'s `$submenus` array — the link is built as `admin.php?page=texty#/<id>`, so the submenu `id` must equal the route path without the leading slash. `menuFix('texty')` in `index.tsx` keeps the active-submenu highlight synced with the hash.

### Frontend conventions
- **UI primitives**: import from `@wedevs/plugin-ui` first; only fall back to `@wordpress/components` for things plugin-ui doesn't have. plugin-ui is base-ui, not radix — `Tooltip` self-wraps in `TooltipProvider` (don't double-wrap), `TooltipTrigger`/`DropdownMenuTrigger` use the `render={<Component .../>}` prop instead of `asChild`.
- **Toasts**: `import { toast } from '@wedevs/plugin-ui'` (sonner under the hood) and mount one `<Toaster />` in `App.tsx`. `react-toastify` is intentionally not a dependency.
- **Charts**: wrap recharts in plugin-ui's `ChartContainer` so the theme tokens (`var(--color-<key>)`) flow in. `recharts` is a direct dependency — import `LineChart`/`XAxis`/etc. from `'recharts'`. The `ChartConfig` type is not re-exported by plugin-ui's index; type config locally as `Record<string, { label?: string; color?: string }>`.
- **Phone input**: `react-phone-input-2` ships its own types; default `country="bd"` matches the dashboard mock.
- **`.tsx` vs `.js`**: webpack's resolve order is `.js, .jsx, .ts, .tsx`, so `Foo.js` shadows `Foo.tsx` in the same directory. When migrating a `.js` file to `.tsx`, **delete the old `.js`** or webpack will keep picking it. New code should be `.tsx`.
- **Effects with async work**: don't make the `useEffect` callback `async` (returning a promise breaks cleanup). Define an inner `async` function and call it; gate state updates behind a `cancelled` flag set in the cleanup function.
- **i18n**: every user-facing string goes through `__('...', 'texty')` from `@wordpress/i18n`. After string changes run `npm run makepot && npm run pot2json`.
