<?php
/**
 * Database tables: created on activation, upgraded when the schema changes,
 * pruned daily.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema management.
 */
final class WooCommerce_Anti_Fraud_Install {

	/**
	 * Bump when a table changes; dbDelta then runs on the next request.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'woocommerce_anti_fraud_db_version';

	/**
	 * Table names without the WordPress prefix.
	 */
	const TABLES = array( 'wcaf_visitors', 'wcaf_ips', 'wcaf_declines' );

	/**
	 * A table name with the site's prefix.
	 *
	 * @param string $name One of TABLES, without the "wcaf_" part (visitors, ips, declines).
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'wcaf_' . $name;
	}

	/**
	 * Create or update the tables when the stored version is behind. Cheap:
	 * one autoloaded option read on most requests.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate  = $wpdb->get_charset_collate();
		$visitors = self::table( 'visitors' );
		$ips      = self::table( 'ips' );
		$declines = self::table( 'declines' );

		// One device per row, keyed by its fingerprint hash.
		dbDelta(
			"CREATE TABLE {$visitors} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fingerprint char(64) NOT NULL,
				source varchar(10) NOT NULL DEFAULT 'browser',
				details text NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_count int(10) unsigned NOT NULL DEFAULT 0,
				decline_count int(10) unsigned NOT NULL DEFAULT 0,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY fingerprint (fingerprint),
				KEY last_seen (last_seen)
			) {$collate};"
		);

		// Every IP a device has checked out from, with what we know about it.
		dbDelta(
			"CREATE TABLE {$ips} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				visitor_id bigint(20) unsigned NOT NULL,
				ip varchar(45) NOT NULL,
				conn_type varchar(8) NOT NULL DEFAULT '',
				asn varchar(16) NOT NULL DEFAULT '',
				org varchar(128) NOT NULL DEFAULT '',
				city varchar(64) NOT NULL DEFAULT '',
				region varchar(64) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				seen_count int(10) unsigned NOT NULL DEFAULT 1,
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY visitor_ip (visitor_id,ip),
				KEY ip_seen (ip,last_seen)
			) {$collate};"
		);

		// Failed payments (kind "decline") and checkouts this plugin refused
		// (kind "blocked"). Cleared rows stay for the record but stop counting.
		dbDelta(
			"CREATE TABLE {$declines} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL DEFAULT 'decline',
				visitor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				fingerprint char(64) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				gateway varchar(64) NOT NULL DEFAULT '',
				reason varchar(100) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				leak varchar(45) NOT NULL DEFAULT '',
				email_hash char(64) NOT NULL DEFAULT '',
				cleared tinyint(1) NOT NULL DEFAULT 0,
				cleared_at datetime NULL,
				cleared_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY fingerprint (fingerprint,created_at),
				KEY ip (ip,created_at),
				KEY leak (leak,created_at),
				KEY email_hash (email_hash,created_at),
				KEY order_id (order_id),
				KEY created_at (created_at)
			) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Delete device, IP and failed-payment records older than the retention
	 * period. Order scores live on in order meta, with the orders themselves.
	 *
	 * @param int $months Retention in months; 0 keeps everything.
	 * @return void
	 */
	public static function prune( $months ) {
		global $wpdb;

		$months = absint( $months );
		if ( $months < 1 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $months * MONTH_IN_SECONDS );

		$ips      = self::table( 'ips' );
		$visitors = self::table( 'visitors' );
		$declines = self::table( 'declines' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own tables; names are not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ips} WHERE last_seen < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$visitors} WHERE last_seen < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$declines} WHERE created_at < %s", $cutoff ) );
		// phpcs:enable
	}
}
