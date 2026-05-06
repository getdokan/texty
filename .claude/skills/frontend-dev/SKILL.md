---
name: frontend-dev
description: |
  MUST invoke before any read or edit that touches the Texty frontend. The 5 coding rules in this skill (text-domain `texty`, arrow functions, try/catch, full types, Tailwind utilities) apply to every line of frontend code — load them first so the very first edit follows them, not the third one.

  TRIGGER (any of these = invoke immediately, before tools):
  - File path matches `src/**` or `src/**/*.{ts,tsx,js,jsx,css,scss}`
  - File path matches `**/*.tsx`, `**/*.ts`, `package.json`, `tsconfig.json`, `webpack.config.js`, `postcss.config.js`, `tailwind.config.*`, `.prettierrc`
  - Task involves: React, JSX, TypeScript, Tailwind, plugin-ui, recharts, react-router, hash routing, admin SPA, dashboard, settings page, notifications page, tools page, toast, chart, REST call, apiFetch, useEffect, useState, custom hook, route, withRouter, menu sync, i18n string, .pot regen
  - User says: "add a page", "build a component", "wire up an endpoint", "fetch from texty/v1/...", "add a chart", "add a toast", "convert .js to .tsx", "refactor this component", "fix a TypeScript error in src/", "style this with Tailwind", "add a route"

  SKIP (do NOT invoke):
  - PHP-only edits in `includes/**`
  - Gateway / notification / integration class additions (PHP-side)
  - Build / release / deploy tasks (`bin/build.sh`, version bumps)
  - Pure docs edits (`README.md`, `CLAUDE.md`, `readme.txt`, `*.pot`)

  IMPORTANT: When this skill applies, invoke it BEFORE the first Read or Edit on a frontend file — not after. The rules need to be in your head before you write the first line, otherwise you will produce code that has to be refactored.
---

# Texty frontend conventions

The Texty admin UI is a React 18 + TypeScript SPA bundled by `wp-scripts` (webpack), mounted at `#texty-app`, and routed with `createHashRouter`. UI primitives come from `@wedevs/plugin-ui` (a base-ui–powered ShadCN-style kit), styled with Tailwind v4. Package manager is **npm**. Follow the rules below — they encode mistakes already made and corrected in this codebase.

> **React version:** the project pins to React 18 (`@types/react: ^18.3.x`). Don't bump to 19 or use React-19-only APIs (`use()`, ref-as-prop, new `<form action>` semantics). The React 19 migration is a planned upgrade feature, not something to slip into incidental changes. Use the top-level `overrides` block in `package.json` to enforce a single React 18 types version across the dep tree if `@wordpress/*` drifts.

## Before you start

`npm run start` runs the watch-mode dev build. There is no separate test suite for the frontend.

**Use npm.** `npm install` is the install command; `package-lock.json` is the lockfile.

## Coding rules (non-negotiable)

These five rules apply to **every** file you write or edit under `src/**`. Treat them as failures the build doesn't catch.

### 1. Text domain is always `'texty'`

Every translatable string passes through `@wordpress/i18n` with the literal text domain `'texty'` — never an empty string, never a different slug, never a variable.

```tsx
// ✅
toast.success(__('Saved.', 'texty'));
const label = __('Phone Number', 'texty');

// ❌
toast.success(__('Saved.'));            // missing domain
toast.success(__('Saved.', 'plugin'));  // wrong domain
```

`phpcs` enforces this on the PHP side; on the JS side it is on you. After string changes run `npm run makepot && npm run pot2json`.

### 2. Arrow functions only

Components, handlers, and helpers are arrow functions assigned to `const`. No `function` declarations.

```tsx
// ✅
type Props = { title: string; onClose: () => void };

const Banner = ({ title, onClose }: Props) => (
  <div className="rounded-md border p-4">
    <h2>{title}</h2>
    <button onClick={onClose}>×</button>
  </div>
);

export default Banner;

// ❌
function Banner({ title, onClose }: Props) { ... }
```

This applies to event handlers, effect bodies, and utility helpers too — `const handleSubmit = async (e) => { ... }`, `const formatNumber = (n: number) => n.toLocaleString();`.

### 3. `try` / `catch` for every async boundary

Any `await`, `.then()`-style call, or other failable operation must be wrapped in `try / catch / finally`. Never let a promise reject silently and never use bare `.catch()` chains.

```tsx
// ✅
const handleSubmit = async (e: FormEvent) => {
  e.preventDefault();
  setSending(true);
  try {
    const resp = await apiFetch<SendResponse>({ path: '/texty/v1/send', method: 'POST', data });
    if (resp.success) {
      toast.success(__('Message sent.', 'texty'));
    } else {
      toast.error(resp.message ?? __('Could not send.', 'texty'));
    }
  } catch (err) {
    console.error(err);
    toast.error(__('Failed to send message.', 'texty'));
  } finally {
    setSending(false);
  }
};
```

Loading flags reset in `finally`, not in both branches. Surface errors to the user via `toast.error` or an inline banner — never just `console.error`.

### 4. Full type coverage on every function and component

No implicit `any`, no `Function`, no untyped props, no untyped event handlers. Every function gets explicit parameter types and (when not obvious from the body) an explicit return type. Every component has a `Props` type.

```tsx
// ✅
import { type ChangeEvent, type FormEvent, type ReactNode } from 'react';

type StatCardProps = {
  icon: ReactNode;
  value: ReactNode;
  label: string;
  hint?: string;
  loading?: boolean;
};

const StatCard = ({ icon, value, label, hint, loading }: StatCardProps) => { ... };

const formatNumber = (n: number): string => n.toLocaleString();

const onChange = (e: ChangeEvent<HTMLInputElement>): void => {
  setValue(e.target.value);
};
```

**Use named imports for React event types** — prefer `import { type FormEvent, type ChangeEvent } from 'react'` over `React.FormEvent` / `React.ChangeEvent`. Both work on React 18; the named form is required for React 19 (TS6385) and produces cleaner stack traces, so it's the safer default today. Soft preference now, hard rule when the project upgrades.

Run `npm run typecheck` before considering work done — the webpack build will silently emit type errors, so `tsc --noEmit` is the real gate.

### 5. Tailwind utilities for all styling

Every visual style is a Tailwind utility class. No inline `style={{...}}` (except dynamic values that genuinely can't be expressed as a class — chart colors, progress widths, computed `transform`). No new `.css` / `.scss` files for component styling. No CSS modules.

```tsx
// ✅
<div className="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
  <h3 className="m-0 text-base font-semibold text-gray-900">{title}</h3>
</div>

// ❌
<div style={{ borderRadius: 12, padding: 24, background: '#fff' }}>
<div className="my-component">  // and a hand-rolled .my-component class
```

**Composing classes conditionally — always use `cn` from `@wedevs/plugin-ui`.** Never use template literals, string concatenation, or array `.join(' ')` for dynamic className composition. `cn` deduplicates, drops falsy values cleanly, and resolves Tailwind conflicts via `tailwind-merge` under the hood.

```tsx
// ✅
import { cn } from '@wedevs/plugin-ui';

<div
  className={cn(
    'rounded-md border px-4 py-2',
    isActive && 'bg-blue-500 text-white',
    isDisabled && 'opacity-50 cursor-not-allowed',
    className,                              // forward incoming className last
  )}
/>

{items.map((item, i) => (
  <li
    className={cn(
      'flex items-center gap-2 px-3 py-2',
      i !== items.length - 1 && 'border-b border-gray-100',
    )}
  />
))}

// ❌
<div className={`rounded-md border ${isActive ? 'bg-blue-500' : ''}`} />          // template literal
<div className={'rounded-md ' + (isActive ? 'bg-blue-500' : '')} />                // concatenation
<div className={['rounded-md', isActive && 'bg-blue-500'].filter(Boolean).join(' ')} />  // ad-hoc join
<div className={classNames('rounded-md', { 'bg-blue-500': isActive })} />          // classnames lib (we use cn)
```

`cn` accepts: strings, falsy values (skipped), nested arrays, objects (`{ 'class': condition }`). Tailwind utilities are scoped to `#texty-app, .pui-root` (see "Tailwind scoping" below).

### 6. Icons come from `lucide-react`

Every icon in the UI is a `lucide-react` component. No `@wordpress/icons`, no inline SVG (except the brand logo in `Header.tsx`), no emoji as icons, no other icon libraries.

```tsx
// ✅
import { Check, MessageSquareText, Settings, Info } from 'lucide-react';

<Check className="size-4 text-emerald-500" />
<MessageSquareText className="size-5" />
<Settings className="size-4" />

// ❌
import { check, settings } from '@wordpress/icons';        // wrong library
<svg viewBox="0 0 24 24" fill="currentColor">...</svg>     // inline SVG
<span>✓</span>                                              // emoji as icon
<i className="dashicons dashicons-yes" />                   // dashicons
```

**Sizing** uses Tailwind utilities — `size-3.5`, `size-4`, `size-5`, `size-6`. Don't pass numeric props (`size={20}`); use `className="size-N"` so the size scales with the design tokens and stays consistent across the app.

**Color** via `text-*` utilities — `text-gray-500`, `text-emerald-500`, `text-violet-600`. Lucide icons inherit `currentColor` so this just works.

**If lucide doesn't have the icon you need:**
1. Check the [lucide gallery](https://lucide.dev/icons/) — coverage is very wide (~1500 icons).
2. If genuinely missing, inline an SVG **with a comment explaining why lucide doesn't cover it**.
3. Don't pull in another icon library just to get one icon.

**Why this matters:** lucide ships as tree-shakable named exports, so unused icons don't bloat the bundle. Mixing icon libraries doubles SVG runtime cost and produces visually inconsistent strokes/proportions across the UI.

### 7. Template literals for string interpolation

When building a string with dynamic values, use a template literal (backticks `${}`) — never `+` concatenation. Plain single-string literals (`'foo'`, `"bar"`) are still fine; this rule only kicks in when there's an embedded expression.

```tsx
// ✅
const path = `/texty/v1/metrics?period=${period}`;
const greeting = `Hello ${user.name}!`;
const url = `+${phone.replace(/\D/g, '')}`;
const filename = `texty-export-${date}.csv`;
const label = `${formatNumber(count)}%`;

// ❌
const path = '/texty/v1/metrics?period=' + period;
const greeting = 'Hello ' + user.name + '!';
const url = '+' + phone.replace(/\D/g, '');
const filename = 'texty-export-' + date + '.csv';
const label = formatNumber(count) + '%';
```

**Why:** faster to scan (no `+ ' ' +` glue noise), no risk of missing whitespace between segments, and `${}` makes embedded expressions visually distinct from the literal text. Don't reach for `String.prototype.concat()` either.

For multi-line strings, template literals are also the only sane option:

```tsx
// ✅
const tooltip = `${point.date}
Number of SMS: ${point.count}`;

// ❌
const tooltip = point.date + '\n' + 'Number of SMS: ' + point.count;
```

### 8. Always use braces for `if` / `else` / `for` / `while`

Every control-flow body gets `{ ... }`, even when it's a single statement. No single-line `if (x) return;`, no inline `if (x) doThing();`, no braceless `else`/`for`/`while`. Loop and conditional bodies are visible blocks even when they're one line.

```tsx
// ✅
if (cancelled) {
  return;
}

if (!data) {
  return null;
}

if (onConfigure) {
  onConfigure();
}

if (isAdmin) {
  hydrateAdminUI();
} else {
  hydrateGuestUI();
}

for (const item of items) {
  process(item);
}

// ❌
if (cancelled) return;
if (!data) return null;
if (onConfigure) onConfigure();
if (isAdmin) hydrateAdminUI();
else hydrateGuestUI();
for (const item of items) process(item);
```

**Why:** adding a second statement to a braceless body is the textbook source of "I changed two things and only one happened" bugs. Diffs read more cleanly when the block boundary is already there. Search-and-replace over conditionals is safer. The cost (one extra line per branch) is negligible.

This rule does **not** apply to ternaries (`cond ? a : b`) or to JSX conditional rendering (`{cond && <Foo />}`) — those are expressions, not statements. Use those when you want concise inline branching; use a full `if` block when you have a statement to run.

### 9. React runtime imports come from `@wordpress/element`, not `react`

Every **runtime** React API — hooks (`useState`, `useEffect`, `useCallback`, `useMemo`, `useRef`, `useReducer`, `useContext`, `useLayoutEffect`, `useId`, `useTransition`, `useDeferredValue`, `useSyncExternalStore`), top-level helpers (`Fragment`, `forwardRef`, `memo`, `lazy`, `Suspense`, `createContext`, `cloneElement`, `createElement`, `isValidElement`, `Children`, `createRef`, `startTransition`), and portal helpers (`createPortal`) — is imported from `@wordpress/element`, never from `react`.

**Type-only** imports (`ReactNode`, `ReactElement`, `ComponentType`, `ChangeEvent`, `FormEvent`, `MouseEvent`, `KeyboardEvent`, `RefObject`, `MutableRefObject`, `Dispatch`, `SetStateAction`, etc.) still come from `react` — `@wordpress/element` doesn't re-export them. Use `import type` so they get erased at build time.

**Fallback rule:** if a runtime API genuinely isn't re-exported by `@wordpress/element` (rare — but e.g. `useInsertionEffect`, `act`, very-new React-18.3+ APIs), then importing from `react` is acceptable. Verify first by checking `node_modules/@wordpress/element/build-types/react.d.ts` (or attempting the `@wordpress/element` import) — don't assume it's missing. Default is `@wordpress/element`; `react` is the escape hatch.

```tsx
// ✅
import { useState, useEffect, useCallback, useMemo, useRef, Fragment } from '@wordpress/element';
import type { ChangeEvent, FormEvent, ReactNode, ComponentType } from 'react';

const Foo = ({ children }: { children: ReactNode }) => {
  const [value, setValue] = useState('');
  const onChange = (e: ChangeEvent<HTMLInputElement>) => setValue(e.target.value);
  return <input value={value} onChange={onChange} />;
};

// ❌
import { useState, useEffect } from 'react';                        // runtime hook from react
import { Fragment, cloneElement, isValidElement } from 'react';     // helpers from react
import React, { useState } from 'react';                            // default + hooks from react
```

**Why:** `@wordpress/element` is the canonical React surface inside WordPress — it routes through the shared `wp.element` global, ensures every plugin uses the *same* React instance as core/Gutenberg (no duplicate copies, no hook-mismatch errors), and makes the bundle marginally smaller because `react` is provided as an external. Mixing `from 'react'` runtime imports with the WP-provided React is the classic source of "Invalid hook call" errors when the plugin loads alongside the block editor.

**Migration note:** several existing files still `import { useState } from 'react'` (see `useDashboardMetrics.ts`, `QuickSendCard.tsx`, `gateway/index.tsx`, etc.) — they predate this rule. When you next touch one of those files, swap the runtime import to `@wordpress/element` in the same edit. Don't open a sweep PR just for the migration; convert opportunistically.

## File layout

- **Pages** live in `src/pages/<page-name>/` with `index.tsx` as the entry. **Directory names are lowercase, kebab-case for multi-word** (`src/pages/dashboard/`, `src/pages/quick-send/`).
- **Feature-level reusable components / hooks** stay scoped to the feature in `src/pages/<page>/components/` and `src/pages/<page>/hooks/`. These are the building blocks of *one* page (or its nested routes / tabs).
- **App-wide reusable components / hooks** go in `src/components/` and `src/hooks/`. These are imported by **two or more** pages or features.
- **Types**: shared types in `src/types/*.d.ts`. Page-local types in `src/pages/<page>/types.ts`.
- **Utilities**: `src/utils/`. Path alias `@/*` → `src/*` is configured in both `tsconfig.json` and `webpack.config.js`.

### Where does this component / hook belong?

Decide by **scope of use**, not by how generic it looks:

| Used by | Lives at |
|---|---|
| One page only (incl. its tabs / nested routes) | `src/pages/<page>/components/` (or `hooks/`) |
| Two or more pages | `src/components/` (or `src/hooks/`) |

Start every new piece in the page it's first used in (`src/pages/<page>/components/`). **Promote it to `src/components/` only when a second page imports it** — not before. Premature globalization couples unrelated pages and produces "everything component" sprawl in `src/components/`. Going the other way (page-local → global) is a one-line move; going back from global → page-local is a refactor.

### Page directory structure (mandatory for new pages)

Every page directory follows this layout. Create `components/` and `hooks/` as soon as you introduce a reusable component or a custom hook — don't let them spread across the page root.

```
src/pages/<page-name>/
├── index.tsx              # page entry (default-exported component)
├── <SectionA>.tsx         # tab bodies / route-level subpages (top-level only)
├── <SectionB>.tsx
├── types.ts               # page-local TS types & contracts
├── components/            # reusable components used by 2+ files in the page
│   ├── <Card>.tsx
│   └── <Row>.tsx
└── hooks/                 # custom hooks scoped to this page
    └── use<Thing>.ts
```

**Canonical example: `src/pages/notifications/`** —

```
notifications/
├── index.tsx                 # Tabs container
├── UserEvents.tsx            # tab body
├── Integrations.tsx          # tab body
├── Settings.tsx              # tab body
├── IntegrationDetail.tsx     # nested route page
├── types.ts                  # NotificationItem, NotificationsResponse, …
├── components/
│   ├── IntegrationCard.tsx
│   ├── NotificationGroup.tsx
│   └── NotificationRow.tsx
└── hooks/
    └── useNotifications.ts
```

**Rules for the structure:**

1. **Top-level files = page entry, top-level subpages, types.** Anything that the page's own `index.tsx` (or its tab bodies / route children) imports directly. One file per top-level concern. Don't dump every component here.
2. **`components/` = presentational pieces reused by 2+ siblings**, or that are clearly "primitives" of the page (cards, rows, list items, modals scoped to this page). Anything one-off used only by a single sibling can stay co-located with that sibling — but if it's worth giving its own file, it almost always belongs in `components/`.
3. **`hooks/` = page-local custom hooks.** If `index.tsx` and one tab body share fetch + mutation logic, extract a `use<Thing>.ts` into `hooks/`. Don't leave hooks at the page root.
4. **Promote to `src/components/` or `src/hooks/`** only when a *second* page imports it — see the "Where does this component / hook belong?" decision table above. A component looking generic isn't enough; the trigger is real cross-page reuse.
5. **Imports cross the boundary as `'./components/<Name>'` / `'./hooks/<Name>'`** — never reach laterally between two pages' subdirs.

The dashboard page (`src/pages/dashboard/`) currently keeps section components flat at the page root. That's a legacy layout — when you next touch it, fold reusable bits into `components/`. New pages always start with the structure above.

## The `.js` → `.tsx` shadow trap

Webpack's resolve order is `['.js', '.jsx', '.ts', '.tsx']`. **`Foo.js` will shadow `Foo.tsx` in the same directory**, even if `tsconfig.json` and your imports look correct. When migrating a JS file:

1. Write the new `.tsx`.
2. **Delete the old `.js`** — don't rename, don't leave it.
3. `grep -rn "from.*'.../Foo'" src/` to confirm nothing imports the old extension explicitly.

If a fresh import "isn't picking up changes," check for a same-named `.js` file first.

## UI primitives — `@wedevs/plugin-ui`

Default to plugin-ui imports. Reach for `@wordpress/components` only when plugin-ui has no equivalent.

```tsx
import {
  Button, Card, CardContent, CardHeader, CardTitle,
  Field, FieldLabel, FieldContent,
  Input, Textarea,
  Select, SelectTrigger, SelectValue, SelectContent, SelectItem,
  Skeleton,
  Tooltip, TooltipTrigger, TooltipContent,
  Toaster, toast,
  ChartContainer,
} from '@wedevs/plugin-ui';
```

### Base-ui gotchas (not radix!)

plugin-ui wraps `@base-ui/react`, **not** Radix. Two specific differences:

1. **No `asChild`** — base-ui uses a `render` prop on triggers:

   ```tsx
   // ❌ Wrong (Radix-style)
   <TooltipTrigger asChild>
     <button>...</button>
   </TooltipTrigger>

   // ✅ Right (base-ui)
   <TooltipTrigger
     render={<button type="button" className="..." aria-label="..." />}
   >
     <Info className="size-3.5" />
   </TooltipTrigger>
   ```

   Same pattern for `DropdownMenuTrigger` (see `src/components/Header.tsx` for a multi-prop example).

2. **`Tooltip` self-wraps in `TooltipProvider`** — don't double-wrap. `TooltipProvider` accepts `delay`, not `delayDuration`.

## Toasts

There is no `react-toastify` in this project — it was removed. Use sonner via plugin-ui:

```tsx
import { toast } from '@wedevs/plugin-ui';

toast.success(__('Saved.', 'texty'));
toast.error(__('Could not save.', 'texty'));
```

A single `<Toaster position="top-right" richColors closeButton />` is mounted in `src/App.tsx` — don't add another.

## Charts

`recharts` is a direct dependency. Wrap charts in plugin-ui's `ChartContainer` so the theme tokens (`var(--color-<key>)`) flow into your `Line`/`Bar`/`Area`:

```tsx
import { ChartContainer } from '@wedevs/plugin-ui';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip as RechartsTooltip } from 'recharts';

type ChartConfig = Record<string, { label?: string; color?: string }>;

const chartConfig: ChartConfig = {
  count: { label: __('Number of SMS', 'texty'), color: 'oklch(0.52 0.13 248)' },
};

<ChartContainer config={chartConfig} className="h-[280px] w-full">
  <LineChart data={data} margin={{ top: 10, right: 16, left: 0, bottom: 0 }}>
    <CartesianGrid vertical={false} strokeDasharray="4 4" stroke="#e5e7eb" />
    <XAxis dataKey="key" tickLine={false} axisLine={false} />
    <YAxis tickLine={false} axisLine={false} allowDecimals={false} />
    <RechartsTooltip content={<CustomTooltip />} />
    <Line dataKey="count" type="monotone" stroke="var(--color-count)" strokeWidth={2} dot={false} />
  </LineChart>
</ChartContainer>
```

`ChartConfig` is **not** re-exported from `@wedevs/plugin-ui`'s entry — type it locally as shown.

## REST calls — `apiFetch`

```tsx
import apiFetch from '@wordpress/api-fetch';

type Response = { success: boolean; message?: string };

const resp = await apiFetch<Response>({
  path: '/texty/v1/send',
  method: 'POST',
  data: { to: phone, message },
});
```

The minimal type declaration for `@wordpress/api-fetch` lives in `src/types/assets.d.ts`. Widen it there if you need an option that isn't already declared. All Texty endpoints are gated by `manage_options` server-side — no client-side auth is needed.

## Async work in `useEffect`

The effect callback **must not** be `async` (returning a promise breaks the cleanup contract). Use an inner async function plus a `cancelled` flag:

```tsx
useEffect(() => {
  let cancelled = false;

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await apiFetch<T>({ path: `/texty/v1/foo?period=${period}` });
      if (cancelled) return;
      setState(data);
    } catch (err) {
      if (cancelled) return;
      console.error(err);
      setError(__('Failed to load.', 'texty'));
    } finally {
      if (!cancelled) setLoading(false);
    }
  };

  load();
  return () => { cancelled = true; };
}, [period]);
```

Prefer this over `.then().catch().finally()` chains.

## Phone input

Use `react-phone-input-2` — it ships its own TypeScript types. Default to `country="bd"` for parity with the existing UI mocks; pass through `inputClass`/`buttonClass` Tailwind to match the design tokens. Always store the value with a leading `+` before sending to `/texty/v1/send`:

```tsx
to: phone.startsWith('+') ? phone : `+${phone}`,
```

## Routing & admin menu

Adding a new admin page takes **three** coordinated edits:

1. **Page**: `src/pages/<page-name>/index.tsx` (lowercase, kebab-case).
2. **Route**: append a `TextyRoute` to `src/routing/routes.tsx`. Required fields: `id` (must be unique), `path` (hash fragment, `/foo`), `element`. Optional: `title`, `backUrl`, `header`, `footer`, `icon`.
3. **WP submenu**: append `{ id: 'foo', title: __('Foo', 'texty') }` to the `$submenus` array in `includes/Admin/Menu.php::register_menu`. The link is built as `admin.php?page=texty#/<id>` — **the submenu `id` must equal the route path without the leading slash**, or `menuFix('texty')` (called from `src/index.tsx`) won't sync the active highlight.

Routes flow through `applyFilters('texty_routes', routes)` in `src/routing/index.tsx`, so extensions can add/remove routes via `@wordpress/hooks`.

**Reference page:** `src/pages/testing/index.tsx` exercises every Layout customization point (route props, slot/fill, filters). It's WP_DEBUG-gated in the submenu so it doesn't ship to end users. Copy from it when scaffolding a new page.

### Error pages

`src/pages/error/` contains two boundary pages used by react-router-dom:

| File | When it renders | Wired in |
|---|---|---|
| `NotFound.tsx` | User navigates to a hash that doesn't match any route. | `path: '*'` catch-all child route in `App.tsx`. |
| `ErrorBoundary.tsx` | A component throws during render in any routed page. Uses `useRouteError()` to read the thrown value. | `errorElement: <ErrorBoundary />` on every route entry in `App.tsx` (parent + each child). |

Both render directly inside `AppLayout` (so the WP TopBar shell stays intact) but **bypass the per-page `Layout` wrapper** — the page Layout depends on a real `route` and would be redundant on top of an error/404 card. If you need to hide the WP-admin-bar shell too, swap `errorElement` to a higher route boundary.

The error boundary shows the error message inline plus the JS stack (if available) in a `<pre>` block — the React app is gated behind `manage_options`, so anyone reaching this screen already has the privilege to read the stack. It also `console.error`s the raw error so it shows up in browser devtools alongside the rendered card.

Use `errorElement` on individual child routes (not just the parent) so a render crash on `/foo` only blanks that route's content area, not the whole app shell.

## Layout — `src/layout/`

Every routed page is wrapped in `Layout` by `App.tsx` so each page gets a consistent header / content / footer scaffold. The layout reads from the `TextyRoute` and forwards override props.

### Default chrome

`Layout` renders three regions:

```
<SlotFillProvider>
  <LayoutHeader />     ← title h1 + back link + actions slot
  <main>{children}</main>
  <LayoutFooter />     ← empty by default, fillable via filter
</SlotFillProvider>
```

The header renders `null` if there's no `title` and no `backUrl` — bare pages have no chrome.

### Override props (per-route or per-render)

| Layout prop | Source | Effect |
|---|---|---|
| `title` | `route.title` (default) | Page heading — `text-2xl font-bold text-gray-900` |
| `backUrl` | `route.backUrl` (default) | URL the back link navigates to. Supports `:param` placeholders that get resolved from `useParams()` on click. |
| `backButtonLabel` | `route.backButtonLabel` (default) | Text shown next to the chevron in the back link. Defaults to `__('Back', 'texty')` when omitted — set explicitly to name the destination ("Dashboard", "Orders", etc.). |
| `header` | `route.header` | Replace the entire default header. Pass `<></>` to hide. |
| `footer` | `route.footer` | Replace the default footer. Default is empty. |

The Header component supports `:param` placeholders in `backUrl` — they're substituted from `useParams()` on click, so a path like `/order/:id/edit` with `backUrl: '/order/:id'` navigates back to the same order.

### Slot/Fill — UI extension

The header has one slot for action buttons:

```tsx
<Slot name={`texty_${route.id}_header_actions`} fillProps={{ route }} />
```

**Render UI from a Fill** when extending — fills can use hooks, manage state, and unmount cleanly:

```tsx
import { Fill } from '@wordpress/components';

<Fill name="texty_dashboard_header_actions">
  {(fillProps: unknown) => {
    const { route } = fillProps as { route: TextyRoute };
    return (
      <Button onClick={() => doThing(route)}>
        {__('Custom Action', 'texty')}
      </Button>
    );
  }}
</Fill>
```

Cast `fillProps` inside the render callback — `@wordpress/components` types `Fill`'s children as `(fillProps: unknown) => ReactNode` and doesn't propagate the slot's prop types automatically.

### Filter hooks (value transforms, not UI)

Use filters when you only need to **transform a value** without replacing UI:

| Hook | Args | Purpose |
|---|---|---|
| `texty_routes` | `(routes)` | Add or remove route entries |
| `texty_<route.id>_header_title` | `(title, route)` | Override page title for one route |
| `texty_<route.id>_header_back_url` | `(backUrl, route)` | Override back URL for one route |
| `texty_layout_before_render` | `(pathname, route)` | Side-effect on every render |
| `texty_layout_footer` | `(null)` | Inject content into the global footer slot |

When interpolating route IDs, use `route.id` raw — don't slugify. So `texty_${route.id}_header_title` becomes `texty_texty-dashboard_header_title` for the dashboard route. Consumers match the literal string.

### Slot vs Filter — which one to use?

- **UI extension** (buttons, banners, custom widgets) → `Slot/Fill`. Fills can render anything React.
- **Value transform** (string title, URL, boolean) → `applyFilters`. Filters compose; multiple subscribers each transform the previous value's output.

Don't use filters to render UI (the `as JSX.Element` cast is a code smell). Don't use slots to transform values.

## Tailwind scoping

Tailwind utilities are scoped to `#texty-app, .pui-root` (see `src/base-tailwind.css`) so they don't bleed into the WP admin chrome. If a utility "isn't applying," check the rendered tree — it may be portaled outside that selector. plugin-ui's portal-rendered components (Tooltip, DropdownMenu, Select content, Modal) already inject `pui-root` on their portals, so this is usually only a concern for ad-hoc portals you build yourself.

## Hook names — `snake_case`, prefixed `texty_`

Every `@wordpress/hooks` call (`applyFilters`, `addFilter`, `doAction`, `addAction`) uses **snake_case** with the `texty_` prefix. Same convention as the PHP side — never dot-notation, kebab-case, or camelCase. This keeps JS and PHP hooks searchable as a single namespace and makes it obvious where a hook is consumed.

```ts
// ✅
import { applyFilters, addFilter } from '@wordpress/hooks';

const routes = applyFilters('texty_routes', baseRoutes);
const title  = applyFilters(`texty_${slug}_header_title`, raw, route);

addFilter('texty_dashboard_metrics', 'my-extension/scale', (data) => ({
  ...data,
  sms_sent: data.sms_sent * 2,
}));

// ❌
applyFilters('texty.routes', baseRoutes);                      // dot-notation
applyFilters('textyRoutes', baseRoutes);                       // camelCase
applyFilters('texty-routes', baseRoutes);                      // kebab-case
applyFilters('routes', baseRoutes);                            // missing prefix
applyFilters(`texty.${route.id}.header.title`, ...);           // dot-notation w/ interpolation
```

When interpolating a route ID into a hook name, use `route.id` raw — don't slugify or transform it. The route's own ID is the authoritative key; consumers (`addFilter` callers) match against the literal string. So `texty_${route.id}_header_title` for `route.id = 'texty-dashboard'` produces `texty_texty-dashboard_header_title` — the dash in the middle is intentional and shouldn't be normalized.

The single existing exception was `texty.routes` (predates this rule); it has been migrated to `texty_routes`. Do not introduce new dot-notation hooks.

## i18n

Every user-facing string goes through `__('text', 'texty')` from `@wordpress/i18n`. After string changes:

```bash
npm run makepot     # regenerate languages/texty.pot
npm run pot2json    # convert POT → JSON for JS consumers
```

## Manual verification

When a change touches admin routing or menus, the user clicks through every submenu in the WP admin to confirm `menuFix` keeps the highlight in sync with the hash — that's their manual step.

## Common pitfalls

- **Missing or wrong text domain** — every `__()` / `_n()` / `_x()` call must end with `'texty'`. Rule #1.
- **`function Foo()` declarations** — convert to `const Foo = (...) => ...`. Rule #2.
- **Bare `await` without `try / catch`** — wrap and surface failures via `toast.error`. Rule #3.
- **Implicit `any` on props or handlers** — every function and component gets explicit types. Rule #4.
- **Inline `style={{...}}` or hand-rolled CSS classes** — use Tailwind utilities. Rule #5.
- **Template-literal / concat / `classnames` for dynamic classes** — use `cn` from `@wedevs/plugin-ui`. Rule #5.
- **Icons from `@wordpress/icons`, dashicons, inline SVG, or emoji** — use `lucide-react`. Rule #6.
- **Numeric `size={20}` prop on a lucide icon** — use `className="size-5"` instead so it matches the rest of the app.
- **`+` string concatenation (`'foo' + bar + '!'`)** — use a template literal `` `foo${bar}!` ``. Rule #7.
- **Braceless `if (...) return;` / `if (...) doThing();`** — wrap every control-flow body in `{ ... }`. Rule #8.
- **`import { useState } from 'react'` (or any runtime hook/helper from `'react'`)** — runtime imports come from `@wordpress/element`; only type-only imports (`ReactNode`, `ChangeEvent`, …) stay on `'react'`. Rule #9.
- **`ChartConfig` import error** — type locally; not re-exported by plugin-ui's index.
- **`asChild` / `delayDuration` errors on Tooltip** — plugin-ui is base-ui; use `render={...}` and `delay`.
- **Missing `@wordpress/api-fetch` types** — extend `src/types/assets.d.ts`.
- **`Foo.js` shadowing new `Foo.tsx`** — delete the `.js`.
- **Effect callback returning a promise** — wrap in inner async function with `cancelled` flag.
- **Forgetting to update `Admin\Menu`** when adding a route — the page works but the WP submenu link is missing.
- **Reusable components or hooks left at the page root** — fold them into `src/pages/<page>/components/` and `src/pages/<page>/hooks/`. See "Page directory structure". `notifications/` is the canonical example.
