<?php
/**
 * Failed payments, counted per device and IP, and the optional guard that
 * refuses checkout once one of them has failed too often (card testing).
 *
 * Failures are recorded from WooCommerce's "failed" status change (any
 * gateway) and from WooPayments' and Stripe's per-attempt error hooks,
 * which also fire for repeat attempts on an order that has already failed.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decline tracking.
 */
final class WooCommerce_Anti_Fraud_Declines {

	/**
	 * Orders already recorded in this request (a gateway hook and the status
	 * change often both fire for one failed attempt).
	 *
	 * @var array<int,bool>
	 */
	private static $recorded = array();

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

		add_action( 'woocommerce_order_status_failed', array( $this, 'on_failed_status' ), 10, 2 );
		add_action( 'woocommerce_payments_order_failed', array( $this, 'on_gateway_error' ), 10, 2 );
		add_action( 'wc_gateway_stripe_process_payment_error', array( $this, 'on_stripe_error' ), 10, 2 );
	}

	/**
	 * Order moved to Failed.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 * @return void
	 */
	public function on_failed_status( $order_id, $order = null ) {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$code = (string) $order->get_meta( '_transaction_error_code' );
				self::record_for_order( $order, '' !== $code ? $code : 'failed' );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'declines.failed_status', $e );
		}
	}

	/**
	 * WooPayments: a payment attempt failed.
	 *
	 * @param WC_Order|mixed $order Order.
	 * @param mixed          $error Gateway exception.
	 * @return void
	 */
	public function on_gateway_error( $order, $error = null ) {
		try {
			if ( $order instanceof WC_Order ) {
				self::record_for_order( $order, self::error_code( $error ) );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'declines.gateway_error', $e );
		}
	}

	/**
	 * WooCommerce Stripe Gateway: a payment attempt failed.
	 *
	 * @param mixed          $error Exception.
	 * @param WC_Order|mixed $order Order.
	 * @return void
	 */
	public function on_stripe_error( $error, $order = null ) {
		$this->on_gateway_error( $order, $error );
	}

	/**
	 * A short reason code from a gateway exception.
	 *
	 * @param mixed $error Exception or WP_Error.
	 * @return string
	 */
	private static function error_code( $error ) {
		$code = '';

		if ( is_object( $error ) && is_callable( array( $error, 'get_error_code' ) ) ) {
			$code = (string) $error->get_error_code();
		} elseif ( $error instanceof \Exception && $error->getCode() ) {
			$code = (string) $error->getCode();
		}

		$code = sanitize_key( $code );

		return '' !== $code ? $code : 'failed';
	}

	/**
	 * Record a failed payment against the order's customer. Identity comes
	 * from the order's fraud check (taken at checkout, before payment), so
	 * failures reported by a gateway webhook still land on the right person.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Decline code.
	 * @return void
	 */
	public static function record_for_order( WC_Order $order, $reason ) {
		$order_id = (int) $order->get_id();

		if ( ! empty( self::$recorded[ $order_id ] ) || ! WooCommerce_Anti_Fraud_Scorer::in_scope( $order ) ) {
			return;
		}
		self::$recorded[ $order_id ] = true;

		// Only the IP this plugin verified. WooCommerce's own customer IP
		// believes X-Forwarded-For from anyone, so it could be someone else's.
		$fingerprint = (string) $order->get_meta( '_wcaf_fingerprint' );
		$visitor_id  = (int) $order->get_meta( '_wcaf_visitor' );
		$ip          = (string) $order->get_meta( '_wcaf_ip' );
		$report      = WooCommerce_Anti_Fraud_Scorer::report( $order );
		$links       = $report ? WooCommerce_Anti_Fraud_Scorer::links( $report ) : WooCommerce_Anti_Fraud_Scorer::normalize_links( array() );

		self::insert(
			array(
				'kind'        => 'decline',
				'visitor_id'  => $visitor_id,
				'fingerprint' => $fingerprint,
				'order_id'    => $order_id,
				'gateway'     => (string) $order->get_payment_method(),
				'reason'      => $reason,
				'ip'          => $ip,
				'leak'        => $report ? WooCommerce_Anti_Fraud_Scorer::leak_ip( $report ) : '',
				'email'       => (string) $order->get_billing_email(),
			)
		);

		WooCommerce_Anti_Fraud_Identity::bump( $visitor_id, 'decline_count' );

		$failures = self::count( $fingerprint, $links, self::window() );

		WooCommerce_Anti_Fraud_Actions::maybe_alert(
			'declines',
			$failures,
			array(
				'fingerprint' => $fingerprint,
				'links'       => $links,
			),
			$order
		);

		// The decline changes the score of every order from this customer, this one included.
		WooCommerce_Anti_Fraud_Scorer::schedule_rescore( 0, $fingerprint, $links );
	}

	/**
	 * Insert a row.
	 *
	 * @param array $row kind, visitor_id, fingerprint, order_id, gateway, reason, ip, leak (optional), email.
	 * @return void
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
			WooCommerce_Anti_Fraud_Install::table( 'declines' ),
			array(
				'kind'        => 'blocked' === $row['kind'] ? 'blocked' : 'decline',
				'visitor_id'  => (int) $row['visitor_id'],
				'fingerprint' => preg_match( '/^[a-f0-9]{64}$/', (string) $row['fingerprint'] ) ? $row['fingerprint'] : '',
				'order_id'    => (int) $row['order_id'],
				'gateway'     => substr( sanitize_key( (string) $row['gateway'] ), 0, 64 ),
				'reason'      => substr( sanitize_text_field( (string) $row['reason'] ), 0, 100 ),
				'ip'          => WooCommerce_Anti_Fraud_Request::valid_ip( (string) $row['ip'] ),
				'leak'        => WooCommerce_Anti_Fraud_Request::valid_ip( isset( $row['leak'] ) ? (string) $row['leak'] : '' ),
				'email_hash'  => self::email_hash( (string) $row['email'] ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Failed payments in the window from the same device or IP.
	 *
	 * The billing email isn't used: anyone can type anyone's email, so it
	 * would let a stranger pile failures onto a real customer. For the same
	 * reason, an IP the browser only claimed (see
	 * WooCommerce_Anti_Fraud_Scorer::links()) is never matched against
	 * another order's verified IP.
	 *
	 * @param string $fingerprint Device fingerprint ('' to skip).
	 * @param array  $links       WooCommerce_Anti_Fraud_Scorer::links() result.
	 * @param int    $hours       Window.
	 * @return int
	 */
	public static function count( $fingerprint, $links, $hours ) {
		global $wpdb;

		$where = self::identity_where( $fingerprint, $links );
		if ( null === $where ) {
			return 0;
		}

		$table    = WooCommerce_Anti_Fraud_Install::table( 'declines' );
		$params   = $where['params'];
		$params[] = WooCommerce_Anti_Fraud_Identity::since( $hours );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Our own table; the WHERE clause is placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE kind = 'decline' AND cleared = 0 AND ( {$where['sql']} ) AND created_at >= %s", $params ) );
	}

	/**
	 * The failed payments behind count(), newest first.
	 *
	 * @param string $fingerprint Device fingerprint.
	 * @param array  $links       WooCommerce_Anti_Fraud_Scorer::links() result.
	 * @param int    $hours       Window.
	 * @param int    $limit       Maximum rows.
	 * @return object[]
	 */
	public static function rows( $fingerprint, $links, $hours, $limit = 30 ) {
		global $wpdb;

		$where = self::identity_where( $fingerprint, $links );
		if ( null === $where ) {
			return array();
		}

		$table    = WooCommerce_Anti_Fraud_Install::table( 'declines' );
		$params   = $where['params'];
		$params[] = WooCommerce_Anti_Fraud_Identity::since( $hours );
		$params[] = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Our own table; the WHERE clause is placeholders only.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE kind = 'decline' AND cleared = 0 AND ( {$where['sql']} ) AND created_at >= %s ORDER BY created_at DESC LIMIT %d", $params ) );
	}

	/**
	 * Build "fingerprint = %s OR ip IN (...) OR leak IN (...)" for the parts we have.
	 *
	 * @param string $fingerprint Device fingerprint.
	 * @param array  $links       WooCommerce_Anti_Fraud_Scorer::links() result.
	 * @return array{sql:string,params:array}|null Null when there's nothing to match on.
	 */
	private static function identity_where( $fingerprint, $links ) {
		$sql    = array();
		$params = array();
		$links  = WooCommerce_Anti_Fraud_Scorer::normalize_links( $links );
		$all    = array_values( array_unique( array_merge( $links['verified'], $links['claimed'] ) ) );

		if ( preg_match( '/^[a-f0-9]{64}$/', (string) $fingerprint ) ) {
			$sql[]    = 'fingerprint = %s';
			$params[] = $fingerprint;
		}
		if ( $all ) {
			$sql[]  = 'ip IN ( ' . implode( ', ', array_fill( 0, count( $all ), '%s' ) ) . ' )';
			$params = array_merge( $params, $all );
		}
		if ( $links['claimed'] ) {
			$sql[]  = 'leak IN ( ' . implode( ', ', array_fill( 0, count( $links['claimed'] ), '%s' ) ) . ' )';
			$params = array_merge( $params, $links['claimed'] );
		}

		return $sql ? array(
			'sql'    => implode( ' OR ', $sql ),
			'params' => $params,
		) : null;
	}

	/**
	 * The message shown when checkout is refused, or '' to let it through.
	 *
	 * @param string $fingerprint Device fingerprint.
	 * @param array  $links       WooCommerce_Anti_Fraud_Scorer::links() style IPs.
	 * @return string
	 */
	public static function block_message( $fingerprint, $links ) {
		$plugin = WooCommerce_Anti_Fraud_Plugin::instance();

		if ( ! $plugin->get_setting( 'block_declines' ) ) {
			return '';
		}

		$threshold = max( 2, (int) $plugin->get_setting( 'block_threshold' ) );
		$failures  = self::count( $fingerprint, $links, self::window() );

		/**
		 * Filter whether a checkout is refused after repeated failed payments.
		 *
		 * @param bool   $block    Whether the failures reached the threshold.
		 * @param int    $failures Failed payments in the window.
		 * @param array  $identity {fingerprint, links}.
		 */
		$block = apply_filters(
			'woocommerce_anti_fraud_block_checkout',
			$failures >= $threshold,
			$failures,
			array(
				'fingerprint' => $fingerprint,
				'links'       => WooCommerce_Anti_Fraud_Scorer::normalize_links( $links ),
			)
		);

		if ( ! $block ) {
			return '';
		}

		$message = trim( (string) $plugin->get_setting( 'block_message' ) );

		return '' !== $message ? $message : self::default_block_message();
	}

	/**
	 * The default checkout-refused message.
	 *
	 * @return string
	 */
	public static function default_block_message() {
		return __( 'We couldn\'t process your order because of several failed payment attempts. Please contact us to complete your purchase.', 'woocommerce-anti-fraud' );
	}

	/**
	 * Record a refused checkout, for the overview counts.
	 *
	 * @param string $fingerprint Device fingerprint.
	 * @param string $ip          Client IP.
	 * @param string $email       Billing email.
	 * @param int    $order_id    Order ID, if one exists.
	 * @return void
	 */
	public static function record_block( $fingerprint, $ip, $email, $order_id = 0 ) {
		// One row a minute per IP is plenty, however fast they retry (and
		// however many fingerprints they make up).
		$key = 'wcaf_blk_' . md5( '' !== (string) $ip ? $ip : $fingerprint );
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );

		self::insert(
			array(
				'kind'        => 'blocked',
				'visitor_id'  => 0,
				'fingerprint' => $fingerprint,
				'order_id'    => (int) $order_id,
				'gateway'     => '',
				'reason'      => 'blocked',
				'ip'          => $ip,
				'email'       => $email,
			)
		);
	}

	/**
	 * How many different devices had failed payments from an IP in the window.
	 *
	 * @param string $ip IP address.
	 * @return int
	 */
	public static function devices_failing_on( $ip ) {
		global $wpdb;

		if ( '' === WooCommerce_Anti_Fraud_Request::valid_ip( (string) $ip ) ) {
			return 0;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'declines' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT fingerprint) FROM {$table} WHERE kind = 'decline' AND cleared = 0 AND ip = %s AND fingerprint <> '' AND created_at >= %s", $ip, WooCommerce_Anti_Fraud_Identity::since( self::window() ) ) );
	}

	/**
	 * Devices, IPs and emails with repeated failed payments in the window,
	 * most failures first. Powers the "Repeated failed payments" table.
	 *
	 * @param int $hours Window.
	 * @param int $limit Maximum rows per kind.
	 * @return array<int,array{type:string,value:string,failures:int,last:string,order_id:int}>
	 */
	public static function repeat_offenders( $hours, $limit = 10 ) {
		global $wpdb;

		$table = WooCommerce_Anti_Fraud_Install::table( 'declines' );
		$since = WooCommerce_Anti_Fraud_Identity::since( $hours );
		$out   = array();

		// An IP counts both as an order IP and as the real IP leaked behind a proxy.
		$sources = array(
			'fingerprint' => $table,
			'ip'          => "( SELECT ip, created_at, order_id, kind, cleared FROM {$table} UNION ALL SELECT leak AS ip, created_at, order_id, kind, cleared FROM {$table} ) AS t",
			'email_hash'  => $table,
		);

		foreach ( $sources as $column => $from ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table; column and source from a fixed list.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$column} AS value, COUNT(*) AS failures, MAX(created_at) AS last, MAX(order_id) AS order_id FROM {$from} WHERE kind = 'decline' AND cleared = 0 AND {$column} <> '' AND created_at >= %s GROUP BY {$column} HAVING COUNT(*) >= 2 ORDER BY failures DESC, last DESC LIMIT %d", $since, max( 1, (int) $limit ) ) );

			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'type'     => $column,
					'value'    => (string) $row->value,
					'failures' => (int) $row->failures,
					'last'     => (string) $row->last,
					'order_id' => (int) $row->order_id,
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $b['failures'] - $a['failures'];
			}
		);

		return $out;
	}

	/**
	 * Stop counting a device's, IP's or email's failed payments (a false
	 * positive). The rows are kept, marked cleared; new failures count again.
	 *
	 * @param string $type  fingerprint, ip or email_hash.
	 * @param string $value The value to clear.
	 * @return int Rows cleared.
	 */
	public static function clear( $type, $value ) {
		global $wpdb;

		$valid = ( 'fingerprint' === $type || 'email_hash' === $type ) ? (bool) preg_match( '/^[a-f0-9]{64}$/', (string) $value ) : ( 'ip' === $type && '' !== WooCommerce_Anti_Fraud_Request::valid_ip( (string) $value ) );

		if ( ! $valid ) {
			return 0;
		}

		$table = WooCommerce_Anti_Fraud_Install::table( 'declines' );

		$now  = current_time( 'mysql', true );
		$user = get_current_user_id();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table; column validated above.
		if ( 'ip' === $type ) {
			// An IP also counts where it was the real IP behind a proxy.
			return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET cleared = 1, cleared_at = %s, cleared_by = %d WHERE cleared = 0 AND ( ip = %s OR leak = %s )", $now, $user, $value, $value ) );
		}

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET cleared = 1, cleared_at = %s, cleared_by = %d WHERE cleared = 0 AND {$type} = %s", $now, $user, $value ) );
		// phpcs:enable
	}

	/**
	 * Failed payments and refused checkouts since a time.
	 *
	 * @param int $hours Window.
	 * @return array{declines:int,blocked:int}
	 */
	public static function totals( $hours ) {
		global $wpdb;

		$table = WooCommerce_Anti_Fraud_Install::table( 'declines' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY kind", WooCommerce_Anti_Fraud_Identity::since( $hours ) ) );
		$out  = array(
			'declines' => 0,
			'blocked'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$out[ 'blocked' === $row->kind ? 'blocked' : 'declines' ] += (int) $row->total;
		}

		return $out;
	}

	/**
	 * The failed-payment window in hours.
	 *
	 * @return int
	 */
	public static function window() {
		return min( 720, max( 1, (int) WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'decline_window' ) ) );
	}

	/**
	 * SHA-256 of a normalised email, or '' for an invalid one. Raw emails are never stored here.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	public static function email_hash( $email ) {
		$email = strtolower( trim( (string) $email ) );

		return '' !== $email && is_email( $email ) ? hash( 'sha256', $email ) : '';
	}
}
