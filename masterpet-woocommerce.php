<?php
/**
 * Plugin Name:       MasterPet for WooCommerce
 * Plugin URI:        https://github.com/TheYGN/masterpet-woocommerce
 * Update URI:        https://github.com/TheYGN/masterpet-woocommerce
 * Description:       מחבר את חנות WooCommerce ל-MasterPet: המוצרים מגיעים מ-MasterPet וההזמנות מנוהלות שם.
 * Version:           0.12.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            MasterPet
 * Author URI:        https://www.masterpet.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       masterpet-woocommerce
 * WC requires at least: 8.5
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

define( 'MASTERPET_WC_VERSION', '0.12.0' );
define( 'MASTERPET_WC_FILE', __FILE__ );
define( 'MASTERPET_WC_DIR', plugin_dir_path( __FILE__ ) );

require_once MASTERPET_WC_DIR . 'includes/class-masterpet-signature.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-settings.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-client.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-admin.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-rest.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-sync.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-lock.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-no-new.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-fingerprint.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-convert.php';
require_once MASTERPET_WC_DIR . 'includes/class-masterpet-updater.php';

// WooCommerce High-Performance Order Storage and block checkout (PRD #13.1 §4).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MASTERPET_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MASTERPET_WC_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'MasterPet_Admin', 'init' ) );
// Products (PRD #13.1 phase B): the doorbell, the pull, the locked fields.
add_action( 'plugins_loaded', array( 'MasterPet_Rest', 'init' ) );
add_action( 'plugins_loaded', array( 'MasterPet_Sync', 'init' ) );
add_action( 'plugins_loaded', array( 'MasterPet_Lock', 'init' ) );
add_action( 'plugins_loaded', array( 'MasterPet_No_New', 'init' ) );
add_action( 'plugins_loaded', array( 'MasterPet_Fingerprint', 'init' ) );
// Updates from GitHub releases; test sites first (D-34, D-35).
add_action( 'plugins_loaded', array( 'MasterPet_Updater', 'init' ) );

// Best-effort hourly heartbeat. WP-Cron runs only on visits; the real clock
// lives in MasterPet (PRD #13.1 D-14) and rings masterpet/v1/doorbell.
add_action( 'masterpet_wc_heartbeat', array( 'MasterPet_Client', 'heartbeat' ) );

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'masterpet_wc_heartbeat' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'masterpet_wc_heartbeat' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'masterpet_wc_heartbeat' );
		wp_clear_scheduled_hook( 'masterpet_wc_self_update' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'masterpet_wc_sync', array(), 'masterpet' );
		}
	}
);
