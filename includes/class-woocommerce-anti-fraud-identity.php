<?php
/**
 * Devices and the IPs they check out from.
 *
 * A device is a browser fingerprint (or, when the browser checks didn't
 * run, a hash of the IP and request headers). Each device keeps a history
 * of the IPs it has used, which powers the "IP spread", "IP shared by many
 * devices" and "new country" signals.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Device and IP history.
 */
final class WooCommerce_Anti_Fraud_Identity {

	/**
	 * Find or create a device row and return its ID.
	 *
	 * @param string $fingerprint 64-character hash.
	 * @param string $source      browser or server.
	 * @param array  $details     Readable device details for the order screen.
	 * @return int 0 on failure.
	 */
	public static function upsert_visitor( $fingerprint, $source, array $details = array() ) {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $fingerprint ) ) {
			return 0;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'visitors' );
		$now   = current_time( 'mysql', true );
		$user  = get_current_user_id();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s", $fingerprint ) );

		if ( $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_seen = %s, user_id = IF( %d > 0, %d, user_id ) WHERE id = %d", $now, $user, $user, $id ) );
			if ( 'browser' === $source && $details ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET details = %s, source = 'browser' WHERE id = %d", wp_json_encode( $details ), $id ) );
			}
			return $id;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} ( fingerprint, source, details, user_id, first_seen, last_seen ) VALUES ( %s, %s, %s, %d, %s, %s )",
				$fingerprint,
				'browser' === $source ? 'browser' : 'server',
				$details ? wp_json_encode( $details ) : '',
				$user,
				$now,
				$now
			)
		);

		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			// Another request inserted the same device a moment ago.
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s", $fingerprint ) );
		}
		// phpcs:enable

		return $id;
	}

	/**
	 * Add one to a device's order or decline counter.
	 *
	 * @param int    $visitor_id Device ID.
	 * @param string $counter    order_count or decline_count.
	 * @return void
	 */
	public static function bump( $visitor_id, $counter ) {
		global $wpdb;

		if ( ! $visitor_id || ! in_array( $counter, array( 'order_count', 'decline_count' ), true ) ) {
			return;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'visitors' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table; column whitelisted above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$counter} = {$counter} + 1 WHERE id = %d", (int) $visitor_id ) );
	}

	/**
	 * A device row.
	 *
	 * @param int $visitor_id Device ID.
	 * @return object|null
	 */
	public static function get( $visitor_id ) {
		global $wpdb;

		$table = WooCommerce_Anti_Fraud_Install::table( 'visitors' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $visitor_id ) );
	}

	/**
	 * Record that a device used an IP.
	 *
	 * @param int   $visitor_id Device ID.
	 * @param array $info       IP_Intel::lookup() data (ip required).
	 * @return void
	 */
	public static function record_ip( $visitor_id, array $info ) {
		global $wpdb;

		$ip = WooCommerce_Anti_Fraud_Request::valid_ip( isset( $info['ip'] ) ? $info['ip'] : '' );
		if ( ! $visitor_id || '' === $ip ) {
			return;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'ips' );
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET seen_count = seen_count + 1, last_seen = %s, conn_type = IF( %s <> '', %s, conn_type ), asn = IF( %s <> '', %s, asn ), org = IF( %s <> '', %s, org ), city = IF( %s <> '', %s, city ), region = IF( %s <> '', %s, region ), country = IF( %s <> '', %s, country ) WHERE visitor_id = %d AND ip = %s",
				$now,
				$info['type'],
				$info['type'],
				$info['asn'],
				$info['asn'],
				$info['org'],
				$info['org'],
				$info['city'],
				$info['city'],
				$info['region'],
				$info['region'],
				$info['country'],
				$info['country'],
				(int) $visitor_id,
				$ip
			)
		);

		if ( ! $updated ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} ( visitor_id, ip, conn_type, asn, org, city, region, country, seen_count, first_seen, last_seen ) VALUES ( %d, %s, %s, %s, %s, %s, %s, %s, 1, %s, %s )",
					(int) $visitor_id,
					$ip,
					$info['type'],
					$info['asn'],
					$info['org'],
					$info['city'],
					$info['region'],
					$info['country'],
					$now,
					$now
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * How many different IPs a device used in the window. Addresses on
	 * shared networks (iCloud Private Relay, mobile carriers) rotate by
	 * design and don't count.
	 *
	 * @param int $visitor_id Device ID.
	 * @param int $hours      Window.
	 * @return int
	 */
	public static function ip_count( $visitor_id, $hours ) {
		global $wpdb;

		if ( ! $visitor_id ) {
			return 0;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'ips' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE visitor_id = %d AND conn_type NOT IN ( 'CPN', 'MOB' ) AND last_seen >= %s", (int) $visitor_id, self::since( $hours ) ) );
	}

	/**
	 * How many different devices checked out from an IP in the window.
	 *
	 * @param string $ip    IP address.
	 * @param int    $hours Window.
	 * @return int
	 */
	public static function devices_on_ip( $ip, $hours ) {
		global $wpdb;

		if ( '' === (string) $ip ) {
			return 0;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'ips' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor_id) FROM {$table} WHERE ip = %s AND last_seen >= %s", $ip, self::since( $hours ) ) );
	}

	/**
	 * Countries a device checked out from before, other than this one, in the last 30 days.
	 *
	 * @param int    $visitor_id Device ID.
	 * @param string $ip         The current IP (left out).
	 * @return string[]
	 */
	public static function other_countries( $visitor_id, $ip ) {
		global $wpdb;

		if ( ! $visitor_id ) {
			return array();
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'ips' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT country FROM {$table} WHERE visitor_id = %d AND ip <> %s AND country <> '' AND conn_type NOT IN ( 'CPN', 'VPN', 'TOR', 'DCH' ) AND last_seen >= %s", (int) $visitor_id, (string) $ip, self::since( 30 * 24 ) ) );

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Every IP a device has used, newest first.
	 *
	 * @param int $visitor_id Device ID.
	 * @param int $limit      Maximum rows.
	 * @return object[]
	 */
	public static function ips( $visitor_id, $limit = 30 ) {
		global $wpdb;

		if ( ! $visitor_id ) {
			return array();
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'ips' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE visitor_id = %d ORDER BY last_seen DESC LIMIT %d", (int) $visitor_id, max( 1, (int) $limit ) ) );
	}

	/**
	 * GMT datetime a number of hours ago.
	 *
	 * @param int $hours Hours.
	 * @return string
	 */
	public static function since( $hours ) {
		return gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $hours ) * HOUR_IN_SECONDS );
	}
}
