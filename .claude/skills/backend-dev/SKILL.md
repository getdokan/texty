---
name: backend-dev
description: |
  MUST invoke before any read or edit that touches Texty PHP code under `includes/**`. Encodes the project's namespace, registry, REST, send-pipeline, notification, and gateway conventions plus the WordPress Coding Standards rules `phpcs.xml` enforces.

  TRIGGER (any of these = invoke immediately, before tools):
  - File path matches `includes/**`, `texty.php`, or `**/*.php`
  - Task involves: PHP class, REST endpoint, hook (action/filter), gateway, notification, integration, dispatcher, install/uninstall, DataLayer, SmsStat, dbDelta, wp_options, user_meta, post_meta, Mozart, WPCS, phpcs, composer
  - User says: "add a gateway", "add a notification", "add a REST endpoint", "register a hook", "add a setting", "add an integration", "create a PHP class", "fix this PHPCS warning", "wire up `texty_*` hook", "add a column to `wp_texty_sms_stat`"

  SKIP (do NOT invoke):
  - Frontend-only edits in `src/**` (use `frontend-dev`)
  - Pure docs (`README.md`, `CLAUDE.md`, `readme.txt`)
  - Build / release tasks (`bin/build.sh`, version bumps)

  IMPORTANT: When this skill applies, invoke it BEFORE the first Read or Edit on a PHP file — the rules need to be in your head before you write the first line.
---

# Texty Backend Development

PHP guidance for the Texty plugin. Targets PHP 7.4+, WordPress 6.8+. Coding standards enforced by `phpcs.xml` (WordPress + WordPress-Core ruleset).

## Namespace & file structure

- **Root namespace:** `Texty\`
- **PSR-4:** `Texty\` → `includes/`
- **Mozart:** `Texty\Dependencies\` → `dependencies/` (Appsero only — don't add new packages here unless they actually need prefixing)
- **File path follows namespace:** `Texty\Notifications\WC\CompleteAdmin` → `includes/Notifications/WC/CompleteAdmin.php`

## Bootstrap

`texty.php` defines a `Texty` singleton (one final class). On `plugins_loaded` it instantiates three objects:

- `Texty\Admin` — only when `is_admin()`. Owns the admin menu and asset enqueue.
- `Texty\Api` — registers REST controllers in a fixed array on `rest_api_init`.
- `Texty\Dispatcher` — wires WP / WC / Dokan events to notifications and registers integrations behind `class_exists()` guards.

The singleton lazily exposes three registries via the global `texty()` accessor:

```php
texty()->gateways()       // Texty\Gateways      — gateway registry + send dispatch
texty()->settings()       // Texty\Settings      — texty_settings option
texty()->notifications()  // Texty\Notifications — notification class registry
```

**Always go through these accessors.** They own the option-key constants and registration lifecycle. Don't construct `new Gateways()` etc. directly.

## Coding standards (`phpcs.xml`)

Enforced as **errors** — these will fail `composer phpcs`:

- **Strict comparisons** (`===`, `!==`) — no `==`. Rule: `Universal.Operators.StrictComparisons`.
- **`in_array()` strict mode** — always pass `true` as the third argument. Rule: `WordPress.PHP.StrictInArray.MissingTrueStrict`.
- **Text domain `'texty'`** — every translation function call must end with `'texty'`. Rule: `WordPress.WP.I18n` configured with `text_domain=texty`.

Disabled (don't fight these): Yoda conditions, `WordPress.Security.EscapeOutput.OutputNotEscaped`, file-name rule, short-array-syntax rule. **Use short array syntax `[]`** — long syntax `array()` is allowed but the codebase uses `[]`.

Run `composer phpcs` before submitting. To check one file: `vendor/bin/phpcs -p -s includes/Path/To/File.php`.

## Naming

- **Methods, properties, variables:** `snake_case` (WordPress convention) — `register_routes()`, `$gateway_name`.
- **Classes:** `PascalCase` — `CompleteAdmin`, `SmsStat`.
- **Constants:** `UPPER_SNAKE_CASE` — `TEXTY_VERSION`, `OPTION_KEY`.
- **Hook names:** `snake_case` and **always prefixed with `texty_`** — `texty_after_send_sms`, `texty_register_gateways`. Never use unprefixed hook names; collisions with other plugins are real.

## Class imports — `use` at the top, never inline FQCN

Every class reference outside the current namespace goes through a `use` statement at the top of the file. **Never inline a fully-qualified class name (`\WP_Error`, `\Exception`, `\DateTimeImmutable`) in the body or in PHPDoc.** Every `use` statement gets its own line — don't combine them.

```php
// ✅
namespace Texty\Api;

use DateTimeImmutable;
use Exception;
use Texty\Models\SmsStat;
use WeDevs\WPKit\DataLayer\DataLayerFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Metrics extends Base {

    /**
     * @param  WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_metrics( $request ) {
        try {
            $now = new DateTimeImmutable( 'now', wp_timezone() );
            // ...
        } catch ( Exception $e ) {
            return new WP_Error( 'metrics_error', $e->getMessage() );
        }
    }
}

// ❌
class Metrics extends Base {

    /**
     * @return \WP_REST_Response|\WP_Error           — FQCN in PHPDoc
     */
    public function get_metrics( $request ) {
        try {
            $now = new \DateTimeImmutable( 'now' );  — leading-backslash inline
        } catch ( \Exception $e ) {                  — same
            return new \WP_Error( ... );             — same
        }
    }
}
```

**This applies to PHPDoc too.** `@param \WP_Error $err` is a violation; import `WP_Error` and write `@param WP_Error $err`. PHPCS's `WordPress.NamingConventions.ValidHookName` doesn't catch this — it's on you.

**Why:**
- Imports declare the file's external dependencies up front, so a reader sees what the class touches without scanning the body.
- Renaming a global (e.g., a future `WP_Error` rename) changes one `use` line, not 12 inline references.
- IDE refactors and static analyzers handle `use` correctly; inline FQCNs are easy to mistype (`\WP_Error` vs `\WP_error`) and silently work at runtime.

**Exceptions:**
- The class lives in the same namespace — no import needed at all (just reference by short name).
- A name conflict with an already-imported class — alias it: `use Some\Other\Logger as OtherLogger;`. Don't dodge by inlining.

## Translation (i18n)

**Text domain is always `'texty'`** — never empty, never hardcoded twice, never a variable.

| Function | Use |
|---|---|
| `__( 'Text', 'texty' )` | Return translated |
| `_e( 'Text', 'texty' )` | Echo translated |
| `esc_html__( 'Text', 'texty' )` | Return + HTML-escape |
| `esc_html_e( 'Text', 'texty' )` | Echo + HTML-escape |
| `esc_attr__( 'Text', 'texty' )` | Return + attribute-escape |
| `_n( 'one', 'many', $count, 'texty' )` | Pluralization |
| `_x( 'Post', 'verb context', 'texty' )` | Context-aware |

**Translator comments** — required before any `sprintf()` with placeholders:

```php
/* translators: %s: Recipient phone number */
$message = sprintf( __( 'SMS sent to %s.', 'texty' ), $to );
```

**Never concatenate translated strings** — always `sprintf()`. Run `npm run makepot && npm run pot2json` after string changes.

## Send pipeline (`Texty\Gateways::send`)

Every SMS funnels through this method. Hook chain in order:

```
texty_sms_to             (filter)  — modify recipient
texty_sms_message        (filter)  — modify body
texty_pre_send_sms       (filter)  — short-circuit (return non-null to skip gateway)
texty_before_send_sms    (action)
$gateway->send( $to, $message )
texty_after_send_sms     (action)  — Dispatcher logs to SmsStat here
texty_send_sms_failed    (action)  — only on WP_Error
```

`Dispatcher::log_sms` listens on `texty_after_send_sms` and writes the row, so any new send path is automatically logged. Return an array containing one of `sid`, `message-id`, `message_uuid`, `apiMsgId`, or `reference_id` from your gateway's `send()` and it'll be captured as the row's `reference_id`.

## Adding a gateway

1. Class in `includes/Gateways/<Name>.php` implementing `Texty\Gateways\GatewayInterface` (`send`, `name`, `logo`, `description`, `get_settings`, `validate`).
2. Register on `texty_register_gateways` (or for a built-in, append to `Gateways::all()`):
   ```php
   add_action( 'texty_register_gateways', function ( $manager ) {
       $manager->register( 'mygateway', \My\Plugin\Gateways\MyGateway::class );
   } );
   ```
3. Credentials live as a sub-array of the `texty_settings` option keyed by your registry key. `Api\Settings::update_items` calls `validate()` before persisting — return a `WP_Error` to block save.
4. Logging is automatic. Don't write your own SmsStat call.

## Adding a notification

1. Class in `includes/Notifications/<Group>/<Name>.php` extending `Texty\Notifications\Notification`. Set `$id`, `$group` (`wp` / `wc` / `dokan` / custom), `$title`, `$default` template with `{token}` placeholders, `$default_recipients`. Override `replacement_keys()` to map `'token' => 'method_name'` on the bound object.

   ```php
   namespace Texty\Notifications\WP;

   use Texty\Notifications\Notification;

   class MyEvent extends Notification {

       protected $id    = 'my_event';
       protected $group = 'wp';
       protected $title = 'My Event';

       protected $default = "Hi {user_name}, something happened on {site_name}.";

       protected $default_recipients = [ 'administrator' ];

       public function replacement_keys() {
           return [
               'user_name' => 'display_name',
           ];
       }

       public function set_user( $user_id ) {
           $this->user = get_user_by( 'id', $user_id );
           return $this;
       }
   }
   ```

2. Register on `texty_register_notifications`:

   ```php
   add_action( 'texty_register_notifications', function ( $manager ) {
       $manager->register( 'my_event', MyEvent::class );
   } );
   ```

3. Trigger from `Dispatcher` or an integration:

   ```php
   $class    = texty()->notifications()->get( 'my_event' );
   $notifier = new $class();
   $notifier->set_user( $user_id )->send();
   ```

4. **WC subclasses must extend `Notifications\WC\Base`** — it carries the `_texty_{id}` order-meta idempotency flag. Don't override `send()` without re-applying that guard, or order status changes will fire duplicate SMSs.

## Adding an integration

1. Class in `includes/Integrations/<Name>.php`. Constructor hooks `texty_register_notifications` and the platform's status events.
2. Wire from `Dispatcher::register_integrations` behind a `class_exists()` guard on the host plugin's main class — never assume the host plugin is loaded.
3. Add a group entry to `Notifications::get_groups()` if the UI should display it as a tab.

## REST API

All controllers extend `Texty\Api\Base`, which gates access on `manage_options`. Namespace is `texty/v1`.

Adding a controller:

```php
namespace Texty\Api;

use WP_REST_Server;

class MyResource extends Base {

    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'my-resource';
    }

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'period' => [
                            'description' => __( 'The time range.', 'texty' ),
                            'type'        => 'string',
                            'enum'        => [ 'this_month', 'last_month' ],
                            'default'     => 'this_month',
                        ],
                    ],
                ],
            ]
        );
    }

    public function get_items( $request ) {
        // ...
        return rest_ensure_response( $data );
    }
}
```

Then **append the FQCN to the array in `Api::__construct`** — that's the only place controllers are wired in.

**Permission callbacks are mandatory.** Never omit `permission_callback`. Use `[ $this, 'admin_permissions_check' ]` (inherited from `Api\Base`) for admin-only endpoints.

## Persistence

### Custom table — `{prefix}texty_sms_stat`

Created on activation by `Install::run()` via `dbDelta`. Modified through the `SmsStat` model (extends `WeDevs\WPKit\DataLayer\Model\BaseModel`) and `SmsStatStore`. The factory is bootstrapped in `Texty::init_datalayer()` with prefix `texty`.

Querying:

```php
use Texty\Models\SmsStat;
use WeDevs\WPKit\DataLayer\DataLayerFactory;

$store = DataLayerFactory::make_store( SmsStat::class );
if ( null === $store ) {
    return new \WP_Error( 'store_error', 'Store not available', [ 'status' => 503 ] );
}

$result = $store->query( [
    'per_page'   => -1,
    'date_query' => [
        'column' => 'created_at',
        'after'  => $start,
        'before' => $end,
    ],
] );
```

If you need to add a column, edit both `Install::create_tables()` (the schema) **and** the `SmsStat` model (`$data` defaults + `$casts` + setter/getter pair).

### Options

- `texty_settings` — gateway selection + per-gateway credentials. Read via `texty()->settings()->all()` / `->get($key)` / `->gateway()`. Write goes through `Api\Settings::update_items` so each gateway's `validate()` runs first.
- `texty_notifications` — per-notification config (`enabled` / `message` / `recipients`), keyed by notification ID. Read via `texty()->notifications()->settings()`.
- `texty_installed`, `texty_version` — set by `Install::run()`.

### Meta

- User meta `texty_phone` — vendor phone (Dokan integration).
- Order meta `_texty_{notification_id}` — WC idempotency flag. Set/read via `WC\Base::send()`. Don't repurpose this key.

## Security checklist (every PR)

- [ ] **Permission callback** on every `register_rest_route` — never omitted.
- [ ] **Sanitize** all `$request` params before use: `sanitize_text_field`, `absint`, `sanitize_email`, `wp_kses_post`, `sanitize_key`.
- [ ] **Escape** all output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`. Use `esc_html__` / `esc_attr__` in templates.
- [ ] **Prepared SQL** — every dynamic value through `$wpdb->prepare()`. Direct string interpolation is never acceptable.
- [ ] **Strict comparison** — `===` not `==`, `in_array( $x, $arr, true )`.
- [ ] **Capability checks** at the right level — `manage_options` for admin endpoints, role-specific where appropriate.
- [ ] **Nonce verification** for non-REST form submissions (`wp_verify_nonce`, `check_admin_referer`).

## Extensibility

Apply filters at extension points so the pro plugin (and third parties) can hook in:

```php
// Filter values
$value = apply_filters( 'texty_setting', $value, $key );
$enabled = apply_filters( 'texty_notification_enabled', $enabled, $this->get_id(), $this );

// Action lifecycle events
do_action( 'texty_register_gateways', $manager );
do_action( 'texty_register_notifications', $manager );
do_action( 'texty_register_integrations' );
do_action( 'texty_after_send_sms', $result, $to, $message, $gateway );
```

**Hook prefix is always `texty_`** — never use a generic name. PHPDoc the hook signature so consumers know what they get.

## Backward compatibility

Texty is a published WordPress plugin used by real sites and (likely) extended by a paid pro plugin. **Treat every public/protected method, every `texty_*` hook, every option/meta key, every REST response field as a stable contract.** Breaking these silently breaks live sites on update.

### Hooks are public API

- **Never rename a hook.** Once `texty_after_send_sms` exists, it exists forever. If you need a different shape, *add* a new hook alongside it.
- **Never change a hook's parameter list.** Don't drop a param, don't reorder params, don't change a param's type. Adding a new param at the end is allowed (existing handlers ignore extra args).
- **Never change what a filter is expected to return.** If a filter previously returned an array, it still returns an array.
- **Deprecating a hook?** Trigger both the old and new hook for at least one release, with the old one wrapped in `apply_filters_deprecated()` / `do_action_deprecated()`:
  ```php
  // New hook
  $value = apply_filters( 'texty_new_name', $value, $context );
  // Deprecated alias for one release
  $value = apply_filters_deprecated(
      'texty_old_name',
      [ $value, $context ],
      '1.2.0',
      'texty_new_name'
  );
  ```

### Methods are public API

- **Never rename a public/protected method on a class the pro plugin or third parties might extend** (gateways, notifications, registries, REST controllers).
- **Never add a required parameter** to an existing method — make new params optional with sane defaults.
- **Never tighten visibility** (public → protected, protected → private).
- **Removing a method?** Replace the body with `_deprecated_function()` for one release before deletion:
  ```php
  public function old_method( $arg ) {
      _deprecated_function( __METHOD__, '1.2.0', 'new_method()' );
      return $this->new_method( $arg );
  }
  ```
- **Renaming a class?** Use `class_alias( 'New\Class', 'Old\Class' )` at the bottom of the new file for one release.

### Database is public API

- **Never rename `texty_settings`, `texty_notifications`, `texty_installed`, `texty_version`, `texty_phone`, or `_texty_{id}`.** Existing rows would orphan.
- **Never remove a column from `wp_texty_sms_stat`.** Live tables have data. Add new columns; deprecate old ones in PHP without dropping them.
- **Adding a column or table?** Bump `TEXTY_VERSION` in `texty.php` and update the schema in `Install::create_tables()`. `dbDelta` is idempotent and additive — it adds new columns on activation, but **only if `Install::run()` re-runs**. Wire a version-diff trigger in `Install::run()` if the user is upgrading rather than activating fresh:
  ```php
  $installed_version = get_option( 'texty_version' );
  if ( $installed_version !== TEXTY_VERSION ) {
      $this->create_tables(); // dbDelta adds new columns
      // ... any version-specific migrations
      update_option( 'texty_version', TEXTY_VERSION );
  }
  ```
- **Changing an option's array shape?** Read both the old and new shapes for one release; write the new shape. Migrate on read, not in a one-shot upgrade hook (which can fail mid-run on a large site).

### REST responses are public API

- **Never remove a field from a `prepare_item_for_response`-style response** — clients depend on it.
- **Never rename a field.** Add the new name, leave the old one in place for one release with the same value.
- **Never tighten an `args` schema** (e.g., narrowing an `enum`, lowering `maxLength`) — existing clients will start getting 400s.

### Frontend localized data is public API

If you change `wp_localize_script` data shape (`window.texty` global), the same rules apply — Pro and third-party JS reads it.

## Fatal error prevention

A fatal in a WordPress plugin takes the site down. Ship paranoid code.

### File-level guards

Every PHP file under `includes/` starts with:

```php
defined( 'ABSPATH' ) || exit;
```

(The main `texty.php` already has this — but every new include should too.)

### Third-party class references

Every reference to a class outside `Texty\` (and outside `Texty\Dependencies\`) must be `class_exists()`-guarded **before** the reference, not after:

```php
// ✅ Right
if ( class_exists( 'WooCommerce' ) ) {
    new WooCommerce();
}

// ❌ Wrong — fatals on sites without WC
new WooCommerce();
```

Same for Dokan (`WeDevs_Dokan`), Subscriptions (`WC_Subscriptions`), etc. **Don't trust integrations to load just because their plugin is "usually installed."** Check.

### WordPress / WooCommerce function calls

Functions outside the always-loaded WP core set (e.g., WC functions, admin-only WP functions) should be `function_exists()`-guarded when called from a context that might not have them loaded:

```php
if ( function_exists( 'wc_get_order' ) ) {
    $order = wc_get_order( $order_id );
}
```

`wp_get_current_user()`, `current_user_can()`, `is_admin()`, etc. are always available. WC, BuddyPress, Dokan functions are not.

### Registry / option results may be empty

`texty()` registry calls and `get_option` can return falsy values. Always guard:

```php
$gateway = texty()->settings()->gateway();   // may return '' or false
if ( ! $gateway ) {
    return;
}

$settings = get_option( 'texty_settings', [] );  // may return false if option deleted
if ( ! is_array( $settings ) ) {
    $settings = [];
}
```

### `WP_Error` everywhere

Many WP/WC functions return `WP_Error` on failure instead of throwing. **Always check** before treating the result as the success type:

```php
$result = $gateway->send( $to, $message );
if ( is_wp_error( $result ) ) {
    return $result;  // or log + bail
}
```

The send pipeline already does this — follow the pattern.

### DataLayer factory may fail

`DataLayerFactory::make_store()` returns `null` if the factory wasn't initialized or the model isn't registered. Always guard:

```php
$store = DataLayerFactory::make_store( SmsStat::class );
if ( null === $store ) {
    error_log( 'Texty: SmsStat store not available' );
    return new \WP_Error( 'store_error', 'Store not available', [ 'status' => 503 ] );
}
```

### Activation hook

The activation hook (`Texty::activate`) runs once on plugin activation. **A fatal here locks the user out of the plugin.** Wrap `dbDelta`, option writes, and any cross-plugin lookups in `try/catch`:

```php
public function activate() {
    try {
        $installer = new Texty\Install();
        $installer->run();
    } catch ( \Throwable $e ) {
        error_log( 'Texty activation failed: ' . $e->getMessage() );
        // don't re-throw — let activation succeed even if migration partially fails
    }
}
```

### PHP version

Texty's `Plugin Header` says `Requires PHP: 7.4`. Don't use PHP 8+ syntax (constructor property promotion, named args, enums, `readonly`, `match`, `mixed` type) without first bumping the requirement and confirming the user base.

### Top-level code in plugin file

`texty.php` does work at file load. **Anything that runs at top level — outside hooks — must be defensive.** No DB queries before `plugins_loaded`. No translation calls before `init` (or you'll trigger the WP 6.7 `_load_textdomain_just_in_time` notice).

### Don't `require` files unconditionally inside hot paths

If a class is only needed by an admin screen, don't include it on every front-end page load. Composer autoloading handles this for you — don't fight it with manual `require` calls.

## Documentation

- **PHPDoc on every public/protected method.** `@param` and `@return` types required.
- **`@since` tag** on new public/protected methods and on hook docs that the pro plugin will rely on.
- **Hook docs:** every `apply_filters` and `do_action` gets a docblock describing each param. Example in `includes/Gateways.php`.
- **`@deprecated` tag** on any method/hook scheduled for removal — note the version it became deprecated and the replacement.

## Mozart-prefixed dependencies

Only `appsero/client` and `appsero/updater` are namespaced into `Texty\Dependencies\` via Mozart (`composer.json` `extra.mozart`). The composer post-install hook runs Mozart only in dev mode; `bin/build.sh` runs `composer install --no-dev` after copying so the production zip ships clean autoloads.

**Don't add new packages to the Mozart `packages` list unless they truly need prefixing** (i.e., risk of conflict with another plugin's vendored copy). Most modern composer packages don't.

## Common pitfalls

- **Renaming a hook / method / option key** — breaks the pro plugin and live sites. Add new, deprecate old, never delete in the same release. See "Backward compatibility".
- **Adding a required parameter to an existing public method** — same.
- **Removing a column from `wp_texty_sms_stat`** — live data depends on it.
- **Using a third-party class without `class_exists()` guard** — fatal on sites without that plugin.
- **Treating `texty()->settings()->gateway()` as a non-empty string** — it can be `''` or `false`.
- **Forgetting `is_wp_error()` after a WP/WC API call** — runtime crash on the failure path.
- **PHP 8+ syntax (`readonly`, named args, enums, constructor promotion)** — Texty supports PHP 7.4. Bump the plugin header first if you really need them.
- **Top-level translation calls before `init`** — triggers the WP 6.7 just-in-time warning. Defer to a hook.
- **Activation hook throwing** — locks the user out. Wrap `dbDelta` / option writes in `try/catch`.
- **`==` instead of `===`** — fails `phpcs` as an error.
- **`in_array( $x, $arr )` without `true`** — fails `phpcs`.
- **Missing text domain or wrong domain** — fails `phpcs` (`WordPress.WP.I18n`).
- **Inline `\ClassName` instead of importing via `use`** — `\WP_Error` / `\Exception` / `\DateTimeImmutable` in code or PHPDoc are wrong. Add a `use ClassName;` at the top and reference by short name. See "Class imports".
- **Direct `new Gateways()` / `new Settings()`** — bypasses the singleton; use `texty()->gateways()` / `->settings()`.
- **Forgetting to register a new REST controller in `Api::__construct`** — routes silently never load.
- **Overriding `WC\Base::send()` without re-applying `_texty_{id}` order meta** — duplicate SMSs on every status flip.
- **New PHP package added to Mozart `packages`** without need — bloats the build and slows CI.
- **Hook name without `texty_` prefix** — collision risk; future-you will hate present-you.

## Verification

Before submitting:

```bash
composer phpcs                                # must pass with zero errors
vendor/bin/phpcs -p -s includes/Path/File.php # spot-check one file
php -l includes/Path/File.php                 # syntax check
```

There's no PHP test suite in this repo. Manual smoke test through the WP admin (`Texty > Tools > Send Test Message`) is the practical sanity check for changes that touch the send pipeline.

## Key reference files

- `texty.php` — singleton + bootstrap
- `includes/Gateways.php` — registry + `Gateways::send()` (the send pipeline)
- `includes/Notifications.php` — notification registry + group definitions
- `includes/Settings.php` — option access
- `includes/Dispatcher.php` — event-to-notification wiring + `log_sms`
- `includes/Api.php` — controller list (append here when adding a controller)
- `includes/Api/Base.php` — `admin_permissions_check`
- `includes/Install.php` — `dbDelta` schema + activation hook
- `includes/Models/SmsStat.php` + `SmsStatStore.php` — DataLayer model
- `includes/Notifications/Notification.php` — abstract base for all notifications
- `includes/Notifications/WC/Base.php` — WC base with `_texty_{id}` idempotency
- `phpcs.xml` — coding standard ruleset
- `composer.json` — Mozart config
