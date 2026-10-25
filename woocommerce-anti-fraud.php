<?php
/**
 * Plugin Name:       WooCommerce Anti Fraud
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Catches fraudulent WooCommerce orders before you ship them: device fingerprints, WebRTC proxy leak checks, copy-paste detection, IP intelligence and a 0-100 risk score on every order.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-anti-fraud
 * Update URI:        https://github.com/jmsprsns/woocommerce-anti-fraud
 * WC requires at least: 6.0
 * WC tested up to:   11.2
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$woocommerce_anti_fraud_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'WOOCOMMERCE_ANTI_FRAUD_VERSION', $woocommerce_anti_fraud_headers['version'] );
define( 'WOOCOMMERCE_ANTI_FRAUD_REPO', $woocommerce_anti_fraud_headers['repo'] );
define( 'WOOCOMMERCE_ANTI_FRAUD_FILE', __FILE__ );
define( 'WOOCOMMERCE_ANTI_FRAUD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOCOMMERCE_ANTI_FRAUD_URL', plugin_dir_url( __FILE__ ) );

unset( $woocommerce_anti_fraud_headers );

require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-icons.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-updater.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-menu.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-install.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-request.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-ip-intel.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-checks.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-identity.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-declines.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-scorer.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-collector.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-actions.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-privacy.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-order-admin.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-admin.php';
require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'includes/class-woocommerce-anti-fraud-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
WooCommerce_Anti_Fraud_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return WooCommerce_Anti_Fraud_Plugin
 */
function woocommerce_anti_fraud_plugin() {
	return WooCommerce_Anti_Fraud_Plugin::instance();
}
add_action( 'plugins_loaded', 'woocommerce_anti_fraud_plugin' );

/**
 * Tell WooCommerce this plugin works with its order tables (HPOS) and the
 * Cart and Checkout blocks.
 *
 * @return void
 */
function woocommerce_anti_fraud_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOOCOMMERCE_ANTI_FRAUD_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WOOCOMMERCE_ANTI_FRAUD_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'woocommerce_anti_fraud_declare_compatibility' );

/**
 * Create the plugin's tables on activation.
 *
 * Network activation and new sites on a multisite are covered too: the
 * tables are created on each site's first request (see
 * WooCommerce_Anti_Fraud_Install::maybe_upgrade()).
 *
 * @return void
 */
function woocommerce_anti_fraud_activate() {
	WooCommerce_Anti_Fraud_Install::install();
}
register_activation_hook( WOOCOMMERCE_ANTI_FRAUD_FILE, 'woocommerce_anti_fraud_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and recorded data stay put (uninstall.php removes them).
 *
 * @return void
 */
function woocommerce_anti_fraud_deactivate() {
	wp_clear_scheduled_hook( WooCommerce_Anti_Fraud_Plugin::CRON_DAILY );
	wp_unschedule_hook( WooCommerce_Anti_Fraud_Scorer::CRON_RESCORE );
}
register_deactivation_hook( WOOCOMMERCE_ANTI_FRAUD_FILE, 'woocommerce_anti_fraud_deactivate' );
