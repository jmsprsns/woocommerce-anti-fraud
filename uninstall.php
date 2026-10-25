<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data,
 * unless "Keep the plugin's data and settings" is ticked. On a multisite
 * this covers every site.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's tables, settings, cached lookups and order meta for the current site.
 *
 * @return void
 */
function woocommerce_anti_fraud_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'woocommerce_anti_fraud_settings' );
	if ( is_array( $settings ) && ! empty( $settings['keep_data'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own tables and rows on uninstall.
	foreach ( array( 'wcaf_visitors', 'wcaf_ips', 'wcaf_declines' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}

	// Cached IP lookups and alert throttles.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wcaf\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wcaf\\_%'" );

	// Fraud scores saved on orders, in both order storages.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_wcaf\\_%'" );
	$orders_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) ) === $orders_meta ) {
		$wpdb->query( "DELETE FROM {$orders_meta} WHERE meta_key LIKE '\\_wcaf\\_%'" );
	}
	// phpcs:enable

	foreach ( array( 'woocommerce_anti_fraud_settings', 'woocommerce_anti_fraud_db_version', 'woocommerce_anti_fraud_cf_ranges' ) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'woocommerce_anti_fraud_daily' );
	wp_unschedule_hook( 'woocommerce_anti_fraud_rescore' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $woocommerce_anti_fraud_site ) {
		switch_to_blog( $woocommerce_anti_fraud_site );
		woocommerce_anti_fraud_uninstall_site();
		restore_current_blog();
	}
} else {
	woocommerce_anti_fraud_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-woocommerce-anti-fraud' );
