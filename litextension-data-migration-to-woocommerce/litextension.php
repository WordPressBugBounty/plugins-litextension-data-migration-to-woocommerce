<?php
/**
 * Plugin Name: LitExtension - Automated Store Migration & Import
 * Plugin URI: https://litextension.com/
 * Description: Migrate your store from 140+ platforms to WooCommerce with no downtime, no data loss, and secure, automated migration.
 * Version: 1.2.5
 * Author: Litextension
 * Author URI: https://litextension.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: litextension-data-migration-to-woocommerce
 * Requires at least: 5.8
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

namespace LitExtension;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LIT_VERSION', '1.2.5' );
define( 'LIT_PATH_PLUGIN', __DIR__ . '/' );
define( 'LIT_URL_PLUGIN', plugin_dir_url( __FILE__ ) ); // plugin_dir_url already ends with /

require LIT_PATH_PLUGIN . 'class/LitAutoLoad.php';

LitAutoLoad::init();

/**
 * Declare WooCommerce HPOS (custom_order_tables) compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

add_action( 'init', array( __NAMESPACE__ . '\LitMain', 'init' ), 21 );

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\LitInstaller', 'litActivate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\LitInstaller', 'litDeactivate' ) );
register_uninstall_hook( __FILE__, array( __NAMESPACE__ . '\LitInstaller', 'litUninstall' ) );
