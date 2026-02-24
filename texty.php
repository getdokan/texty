<?php
/**
 * Plugin Name: Texty
 * Description: SMS Notification for WordPress
 * Plugin URI: https://wordpress.org/plugins/texty/
 * Author: weDevs
 * Author URI: https://wptexty.com/
 * Version: 1.1.5
 * License: GPL2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: texty
 * Requires at least: 6.8
 * Requires PHP: 7.4
 */
defined( 'ABSPATH' ) || exit;

require __DIR__ . '/vendor/autoload.php';

/**
 * Texty Class
 */
final class Texty {

    /**
     * Plugin version
     *
     * @var string
     */
    private $version = '1.1.5';

    /**
     * Instances array
     *
     * @var array
     */
    private $instances = [];

    /**
     * Initialize
     */
    public function __construct() {
        $this->define_constants();
        $this->appsero_init();

        // run the installer
        register_activation_hook( __FILE__, [ $this, 'activate' ] );

        // load the plugin
        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
    }

    /**
     * Initializes the Texty class
     *
     * Checks for an existing Texty instance
     * and if it doesn't find one, creates it.
     */
    public static function instance() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new self();
        }

        return $instance;
    }

    /**
     * Initialize the plugin
     *
     * @return void
     */
    public function init_plugin() {
        if ( is_admin() ) {
            new Texty\Admin();
        }

        new Texty\Api();
        new Texty\Dispatcher();

        /**
         * Fires after the Texty plugin is fully initialized.
         *
         * @param Texty $texty The main plugin instance
         */
        do_action( 'texty_loaded', $this );
    }

    /**
     * Define constants
     *
     * @return void
     */
    private function define_constants() {
        define( 'TEXTY_VERSION', $this->version );
        define( 'TEXTY_DIR', __DIR__ );
        define( 'TEXTY_FILE', __FILE__ );
        define( 'TEXTY_URL', plugins_url( '', __FILE__ ) );
    }

    /**
     * Run the installer
     *
     * @return void
     */
    public function activate() {
        $installer = new Texty\Install();
        $installer->run();
    }

    /**
     * Access to gateway manager
     *
     * @return Texty\Gateways
     */
    public function gateways() {
        if ( ! isset( $this->instances['gateway'] ) ) {
            $this->instances['gateway'] = new \Texty\Gateways();
        }

        return $this->instances['gateway'];
    }

    /**
     * Access to gateway manager
     *
     * @return Texty\Settings
     */
    public function settings() {
        if ( ! isset( $this->instances['settings'] ) ) {
            $this->instances['settings'] = new \Texty\Settings();
        }

        return $this->instances['settings'];
    }

    /**
     * Access to gateway manager
     *
     * @return Texty\Notifications
     */
    public function notifications() {
        if ( ! isset( $this->instances['notification'] ) ) {
            $this->instances['notification'] = new \Texty\Notifications();
        }

        return $this->instances['notification'];
    }

    /**
     * Initialize the plugin tracker
     *
     * @return void
     */
    public function appsero_init() {
        $client = new Texty\Dependencies\Appsero\Client( 'd4c17b0f-8f01-4b95-a8de-42b0641eec9a', 'Texty', __FILE__ );

        // Active insights
        $client->insights()->init();
    }
}

/**
 * Return the instance
 *
 * @return \Texty
 */
function texty() {
    return Texty::instance();
}

// take off
texty();
