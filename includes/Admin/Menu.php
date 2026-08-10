<?php

namespace Texty\Admin;

/**
 * Menu Class
 */
class Menu {

    /**
     * Hook suffix of the Texty admin page
     *
     * @since 2.0.2
     *
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Class constructor
     *
     * @return void
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );

        // Capture admin notices so the Texty header renders above them.
        add_action( 'admin_notices', [ $this, 'inject_before_notices' ], -9999 );
        add_action( 'admin_notices', [ $this, 'inject_after_notices' ], PHP_INT_MAX );
    }

    /**
     * Whether the current admin screen is the Texty page
     *
     * @since 2.0.2
     *
     * @return bool
     */
    private function is_texty_admin_page() {
        if ( empty( $this->hook_suffix ) || ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && $screen->id === $this->hook_suffix;
    }

    /**
     * Open a hidden wrapper before admin notices render
     *
     * WordPress core relocates stray `.notice` elements to just after the first
     * `.wp-header-end` node. Opening the wrapper — and printing the catcher —
     * before any notice fires collects them all inside a hidden container, so
     * the React header can sit at the very top of the page.
     *
     * @since 2.0.2
     *
     * @return void
     */
    public function inject_before_notices() {
        if ( ! $this->is_texty_admin_page() ) {
            return;
        }

        echo '<div class="texty-notice-list-hide" id="texty__notice-list">';
        echo '<div class="wp-header-end" id="texty__notice-catcher"></div>';
    }

    /**
     * Close the hidden notice wrapper opened in inject_before_notices()
     *
     * @since 2.0.2
     *
     * @return void
     */
    public function inject_after_notices() {
        if ( ! $this->is_texty_admin_page() ) {
            return;
        }

        echo '</div>';
    }

    /**
     * Add Texty admin menu
     *
     * @return void
     */
    public function register_menu() {
        global $submenu;

        $capability = apply_filters( 'texty_admin_menu_capability', 'manage_options' );
        if ( ! current_user_can( $capability ) ) {
            return;
        }

        $menu_position = apply_filters( 'texty_menu_position', 58 );
        $slug          = 'texty';
        $menu_icon     = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a0a5aa" d="M10.52 1c.603 0 1.191.056 1.761.164a5.358 5.358 0 00-1.1 2.754 6.538 6.538 0 00-5.737 10.626l.629.773-.966 1.645h5.414a6.538 6.538 0 006.511-7.138 5.355 5.355 0 002.764-1.075 9.423 9.423 0 01-9.275 11.097H0l2.602-4.314-.013-.02A9.423 9.423 0 0110.521 1zm6.018 0a3.462 3.462 0 110 6.923 3.462 3.462 0 010-6.923z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

        $dashboard = add_menu_page(
            __( 'Texty', 'texty' ),
            __( 'Texty', 'texty' ),
            $capability,
            $slug,
            [ $this, 'render_page' ],
            $menu_icon,
            $menu_position
        );

        $this->hook_suffix = $dashboard;

        $submenus = apply_filters(
            'texty_admin_menu',
            [
                [
                    'path'  => 'dashboard',
                    'title' => __( 'Dashboard', 'texty' ),
                ],
                [
                    'path'  => 'gateway',
                    'title' => __( 'Gateway', 'texty' ),
                ],
                [
                    'path'  => 'notifications',
                    'title' => __( 'Notifications', 'texty' ),
                ],
                [
                    'path'  => 'logs',
                    'title' => __( 'Logs', 'texty' ),
                ],
            ],
            $capability,
            $slug
        );

        foreach ( $submenus as $item ) {
            $path = 'admin.php?page=' . $slug . '#/' . $item['path'];
            if ( filter_var( $item['path'], FILTER_VALIDATE_URL ) ) {
                $path = $item['path'];
            }
            $submenu[ $slug ][] = [ // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                $item['title'],
                $capability,
                $path,
            ];
        }

        do_action( 'texty_admin_menu', $capability, $menu_position );

        add_action( 'admin_print_scripts-' . $dashboard, [ $this, 'enqueue_scripts' ] );
    }

    /**
     * Render the page
     *
     * The header mounts in its own root so it paints at the very top of the
     * screen, above the captured admin notices. The notice slot deliberately
     * stays outside the `.texty-app` Tailwind scope — that scope's
     * importantized preflight would strip core notice styling. Captured
     * notices are moved into it on the JS side.
     *
     * @return void
     */
    public function render_page() {
        echo '<div id="texty-header" class="texty-app"></div>';
        echo '<div id="texty-notices"></div>';
        echo '<div id="texty-app" class="texty-app"></div>';
    }

    /**
     * Enqueue JS and CSS
     *
     * @return void
     */
    public function enqueue_scripts() {
        $asset_path = TEXTY_DIR . '/dist/index.asset.php';

        if ( ! file_exists( $asset_path ) ) {
            error_log( 'Texty: missing build artifact ' . $asset_path . ' — run `npm run build`. The admin app was not enqueued.' );

            return;
        }

        $asset_file = include $asset_path;
        $deps       = isset( $asset_file['dependencies'] ) && is_array( $asset_file['dependencies'] ) ? $asset_file['dependencies'] : [];
        $version    = isset( $asset_file['version'] ) ? $asset_file['version'] : TEXTY_VERSION;

        // The shared bundles (`texty-plugin-ui` → window.texty.pluginUi,
        // `texty-components` → window.texty.components) are registered globally
        // by Texty\Assets so add-ons can depend on them on both admin and
        // storefront. `texty-plugin-ui` is already listed in $deps here, since
        // the SPA imports @wedevs/plugin-ui.
        //
        // WP silently drops a handle whose dependency is unregistered, so an
        // unbuilt shared entry would blank the admin page with no error. Say so
        // in the log instead of failing invisibly.
        foreach ( $deps as $dep ) {
            if ( strpos( $dep, 'texty-' ) === 0 && ! wp_script_is( $dep, 'registered' ) ) {
                error_log( 'Texty: shared script handle "' . $dep . '" is not registered — the admin app will not load. Run `npm run build`.' );
            }
        }

        wp_register_script(
            'texty-admin',
            TEXTY_URL . '/dist/index.js',
            $deps,
            $version,
            true
        );

        // Merged instead of wp_localize_script's `var texty = {…}`: the shared
        // bundles assign onto the same `window.texty` namespace and print
        // *before* this script (they are dependencies of it), so a plain
        // assignment would wipe out window.texty.pluginUi and crash the SPA.
        $localized = wp_json_encode( $this->localize_script() );

        wp_add_inline_script(
            'texty-admin',
            'window.texty = Object.assign( window.texty || {}, ' . ( $localized ? $localized : '{}' ) . ' );',
            'before'
        );

        $vendor_style_deps = [ 'wp-components' ];

        if ( wp_style_is( 'texty-plugin-ui', 'registered' ) ) {
            $vendor_style_deps[] = 'texty-plugin-ui';
        }

        wp_register_style(
            'texty-vendor-css',
            TEXTY_URL . '/dist/style-index.css',
            $vendor_style_deps,
            $version
        );

        wp_register_style(
            'texty-css',
            TEXTY_URL . '/dist/index.css',
            [ 'texty-vendor-css' ],
            $version
        );

        wp_enqueue_script( 'texty-admin' );
        wp_enqueue_style( 'texty-css' );
    }

    /**
     * Get the localize script
     *
     * @return array
     */
    public function localize_script() {
        $i18n = [
            'asset_url' => trailingslashit( TEXTY_URL ) . 'assets/',
            'site_name' => get_bloginfo( 'name' ),
            'rest_url'  => esc_url_raw( rest_url() ),
            'ajax_url'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'version'   => [
                'lite' => TEXTY_VERSION,
            ],
        ];

        return apply_filters( 'texty_localize_script', $i18n );
    }
}
