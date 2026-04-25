---
name: code-review
description: |
  THIS IS THE PR REVIEW + CODE REVIEW SKILL. Invoke for any review intent — single file, diff, branch, or full pull request — against Texty's standards (WordPress Coding Standards on PHP, the 5 frontend rules in `frontend-dev`). Covers line-level violations, backward-compatibility breaks, fatal-error risks, and PR-level merge readiness.

  TRIGGER — invoke immediately when the user says ANY of these (case-insensitive, with or without a leading slash):
  - "pr review", "PR review", "review pr", "review PR", "review this pr", "review the pr"
  - "code review", "review this code", "review the code", "code review this"
  - "review #123", "review pr #123", "review PR-123", "review my changes", "review my branch", "review this branch", "review this diff"
  - "check this pr", "audit this pr", "audit this code", "audit this file", "audit this diff", "audit this branch"
  - "is this ready to merge", "is this safe to merge", "approve this pr", "approve this", "ship review"
  - "find issues in this branch", "what's wrong with X", "any problems with this"
  - User pastes a GitHub PR URL, a PR number (`#123`), or a diff
  - Task involves: PHPCS / phpcs warnings, security audit, backward-compatibility check, fatal-error audit, architecture compliance, merge readiness, changelog verification, label triage

  This is the ONLY review skill in the project — there is no separate `pr-review`. Both "pr review" and "code review" mean: invoke `code-review`.

  SKIP (do NOT invoke):
  - Active development on a fresh feature (use `backend-dev` / `frontend-dev` — those guide writing, not auditing)
  - Pure git operations (branch / commit / push) without a review intent
---

# Texty Code Review

Audits code against project standards. Invoke for any review intent — single file, diff, branch, or full PR. Consult `backend-dev` and `frontend-dev` for the underlying rules; this skill is the audit checklist + PR-readiness gate.

When the input is a single file or snippet, skip the **PR-level checks** section. When the input is a full PR (description, branch, multiple files), run the whole skill.

---

## 1. Critical violations to flag (line-level)

### Backend PHP — security (CRITICAL)

- **Missing `permission_callback`** on a `register_rest_route` call. Mandatory; never omit. Use `[ $this, 'admin_permissions_check' ]` for admin-only endpoints (inherited from `Api\Base`).
- **Unsanitized `$request` input** flowing into options, meta, queries, or messages. Every param: `sanitize_text_field`, `absint`, `sanitize_email`, `sanitize_key`, `wp_kses_post` — pick the right one for the type.
- **Unescaped output** — `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` in templates and direct output. (`phpcs.xml` disables `WordPress.Security.EscapeOutput.OutputNotEscaped`, so the linter won't catch this — humans must.)
- **Direct SQL without `$wpdb->prepare()`** for any dynamic value. String interpolation into a query is never acceptable.
- **Missing nonce verification** on non-REST form submissions (`wp_verify_nonce`, `check_admin_referer`).
- **Capability check at the wrong level** — admin endpoints must require `manage_options`, not a lower cap.

### Backend PHP — coding standards (ERROR)

These will fail `composer phpcs`:

- **Loose comparison** (`==`, `!=`) — must be strict (`===`, `!==`). Rule: `Universal.Operators.StrictComparisons`.
- **`in_array()` without `true`** — must pass `true` as third argument. Rule: `WordPress.PHP.StrictInArray.MissingTrueStrict`.
- **Wrong text domain** — every translation function must end with `'texty'`. Rule: `WordPress.WP.I18n`.
- **Missing translator comment** before `sprintf()` with placeholders.
- **Concatenating translated strings** — `__('Hello ', 'texty') . $name` is wrong; use `sprintf( __('Hello %s', 'texty'), $name )`.

### Backend PHP — architecture (ERROR)

- **Inline fully-qualified class names** — `\WP_Error`, `\Exception`, `\DateTimeImmutable`, `\WP_REST_Response` in code or PHPDoc. Every external class gets a `use ClassName;` at the top of the file; references in the body and docblocks use the short name. One `use` per line, no inline FQCN dodge. See `backend-dev: Class imports`.
- **Direct instantiation of registry classes** — `new Gateways()`, `new Settings()`, `new Notifications()`. Use `texty()->gateways()`, `->settings()`, `->notifications()`.
- **REST controller missing from `Api::__construct`** array — routes silently won't register.
- **REST controller not extending `Api\Base`** — must inherit `admin_permissions_check`.
- **Hook name without `texty_` prefix** — `apply_filters('my_event', ...)` is wrong; must be `texty_my_event`. Collision risk with other plugins.
- **WC notification overriding `send()` without re-applying `_texty_{id}` order meta** — will fire duplicate SMSs on order status flips.
- **New gateway not registered via `texty_register_gateways`** action.
- **Integration constructed without `class_exists()` guard** for the host plugin's main class.
- **New table column added to schema without updating `SmsStat` model** (`$data`, `$casts`, getter/setter).
- **Package added to Mozart `packages` list without a real namespace-collision reason** — most modern composer packages don't need prefixing.

### Backend PHP — documentation (WARNING)

- **Missing PHPDoc** on public/protected methods.
- **Missing `@since`** on new public/protected methods or new hooks the pro plugin may rely on.
- **Missing hook docblock** above `apply_filters()` / `do_action()` calls — consumers need to know each param.
- **Missing `@deprecated`** on a method/hook scheduled for removal — must note the version it became deprecated and the replacement.

### Frontend (React/TypeScript)

The 7 rules in `frontend-dev` — flag any violation:

1. **Wrong text domain** — every `__()` / `_n()` / `_x()` ends with `'texty'`. Empty domain or wrong slug = ERROR.
2. **`function Foo()` declarations** for components, handlers, or helpers — must be arrow functions assigned to `const`. ERROR.
3. **Bare `await` without `try / catch`** — every async boundary needs `try / catch / finally` with errors surfaced via `toast.error` (not just `console.error`). ERROR.
4. **Implicit `any` / untyped props / untyped event handlers** — every component has a `Props` type, every function has explicit param types. ERROR.
5. **Inline `style={{...}}`** (except genuinely dynamic chart-color/transform values), hand-rolled CSS classes for component styling, OR **template-literal / string concat / `classnames` lib for dynamic className composition**. Use Tailwind utilities + `cn` from `@wedevs/plugin-ui` for any conditional class. ERROR.
6. **Icons not from `lucide-react`** — `@wordpress/icons`, dashicons, inline SVG (except the brand logo in `Header.tsx`), or emoji-as-icon are all wrong. Use named imports from `lucide-react` with `className="size-N"` for sizing. ERROR.
7. **`+` string concatenation for interpolation** (`'/api/' + path` or `'Hello ' + name`) — use a template literal (`` `/api/${path}` ``, `` `Hello ${name}` ``). Plain single-string literals are fine; only the interpolation case is enforced. ERROR.

Plus the framework-specific traps:

- **`React.FormEvent` / `React.ChangeEvent` namespace types** — deprecated in React 19 (TS6385). Use named imports: `import { type FormEvent } from 'react'`.
- **`asChild` prop on plugin-ui triggers** — wrong; plugin-ui is base-ui, use `render={<button .../>}`.
- **`TooltipProvider delayDuration={...}`** — wrong prop and wrong nesting; `Tooltip` self-wraps, and the prop is `delay`.
- **`react-toastify` import** — removed from this project; use `import { toast } from '@wedevs/plugin-ui'`.
- **Direct recharts use without `ChartContainer`** — wraps it so theme tokens (`var(--color-<key>)`) flow.
- **JS hook name not snake_case + `texty_` prefix** — `applyFilters('texty.routes', ...)` / `applyFilters('textyRoutes', ...)` / `applyFilters('routes', ...)` are all wrong. Must be `texty_routes`. Same convention as PHP hooks; see `frontend-dev: Hook names`. ERROR.
- **`useEffect(async () => ...)`** — effect callbacks must not be async. Use inner async function plus `cancelled` flag.
- **`Foo.js` left in place after creating `Foo.tsx`** — webpack resolves `.js` first, so the new file is shadowed.
- **Missing route ↔ submenu sync** — adding `routes.tsx` entry without updating `Admin\Menu::register_menu` (or vice versa). Submenu `id` must equal route path without leading slash.

---

## 2. Backward compatibility (BLOCKING)

A break here is **CRITICAL** — pro plugin or live sites will fail on update. Treat any "yes" without an explicit migration plan as `Request changes`.

- [ ] **No public/protected method renamed** on an extensible class (gateways, notifications, registries, REST controllers).
- [ ] **No required parameter added** to an existing public/protected method.
- [ ] **No method visibility tightened** (public → protected, protected → private).
- [ ] **No `texty_*` hook renamed.** New hook added alongside the old one is fine; replacing is not.
- [ ] **No hook parameter list shrunk or reordered.** Adding a new param at the end is fine.
- [ ] **No filter return-type change** (e.g., previously returned `array`, now returns `string`).
- [ ] **No option key renamed** (`texty_settings`, `texty_notifications`, etc.).
- [ ] **No meta key renamed** (`texty_phone`, `_texty_{id}`).
- [ ] **No column dropped** from `wp_texty_sms_stat`.
- [ ] **No REST response field removed or renamed.** Adding new fields is fine.
- [ ] **No `args` schema tightened** (narrower `enum`, lower `maxLength`) — existing clients will get 400s.
- [ ] **No `window.texty` localized-data field removed or renamed** (Pro JS reads this).

If any item is "no" with a justification, the PR description must include:

- The replacement (new name / new shape).
- A deprecation shim using `_deprecated_function()` / `apply_filters_deprecated()` / `do_action_deprecated()` for **at least one release**.
- A note in the changelog calling out the breaking-change window.

---

## 3. Fatal error prevention

A fatal in a published WordPress plugin = white screen for users. Verify before approving:

- [ ] **`defined( 'ABSPATH' ) || exit;`** at the top of every new PHP file under `includes/`.
- [ ] **Every reference to a third-party class** (`WooCommerce`, `WeDevs_Dokan`, `WC_Subscriptions`, etc.) is `class_exists()`-guarded *before* the reference, not after.
- [ ] **Every `texty()` registry call result** that can be falsy is guarded — `texty()->settings()->gateway()` may return `''` or `false`.
- [ ] **Every WP/WC API call that can return `WP_Error`** is wrapped in `is_wp_error()` before treating the result as success.
- [ ] **`DataLayerFactory::make_store()` result is null-checked** before use.
- [ ] **Activation hook (`Texty::activate`) work is wrapped in `try/catch`** — a throw here locks users out of the plugin.
- [ ] **Schema migration is idempotent** — `dbDelta` is safe to re-run; custom `ALTER TABLE` calls must check `INFORMATION_SCHEMA` first.
- [ ] **No PHP 8+ syntax** in code that must run on PHP 7.4 (constructor property promotion, named args, enums, `readonly`, `match`, `mixed` type) — Texty's plugin header says `Requires PHP: 7.4`.
- [ ] **No translation calls at top-level file load** (before `init`) — triggers the WP 6.7 just-in-time `_load_textdomain_just_in_time` notice.
- [ ] **No new top-level code in `texty.php` or `includes/*.php`** that runs DB queries before `plugins_loaded`.
- [ ] **PHP version** declared in plugin header still matches the syntax actually used.

If the diff touches the activation flow, the send pipeline, or the REST layer, the author should **manually test on a fresh WordPress install** (deactivate → reactivate → fire the change path). Verify they did.

---

## 4. PR-level checks (when reviewing a full PR)

Skip this section when reviewing a single file or snippet.

### Branch hygiene

- **Base branch** is `develop`. PRs against `main` / `master` should be rejected unless it's a hotfix/release.
- **Feature branches** branch off `develop` and should be rebased on the latest `develop` before merge — no merge commits from `develop` polluting history.
- **Single concern per PR** — if the diff touches the dashboard refactor *and* a gateway fix *and* a doc tweak, ask the author to split. Reviewable PRs are small.
- **No unrelated formatting churn** — Prettier/PHPCBF "while I was here" reformats of untouched files make the diff unreadable. Push back.

### PR description checklist

A reviewable Texty PR description should answer:

1. **What changes?** — One sentence summary.
2. **Why?** — Linked issue, bug report, or feature spec.
3. **How was it tested?** — Specific manual steps. There is no PHP test suite, so manual verification is mandatory for backend changes. For UI changes, a browser test (golden path + an edge case) is the bar.
4. **Screenshots / screen recording** — required for any visual change.
5. **Backwards compatibility notes** — does the change rename a hook, change an option shape, alter a REST response? If yes, the PR description must say so explicitly.
6. **Pro-plugin impact** — if the change adds/removes/renames a `texty_*` hook or REST field, the description must call out whether the pro plugin needs an update.

If any of 1–4 is missing, the verdict is **request changes** — not because the code is wrong, but because the PR isn't reviewable yet.

### Pre-merge gates

Run / confirm these locally (CI runs `release.yml` on tag push, not on PR — so you can't lean on CI):

```bash
# PHP
composer phpcs              # WordPress Coding Standards — must be clean
php -l includes/**/*.php    # syntax check (or rely on phpcs)

# Frontend
npm run typecheck           # tsc --noEmit, strict mode
npm run build               # webpack — only the standard "asset size" warnings allowed

# Build artifact (release-candidate PRs only)
bin/build.sh                # produces build/texty.zip
```

PHPCS / typecheck failures block the review — fix-or-document first, before line-by-line.

### Specific things to verify

**Backend changes:**

- Does any new `register_rest_route` have a `permission_callback`?
- New REST controller? Did the author append it to `Api::__construct` and confirm the route shows up at `wp-json/texty/v1`?
- New gateway? Tested with at least one real send (or `Fake` gateway when `WP_DEBUG`)?
- New notification? Manually triggered the source event (user register, comment, order status change) and confirmed delivery?
- Schema change to `wp_texty_sms_stat`? Author bumped `TEXTY_VERSION` so `Install::run()` re-runs `dbDelta` on activation? Existing rows survive the migration?
- New `texty_*` hook? Has a docblock with each param described.
- Mozart `packages` list grew? Genuinely needed, or could the dependency be loaded normally?

**Frontend changes:**

- Build clean? (typecheck + webpack)
- New page added? Three-place edit confirmed: `src/pages/<name>/index.tsx`, `src/routing/routes.tsx`, `includes/Admin/Menu.php` `$submenus`?
- New REST call wrapped in `try / catch` with errors surfaced via `toast.error`?
- Components migrated from `.js` → `.tsx`? Old `.js` files deleted, not left as shadowed dead code?
- Tailwind classes only — no new `style={{}}` (except recharts SVG props), no new `.css` files?
- All strings go through `__('...', 'texty')`? POT/JSON regenerated if user-facing strings changed?

**Cross-cutting:**

- Any `composer.lock` / `package-lock.json` changes? Did the lockfile drift from a real `composer.json` / `package.json` change, or is it accidental?
- Any new env var, build flag, or config file? Documented in `CLAUDE.md` or `README.md`?
- Any commit with hooks bypassed (`--no-verify`)? Block — investigate why a hook was failing instead.

---

## 5. Review approach

1. **Determine scope.** Is this a single file, a diff, or a full PR? Skip section 4 for the first two.
2. **Generate the diff.**
   ```bash
   git diff --stat develop...HEAD
   git diff develop...HEAD -- 'includes/**/*.php'
   git diff develop...HEAD -- 'src/**'
   ```
3. **Run the gates** (full PR only) — `composer phpcs`, `npm run typecheck`, `npm run build`, `php -l <changed.php>`.
4. **Read the PR description first** (full PR only). If it's incomplete, request changes immediately and don't read the diff yet — wait for the author to fill it in.
5. **Scan for critical violations** (section 1) — security first, then architecture.
6. **Walk the BC checklist** (section 2). Any unjustified break = blocker.
7. **Walk the fatal-prevention checklist** (section 3). Any unguarded third-party class / unwrapped `WP_Error` / activation throw = blocker.
8. **Verify REST patterns** when controllers changed: permission_callback, sanitization, schema validation, `WP_Error` returns, controller registration.
9. **Check extensibility** — does the change expose filters/actions where the pro plugin or third parties will need to hook in? `texty_*` prefix on every name?
10. **Review tests / sanity checks.** No PHP test suite in this repo, so manual verification through `Texty > Tools > Send Test Message` (for send-pipeline changes) and browser testing (for UI) is the bar. Push back if missing.
11. **Synthesize a verdict.**

---

## 6. Output format

For each finding:

```text
[severity]: [Specific problem]
Location: path/to/file.php:42  (or src/pages/dashboard/index.tsx:88)
Standard: [Reference — e.g. "code-review §1: REST API > Permission Callbacks", or "frontend-dev: Rule 3 try/catch"]
Fix: [Brief explanation or correct code snippet]
```

### Severity levels

- **CRITICAL** — Security issue, data loss risk, broken functionality, BC break without deprecation, unguarded fatal risk.
- **ERROR** — Standards violation, build will fail, or required pattern missing.
- **WARNING** — Suboptimal approach, missing best practice, or extensibility gap.
- **SUGGESTION** — Improvement opportunity, not a violation.

### Verdict criteria

| Verdict | When |
|---|---|
| **Approve** | All gates green, CRITICAL/ERROR list empty, BC checklist clean, fatal-prevention checklist clean, PR description complete, scope single-concern. |
| **Request changes** | Any CRITICAL or ERROR. Any unjustified BC break (renamed hook/method/option/column, removed REST field, tightened schema). Any unguarded third-party class reference. Missing PR description elements. Failing PHPCS / typecheck. Mixed-concern diff that should be split. |
| **Needs discussion** | Architectural choice that warrants a second opinion (new dependency, new data model, intentional breaking hook signature with migration plan, raising minimum PHP version). Don't approve or block unilaterally — escalate. |
| **DO NOT MERGE** | Touches credentials/secrets, adds a destructive/irreversible migration, drops a database column, removes a public method without deprecation, or relies on infra that isn't ready. State the blocker in plain English. |

End with a one-line verdict.

### Output template (full PR review)

```markdown
## Verdict: [Approve | Request changes | Needs discussion | DO NOT MERGE]

**Summary:** One sentence on what this PR does and whether it's ready.

### Blockers
- [CRITICAL/ERROR] file:line — issue + fix.

### Improvements
- [WARNING] file:line — issue + suggested change.

### Nice-to-have
- [SUGGESTION] file:line — optional refinement.

### Backward compatibility
- [ ] No renamed/removed public methods, hooks, options, meta keys, columns, REST fields
- [ ] Any deprecation uses `_deprecated_function()` / `apply_filters_deprecated()` / `do_action_deprecated()` shim for ≥1 release
- [ ] Pro-plugin impact noted in description (or N/A explicitly)

### Fatal error prevention
- [ ] All third-party class references `class_exists()`-guarded
- [ ] `WP_Error` results checked before use
- [ ] `texty()` registry results null/empty-checked
- [ ] Activation hook wrapped in `try/catch`
- [ ] No PHP 8+ syntax (still PHP 7.4)
- [ ] No top-level i18n / DB calls before `init` / `plugins_loaded`

### PR description / process
- [ ] Description complete (what / why / how tested)
- [ ] Screenshots for visual changes
- [ ] PHPCS green
- [ ] Typecheck green
- [ ] Build green
```

---

## Reviewer principles

> **Correct** — Does it do what the task says? Are the edge cases handled?
> **Secure** — Could a malicious request bypass the permission check? Every input sanitized, every output escaped? Could a SQL injection slip in?
> **Readable** — Will future-you understand this in six months? Are names self-explanatory? Is the file scoped to one concern?
> **Elegant** — Does it match the surrounding architecture, or does it add a new shape that breaks the pattern?
> **Reversible** — If this ships and breaks production, how hard is it to back out? Is there a feature flag, or does it require another deploy?

If a CRITICAL or ERROR is found, the verdict is `Request changes` regardless of how clean the WARNINGs/SUGGESTIONs are. A PR that's secure, correct, readable, and elegant but adds a hidden one-way migration (irreversible schema change without rollback) is **not** ready — push back.
