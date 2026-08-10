<?php

namespace Texty;

defined( 'ABSPATH' ) || exit;

/**
 * Shared asset registration.
 *
 * Registers the bundles Texty shares with its add-ons on wp-admin, the
 * front-end and the login page:
 *
 * - `texty-plugin-ui`  — the @wedevs/plugin-ui kit (`window.texty.pluginUi`).
 * - `texty-components` — Texty's reusable components (`window.texty.components`).
 *
 * Add-ons `import` from `@wedevs/plugin-ui` / `@texty/components` and let
 * @wordpress/dependency-extraction-webpack-plugin (see
 * webpack-dependency-mapping.js) rewrite the import to the global plus a
 * dependency on the script handle — so one copy is loaded no matter how many
 * add-ons use it, including on storefront surfaces (checkout, my-account) and
 * the wp-login.php form where the admin SPA never loads.
 *
 * Nothing is enqueued here; consumers pull a bundle in by declaring the handle
 * as a dependency (or calling wp_enqueue_script directly).
 */
class Assets {

    /**
     * Wire the global registration on every request context an add-on may
     * enqueue from: wp-admin, the front-end, and wp-login.php.
     */
    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ], 5 );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ], 5 );
        add_action( 'login_enqueue_scripts', [ $this, 'register_assets' ], 5 );
    }

    /**
     * Register every shared bundle.
     *
     * The plugin-ui bundle goes first — `texty-components` (and `texty-admin`)
     * list it as a dependency through their generated asset files.
     *
     * @since 2.0.2
     *
     * @return void
     */
    public function register_assets() {
        $this->register_plugin_ui();
        $this->register_components();
    }

    /**
     * Register the `texty-plugin-ui` handle.
     *
     * The bundle assigns the plugin-ui export surface to
     * `window.texty.pluginUi` and its stylesheet is registered under the same
     * handle. Texty's own admin bundle depends on it too, so the kit is loaded
     * exactly once per page.
     *
     * @since 2.0.2
     *
     * @return void
     */
    public function register_plugin_ui() {
        $this->register_bundle( 'texty-plugin-ui', 'plugin-ui', 'plugin-ui.css' );
    }

    /**
     * Register the `texty-components` handle.
     *
     * @return void
     */
    public function register_components() {
        $this->register_bundle( 'texty-components', 'components', 'style-components.css', [ 'texty-plugin-ui' ] );
    }

    /**
     * Register one built entry as a script (and, when present, a stylesheet)
     * under a single handle.
     *
     * Script dependencies come from the entry's generated `*.asset.php`, which
     * already carries the `texty-*` handles for any shared package the entry
     * imports.
     *
     * @since 2.0.2
     *
     * @param string $handle     Script + style handle to register.
     * @param string $entry      Webpack entry name (dist/<entry>.js).
     * @param string $style_file Stylesheet filename inside dist/, if any.
     * @param array  $style_deps Style dependencies.
     *
     * @return void
     */
    protected function register_bundle( $handle, $entry, $style_file, $style_deps = [] ) {
        $asset_path = TEXTY_DIR . '/dist/' . $entry . '.asset.php';

        if ( ! file_exists( $asset_path ) ) {
            // Bailing quietly here is what makes a stale/partial dist/ look like
            // a working install: `texty-admin` declares `texty-plugin-ui` as a
            // dependency, WP drops the whole handle when it is not registered,
            // and the admin page renders empty with no error anywhere.
            error_log( 'Texty: missing build artifact ' . $asset_path . ' — run `npm run build`. The "' . $handle . '" handle was not registered.' );

            return;
        }

        $asset   = include $asset_path;
        $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
        $version = isset( $asset['version'] ) ? $asset['version'] : TEXTY_VERSION;

        if ( ! wp_script_is( $handle, 'registered' ) ) {
            wp_register_script(
                $handle,
                TEXTY_URL . '/dist/' . $entry . '.js',
                $deps,
                $version,
                true
            );
        }

        $style_path = TEXTY_DIR . '/dist/' . $style_file;

        if ( ! wp_style_is( $handle, 'registered' ) && file_exists( $style_path ) ) {
            wp_register_style(
                $handle,
                TEXTY_URL . '/dist/' . $style_file,
                $style_deps,
                $version
            );
        }
    }
}
