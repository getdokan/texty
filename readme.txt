=== Texty - SMS Notification for WordPress, WooCommerce, Dokan and more ===
Contributors: tareq1988, wedevs, nizamuddinbabu
Donate link: https://tareq.co/donate/
Tags: sms, notification, twilio, woocommerce, dokan
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight SMS notification plugin for WordPress, WooCommerce, and Dokan.

== Description ==

Texty is a lightweight SMS notification plugin for WordPress. With so many emails coming to your inbox, often it becomes overwhelming to stay on top of things that matter most. A text notification on your phone may be more desired.

Texty integrates with 3rd party SMS providers to add support for text messaging. When events occur in WordPress, WooCommerce, or Dokan, Texty sends an SMS notification to the right people at the right time.

👉 [GitHub](https://github.com/getdokan/texty) | [Developer Docs](https://github.com/getdokan/texty#developer-documentation)

= Features =

- **Multiple SMS Gateways** - Choose from Twilio, Vonage, Plivo, or Clickatell
- **WordPress Notifications** - New user registration and new comment alerts
- **WooCommerce Notifications** - Admin and customer order status notifications
- **Dokan Notifications** - Vendor order status notifications
- **Customizable Messages** - Use template tags to personalize every SMS
- **Developer Friendly** - 20+ action and filter hooks for full extensibility

= Supported Gateways =

- [Twilio](https://twilio.com)
- [Vonage](https://vonage.com/communications-apis/) - Formerly Nexmo
- [Plivo](https://www.plivo.com/)
- [Clickatell](https://www.clickatell.com/)

= Supported Events =

**WordPress Core**

- New User Registration
- New Comment

**WooCommerce (Admin)**

- Order Processing
- Order Complete
- Order Cancelled
- Order Failed
- Order Refunded

**WooCommerce (Customer)**

- Order On Hold
- Order Processing
- Order Complete
- Order Cancelled
- Order Failed
- Order Refunded

**Dokan (Vendor)**

- Order Processing
- Order Complete
- Order Cancelled
- Order Failed
- Order Refunded

= Extensibility =

Texty is built with developers in mind. Register custom gateways, add new notification types, modify messages on the fly, or integrate with any WordPress plugin. See the [Developer Documentation](https://github.com/getdokan/texty#developer-documentation) for details.

= Disclaimer =

Texty integrates with 3rd party providers to send SMS messages. Please review each provider's terms before use:

- Twilio - [Terms of Service](https://www.twilio.com/legal/tos) | [Privacy Policy](https://www.twilio.com/legal/privacy)
- Vonage - [Legal](https://www.vonage.com/legal/) | [Privacy Policy](https://www.vonage.com/legal/privacy-policy/)
- Plivo - [Terms of Service](https://www.plivo.com/legal/tos/) | [Privacy Policy](https://www.plivo.com/legal/privacy/)
- Clickatell - [Terms of Service](https://www.clickatell.com/legal/master-terms/) | [Privacy Policy](https://www.clickatell.com/legal/general-terms-notices/privacy-notice/)

= Privacy Policy =

Texty uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users.

Learn more about how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).

== Installation ==

= Minimum Requirements =

- WordPress 6.8 or greater
- PHP 7.4 or greater

= Automatic Installation =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "Texty"
3. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin zip file
2. Extract and upload the `texty` folder to `/wp-content/plugins/`
3. Activate the plugin from the **Plugins** page in WordPress admin

= Setup =

1. Navigate to **Texty > Settings** in your WordPress admin
2. Select your SMS gateway and enter the API credentials
3. Go to the **Notifications** tab to enable and configure alerts
4. Use the **Tools** tab to send a test SMS

== Frequently Asked Questions ==

= Which gateways does Texty support? =

Texty supports Twilio, Vonage (Nexmo), Plivo, and Clickatell. You can also register custom gateways via the `texty_register_gateways` action hook.

= Does it support WooCommerce? =

Yes. Texty supports admin and customer notifications for order status changes including processing, complete, on-hold, cancelled, failed, and refunded.

= Does it support Dokan? =

Yes. Dokan vendors receive SMS notifications when their order statuses change.

= Can I add custom gateways? =

Yes. Implement the `GatewayInterface` and register your gateway using the `texty_register_gateways` action hook. See the [Developer Documentation](https://github.com/getdokan/texty#developer-documentation) for details.

= Can I modify SMS content before sending? =

Yes. Use the `texty_sms_message` filter to modify the SMS body, or `texty_notification_message` to modify notification content before sending.

= Can I add notifications for other plugins? =

Yes. Use the `texty_register_notifications` action hook to register custom notification types.

== Screenshots ==

1. Gateway settings page
2. All supported notifications panel
3. Tools page for quick testing
4. WooCommerce admin notification
5. WooCommerce customer notification

== Changelog ==

= v2.0.1 (16 Jun, 2026) =

- **Fix:** Admin assets (the React dashboard bundle) are now included in the WP.org release; the 2.0.0 package was missing the compiled `dist/` files.

= v2.0.0 (16 Jun, 2026) =

- **New:** Complete design revamp. Every admin screen has been redesigned and rebuilt from the ground up with a fresh layout, simpler navigation, and a consistent look across the plugin.
- **New:** Dashboard page that brings SMS metrics, delivery-rate stats, and volume analytics together in one place, along with a Quick Send card for sending messages on the go.
- **New:** SMS Logs page where every message can be searched, filtered, and reviewed. Each entry includes a detailed view and a clear delivery-status badge.
- **New:** SMS logging that records every send automatically, so message history and delivery details are always available for reference.
- **New:** Gateway configuration page with a guided Connect and Activate flow, plus easy controls to activate, deactivate, or disconnect each gateway.
- **New:** Redesigned notifications area with separate tabs for User Events, Integrations, and global Settings. Each event can be expanded to manage its toggle, recipients, message template, and available variables, with dedicated branding for WordPress, WooCommerce, and Dokan.
- **New:** Compliance controls that allow all notifications to be paused at once, a company name to be appended to outgoing messages, and a global fallback Sender ID to be set.
- **New:** Smarter database update process with a clear notice when an update is required, making upgrades smooth and reliable.
- **New:** Expanded REST API for deeper integrations, covering metrics, logs, gateway settings, notification settings, and more.
- **New:** Friendly error and 404 screens added across the admin app for a smoother experience when something goes wrong.

= v1.1.5 (3 Feb, 2026) =

- **New:** Added notifications for WooCommerce order statuses: cancelled, failed, and refunded
- **New:** Added notifications for Dokan Vendor order statuses: cancelled, failed, and refunded
- **New:** Added 21 action and filter hooks for developer extensibility
- **New:** Gateway registry system — register custom gateways via `texty_register_gateways`
- **New:** Notification registry system — register custom notifications via `texty_register_notifications`
- **Update:** Appsero updated for WordPress 6.8 compatibility
- **Update:** Mozart is integrated with the plugin
- **Update:** WordPress 6.9 compatibility added

= v1.1.4 (8 Oct, 2024) =

- **Update:** WordPress 6.6.2 compatibility

= v1.1.2 (31 Oct, 2023) =

- **Update:** WordPress 6.3.2 compatibility
- **Fix:** Fixed Appsero SDK security issue

= v1.1.1 (2 June, 2022) =

- **Fix:** WordPress 6.0 compatibility

= v1.1 (31 Aug, 2021) =

- **Fix:** Responsive issue in the settings panel where gateway names overflowed the viewport
- **Fix:** Remove duplicate numbers while sending messages
- **New:** Syncing of vendor phone number from Dokan during registration

= v1.0 (22 Jan, 2021) =

- **New:** Added Plivo gateway
- **New:** Added Clickatell gateway
- **New:** Added Dokan integration for vendor order notifications
- **New:** Added `{items}` shortcode for WooCommerce orders

= v0.2 (18 Jan, 2021) =

- Initial Release

== Upgrade Notice ==

= 2.0.0 =
Major release: fully redesigned admin (Dashboard, SMS Logs, Gateways, Notifications), SMS logging to a new database table, and an expanded REST API. A one-time database update is required after upgrading — click the prompt in wp-admin.

= 1.1.5 =
Adds new notification types for cancelled, failed, and refunded orders. Adds 21 extensibility hooks for developers.
