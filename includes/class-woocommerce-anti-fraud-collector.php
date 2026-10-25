<?php
/**
 * The checkout side: loads the browser checks on the checkout and pay-for-order
 * pages, then reads what they found from the order submission itself.
 *
 * There's no separate endpoint for the browser data. It rides along in the
 * checkout request (a hidden field on the classic checkout, extension data
 * on the block checkout), so it's there at the moment the order is placed,
 * and nothing new is open to the internet.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout integration.
 */
final class WooCommerce_Anti_Fraud_Collector {

	/**
	 * Hidden field (classic checkout) and Store API namespace (block checkout).
	 */
	const FIELD        = 'wcaf_payload';
	const STORE_API_NS = 'woocommerce-anti-fraud';

	/**
	 * Largest payload accepted, in bytes.
	 */
	const MAX_PAYLOAD = 16384;

	/**
	 * Block checkout payloads, held between the request hook and order processing.
	 *
	 * @var array<int,array|null>
	 */
	private $store_api_payloads = array();

	/**
	 * Plugin instance.
	 *
	 * @var WooCommerce_Anti_Fraud_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param WooCommerce_Anti_Fraud_Plugin $plugin Plugin instance.
	 */
	public function __construct( WooCommerce_Anti_Fraud_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		// Classic checkout.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'classic_validation' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'classic_order' ), 20, 3 );

		// Block checkout (Store API).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'store_api_request' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'store_api_order' ), 20 );

		// Pay for order.
		add_action( 'woocommerce_before_pay_action', array( $this, 'pay_action' ), 5 );

		// Orders placed some other way (express buttons on product pages, etc.).
		add_action( 'woocommerce_order_status_changed', array( $this, 'fallback' ), 30, 4 );
	}

	/**
	 * Load the browser checks on the checkout and pay-for-order pages.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$checks = array(
			'fingerprint' => (bool) $this->plugin->get_setting( 'fingerprint' ),
			'webrtc'      => (bool) $this->plugin->get_setting( 'webrtc' ),
			'paste'       => (bool) $this->plugin->get_setting( 'paste' ),
		);

		if ( ! array_filter( $checks ) ) {
			return;
		}

		$source = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			$source = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		}
		if ( ! WooCommerce_Anti_Fraud_Scorer::in_scope( $source ) ) {
			return;
		}

		$ip  = WooCommerce_Anti_Fraud_Request::ip();
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script( 'woocommerce-anti-fraud', WOOCOMMERCE_ANTI_FRAUD_URL . 'assets/collector' . $min . '.js', array(), WOOCOMMERCE_ANTI_FRAUD_VERSION, true );

		wp_localize_script(
			'woocommerce-anti-fraud',
			'wcafCollector',
			array(
				'field'  => self::FIELD,
				'ns'     => self::STORE_API_NS,
				'ip'     => $ip,
				'token'  => self::token( $ip ),
				'stun'   => self::stun_server(),
				'checks' => array_map( 'intval', $checks ),
			)
		);
	}

	/**
	 * The STUN server the WebRTC check asks for the browser's address.
	 *
	 * @return string
	 */
	public static function stun_server() {
		$stun = trim( (string) WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'stun_server' ) );

		return self::valid_stun( $stun ) ? $stun : 'stun:stun.l.google.com:19302';
	}

	/**
	 * Whether a string is a stun: or stuns: URL.
	 *
	 * @param string $stun Value.
	 * @return bool
	 */
	public static function valid_stun( $stun ) {
		return (bool) preg_match( '/^stuns?:[a-z0-9.\-]{1,253}(?::\d{1,5})?$/i', (string) $stun );
	}

	/**
	 * A signed note of the IP the checkout page was served to. If the order
	 * arrives from a different IP, the visitor switched connection (VPN on,
	 * VPN off) while on the page. Signed so it can't be made up.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	public static function token( $ip ) {
		if ( '' === $ip ) {
			return '';
		}

		$time = time();

		return $ip . '|' . $time . '|' . substr( hash_hmac( 'sha256', $ip . '|' . $time, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * The IP in a valid, recent token, or ''.
	 *
	 * @param string $token Token from token().
	 * @return string
	 */
	public static function verify_token( $token ) {
		$parts = explode( '|', (string) $token );

		if ( 3 !== count( $parts ) || '' === WooCommerce_Anti_Fraud_Request::valid_ip( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
			return '';
		}

		$age = time() - (int) $parts[1];
		if ( $age < 0 || $age > 12 * HOUR_IN_SECONDS ) {
			return '';
		}

		$expected = substr( hash_hmac( 'sha256', $parts[0] . '|' . $parts[1], wp_salt( 'nonce' ) ), 0, 32 );

		return hash_equals( $expected, $parts[2] ) ? $parts[0] : '';
	}

	/**
	 * Validate and clean the browser payload. It comes from the visitor, so
	 * every part is checked, trimmed and capped; anything unexpected is dropped.
	 *
	 * @param mixed $raw JSON string.
	 * @return array|null Clean payload, or null when there's none.
	 */
	public static function parse_payload( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_PAYLOAD ) {
			return null;
		}

		$data = json_decode( $raw, true, 6 );
		if ( ! is_array( $data ) || 1 !== ( isset( $data['v'] ) ? (int) $data['v'] : 0 ) ) {
			return null;
		}

		$out = array(
			'device'   => isset( $data['d'] ) && is_string( $data['d'] ) && preg_match( '/^[a-f0-9]{32}$/', $data['d'] ) ? $data['d'] : '',
			'fp'       => array(),
			'rtc_on'   => false,
			'rtc_ok'   => false,
			'rtc'      => array(),
			'last_ip'  => '',
			'last_age' => -1,
			'token'    => isset( $data['t'] ) && is_string( $data['t'] ) ? substr( $data['t'], 0, 128 ) : '',
			'fields'   => array(),
			'tz'       => '',
			'tzo'      => null,
			'elapsed'  => isset( $data['e'] ) && is_numeric( $data['e'] ) ? min( 86400, max( 0, (int) $data['e'] ) ) : 0,
		);

		// Fingerprint parts: short strings and numbers only.
		if ( isset( $data['fp'] ) && is_array( $data['fp'] ) ) {
			foreach ( array( 'c', 'g', 'a', 'f', 's', 'cd', 'pr', 'l', 'ls', 'p', 'hc', 'dm', 'mt', 'tz' ) as $key ) {
				if ( isset( $data['fp'][ $key ] ) && is_scalar( $data['fp'][ $key ] ) ) {
					$out['fp'][ $key ] = substr( sanitize_text_field( (string) $data['fp'][ $key ] ), 0, 'f' === $key ? 400 : 160 );
				}
			}
		}

		// WebRTC: whether it ran, whether the browser has it, and the public IPs it found.
		if ( isset( $data['r'] ) && is_array( $data['r'] ) ) {
			$out['rtc_on'] = ! empty( $data['r']['on'] );
			$out['rtc_ok'] = ! empty( $data['r']['ok'] );
			if ( isset( $data['r']['ips'] ) && is_array( $data['r']['ips'] ) ) {
				foreach ( array_slice( $data['r']['ips'], 0, 8 ) as $ip ) {
					$ip = WooCommerce_Anti_Fraud_Request::valid_ip( $ip );
					if ( '' !== $ip && WooCommerce_Anti_Fraud_Request::is_public( $ip ) && ! in_array( $ip, $out['rtc'], true ) ) {
						$out['rtc'][] = $ip;
					}
				}
			}
		}

		// The IP this browser had on its last checkout visit, and how long ago.
		if ( isset( $data['li'] ) ) {
			$out['last_ip'] = WooCommerce_Anti_Fraud_Request::valid_ip( $data['li'] );
			if ( '' !== $out['last_ip'] && isset( $data['la'] ) && is_numeric( $data['la'] ) ) {
				$out['last_age'] = max( 0, (int) $data['la'] );
			}
		}

		// How each checkout field was filled in: t(yped), p(asted), a(utofilled).
		if ( isset( $data['f'] ) && is_array( $data['f'] ) ) {
			$methods = array(
				't' => 'typed',
				'p' => 'pasted',
				'a' => 'autofill',
			);
			foreach ( $data['f'] as $field => $method ) {
				if ( in_array( $field, WooCommerce_Anti_Fraud_Checks::FIELDS, true ) && is_string( $method ) && isset( $methods[ $method ] ) ) {
					$out['fields'][ $field ] = $methods[ $method ];
				}
			}
		}

		if ( isset( $data['tz'] ) && is_string( $data['tz'] ) && preg_match( '#^[A-Za-z0-9_+\-/]{1,64}$#', $data['tz'] ) ) {
			$out['tz'] = $data['tz'];
		}
		if ( isset( $data['tzo'] ) && is_numeric( $data['tzo'] ) && abs( (int) $data['tzo'] ) <= 900 ) {
			$out['tzo'] = (int) $data['tzo'];
		}

		return $out;
	}

	/**
	 * The device fingerprint for a payload: a hash of the browser's traits
	 * plus a random ID the browser keeps, so two people with the same phone
	 * model aren't mistaken for one device. Without browser data, a hash of
	 * the IP and request headers stands in.
	 *
	 * @param array|null $payload Clean payload.
	 * @param string     $ip      Client IP.
	 * @return array{hash:string,source:string,details:array}
	 */
	public static function identity( $payload, $ip ) {
		if ( is_array( $payload ) && ( $payload['fp'] || '' !== $payload['device'] ) ) {
			$traits = $payload['fp'];
			ksort( $traits );

			return array(
				'hash'    => hash( 'sha256', 'wcaf1|' . $payload['device'] . '|' . wp_json_encode( $traits ) ),
				'source'  => 'browser',
				'details' => self::details( $traits ),
			);
		}

		$headers = '';
		foreach ( array( 'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_SEC_CH_UA', 'HTTP_SEC_CH_UA_PLATFORM' ) as $key ) {
			$headers .= '|' . ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '' );
		}

		return array(
			'hash'    => hash( 'sha256', 'srv|' . $ip . $headers ),
			'source'  => 'server',
			'details' => array(),
		);
	}

	/**
	 * Readable device details for the order screen.
	 *
	 * @param array $fp Fingerprint parts.
	 * @return array<string,string>
	 */
	private static function details( array $fp ) {
		$get = function ( $key ) use ( $fp ) {
			return isset( $fp[ $key ] ) ? (string) $fp[ $key ] : '';
		};

		$fonts = '' !== $get( 'f' ) ? count( array_filter( explode( ',', $get( 'f' ) ) ) ) : 0;

		return array_filter(
			array(
				'screen'   => $get( 's' ) . ( '' !== $get( 'pr' ) && '1' !== $get( 'pr' ) ? ' @' . $get( 'pr' ) . 'x' : '' ),
				'gpu'      => $get( 'g' ),
				'platform' => $get( 'p' ),
				'cores'    => $get( 'hc' ),
				'memory'   => '' !== $get( 'dm' ) && '0' !== $get( 'dm' ) ? $get( 'dm' ) . ' GB' : '',
				'touch'    => $get( 'mt' ),
				'language' => '' !== $get( 'ls' ) ? $get( 'ls' ) : $get( 'l' ),
				'timezone' => $get( 'tz' ),
				'fonts'    => $fonts ? (string) $fonts : '',
			),
			'strlen'
		);
	}

	/**
	 * The payload posted with a classic checkout or pay-for-order form.
	 *
	 * @return array|null
	 */
	private static function posted_payload() {
		// WooCommerce verified the checkout/pay nonce before these hooks run. The
		// value is JSON, validated field by field in parse_payload().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';

		return self::parse_payload( $raw );
	}

	/**
	 * The guard's verdict for a checkout: '' to let it through, or the
	 * message to show. Records refused checkouts.
	 *
	 * @param array|null $payload  Clean payload.
	 * @param string     $email    Billing email.
	 * @param int        $order_id Order ID, if one exists yet.
	 * @return string
	 */
	private static function guard( $payload, $email, $order_id = 0 ) {
		if ( ! WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'block_declines' ) ) {
			return '';
		}

		$ip       = WooCommerce_Anti_Fraud_Request::ip();
		$identity = self::identity( $payload, $ip );
		$info     = WooCommerce_Anti_Fraud_IP_Intel::lookup( $ip, true );
		$links    = array(
			'verified' => array(),
			'claimed'  => array(),
		);

		// The order IP blocks when it's a home or office connection one person
		// uses. Never a mobile carrier or relay address, or one many devices
		// share, where a card tester's failures would lock out everyone else
		// on it. A VPN, Tor or data center IP blocks only once its failures
		// came from two or more devices: a card tester rotating browsers.
		if ( WooCommerce_Anti_Fraud_Request::is_public( $ip ) && ! WooCommerce_Anti_Fraud_Scorer::crowded( $ip ) ) {
			if ( in_array( $info['type'], array( 'RES', 'BIZ', '' ), true ) ) {
				$links['verified'][] = $ip;
			} elseif ( in_array( $info['type'], WooCommerce_Anti_Fraud_IP_Intel::RISKY_TYPES, true ) && WooCommerce_Anti_Fraud_Declines::devices_failing_on( $ip ) >= 2 ) {
				$links['verified'][] = $ip;
			}
		}

		// A real IP the browser showed behind a proxy only counts against this
		// checkout (see WooCommerce_Anti_Fraud_Scorer::links()). No lookups for
		// it here: only what's already cached.
		foreach ( array_slice( is_array( $payload ) ? $payload['rtc'] : array(), 0, 2 ) as $claim ) {
			$known = WooCommerce_Anti_Fraud_IP_Intel::cached( $claim );
			if ( $claim !== $ip && ! in_array( $known['type'], WooCommerce_Anti_Fraud_IP_Intel::SHARED_TYPES, true ) ) {
				$links['claimed'][] = $claim;
			}
		}

		$message = WooCommerce_Anti_Fraud_Declines::block_message( $identity['hash'], $links );

		if ( '' !== $message ) {
			WooCommerce_Anti_Fraud_Declines::record_block( $identity['hash'], $ip, $email, $order_id );
		}

		return $message;
	}

	/**
	 * Classic checkout: refuse the order before payment if the guard says so.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 * @return void
	 */
	public function classic_validation( $data, $errors ) {
		try {
			if ( ! $errors instanceof WP_Error || $errors->has_errors() || ! WooCommerce_Anti_Fraud_Scorer::in_scope( WC()->cart ) ) {
				return;
			}

			$email   = is_array( $data ) && isset( $data['billing_email'] ) ? (string) $data['billing_email'] : '';
			$message = self::guard( self::posted_payload(), $email );

			if ( '' !== $message ) {
				$errors->add( 'woocommerce_anti_fraud_blocked', $message );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.classic_validation', $e );
		}
	}

	/**
	 * Classic checkout: score the order (before payment runs).
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public function classic_order( $order_id, $posted = array(), $order = null ) {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				WooCommerce_Anti_Fraud_Scorer::score_live( $order, self::posted_payload(), 'checkout' );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.classic_order', $e );
		}
	}

	/**
	 * Let the block checkout send our payload as extension data.
	 *
	 * @return void
	 */
	public function register_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema' ) ) {
			return;
		}

		try {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
					'namespace'       => self::STORE_API_NS,
					'schema_callback' => array( $this, 'store_api_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.register_store_api', $e );
		}
	}

	/**
	 * Store API schema for our extension data.
	 *
	 * @return array
	 */
	public function store_api_schema() {
		return array(
			'payload' => array(
				'description' => __( 'Fraud check data from the browser.', 'woocommerce-anti-fraud' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array(),
				'arg_options' => array(
					'validate_callback' => function ( $value ) {
						return null === $value || ( is_string( $value ) && strlen( $value ) <= self::MAX_PAYLOAD );
					},
				),
			),
		);
	}

	/**
	 * Block checkout: read the payload and, when placing the order, refuse it
	 * if the guard says so.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Store API request.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException To refuse the checkout.
	 */
	public function store_api_request( $order, $request ) {
		if ( ! $order instanceof WC_Order || ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
			return;
		}

		$message = '';

		try {
			$extensions = $request->get_param( 'extensions' );
			$raw        = is_array( $extensions ) && isset( $extensions[ self::STORE_API_NS ]['payload'] ) ? $extensions[ self::STORE_API_NS ]['payload'] : '';
			$payload    = self::parse_payload( $raw );

			$this->store_api_payloads[ (int) $order->get_id() ] = $payload;

			if ( WooCommerce_Anti_Fraud_Scorer::in_scope( $order ) ) {
				$message = self::guard( $payload, (string) $order->get_billing_email(), (int) $order->get_id() );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.store_api_request', $e );
		}

		if ( '' !== $message ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'woocommerce_anti_fraud_blocked', esc_html( $message ), 403 );
			}
			throw new \Exception( esc_html( $message ) );
		}
	}

	/**
	 * Block checkout: score the order (before payment runs).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function store_api_order( $order ) {
		try {
			if ( $order instanceof WC_Order ) {
				$id      = (int) $order->get_id();
				$payload = isset( $this->store_api_payloads[ $id ] ) ? $this->store_api_payloads[ $id ] : null;
				WooCommerce_Anti_Fraud_Scorer::score_live( $order, $payload, 'checkout' );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.store_api_order', $e );
		}
	}

	/**
	 * Pay for order: refuse payment if the guard says so, otherwise score
	 * the attempt.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function pay_action( $order ) {
		try {
			if ( ! $order instanceof WC_Order || ! WooCommerce_Anti_Fraud_Scorer::in_scope( $order ) ) {
				return;
			}

			$payload = self::posted_payload();
			$message = self::guard( $payload, (string) $order->get_billing_email(), (int) $order->get_id() );

			if ( '' !== $message ) {
				// WooCommerce skips the payment while an error notice is waiting.
				wc_add_notice( $message, 'error' );
				return;
			}

			WooCommerce_Anti_Fraud_Scorer::score_live( $order, $payload, 'order_pay' );
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.pay_action', $e );
		}
	}

	/**
	 * Score orders that were placed without going through the checkout
	 * hooks, the first time their status changes. Orders made in the admin,
	 * subscription renewals and orders older than a day are left alone.
	 *
	 * @param int           $order_id Order ID.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 * @return void
	 */
	public function fallback( $order_id, $from = '', $to = '', $order = null ) {
		// Block checkout moves its draft to pending just before our own hook
		// scores it, and a pending order hasn't been paid for yet anyway.
		if ( in_array( $to, array( 'pending', 'checkout-draft' ), true ) ) {
			return;
		}

		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || '' !== (string) $order->get_meta( '_wcaf_score' ) ) {
				return;
			}

			$skip = (array) apply_filters( 'woocommerce_anti_fraud_skip_created_via', array( 'admin', 'subscription', 'rest-api' ) );
			if ( in_array( $order->get_created_via(), $skip, true ) ) {
				return;
			}

			$created = $order->get_date_created();
			if ( ! $created || time() - $created->getTimestamp() > DAY_IN_SECONDS || ! WooCommerce_Anti_Fraud_Scorer::in_scope( $order ) ) {
				return;
			}

			WooCommerce_Anti_Fraud_Scorer::score_offline( $order, 'fallback' );
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'collector.fallback', $e );
		}
	}
}
