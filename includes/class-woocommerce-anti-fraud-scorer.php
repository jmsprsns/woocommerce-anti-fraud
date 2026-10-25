<?php
/**
 * The 0-100 risk score.
 *
 * Every order starts at 100. Each signal that looks wrong takes points off,
 * up to its own maximum, and the total never goes below 0. Below "review"
 * (80 by default) an order needs a look; below "high risk" (50) it probably
 * isn't genuine.
 *
 * Everything the score is based on is saved with the order as a report, so
 * the order screen can show exactly why, and so the counting signals
 * (orders, failed payments, IPs) can be re-run later as new orders and
 * declines from the same customer come in.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scoring engine.
 */
final class WooCommerce_Anti_Fraud_Scorer {

	/**
	 * Background re-score of related orders.
	 */
	const CRON_RESCORE = 'woocommerce_anti_fraud_rescore';

	/**
	 * Report format version.
	 */
	const REPORT_VERSION = 1;

	/**
	 * Most points each signal can take off.
	 */
	const FACTOR_MAX = array(
		'proxy'     => 50,
		'declines'  => 40,
		'ip_change' => 40,
		'velocity'  => 30,
		'addresses' => 30,
		'geo'       => 25,
		'ip_spread' => 20,
		'paste'     => 15,
		'timezone'  => 15,
		'phone'     => 5,
	);

	/**
	 * Points for counts: "count reached => points", highest first.
	 */
	const LADDERS = array(
		'declines'  => array(
			4 => 40,
			3 => 25,
			2 => 15,
		),
		'velocity'  => array(
			4 => 30,
			3 => 20,
			2 => 10,
		),
		'ip_spread' => array(
			8 => 20,
			5 => 14,
			3 => 8,
		),
		'paste'     => array(
			4 => 15,
			2 => 8,
			1 => 3,
		),
	);

	/**
	 * Set while related orders are being re-scored, so they don't queue more re-scores.
	 *
	 * @var bool
	 */
	private static $rescoring = false;

	/**
	 * The signals, in display order, with their labels.
	 *
	 * @return array<string,array{max:int,icon:string,label:string,help:string}>
	 */
	public static function factors() {
		$factors = array(
			'proxy'     => array(
				'icon'  => 'venetian-mask',
				'label' => __( 'VPN, proxy and WebRTC leak', 'woocommerce-anti-fraud' ),
				'help'  => __( 'The order came through a VPN, proxy, Tor or a data center, or the browser\'s WebRTC check showed a different real IP.', 'woocommerce-anti-fraud' ),
			),
			'declines'  => array(
				'icon'  => 'credit-card',
				'label' => __( 'Failed payments', 'woocommerce-anti-fraud' ),
				'help'  => __( 'Declined payments from the same device or IP: the sign of someone testing stolen cards.', 'woocommerce-anti-fraud' ),
			),
			'ip_change' => array(
				'icon'  => 'route',
				'label' => __( 'IP changes', 'woocommerce-anti-fraud' ),
				'help'  => __( 'The IP changed while the customer was on the checkout page, one IP is used by many devices, or the device suddenly shows up in a new country.', 'woocommerce-anti-fraud' ),
			),
			'velocity'  => array(
				'icon'  => 'gauge',
				'label' => __( 'Order velocity', 'woocommerce-anti-fraud' ),
				'help'  => __( 'Several orders from the same device or IP within 48 hours.', 'woocommerce-anti-fraud' ),
			),
			'addresses' => array(
				'icon'  => 'map-pinned',
				'label' => __( 'Addresses per IP', 'woocommerce-anti-fraud' ),
				'help'  => __( 'One IP used three or more different addresses across three or more states or regions in a week: a reshipping pattern.', 'woocommerce-anti-fraud' ),
			),
			'geo'       => array(
				'icon'  => 'map-pin',
				'label' => __( 'IP location vs billing', 'woocommerce-anti-fraud' ),
				'help'  => __( 'The IP is in a different country, or a different state or region, from the billing address.', 'woocommerce-anti-fraud' ),
			),
			'ip_spread' => array(
				'icon'  => 'network',
				'label' => __( 'IPs per device', 'woocommerce-anti-fraud' ),
				'help'  => __( 'One device checked out from many different IPs in a short time. Mobile and iCloud Private Relay addresses don\'t count.', 'woocommerce-anti-fraud' ),
			),
			'paste'     => array(
				'icon'  => 'clipboard-paste',
				'label' => __( 'Pasted details', 'woocommerce-anti-fraud' ),
				'help'  => __( 'Name, address, email or phone were pasted in rather than typed or autofilled, as when working from a list of stolen details.', 'woocommerce-anti-fraud' ),
			),
			'timezone'  => array(
				'icon'  => 'clock',
				'label' => __( 'Browser timezone', 'woocommerce-anti-fraud' ),
				'help'  => __( 'The browser\'s clock is set to a different timezone from the IP\'s location, common with residential proxies.', 'woocommerce-anti-fraud' ),
			),
			'phone'     => array(
				'icon'  => 'phone',
				'label' => __( 'Phone area code', 'woocommerce-anti-fraud' ),
				'help'  => __( 'A US or Canadian phone number from a different state than the billing address. Weak on its own: people keep their numbers when they move.', 'woocommerce-anti-fraud' ),
			),
		);

		foreach ( $factors as $key => $factor ) {
			$factors[ $key ]['max'] = self::FACTOR_MAX[ $key ];
		}

		return $factors;
	}

	/**
	 * Score an order at checkout, from the current request and the browser checks.
	 *
	 * @param WC_Order   $order   Order.
	 * @param array|null $payload WooCommerce_Anti_Fraud_Collector::parse_payload() result, or null.
	 * @param string     $step    checkout or order_pay.
	 * @return array|null The report, or null when the order is out of scope.
	 */
	public static function score_live( WC_Order $order, $payload, $step ) {
		if ( ! self::in_scope( $order ) ) {
			return null;
		}

		$client   = WooCommerce_Anti_Fraud_Request::client();
		$ip       = $client['ip'];
		$info     = WooCommerce_Anti_Fraud_IP_Intel::lookup( $ip, true );
		$identity = WooCommerce_Anti_Fraud_Collector::identity( $payload, $ip );
		$ua       = WooCommerce_Anti_Fraud_Request::user_agent();
		$first    = '' === (string) $order->get_meta( '_wcaf_score' );

		if ( '' === $ua ) {
			$ua = (string) $order->get_customer_user_agent();
		}

		$visitor_id = WooCommerce_Anti_Fraud_Identity::upsert_visitor( $identity['hash'], $identity['source'], $identity['details'] );

		// Look at the device's earlier IPs before adding this one.
		$other_countries = WooCommerce_Anti_Fraud_Identity::other_countries( $visitor_id, $ip );

		WooCommerce_Anti_Fraud_Identity::record_ip( $visitor_id, $info );
		if ( $first ) {
			WooCommerce_Anti_Fraud_Identity::bump( $visitor_id, 'order_count' );
		}

		$shared  = in_array( $info['type'], WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true );
		$browser = is_array( $payload );
		$webrtc  = array(
			'checked'   => $browser && ! empty( $payload['rtc_on'] ),
			'available' => $browser && ! empty( $payload['rtc_ok'] ),
			'ips'       => $browser ? $payload['rtc'] : array(),
			'status'    => 'none',
			'leak'      => '',
			'leak_info' => array(),
		);

		if ( $webrtc['checked'] ) {
			$webrtc = array_merge(
				$webrtc,
				WooCommerce_Anti_Fraud_Checks::webrtc(
					$ip,
					$info,
					$webrtc['ips'],
					function ( $leak ) {
						return WooCommerce_Anti_Fraud_IP_Intel::lookup( $leak );
					}
				)
			);
		}

		$page_ip  = $browser ? WooCommerce_Anti_Fraud_Collector::verify_token( $payload['token'] ) : '';
		$last_ip  = $browser ? $payload['last_ip'] : '';
		$last_age = $browser ? (int) $payload['last_age'] : -1;
		$fields   = $browser ? $payload['fields'] : array();
		$tz       = $browser ? $payload['tz'] : '';
		$tzo      = $browser ? $payload['tzo'] : null;

		$report = array(
			'v'        => self::REPORT_VERSION,
			'step'     => $step,
			'browser'  => $browser,
			'ip'       => array(
				'ip'       => $ip,
				'source'   => $client['source'],
				'remote'   => $client['remote'],
				'order_ip' => (string) $order->get_customer_ip_address(),
				'info'     => $info,
			),
			'device'   => array(
				'fingerprint' => $identity['hash'],
				'source'      => $identity['source'],
				'visitor'     => $visitor_id,
				'details'     => $identity['details'],
				'ua'          => $ua,
				'type'        => WooCommerce_Anti_Fraud_Checks::device_type( $ua ),
			),
			'webrtc'   => $webrtc,
			'session'  => array(
				'page_ip'  => $page_ip,
				'last_ip'  => $last_ip,
				'last_age' => $last_age,
				'elapsed'  => $browser ? (int) $payload['elapsed'] : 0,
			),
			'fields'   => $fields,
			'tz'       => array(
				'name'   => $tz,
				'offset' => $tzo,
			),
			'checks'   => array(
				'geo'             => WooCommerce_Anti_Fraud_Checks::geo( $info, $order ),
				'timezone'        => WooCommerce_Anti_Fraud_Checks::timezone( $tz, $tzo, $info['timezone'] ),
				'phone'           => WooCommerce_Anti_Fraud_Checks::phone( (string) $order->get_billing_phone(), (string) $order->get_billing_country(), (string) $order->get_billing_state() ),
				'paste'           => WooCommerce_Anti_Fraud_Checks::paste_count( $fields ),
				'changed_on_page' => ! $shared && self::ip_changed( $page_ip, $ip, true ),
				'changed_session' => ! $shared && $last_age >= 0 && $last_age < HOUR_IN_SECONDS && $last_ip !== $page_ip && self::ip_changed( $last_ip, $ip, false ),
				'new_country'     => '' !== $info['country'] && $other_countries && ! in_array( $info['country'], $other_countries, true ) && ! in_array( $info['type'], array( 'CPN', 'VPN', 'TOR', 'DCH' ), true ),
				'other_countries' => $other_countries,
			),
			'rescored' => 0,
		);

		$report = self::evaluate( $order, $report );

		self::save( $order, $report, $first, $identity['hash'], $visitor_id, in_array( $client['source'], array( 'direct', 'cloudflare', 'proxy' ), true ) ? $ip : '' );

		if ( $first ) {
			self::after_first_score( $order, $report );
		}

		if ( ! self::$rescoring ) {
			self::schedule_rescore( (int) $order->get_id(), $identity['hash'], self::links( $report ) );
		}

		return $report;
	}

	/**
	 * Score an order that didn't come through the checkout page (created
	 * elsewhere, or placed before the plugin was active). Uses the IP
	 * WooCommerce stored with the order. There's no browser data, and that
	 * IP isn't verified, so it's shown but not used to link orders together.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $step  fallback or manual.
	 * @return array|null
	 */
	public static function score_offline( WC_Order $order, $step ) {
		$ip   = WooCommerce_Anti_Fraud_Request::valid_ip( (string) $order->get_customer_ip_address() );
		$info = WooCommerce_Anti_Fraud_IP_Intel::lookup( $ip );
		$ua   = (string) $order->get_customer_user_agent();

		$report = array(
			'v'        => self::REPORT_VERSION,
			'step'     => $step,
			'browser'  => false,
			'ip'       => array(
				'ip'       => $ip,
				'source'   => 'order',
				'remote'   => '',
				'order_ip' => $ip,
				'info'     => $info,
			),
			'device'   => array(
				'fingerprint' => '',
				'source'      => 'order',
				'visitor'     => 0,
				'details'     => array(),
				'ua'          => $ua,
				'type'        => WooCommerce_Anti_Fraud_Checks::device_type( $ua ),
			),
			'webrtc'   => array(
				'checked'   => false,
				'available' => false,
				'ips'       => array(),
				'status'    => 'none',
				'leak'      => '',
				'leak_info' => array(),
			),
			'session'  => array(
				'page_ip'  => '',
				'last_ip'  => '',
				'last_age' => -1,
				'elapsed'  => 0,
			),
			'fields'   => array(),
			'tz'       => array(
				'name'   => '',
				'offset' => null,
			),
			'checks'   => array(
				'geo'             => WooCommerce_Anti_Fraud_Checks::geo( $info, $order ),
				'timezone'        => WooCommerce_Anti_Fraud_Checks::timezone( '', null, '' ),
				'phone'           => WooCommerce_Anti_Fraud_Checks::phone( (string) $order->get_billing_phone(), (string) $order->get_billing_country(), (string) $order->get_billing_state() ),
				'paste'           => 0,
				'changed_on_page' => false,
				'changed_session' => false,
				'new_country'     => false,
				'other_countries' => array(),
			),
			'rescored' => 0,
		);

		$first  = '' === (string) $order->get_meta( '_wcaf_score' );
		$report = self::evaluate( $order, $report );

		self::save( $order, $report, $first, '', 0, '' );

		return $report;
	}

	/**
	 * Re-run the counting signals for an already-scored order against the
	 * data as it stands now, keeping everything captured at checkout.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null The new report, or null if the order was never scored.
	 */
	public static function rescore( WC_Order $order ) {
		$report = self::report( $order );
		if ( ! $report ) {
			return null;
		}

		$report['rescored'] = isset( $report['rescored'] ) ? (int) $report['rescored'] + 1 : 1;
		$report             = self::evaluate( $order, $report );

		self::save( $order, $report, false, (string) $report['device']['fingerprint'], (int) $report['device']['visitor'], (string) $order->get_meta( '_wcaf_ip' ) );

		return $report;
	}

	/**
	 * Count the related orders, failures and IPs, then work out every
	 * deduction and the score.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report so far.
	 * @return array Report with counts, factors, score and risk.
	 */
	private static function evaluate( WC_Order $order, array $report ) {
		$plugin   = WooCommerce_Anti_Fraud_Plugin::instance();
		$info     = $report['ip']['info'];
		$type     = isset( $info['type'] ) ? $info['type'] : '';
		$fp       = (string) $report['device']['fingerprint'];
		$visitor  = (int) $report['device']['visitor'];
		$window   = WooCommerce_Anti_Fraud_Declines::window();
		$links    = self::links( $report );
		$order_ip = self::fanout_ip( $report );

		$spread = self::address_spread( $order, $links );
		$counts = array(
			'orders'        => count( self::related_order_ids( $fp, $links, 48, 50, (int) $order->get_id() ) ),
			'declines'      => WooCommerce_Anti_Fraud_Declines::count( $fp, $links, $window ),
			'ips'           => WooCommerce_Anti_Fraud_Identity::ip_count( $visitor, $window ),
			'devices_on_ip' => '' !== $order_ip ? WooCommerce_Anti_Fraud_Identity::devices_on_ip( $order_ip, $window ) : 0,
			'addresses'     => $spread['addresses'],
			'regions'       => $spread['regions'],
			'address_list'  => $spread['list'],
		);

		$checks = $report['checks'];
		$webrtc = $report['webrtc'];

		$deductions = array(
			'proxy'     => self::proxy_deduction( $type, $webrtc, $order ),
			'declines'  => self::step( $counts['declines'], self::LADDERS['declines'] ),
			'ip_change' => ( $checks['changed_on_page'] ? 20 : 0 ) + ( $checks['changed_session'] ? 10 : 0 ) + ( $counts['devices_on_ip'] >= 4 ? 20 : 0 ) + ( $checks['new_country'] ? 10 : 0 ),
			'velocity'  => self::step( $counts['orders'], self::LADDERS['velocity'] ),
			'addresses' => $counts['addresses'] >= 3 && $counts['regions'] >= 3 ? 30 : 0,
			'geo'       => $checks['geo']['country'] ? 25 : ( $checks['geo']['region'] ? 10 : 0 ),
			'ip_spread' => self::step( $counts['ips'], self::LADDERS['ip_spread'] ),
			'paste'     => self::step( (int) $checks['paste'], self::LADDERS['paste'] ),
			'timezone'  => 'mismatch' === $checks['timezone']['match'] ? 15 : 0,
			'phone'     => 'mismatch' === $checks['phone']['match'] ? 5 : 0,
		);

		$factors = array();
		$total   = 0;

		foreach ( $deductions as $key => $points ) {
			if ( ! $plugin->factor_on( $key ) ) {
				$factors[ $key ] = null;
				continue;
			}

			/**
			 * Filter how many points a signal takes off an order's score.
			 *
			 * @param int      $points Points to take off (0 when the signal is clean).
			 * @param string   $key    Signal key, e.g. proxy, declines, geo.
			 * @param array    $report Everything captured for the order.
			 * @param WC_Order $order  Order.
			 */
			$points          = (int) apply_filters( 'woocommerce_anti_fraud_deduction', $points, $key, $report, $order );
			$points          = max( 0, min( self::FACTOR_MAX[ $key ], $points ) );
			$factors[ $key ] = $points;
			$total          += $points;
		}

		/**
		 * Filter the final 0-100 score (100 = nothing suspicious).
		 *
		 * @param int      $score  Score.
		 * @param array    $report Everything captured for the order.
		 * @param WC_Order $order  Order.
		 */
		$score = (int) apply_filters( 'woocommerce_anti_fraud_score', max( 0, 100 - $total ), $report, $order );
		$score = max( 0, min( 100, $score ) );

		$report['counts']    = $counts;
		$report['factors']   = $factors;
		$report['score']     = $score;
		$report['risk']      = self::risk( $score );
		$report['scored_at'] = current_time( 'mysql', true );

		return $report;
	}

	/**
	 * Points off for the connection type and the WebRTC check (the larger of the two).
	 *
	 * @param string   $type   Connection type of the order IP.
	 * @param array    $webrtc WebRTC result.
	 * @param WC_Order $order  Order.
	 * @return int
	 */
	private static function proxy_deduction( $type, array $webrtc, WC_Order $order ) {
		$by_type = array(
			'TOR' => 50,
			'VPN' => 30,
			'DCH' => 25,
		);

		$points = isset( $by_type[ $type ] ) ? $by_type[ $type ] : 0;

		if ( 'mismatch' === $webrtc['status'] ) {
			$leak_country = isset( $webrtc['leak_info']['country'] ) ? $webrtc['leak_info']['country'] : '';
			$rtc          = 35;
			// The real connection is in another country from the card's billing address.
			if ( '' !== $leak_country && '' !== $order->get_billing_country() && strtoupper( $order->get_billing_country() ) !== $leak_country ) {
				$rtc = 45;
			}
			$points = max( $points, $rtc );
		} elseif ( 'mobile_mismatch' === $webrtc['status'] ) {
			$points = max( $points, 20 );
		}

		return $points;
	}

	/**
	 * Points for a count, from a "count => points" ladder (highest rung first).
	 *
	 * @param int            $count  Count.
	 * @param array<int,int> $ladder Thresholds, highest first.
	 * @return int
	 */
	private static function step( $count, array $ladder ) {
		foreach ( $ladder as $threshold => $points ) {
			if ( $count >= $threshold ) {
				return $points;
			}
		}

		return 0;
	}

	/**
	 * Whether the visitor really moved to a different connection between two
	 * IPs. A new address from the same provider block (a DHCP renewal), or a
	 * switch to or from a mobile carrier or iCloud Private Relay (a phone
	 * leaving Wi-Fi), doesn't count.
	 *
	 * @param string $from    Earlier IP.
	 * @param string $ip      Order IP.
	 * @param bool   $trusted Whether $from was verified by this plugin. An IP
	 *                        the browser reports is only checked against
	 *                        lookups already cached, so a visitor can't make
	 *                        the site look up addresses of their choosing.
	 * @return bool
	 */
	private static function ip_changed( $from, $ip, $trusted ) {
		if ( '' === (string) $from || '' === (string) $ip || $from === $ip || WooCommerce_Anti_Fraud_Request::same_network( $from, $ip ) ) {
			return false;
		}

		$before = $trusted ? WooCommerce_Anti_Fraud_IP_Intel::lookup( $from ) : WooCommerce_Anti_Fraud_IP_Intel::cached( $from );

		return ! in_array( $before['type'], WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true );
	}

	/**
	 * low, medium or high for a score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	public static function risk( $score ) {
		$plugin = WooCommerce_Anti_Fraud_Plugin::instance();
		$high   = (int) $plugin->get_setting( 'high_below' );
		$review = max( $high, (int) $plugin->get_setting( 'review_below' ) );

		if ( $score < $high ) {
			return 'high';
		}

		return $score < $review ? 'medium' : 'low';
	}

	/**
	 * Save the report and the fields other code looks up.
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $report      Report.
	 * @param bool     $first       Whether this is the order's first score.
	 * @param string   $fingerprint Device fingerprint ('' for none).
	 * @param int      $visitor_id  Device ID.
	 * @param string   $ip          Verified IP ('' for none).
	 * @return void
	 */
	private static function save( WC_Order $order, array $report, $first, $fingerprint, $visitor_id, $ip ) {
		$order->update_meta_data( '_wcaf_score', (int) $report['score'] );
		$order->update_meta_data( '_wcaf_risk', $report['risk'] );
		$order->update_meta_data( '_wcaf_report', wp_json_encode( $report ) );
		$order->update_meta_data( '_wcaf_scored_at', $report['scored_at'] );

		if ( '' !== $fingerprint ) {
			$order->update_meta_data( '_wcaf_fingerprint', $fingerprint );
			$order->update_meta_data( '_wcaf_visitor', (int) $visitor_id );
		}
		if ( '' !== $ip ) {
			$order->update_meta_data( '_wcaf_ip', $ip );
		}

		// The real IP the WebRTC check found behind a proxy links this
		// order to others from the same person, whatever proxy they use.
		$leak = self::leak_ip( $report );
		if ( '' !== $leak ) {
			$order->update_meta_data( '_wcaf_leak', $leak );
		} else {
			$order->delete_meta_data( '_wcaf_leak' );
		}

		if ( $first ) {
			$order->update_meta_data( '_wcaf_initial', (int) $report['score'] );
		}

		$order->save();

		/**
		 * Fires after an order has been scored or re-scored.
		 *
		 * @param WC_Order $order  Order.
		 * @param int      $score  0-100.
		 * @param string   $risk   low, medium or high.
		 * @param array    $report Everything captured for the order.
		 */
		do_action( 'woocommerce_anti_fraud_order_scored', $order, (int) $report['score'], $report['risk'], $report );
	}

	/**
	 * Order note and alerts the first time an order is scored.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private static function after_first_score( WC_Order $order, array $report ) {
		if ( 'low' !== $report['risk'] ) {
			$order->add_order_note( self::summary( $report ) );
		}

		if ( 'high' === $report['risk'] ) {
			WooCommerce_Anti_Fraud_Actions::maybe_alert( 'high_risk', 1, array( 'order' => (int) $order->get_id() ), $order );
		}

		WooCommerce_Anti_Fraud_Actions::maybe_alert(
			'orders',
			(int) $report['counts']['orders'],
			array(
				'fingerprint' => (string) $report['device']['fingerprint'],
				'ip'          => in_array( $report['ip']['info']['type'], WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true ) ? '' : (string) $report['ip']['ip'],
			),
			$order
		);
	}

	/**
	 * "Fraud check: 42/100, high risk. VPN or proxy (-30), Failed payments (-15)."
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function summary( array $report ) {
		$labels  = self::factors();
		$reasons = array();
		$factors = array_filter( (array) $report['factors'] );

		arsort( $factors );

		foreach ( array_slice( $factors, 0, 3, true ) as $key => $points ) {
			$reasons[] = sprintf( '%s (-%d)', $labels[ $key ]['label'], $points );
		}

		$risk = self::risk_label( $report['risk'] );

		/* translators: 1: score, 2: risk level, e.g. "High risk". */
		$text = sprintf( __( 'Fraud check: %1$d/100, %2$s.', 'woocommerce-anti-fraud' ), (int) $report['score'], strtolower( $risk ) );

		return $reasons ? $text . ' ' . implode( ', ', $reasons ) . '.' : $text;
	}

	/**
	 * Display label for a risk level.
	 *
	 * @param string $risk low, medium or high.
	 * @return string
	 */
	public static function risk_label( $risk ) {
		$labels = array(
			'low'    => __( 'Low risk', 'woocommerce-anti-fraud' ),
			'medium' => __( 'Needs review', 'woocommerce-anti-fraud' ),
			'high'   => __( 'High risk', 'woocommerce-anti-fraud' ),
		);

		return isset( $labels[ $risk ] ) ? $labels[ $risk ] : __( 'Not checked', 'woocommerce-anti-fraud' );
	}

	/**
	 * The saved report for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function report( WC_Order $order ) {
		$report = json_decode( (string) $order->get_meta( '_wcaf_report' ), true );

		return is_array( $report ) && isset( $report['v'], $report['ip'], $report['device'], $report['checks'] ) ? $report : null;
	}

	/**
	 * Whether an order should be checked (all orders, or only orders with
	 * the chosen products or categories).
	 *
	 * @param WC_Order|WC_Cart|null $source Order or cart.
	 * @return bool
	 */
	public static function in_scope( $source ) {
		$plugin = WooCommerce_Anti_Fraud_Plugin::instance();
		$result = true;

		if ( 'products' === $plugin->get_setting( 'scope' ) ) {
			$result = false;
			$ids    = array_map( 'absint', (array) $plugin->get_setting( 'product_ids' ) );
			$cats   = array_map( 'absint', (array) $plugin->get_setting( 'product_cats' ) );

			foreach ( self::product_ids( $source ) as $product_id ) {
				$parent = (int) wp_get_post_parent_id( $product_id );
				if ( in_array( $product_id, $ids, true ) || ( $parent && in_array( $parent, $ids, true ) ) ) {
					$result = true;
					break;
				}
				if ( $cats && array_intersect( $cats, self::term_ids( $parent ? $parent : $product_id ) ) ) {
					$result = true;
					break;
				}
			}
		}

		/**
		 * Filter whether an order or cart gets fraud checks.
		 *
		 * @param bool                  $result Whether it's in scope.
		 * @param WC_Order|WC_Cart|null $source Order or cart.
		 */
		return (bool) apply_filters( 'woocommerce_anti_fraud_in_scope', $result, $source );
	}

	/**
	 * Product IDs (variation IDs for variations) in an order or cart.
	 *
	 * @param WC_Order|WC_Cart|null $source Order or cart.
	 * @return int[]
	 */
	private static function product_ids( $source ) {
		$ids = array();

		if ( $source instanceof WC_Order ) {
			foreach ( $source->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product ) {
					$ids[] = (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
				}
			}
		} elseif ( $source instanceof WC_Cart ) {
			foreach ( $source->get_cart() as $line ) {
				$ids[] = (int) ( ! empty( $line['variation_id'] ) ? $line['variation_id'] : ( isset( $line['product_id'] ) ? $line['product_id'] : 0 ) );
			}
		}

		return array_filter( $ids );
	}

	/**
	 * A product's categories, with every parent category included.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	private static function term_ids( $product_id ) {
		$terms = wc_get_product_term_ids( $product_id, 'product_cat' );
		$all   = array_map( 'intval', $terms );

		foreach ( $terms as $term_id ) {
			$all = array_merge( $all, array_map( 'intval', get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ) );
		}

		return array_values( array_unique( $all ) );
	}

	/**
	 * The order IP, when it can link orders: verified by this plugin, public,
	 * not on a network strangers share (mobile carrier, iCloud Private Relay),
	 * and not crowded (see crowded()).
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function link_ip( array $report ) {
		$type = isset( $report['ip']['info']['type'] ) ? $report['ip']['info']['type'] : '';
		$ip   = isset( $report['ip']['ip'] ) ? (string) $report['ip']['ip'] : '';

		if ( ! in_array( $report['ip']['source'], array( 'direct', 'cloudflare', 'proxy' ), true ) || in_array( $type, WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true ) || ! WooCommerce_Anti_Fraud_Request::is_public( $ip ) || self::crowded( $ip ) ) {
			return '';
		}

		return $ip;
	}

	/**
	 * The order IP for the "many devices on this IP" signal: like link_ip(),
	 * but it still counts when the IP is crowded, so a visitor can't switch
	 * the signal off by inventing devices on their own IP.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function fanout_ip( array $report ) {
		$type = isset( $report['ip']['info']['type'] ) ? $report['ip']['info']['type'] : '';
		$ip   = isset( $report['ip']['ip'] ) ? (string) $report['ip']['ip'] : '';

		if ( ! in_array( $report['ip']['source'], array( 'direct', 'cloudflare', 'proxy' ), true ) || in_array( $type, WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true ) || ! WooCommerce_Anti_Fraud_Request::is_public( $ip ) ) {
			return '';
		}

		return $ip;
	}

	/**
	 * The real IP the WebRTC check found behind a proxy, when it can link
	 * orders (public, and not a shared mobile or relay address).
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function leak_ip( array $report ) {
		$webrtc = isset( $report['webrtc'] ) ? (array) $report['webrtc'] : array();
		$status = isset( $webrtc['status'] ) ? $webrtc['status'] : '';
		$type   = isset( $webrtc['leak_info']['type'] ) ? $webrtc['leak_info']['type'] : '';
		$leak   = isset( $webrtc['leak'] ) ? WooCommerce_Anti_Fraud_Request::valid_ip( (string) $webrtc['leak'] ) : '';

		if ( 'mismatch' !== $status || ! WooCommerce_Anti_Fraud_Request::is_public( $leak ) || in_array( $type, WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true ) ) {
			return '';
		}

		return $leak;
	}

	/**
	 * The IPs that link this order to others.
	 *
	 * "verified" is the order IP, which the plugin resolved itself. "claimed"
	 * is the real IP the browser reported behind a proxy. A claim only ever
	 * counts against the order that made it: it's matched against other
	 * orders' verified IPs and claims, but other orders' claims are never
	 * matched against this order's verified IP. Otherwise anyone could put a
	 * stranger's IP in their own browser data and drag that stranger's
	 * orders down (or get them blocked).
	 *
	 * @param array $report Report.
	 * @return array{verified:string[],claimed:string[]}
	 */
	public static function links( array $report ) {
		$verified = self::link_ip( $report );
		$claimed  = self::leak_ip( $report );

		return array(
			'verified' => '' !== $verified ? array( $verified ) : array(),
			'claimed'  => '' !== $claimed && $claimed !== $verified ? array( $claimed ) : array(),
		);
	}

	/**
	 * Clean linking IPs. A plain list (from older scheduled jobs) counts as verified.
	 *
	 * @param mixed $links links() result, or a list of IPs.
	 * @return array{verified:string[],claimed:string[]}
	 */
	public static function normalize_links( $links ) {
		$links = is_array( $links ) ? $links : array();

		if ( ! isset( $links['verified'] ) && ! isset( $links['claimed'] ) ) {
			$links = array( 'verified' => $links );
		}

		$out = array();
		foreach ( array( 'verified', 'claimed' ) as $kind ) {
			$list         = isset( $links[ $kind ] ) ? array_slice( (array) $links[ $kind ], 0, 4 ) : array();
			$out[ $kind ] = array_values( array_unique( array_filter( array_map( array( 'WooCommerce_Anti_Fraud_Request', 'valid_ip' ), $list ) ) ) );
		}

		return $out;
	}

	/**
	 * Whether so many different devices use an IP that it can't be one
	 * person: a CDN or firewall edge (on a site where it isn't set up as a
	 * trusted proxy), an office or campus, a big VPN exit. Such an IP never
	 * links orders or blocks checkouts.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function crowded( $ip ) {
		static $memo = array();

		if ( '' === (string) $ip ) {
			return false;
		}
		if ( ! isset( $memo[ $ip ] ) ) {
			/**
			 * Filter how many devices on one IP in 24 hours make it a shared address.
			 *
			 * @param int $devices Default 20.
			 */
			$limit       = max( 2, (int) apply_filters( 'woocommerce_anti_fraud_shared_ip_devices', 20 ) );
			$memo[ $ip ] = WooCommerce_Anti_Fraud_Identity::devices_on_ip( $ip, 24 ) >= $limit;
		}

		return $memo[ $ip ];
	}

	/**
	 * Distinct addresses used with an IP in the last 7 days.
	 *
	 * @param WC_Order $order Current order (counted too).
	 * @param array    $links links() result.
	 * @return array{addresses:int,regions:int,list:array}
	 */
	private static function address_spread( WC_Order $order, $links ) {
		$out = array(
			'addresses' => 0,
			'regions'   => 0,
			'list'      => array(),
		);

		$links = self::normalize_links( $links );
		if ( ! $links['verified'] && ! $links['claimed'] ) {
			return $out;
		}

		$ids   = self::related_order_ids( '', $links, 168, 60, (int) $order->get_id() );
		$found = array();

		foreach ( $ids as $id ) {
			$other = (int) $order->get_id() === $id ? $order : wc_get_order( $id );
			if ( ! $other instanceof WC_Order ) {
				continue;
			}
			foreach ( array( 'billing', 'shipping' ) as $side ) {
				$line1   = trim( (string) call_user_func( array( $other, "get_{$side}_address_1" ) ) );
				$city    = trim( (string) call_user_func( array( $other, "get_{$side}_city" ) ) );
				$state   = trim( (string) call_user_func( array( $other, "get_{$side}_state" ) ) );
				$country = trim( (string) call_user_func( array( $other, "get_{$side}_country" ) ) );
				if ( '' === $line1 || '' === $state ) {
					continue;
				}
				$key = strtolower( preg_replace( '/[^a-z0-9]/i', '', $line1 . $city . $state . $country ) );
				if ( ! isset( $found[ $key ] ) ) {
					$found[ $key ] = array(
						'order'   => (int) $other->get_id(),
						'address' => implode( ', ', array_filter( array( $line1, $city, $state, $country ) ) ),
						'region'  => strtoupper( $country . '-' . $state ),
					);
				}
			}
		}

		$out['addresses'] = count( $found );
		$out['regions']   = count( array_unique( wp_list_pluck( $found, 'region' ) ) );
		$out['list']      = array_slice( array_values( $found ), 0, 20 );

		return $out;
	}

	/**
	 * IDs of scored orders sharing a fingerprint or IP within the window,
	 * this order included. Verified IPs match other orders' verified IPs;
	 * claimed IPs (see links()) also match other orders' claims. Plain SQL on
	 * whichever order storage is active.
	 *
	 * @param string $fingerprint Fingerprint ('' to skip).
	 * @param array  $links       links() result.
	 * @param int    $hours       Window.
	 * @param int    $limit       Maximum IDs.
	 * @param int    $include_id  An order ID to always include (0 for none).
	 * @return int[]
	 */
	public static function related_order_ids( $fingerprint, $links, $hours, $limit = 50, $include_id = 0 ) {
		global $wpdb;

		$ids    = $include_id ? array( (int) $include_id ) : array();
		$pairs  = array();
		$params = array();
		$links  = self::normalize_links( $links );
		$all    = array_values( array_unique( array_merge( $links['verified'], $links['claimed'] ) ) );

		if ( preg_match( '/^[a-f0-9]{64}$/', (string) $fingerprint ) ) {
			$pairs[]  = "( m.meta_key = '_wcaf_fingerprint' AND m.meta_value = %s )";
			$params[] = $fingerprint;
		}
		if ( $all ) {
			$pairs[] = "( m.meta_key = '_wcaf_ip' AND m.meta_value IN ( " . implode( ', ', array_fill( 0, count( $all ), '%s' ) ) . ' ) )';
			$params  = array_merge( $params, $all );
		}
		if ( $links['claimed'] ) {
			$pairs[] = "( m.meta_key = '_wcaf_leak' AND m.meta_value IN ( " . implode( ', ', array_fill( 0, count( $links['claimed'] ), '%s' ) ) . ' ) )';
			$params  = array_merge( $params, $links['claimed'] );
		}
		if ( ! $pairs ) {
			return $ids;
		}

		$params[] = WooCommerce_Anti_Fraud_Identity::since( $hours );
		$params[] = max( 1, (int) $limit );
		$where    = implode( ' OR ', $pairs );

		if ( self::hpos() ) {
			$sql = "SELECT DISTINCT o.id FROM {$wpdb->prefix}wc_orders o INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id
				WHERE ( {$where} ) AND o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' ) AND o.date_created_gmt >= %s
				ORDER BY o.id DESC LIMIT %d";
		} else {
			$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE ( {$where} ) AND p.post_type = 'shop_order' AND p.post_status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' ) AND p.post_date_gmt >= %s
				ORDER BY p.ID DESC LIMIT %d";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Placeholders only; table names from $wpdb.
		$found = (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_values( array_unique( array_merge( $ids, array_map( 'intval', $found ) ) ) );
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS).
	 *
	 * @return bool
	 */
	public static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Queue a background re-score of orders related to a device or IP.
	 * Identical events within ten minutes collapse into one.
	 *
	 * @param int    $trigger     Order that triggered it (skipped), or 0 to include every related order.
	 * @param string $fingerprint Fingerprint.
	 * @param array  $links       links() result.
	 * @return void
	 */
	public static function schedule_rescore( $trigger, $fingerprint, $links ) {
		$links = self::normalize_links( $links );

		if ( '' === (string) $fingerprint && ! $links['verified'] && ! $links['claimed'] ) {
			return;
		}

		$args = array( (int) $trigger, (string) $fingerprint, $links );

		if ( ! wp_next_scheduled( self::CRON_RESCORE, $args ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_RESCORE, $args );
		}
	}

	/**
	 * Cron: re-score orders related to a device or IP, and note any change.
	 *
	 * @param int    $trigger     Order to skip (0 for none).
	 * @param string $fingerprint Fingerprint.
	 * @param array  $links       links() result.
	 * @return void
	 */
	public static function cron_rescore( $trigger = 0, $fingerprint = '', $links = array() ) {
		if ( self::$rescoring ) {
			return;
		}

		self::$rescoring = true;

		try {
			foreach ( self::related_order_ids( (string) $fingerprint, $links, 168, 25 ) as $order_id ) {
				if ( (int) $trigger === $order_id ) {
					continue;
				}
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order || '' === (string) $order->get_meta( '_wcaf_score' ) ) {
					continue;
				}

				$before = (int) $order->get_meta( '_wcaf_score' );
				$report = self::rescore( $order );

				if ( $report && (int) $report['score'] !== $before ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: old score, 2: new score. */
							__( 'Fraud score updated from %1$d to %2$d after new orders or failed payments from the same device or IP.', 'woocommerce-anti-fraud' ),
							$before,
							(int) $report['score']
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'scorer.cron_rescore', $e );
		}

		self::$rescoring = false;
	}
}
