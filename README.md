# Texty - SMS Notification for WordPress, WooCommerce, Dokan and more

A lightweight SMS notification plugin for WordPress, WooCommerce, and Dokan.

## Table of Contents

- [Description](#description)
- [Installation](#installation)
- [Development Setup](#development-setup)
- [Developer Documentation](#developer-documentation)
- [Frequently Asked Questions](#frequently-asked-questions)
- [Changelog](#changelog)

## Description

Texty is a lightweight SMS notification plugin for WordPress. With so many emails coming to your inbox, often it becomes overwhelming to stay on top of things that matter most. A text notification on your phone may be more desired.

Texty integrates with 3rd party SMS providers to add support for text messaging. When events occur in WordPress, WooCommerce, or Dokan, Texty sends an SMS notification to the right people at the right time.

### Features

- **Multiple SMS Gateways** - Choose from Twilio, Vonage, Plivo, or Clickatell
- **WordPress Notifications** - New user registration and new comment alerts
- **WooCommerce Notifications** - Admin and customer order status notifications
- **Dokan Notifications** - Vendor order status notifications
- **Customizable Messages** - Use template tags to personalize every SMS
- **Developer Friendly** - 20+ action and filter hooks for full extensibility

### Supported Gateways

- [Twilio](https://twilio.com)
- [Vonage](https://vonage.com/communications-apis/) - Formerly Nexmo
- [Plivo](https://www.plivo.com/)
- [Clickatell](https://www.clickatell.com/)

### Supported Events

**WordPress Core**
- New User Registration
- New Comment

**WooCommerce (Admin)** - Processing, Complete, Cancelled, Failed, Refunded

**WooCommerce (Customer)** - On Hold, Processing, Complete, Cancelled, Failed, Refunded

**Dokan (Vendor)** - Processing, Complete, Cancelled, Failed, Refunded

## Installation

### Minimum Requirements

- WordPress 6.8 or greater
- PHP 7.4 or greater

### Automatic Installation

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Texty"
3. Click **Install Now** and then **Activate**

### Manual Installation

1. Download the plugin zip file
2. Extract and upload the `texty` folder to `/wp-content/plugins/`
3. Activate the plugin from the **Plugins** page

### Setup

1. Navigate to **Texty > Settings** in your WordPress admin
2. Select your SMS gateway and enter the API credentials
3. Go to the **Notifications** tab to enable and configure alerts
4. Use the **Tools** tab to send a test SMS

## Development Setup

### Prerequisites

- [Node.js](https://nodejs.org/) (v18 or later)
- [Composer](https://getcomposer.org/)
- PHP 7.4 or later
- A local WordPress installation

### Clone and Install

```bash
# Clone the repository into your WordPress plugins directory
cd /path/to/wordpress/wp-content/plugins
git clone https://github.com/getdokan/texty.git
cd texty

# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install
```

### Build

```bash
# Build assets for production
npm run build

# Start development mode with file watching
npm run start
```

### Available Scripts

| Command | Description |
|---------|-------------|
| `npm run build` | Build production assets |
| `npm run start` | Start development mode with file watching |
| `npm run clean` | Remove the `dist` directory |
| `npm run makepot` | Generate translation POT file |
| `npm run pot2json` | Convert POT file to JSON for JavaScript translations |
| `npm run readme` | Convert `readme.txt` to `readme.md` |
| `composer phpcs` | Run PHP CodeSniffer |
| `composer phpcbf` | Auto-fix PHP CodeSniffer issues |

### Project Structure

```
texty/
├── includes/               # PHP source files
│   ├── Gateways/           # SMS gateway implementations
│   ├── Integrations/       # Third-party plugin integrations (WooCommerce, Dokan)
│   ├── Notifications/      # Notification types (WP, WC, Dokan)
│   ├── Dispatcher.php      # Event dispatcher
│   ├── Gateways.php        # Gateway manager/registry
│   ├── Notifications.php   # Notification manager/registry
│   └── Settings.php        # Plugin settings
├── src/                    # JavaScript/React source files
├── dist/                   # Built assets (generated)
├── dependencies/           # Mozart-managed PHP dependencies
├── languages/              # Translation files
├── texty.php               # Plugin entry point
├── composer.json
└── package.json
```

## Developer Documentation

Texty provides a comprehensive set of action and filter hooks that allow developers to extend every aspect of the plugin — from custom gateways and notifications to message modification and send pipeline control.

### Table of Contents

- [Send Pipeline Hooks](#send-pipeline-hooks)
- [Notification Hooks](#notification-hooks)
- [Gateway Registry](#gateway-registry)
- [Notification Registry](#notification-registry)
- [Integration Registry](#integration-registry)
- [Settings Hooks](#settings-hooks)
- [Plugin Lifecycle](#plugin-lifecycle)
- [Custom Gateway Guide](#custom-gateway-guide)
- [Custom Notification Guide](#custom-notification-guide)
- [Hooks Reference](#hooks-reference)

---

### Send Pipeline Hooks

These hooks wrap every SMS sent through the `Gateways::send()` method, giving you control over the entire send lifecycle.

#### `texty_sms_to` (filter)

Modify the recipient phone number before sending.

```php
add_filter( 'texty_sms_to', function ( $to, $message, $gateway ) {
    // Normalize phone numbers to E.164 format
    if ( strpos( $to, '+' ) !== 0 ) {
        $to = '+1' . $to;
    }

    return $to;
}, 10, 3 );
```

**Parameters:**
- `$to` *(string)* — The recipient phone number
- `$message` *(string)* — The message body
- `$gateway` *(GatewayInterface)* — The active gateway instance

#### `texty_sms_message` (filter)

Modify the SMS body at the gateway level.

```php
add_filter( 'texty_sms_message', function ( $message, $to, $gateway ) {
    // Append a signature to all outgoing SMS
    return $message . "\n— Sent via " . get_bloginfo( 'name' );
}, 10, 3 );
```

**Parameters:**
- `$message` *(string)* — The message body
- `$to` *(string)* — The recipient phone number
- `$gateway` *(GatewayInterface)* — The active gateway instance

#### `texty_pre_send_sms` (filter)

Short-circuit SMS sending. Return a non-null value to skip the gateway call entirely.

```php
add_filter( 'texty_pre_send_sms', function ( $pre_send, $to, $message, $gateway ) {
    // Block SMS to specific numbers
    $blocked = [ '+1234567890' ];

    if ( in_array( $to, $blocked, true ) ) {
        return new \WP_Error( 'blocked', 'This number is blocked.' );
    }

    return $pre_send; // Return null to continue sending
}, 10, 4 );
```

**Parameters:**
- `$pre_send` *(null|mixed)* — Return non-null to short-circuit
- `$to` *(string)* — The recipient phone number
- `$message` *(string)* — The message body
- `$gateway` *(GatewayInterface)* — The active gateway instance

#### `texty_before_send_sms` (action)

Fires immediately before each SMS is sent. Useful for logging, rate limiting, or analytics.

```php
add_action( 'texty_before_send_sms', function ( $to, $message, $gateway ) {
    error_log( sprintf( 'Texty: Sending SMS to %s via %s', $to, $gateway->name() ) );
}, 10, 3 );
```

**Parameters:**
- `$to` *(string)* — The recipient phone number
- `$message` *(string)* — The message body
- `$gateway` *(GatewayInterface)* — The active gateway instance

#### `texty_after_send_sms` (action)

Fires after each SMS is sent, with the result.

```php
add_action( 'texty_after_send_sms', function ( $result, $to, $message, $gateway ) {
    if ( is_wp_error( $result ) ) {
        error_log( 'Texty: SMS failed - ' . $result->get_error_message() );
    } else {
        error_log( 'Texty: SMS sent successfully to ' . $to );
    }
}, 10, 4 );
```

**Parameters:**
- `$result` *(bool|WP_Error)* — The send result
- `$to` *(string)* — The recipient phone number
- `$message` *(string)* — The message body
- `$gateway` *(GatewayInterface)* — The active gateway instance

#### `texty_send_sms_failed` (action)

Fires specifically when SMS sending returns a `WP_Error`.

```php
add_action( 'texty_send_sms_failed', function ( $error, $to, $message, $gateway ) {
    // Send failure alert to admin or log to external service
    do_action( 'my_sms_failure_handler', $error, $to );
}, 10, 4 );
```

**Parameters:**
- `$error` *(WP_Error)* — The error object
- `$to` *(string)* — The recipient phone number
- `$message` *(string)* — The message body
- `$gateway` *(GatewayInterface)* — The active gateway instance

---

### Notification Hooks

These hooks let you modify recipients, message content, and the notification lifecycle.

#### `texty_notification_recipients` (filter)

Modify the list of recipients for any notification.

```php
add_filter( 'texty_notification_recipients', function ( $recipients, $notification ) {
    // Add an extra number for WooCommerce admin notifications
    if ( $notification->get_group() === 'wc' && $notification->get_type() === 'role' ) {
        $recipients[] = '+1987654321';
    }

    return $recipients;
}, 10, 2 );
```

**Parameters:**
- `$recipients` *(array)* — Phone numbers
- `$notification` *(Notification)* — The notification instance

#### `texty_notification_message` (filter)

Modify the message content for all notifications.

```php
add_filter( 'texty_notification_message', function ( $content, $notification ) {
    return $content . "\n[" . $notification->get_id() . ']';
}, 10, 2 );
```

**Parameters:**
- `$content` *(string)* — The message content
- `$notification` *(Notification)* — The notification instance

#### `texty_notification_message_{$id}` (filter)

Modify the message for a specific notification type. Replace `{$id}` with the notification ID (e.g., `texty_notification_message_registration` for the new user registration).

```php
add_filter( 'texty_notification_message_registration', function ( $content, $notification ) {
    return 'Welcome! ' . $content;
}, 10, 2 );
```

**Parameters:**
- `$content` *(string)* — The message content
- `$notification` *(Notification)* — The notification instance

#### `texty_before_notification` (action)

Fires before the notification send loop begins.

```php
add_action( 'texty_before_notification', function ( $notification, $recipients, $content ) {
    error_log( sprintf(
        'Texty: Sending "%s" notification to %d recipients',
        $notification->get_id(),
        count( $recipients )
    ) );
}, 10, 3 );
```

**Parameters:**
- `$notification` *(Notification)* — The notification instance
- `$recipients` *(array)* — Phone numbers
- `$content` *(string)* — The message content

#### `texty_after_notification` (action)

Fires after the notification send loop completes.

```php
add_action( 'texty_after_notification', function ( $notification, $recipients, $content ) {
    // Track notification completion
}, 10, 3 );
```

**Parameters:**
- `$notification` *(Notification)* — The notification instance
- `$recipients` *(array)* — Phone numbers
- `$content` *(string)* — The message content

#### `texty_notification_enabled` (filter)

Override the enabled state of any notification.

```php
add_filter( 'texty_notification_enabled', function ( $enabled, $id, $notification ) {
    // Force-enable registration notifications
    if ( $id === 'registration' ) {
        return true;
    }

    return $enabled;
}, 10, 3 );
```

**Parameters:**
- `$enabled` *(bool)* — Whether the notification is enabled
- `$id` *(string)* — The notification ID
- `$notification` *(Notification)* — The notification instance

#### `texty_notification_settings` (filter)

Modify the settings for any notification.

```php
add_filter( 'texty_notification_settings', function ( $settings, $id, $notification ) {
    // Override message for a specific notification
    if ( $id === 'comment' ) {
        $settings['message'] = 'New comment on your site!';
    }

    return $settings;
}, 10, 3 );
```

**Parameters:**
- `$settings` *(array)* — The notification settings
- `$id` *(string)* — The notification ID
- `$notification` *(Notification)* — The notification instance

---

### Gateway Registry

#### `texty_register_gateways` (action)

Register custom SMS gateways. This is the recommended way to add new gateways.

```php
add_action( 'texty_register_gateways', function ( $manager ) {
    $manager->register( 'my_gateway', \MyPlugin\Gateways\MyGateway::class );
} );
```

**Parameters:**
- `$manager` *(Gateways)* — The gateway manager instance

See [Custom Gateway Guide](#custom-gateway-guide) for a complete example.

#### `texty_gateway_instance` (filter)

Modify a gateway instance after it is created.

```php
add_filter( 'texty_gateway_instance', function ( $instance, $gateway_key ) {
    // Wrap the gateway with a decorator for logging
    return $instance;
}, 10, 2 );
```

**Parameters:**
- `$instance` *(GatewayInterface)* — The gateway instance
- `$gateway_key` *(string)* — The gateway identifier

#### `texty_available_gateways` (filter)

Filter the final list of available gateways. This is the legacy approach — prefer `texty_register_gateways` for new code.

```php
add_filter( 'texty_available_gateways', function ( $gateways ) {
    // Remove a built-in gateway
    unset( $gateways['clickatell'] );

    return $gateways;
} );
```

---

### Notification Registry

#### `texty_register_notifications` (action)

Register custom notification types.

```php
add_action( 'texty_register_notifications', function ( $manager ) {
    $manager->register( 'my_event', \MyPlugin\Notifications\MyEvent::class );
} );
```

**Parameters:**
- `$manager` *(Notifications)* — The notifications manager instance

See [Custom Notification Guide](#custom-notification-guide) for a complete example.

#### `texty_available_notifications` (filter)

Filter the final list of available notifications. This is the legacy approach — prefer `texty_register_notifications` for new code.

---

### Integration Registry

#### `texty_register_integrations` (action)

Fires during plugin initialization. Use this to bootstrap custom integrations.

```php
add_action( 'texty_register_integrations', function () {
    if ( class_exists( 'EDD' ) ) {
        new \MyPlugin\Integrations\EasyDigitalDownloads();
    }
} );
```

---

### Settings Hooks

#### `texty_setting` (filter)

Modify any settings value. Useful for loading credentials from environment variables.

```php
add_filter( 'texty_setting', function ( $value, $key ) {
    // Load Twilio credentials from environment
    if ( $key === 'twilio' ) {
        $sid   = getenv( 'TEXTY_TWILIO_SID' );
        $token = getenv( 'TEXTY_TWILIO_TOKEN' );
        $from  = getenv( 'TEXTY_TWILIO_FROM' );

        if ( $sid && $token && $from ) {
            return [
                'sid'   => $sid,
                'token' => $token,
                'from'  => $from,
            ];
        }
    }

    return $value;
}, 10, 2 );
```

**Parameters:**
- `$value` *(mixed)* — The setting value
- `$key` *(string)* — The setting key

#### `texty_active_gateway_name` (filter)

Override the active gateway.

```php
add_filter( 'texty_active_gateway_name', function ( $gateway ) {
    // Force Twilio in production
    if ( wp_get_environment_type() === 'production' ) {
        return 'twilio';
    }

    return $gateway;
} );
```

**Parameters:**
- `$gateway` *(string|false)* — The active gateway identifier

---

### Plugin Lifecycle

#### `texty_loaded` (action)

Fires after the Texty plugin is fully initialized. Safe to use Texty APIs after this hook.

```php
add_action( 'texty_loaded', function ( $texty ) {
    // Plugin is ready, initialize your Texty extension
} );
```

**Parameters:**
- `$texty` *(Texty)* — The main plugin instance

---

### Custom Gateway Guide

To add a custom SMS gateway, implement the `GatewayInterface` and register it:

```php
<?php

namespace MyPlugin\Gateways;

use Texty\Gateways\GatewayInterface;

class MyGateway implements GatewayInterface {

    public function send( $to, $message ) {
        $response = wp_remote_post( 'https://api.mygateway.com/send', [
            'body' => [
                'to'      => $to,
                'message' => $message,
                'api_key' => $this->get_api_key(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 ) {
            return new \WP_Error(
                'mygateway_error',
                'MyGateway API returned status ' . $code
            );
        }

        return true;
    }

    public function logo() {
        return 'https://example.com/logo.svg';
    }

    public function name() {
        return 'MyGateway';
    }

    public function description() {
        return 'Send SMS via MyGateway API.';
    }

    public function get_settings() {
        return texty()->settings()->get( 'mygateway' );
    }

    public function validate( $request ) {
        $creds = $request->get_param( 'mygateway' );

        if ( empty( $creds['api_key'] ) ) {
            return new \WP_Error(
                'mygateway_api_key',
                __( 'API key is required.', 'my-plugin' )
            );
        }

        return true;
    }

    private function get_api_key() {
        $settings = $this->get_settings();

        return $settings['api_key'] ?? '';
    }
}
```

Register the gateway:

```php
add_action( 'texty_register_gateways', function ( $manager ) {
    $manager->register( 'mygateway', \MyPlugin\Gateways\MyGateway::class );
} );
```

---

### Custom Notification Guide

To add a custom notification, extend the `Notification` base class:

```php
<?php

namespace MyPlugin\Notifications;

use Texty\Notifications\Notification;

class LowStock extends Notification {

    protected $title = 'Low Stock Alert';
    protected $id    = 'low_stock';
    protected $group = 'wc';

    protected $default = 'Low stock alert: {product_name} has only {stock_qty} left.';
    protected $default_recipients = [ 'administrator' ];

    private $product;

    public function set_product( $product ) {
        $this->product = $product;

        return $this;
    }

    public function get_message() {
        $message = parent::get_message_raw();

        if ( $this->product ) {
            $message = str_replace( '{product_name}', $this->product->get_name(), $message );
            $message = str_replace( '{stock_qty}', $this->product->get_stock_quantity(), $message );
        }

        return $this->replace_global_keys( $message );
    }

    public function get_recipients() {
        return $this->get_numbers_by_roles();
    }

    public function replacement_keys() {
        return [
            'product_name' => 'get_name',
            'stock_qty'    => 'get_stock_quantity',
        ];
    }
}
```

Register the notification and add the notification group:

```php
add_action( 'texty_register_notifications', function ( $manager ) {
    $manager->register( 'low_stock', \MyPlugin\Notifications\LowStock::class );
} );

// If using a custom group, register it
add_filter( 'texty_notification_groups', function ( $groups ) {
    $groups['inventory'] = [
        'title'       => __( 'Inventory', 'my-plugin' ),
        'description' => '',
        'available'   => class_exists( 'WooCommerce' ),
    ];

    return $groups;
} );
```

Trigger the notification from your code:

```php
$class    = texty()->notifications()->get( 'low_stock' );
$notifier = new $class();

$notifier->set_product( $product );
$notifier->send();
```

---

### Hooks Reference

| Hook | Type | Location | Purpose |
|------|------|----------|---------|
| `texty_sms_to` | filter | `Gateways::send()` | Modify recipient number |
| `texty_sms_message` | filter | `Gateways::send()` | Modify SMS body |
| `texty_pre_send_sms` | filter | `Gateways::send()` | Short-circuit sending |
| `texty_before_send_sms` | action | `Gateways::send()` | Before each SMS |
| `texty_after_send_sms` | action | `Gateways::send()` | After each SMS with result |
| `texty_send_sms_failed` | action | `Gateways::send()` | On send failure |
| `texty_register_gateways` | action | `Gateways::all()` | Register custom gateways |
| `texty_gateway_instance` | filter | `Gateways::active_gateway()` | Modify gateway instance |
| `texty_available_gateways` | filter | `Gateways::all()` | Filter gateway list (legacy) |
| `texty_notification_recipients` | filter | `Notification::send()` | Modify recipients |
| `texty_notification_message` | filter | `Notification::send()` | Modify notification message |
| `texty_notification_message_{$id}` | filter | `Notification::send()` | Modify specific notification |
| `texty_before_notification` | action | `Notification::send()` | Before send loop |
| `texty_after_notification` | action | `Notification::send()` | After send loop |
| `texty_notification_enabled` | filter | `Notification::enabled()` | Override enabled state |
| `texty_notification_settings` | filter | `Notification::settings()` | Modify notification config |
| `texty_register_notifications` | action | `Notifications::all()` | Register custom notifications |
| `texty_available_notifications` | filter | `Notifications::all()` | Filter notification list (legacy) |
| `texty_register_integrations` | action | `Dispatcher::__construct()` | Register custom integrations |
| `texty_setting` | filter | `Settings::get()` | Modify any setting value |
| `texty_active_gateway_name` | filter | `Settings::gateway()` | Override active gateway |
| `texty_loaded` | action | `Texty::init_plugin()` | Plugin fully initialized |

## Frequently Asked Questions

### Which gateways does Texty support?

Texty supports Twilio, Vonage (Nexmo), Plivo, and Clickatell. You can also register custom gateways via the `texty_register_gateways` action hook.

### Does it support WooCommerce?

Yes. Texty supports admin and customer notifications for order status changes including processing, complete, on-hold, cancelled, failed, and refunded.

### Does it support Dokan?

Yes. Dokan vendors receive SMS notifications when their order statuses change.

### Can I add custom gateways?

Yes. Implement the `GatewayInterface` and register your gateway using the `texty_register_gateways` action hook. See the [Custom Gateway Guide](#custom-gateway-guide) for a full example.

### Can I modify SMS content before sending?

Yes. Use the `texty_sms_message` filter to modify the SMS body, or `texty_notification_message` to modify notification content before sending.

## Changelog

### v1.1.5 (3 Feb, 2026)

- **New:** Added notifications for WooCommerce order statuses: cancelled, failed, and refunded
- **New:** Added notifications for Dokan Vendor order statuses: cancelled, failed, and refunded
- **New:** Added 21 action and filter hooks for developer extensibility
- **New:** Gateway registry system — register custom gateways via `texty_register_gateways`
- **New:** Notification registry system — register custom notifications via `texty_register_notifications`
- **Update:** Appsero updated for WordPress 6.8 compatibility
- **Update:** Mozart is integrated with the plugin
- **Update:** WordPress 6.9 compatibility added

### v1.1.4 (8 Oct, 2024)

- **Update:** WordPress 6.6.2 compatibility

### v1.1.2 (31 Oct, 2023)

- **Update:** WordPress 6.3.2 compatibility
- **Fix:** Fixed Appsero SDK security issue

### v1.1.1 (2 June, 2022)

- **Fix:** WordPress 6.0 compatibility

### v1.1 (31 Aug, 2021)

- **Fix:** Responsive issue in the settings panel where gateway names overflowed the viewport
- **Fix:** Remove duplicate numbers while sending messages
- **New:** Syncing of vendor phone number from Dokan during registration

### v1.0 (22 Jan, 2021)

- **New:** Added Plivo gateway
- **New:** Added Clickatell gateway
- **New:** Added Dokan integration for vendor order notifications
- **New:** Added `{items}` shortcode for WooCommerce orders

### v0.2 (18 Jan, 2021)

- Initial Release

