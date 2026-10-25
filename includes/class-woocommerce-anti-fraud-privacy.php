<?php
/**
 * Privacy: suggested privacy-policy text, and the plugin's data in
 * WordPress's "Export Personal Data" and "Erase Personal Data" tools.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools integration.
 */
final class WooCommerce_Anti_Fraud_Privacy {

	/**
	 * Orders handled per page of an export or erase request.
	 */
	const BATCH = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Suggested text for Settings > Privacy > Policy Guide.
	 *
	 * @return void
	 */
	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . __( 'To protect our store and our customers from fraud, when you place an order we check it for signs of fraud. We record your IP address and look up its approximate location and network provider (using ipinfo.io), and your browser runs a few checks on the checkout page: a device fingerprint built from your browser\'s settings, a WebRTC check of your connection\'s public IP address (which contacts a STUN server), your browser\'s timezone, and whether checkout fields were typed, pasted or autofilled. We never record what you type into those fields, or any card details.', 'woocommerce-anti-fraud' ) . '</p>'
			. '<p>' . __( 'We use this to give each order a fraud risk score and to stop repeated failed payments. Device and IP records are deleted after a set period; the score and its reasons are kept with the order.', 'woocommerce-anti-fraud' ) . '</p>';

		wp_add_privacy_policy_content( __( 'WooCommerce Anti Fraud', 'woocommerce-anti-fraud' ), wp_kses_post( $text ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['woocommerce-anti-fraud'] = array(
			'exporter_friendly_name' => __( 'Fraud checks', 'woocommerce-anti-fraud' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['woocommerce-anti-fraud'] = array(
			'eraser_friendly_name' => __( 'Fraud checks', 'woocommerce-anti-fraud' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Orders for an email address, one page at a time.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return WC_Order[]
	 */
	private function orders( $email, $page ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! is_email( $email ) ) {
			return array();
		}

		return (array) wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::BATCH,
				'page'          => max( 1, (int) $page ),
				'type'          => 'shop_order',
			)
		);
	}

	/**
	 * Export the fraud check on each of a person's orders.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$items  = array();
		$orders = $this->orders( $email, $page );

		foreach ( $orders as $order ) {
			$report = WooCommerce_Anti_Fraud_Scorer::report( $order );
			if ( ! $report ) {
				continue;
			}

			// Only a browser fingerprint (which includes a random device ID) is
			// one person's; a headers-only device can be shared by strangers.
			$device = WooCommerce_Anti_Fraud_Identity::get( (int) $order->get_meta( '_wcaf_visitor' ) );
			$rows   = $device && 'browser' === $device->source ? WooCommerce_Anti_Fraud_Identity::ips( (int) $device->id ) : array();

			foreach ( $rows as $row ) {
				$items[] = array(
					'group_id'    => 'woocommerce-anti-fraud-ips',
					'group_label' => __( 'IP addresses seen at checkout', 'woocommerce-anti-fraud' ),
					'item_id'     => 'wcaf-ip-' . (int) $row->id,
					'data'        => array(
						array(
							'name'  => __( 'IP address', 'woocommerce-anti-fraud' ),
							'value' => (string) $row->ip,
						),
						array(
							'name'  => __( 'Network', 'woocommerce-anti-fraud' ),
							'value' => (string) $row->org,
						),
						array(
							'name'  => __( 'Location', 'woocommerce-anti-fraud' ),
							'value' => implode( ', ', array_filter( array( $row->city, $row->region, $row->country ) ) ),
						),
						array(
							'name'  => __( 'Last seen', 'woocommerce-anti-fraud' ),
							'value' => (string) $row->last_seen,
						),
					),
				);
			}

			$items[] = array(
				'group_id'    => 'woocommerce-anti-fraud',
				'group_label' => __( 'Fraud checks', 'woocommerce-anti-fraud' ),
				'item_id'     => 'wcaf-order-' . $order->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'Order', 'woocommerce-anti-fraud' ),
						'value' => '#' . $order->get_order_number(),
					),
					array(
						'name'  => __( 'Fraud score', 'woocommerce-anti-fraud' ),
						'value' => (int) $report['score'] . '/100',
					),
					array(
						'name'  => __( 'IP address', 'woocommerce-anti-fraud' ),
						'value' => (string) $report['ip']['ip'],
					),
					array(
						'name'  => __( 'IP location', 'woocommerce-anti-fraud' ),
						'value' => WooCommerce_Anti_Fraud_IP_Intel::place( $report['ip']['info'] ),
					),
					array(
						'name'  => __( 'WebRTC IPs', 'woocommerce-anti-fraud' ),
						'value' => implode( ', ', (array) $report['webrtc']['ips'] ),
					),
					array(
						'name'  => __( 'Device fingerprint', 'woocommerce-anti-fraud' ),
						'value' => (string) $report['device']['fingerprint'],
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $orders ) < self::BATCH,
		);
	}

	/**
	 * Erase the fraud check from each of a person's orders, and their failed-payment records.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;

		$removed  = false;
		$orders   = $this->orders( $email, $page );
		$visitors = array();

		foreach ( $orders as $order ) {
			if ( '' === (string) $order->get_meta( '_wcaf_score' ) ) {
				continue;
			}
			$visitors[] = (int) $order->get_meta( '_wcaf_visitor' );
			foreach ( array( '_wcaf_score', '_wcaf_risk', '_wcaf_report', '_wcaf_scored_at', '_wcaf_fingerprint', '_wcaf_visitor', '_wcaf_ip', '_wcaf_leak', '_wcaf_initial', '_wcaf_held' ) as $key ) {
				$order->delete_meta_data( $key );
			}
			$order->save();
			$removed = true;
		}

		// The devices those orders came from, and every IP recorded for them.
		foreach ( array_unique( array_filter( $visitors ) ) as $visitor_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Our own tables.
			$wpdb->delete( WooCommerce_Anti_Fraud_Install::table( 'ips' ), array( 'visitor_id' => $visitor_id ), array( '%d' ) );
			$wpdb->delete( WooCommerce_Anti_Fraud_Install::table( 'visitors' ), array( 'id' => $visitor_id ), array( '%d' ) );
			$wpdb->delete( WooCommerce_Anti_Fraud_Install::table( 'declines' ), array( 'visitor_id' => $visitor_id ), array( '%d' ) );
			// phpcs:enable
		}

		if ( 1 === (int) $page ) {
			$hash = WooCommerce_Anti_Fraud_Declines::email_hash( $email );
			if ( '' !== $hash ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
				$removed = (bool) $wpdb->delete( WooCommerce_Anti_Fraud_Install::table( 'declines' ), array( 'email_hash' => $hash ), array( '%s' ) ) || $removed;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < self::BATCH,
		);
	}
}
