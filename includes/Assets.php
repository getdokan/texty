<?php

namespace Texty;

defined( 'ABSPATH' ) || exit;

/**
 * Shared asset registration.
 *
 * Registers the reusable components bundle (`window.texty.components`,
 * handle `texty-components`) on both wp-admin and the front-end so add-ons
 * that declare a `texty-components` script dependency get it loaded on
 * demand — including on storefront surfaces (checkout, my-account) where the
 * admin SPA never loads. Nothing is enqueued here; consumers pull the bundle
 * in by depending on the handle (or calling wp_enqueue_script directly).
 */
class Assets {

    /**
     * Wire the global registration on both request contexts.
     */
    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'register_components' ], 5 );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_components' ], 5 );
    }

    /**
     * Register the `texty-components` handle.
     *
     * The bundle self-initializes `window.texty` (webpack library
     * `['texty','components']`). In wp-admin the SPA localizes `var texty =
     * {…}` onto that global, which would clobber `.components` unless the
     * bundle loads *after* it — so `texty-admin` is added as a dependency
     * there. On the front-end there is no localize step and the admin SPA is
     * absent, so the base asset deps are used as-is.
     *
     * @return void
     */
    public function register_components() {
        if ( wp_script_is( 'texty-components', 'registered' ) ) {
            return;
        }

        $asset_path = TEXTY_DIR . '/dist/components.asset.php';

        if ( ! file_exists( $asset_path ) ) {
            return;
        }

        $asset = include $asset_path;
        $deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];

        if ( is_admin() ) {
            $deps = array_merge( $deps, [ 'texty-admin' ] );
        }

        wp_register_script(
            'texty-components',
            TEXTY_URL . '/dist/components.js',
            $deps,
            isset( $asset['version'] ) ? $asset['version'] : TEXTY_VERSION,
            true
        );

        // Base stylesheet for component-level styles (e.g. PhoneField's
        // react-phone-input-2 layout). Emitted by the components entry as
        // dist/style-components.css. Add-ons pull it by depending on this
        // handle; nothing enqueues it here.
        $style_path = TEXTY_DIR . '/dist/style-components.css';

        if ( file_exists( $style_path ) && ! wp_style_is( 'texty-components', 'registered' ) ) {
            wp_register_style(
                'texty-components',
                TEXTY_URL . '/dist/style-components.css',
                [],
                isset( $asset['version'] ) ? $asset['version'] : TEXTY_VERSION
            );
        }
    }
}
