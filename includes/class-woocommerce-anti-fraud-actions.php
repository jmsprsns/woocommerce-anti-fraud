<?php
/**
 * What the plugin does about risky orders, beyond scoring them. Everything
 * here is off until switched on in the settings:
 *
 * - Hold high-risk orders: a paid order that scored high risk goes to
 *   On hold instead of Processing, so it isn't shipped until someone looks.
 * - Email alerts: on a high-risk order, and when one device or IP
 *   piles up failed payments or orders. One email per person per trigger
 *   per window, so a persistent attacker can't flood the inbox.
 *
 * (Refusing checkout after repeated failed payments lives in
 * WooCommerce_Anti_Fraud_Declines and WooCommerce_Anti_Fraud_Collector.)
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hold and alert.
 */
final class WooCommerce_Anti_Fraud_Actions {

	/**
	 * Orders inside a payment_complete() call right now.
	 *
	 * @var array<int,bool>
	 */
	private static $paying = array();

	/**
	 * Orders this plugin is moving to On hold in this request.
	 *
	 * @var array<int,bool>
	 */
	private static $holding = array();

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

		// WooCommerce also applies the payment-complete status filter as a
		// plain question ("what counts as paid?") on every status change, so
		// it's only answered with On hold during a real payment_complete().
		add_action( 'woocommerce_pre_payment_complete', array( $this, 'start_payment' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'end_payment' ), 99 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'hold_paid_order' ), 20, 3 );
		add_filter( 'woocommerce_cod_process_payment_order_status', array( $this, 'hold_cod_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'record_hold' ), 5, 4 );
	}

	/**
	 * A payment_complete() call has started.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function start_payment( $order_id ) {
		self::$paying[ (int) $order_id ] = true;
	}

	/**
	 * A payment_complete() call has finished.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function end_payment( $order_id ) {
		$order_id = (int) $order_id;

		// Already On hold before the payment (say, a cheque order), so there
		// was no status change to note the hold on.
		if ( ! empty( self::$holding[ $order_id ] ) ) {
			$this->record_hold( $order_id, 'on-hold', 'on-hold' );
		}

		unset( self::$paying[ $order_id ], self::$holding[ $order_id ] );
	}

	/**
	 * Paid orders: On hold instead of Processing/Completed when high risk.
	 *
	 * @param string        $status   Status WooCommerce is about to set.
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 * @return string
	 */
	public function hold_paid_order( $status, $order_id = 0, $order = null ) {
		if ( empty( self::$paying[ (int) $order_id ] ) ) {
			return $status;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		return $this->maybe_hold( $status, $order );
	}

	/**
	 * Cash on delivery: On hold instead of Processing when high risk.
	 *
	 * @param string   $status Status WooCommerce is about to set.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	public function hold_cod_order( $status, $order = null ) {
		return $this->maybe_hold( $status, $order );
	}

	/**
	 * The status to use: on-hold for a high-risk order (once), else unchanged.
	 * No side effects here; the hold is noted when the status really changes.
	 *
	 * @param string        $status Proposed status.
	 * @param WC_Order|null $order  Order.
	 * @return string
	 */
	private function maybe_hold( $status, $order ) {
		try {
			if ( ! $this->plugin->get_setting( 'hold_high_risk' ) || ! $order instanceof WC_Order || ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
				return $status;
			}

			// Once only: an order someone has released from the hold isn't held again.
			if ( 'high' !== $order->get_meta( '_wcaf_risk' ) || $order->get_meta( '_wcaf_held' ) ) {
				return $status;
			}

			self::$holding[ (int) $order->get_id() ] = true;

			return 'on-hold';
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'actions.maybe_hold', $e );
			return $status;
		}
	}

	/**
	 * Note the hold when the order actually goes to On hold.
	 *
	 * @param int           $order_id Order ID.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 * @return void
	 */
	public function record_hold( $order_id, $from, $to, $order = null ) {
		$order_id = (int) $order_id;

		if ( 'on-hold' !== $to || empty( self::$holding[ $order_id ] ) ) {
			return;
		}

		unset( self::$holding[ $order_id ] );

		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$order->update_meta_data( '_wcaf_held', 1 );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					/* translators: %d: fraud score. */
					__( 'Held for review: fraud score %d/100. Check the Fraud Risk box, then move the order to Processing if it\'s genuine.', 'woocommerce-anti-fraud' ),
					(int) $order->get_meta( '_wcaf_score' )
				)
			);
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'actions.record_hold', $e );
		}
	}

	/**
	 * Valid alert recipients from the settings.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$raw = (string) WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'alert_emails' );
		$out = array();

		foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) && ! in_array( $email, $out, true ) ) {
				$out[] = $email;
			}
		}

		/**
		 * Filter who gets fraud alert emails.
		 *
		 * @param string[] $out Email addresses (empty turns alerts off).
		 */
		return array_values( array_filter( (array) apply_filters( 'woocommerce_anti_fraud_alert_recipients', $out ), 'is_email' ) );
	}

	/**
	 * Send an alert if it's due. Never throws.
	 *
	 * @param string   $trigger  high_risk, declines or orders.
	 * @param int      $count    Current count (1 for high_risk).
	 * @param array    $identity What to key the once-per-window rule on.
	 * @param WC_Order $order    Latest related order.
	 * @return bool Whether an email went out.
	 */
	public static function maybe_alert( $trigger, $count, array $identity, $order ) {
		try {
			$plugin = WooCommerce_Anti_Fraud_Plugin::instance();

			if ( 'high_risk' === $trigger ) {
				if ( ! $plugin->get_setting( 'alert_high_risk' ) ) {
					return false;
				}
				$window = 7 * 24;
			} else {
				if ( (int) $count < max( 2, (int) $plugin->get_setting( 'alert_threshold' ) ) ) {
					return false;
				}
				$window = 'declines' === $trigger ? WooCommerce_Anti_Fraud_Declines::window() : 48;
			}

			$to = self::recipients();
			if ( ! $to || ! $order instanceof WC_Order ) {
				return false;
			}

			$key = 'wcaf_alert_' . md5( $trigger . '|' . wp_json_encode( $identity ) );
			if ( false !== get_transient( $key ) ) {
				return false;
			}

			// A site-wide cap too, since a bot can change its fingerprint and IP at will.
			$sent_this_hour = (int) get_transient( 'wcaf_alerts_hour' );

			/**
			 * Filter how many fraud alert emails may go out per hour, site-wide.
			 *
			 * @param int $limit Default 10.
			 */
			if ( $sent_this_hour >= (int) apply_filters( 'woocommerce_anti_fraud_alerts_per_hour', 10 ) ) {
				self::send_paused_notice( $to );
				return false;
			}

			$sent = self::send( $trigger, (int) $count, $to, $order, $window );
			if ( $sent ) {
				set_transient( $key, 1, $window * HOUR_IN_SECONDS );
				set_transient( 'wcaf_alerts_hour', $sent_this_hour + 1, HOUR_IN_SECONDS );
			}

			return $sent;
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'actions.maybe_alert', $e );
			return false;
		}
	}

	/**
	 * Once per hour of capped alerts: say that alerts are paused, so a quiet
	 * inbox isn't mistaken for a quiet store.
	 *
	 * @param string[] $to Recipients.
	 * @return void
	 */
	private static function send_paused_notice( array $to ) {
		if ( false !== get_transient( 'wcaf_alerts_paused' ) ) {
			return;
		}
		set_transient( 'wcaf_alerts_paused', 1, HOUR_IN_SECONDS );

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Fraud alerts paused for an hour', 'woocommerce-anti-fraud' ), $site ),
			__( 'So many fraud alerts went out in the last hour that the rest are being held back to protect your inbox. The orders are still being checked and scored.', 'woocommerce-anti-fraud' ) . "\n\n"
			. __( 'Orders to review:', 'woocommerce-anti-fraud' ) . "\n" . admin_url( 'admin.php?page=' . WooCommerce_Anti_Fraud_Admin::PAGE . '#overview' )
		);
	}

	/**
	 * Write and send the alert (plain text).
	 *
	 * @param string   $trigger high_risk, declines or orders.
	 * @param int      $count   Count.
	 * @param string[] $to      Recipients.
	 * @param WC_Order $order   Order.
	 * @param int      $window  Window in hours.
	 * @return bool
	 */
	private static function send( $trigger, $count, array $to, WC_Order $order, $window ) {
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$report = WooCommerce_Anti_Fraud_Scorer::report( $order );
		$number = $order->get_order_number();

		if ( 'high_risk' === $trigger ) {
			/* translators: 1: site name, 2: order number. */
			$subject = sprintf( __( '[%1$s] High fraud risk on order #%2$s', 'woocommerce-anti-fraud' ), $site, $number );
			$intro   = $report ? WooCommerce_Anti_Fraud_Scorer::summary( $report ) : '';
		} elseif ( 'declines' === $trigger ) {
			/* translators: 1: site name, 2: number of failed payments. */
			$subject = sprintf( __( '[%1$s] Fraud alert: %2$d failed payments', 'woocommerce-anti-fraud' ), $site, $count );
			/* translators: 1: number of failed payments, 2: hours. */
			$intro = sprintf( __( '%1$d failed payments in %2$d hours from the same device or IP. This is what card testing looks like.', 'woocommerce-anti-fraud' ), $count, $window );
		} else {
			/* translators: 1: site name, 2: number of orders. */
			$subject = sprintf( __( '[%1$s] Fraud alert: %2$d orders from one device', 'woocommerce-anti-fraud' ), $site, $count );
			/* translators: %d: number of orders. */
			$intro = sprintf( __( '%d orders in 48 hours from the same device or IP.', 'woocommerce-anti-fraud' ), $count );
		}

		$lines = array( $intro, '' );

		/* translators: 1: order number, 2: billing name. */
		$lines[] = sprintf( __( 'Order #%1$s from %2$s', 'woocommerce-anti-fraud' ), $number, wp_specialchars_decode( $order->get_formatted_billing_full_name(), ENT_QUOTES ) );

		if ( $report ) {
			/* translators: 1: score, 2: risk level. */
			$lines[] = sprintf( __( 'Fraud score: %1$d/100 (%2$s)', 'woocommerce-anti-fraud' ), (int) $report['score'], WooCommerce_Anti_Fraud_Scorer::risk_label( $report['risk'] ) );
			if ( ! empty( $report['ip']['ip'] ) ) {
				$place   = WooCommerce_Anti_Fraud_IP_Intel::place( $report['ip']['info'] );
				$lines[] = sprintf(
					/* translators: 1: IP address, 2: connection type, 3: location. */
					__( 'IP: %1$s (%2$s%3$s)', 'woocommerce-anti-fraud' ),
					$report['ip']['ip'],
					WooCommerce_Anti_Fraud_IP_Intel::type_label( $report['ip']['info']['type'] ),
					'' !== $place ? ', ' . $place : ''
				);
			}
		}

		$lines[] = '';
		$lines[] = __( 'Review the order:', 'woocommerce-anti-fraud' );
		$lines[] = $order->get_edit_order_url();
		$lines[] = '';
		$lines[] = __( 'Failed payments and blocked customers:', 'woocommerce-anti-fraud' );
		$lines[] = admin_url( 'admin.php?page=' . WooCommerce_Anti_Fraud_Admin::PAGE . '#overview' );

		/**
		 * Filter a fraud alert email before it's sent.
		 *
		 * @param array    $mail    {to, subject, body}.
		 * @param string   $trigger high_risk, declines or orders.
		 * @param WC_Order $order   Order.
		 */
		$mail = apply_filters(
			'woocommerce_anti_fraud_alert_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => implode( "\n", $lines ),
			),
			$trigger,
			$order
		);

		if ( empty( $mail['to'] ) ) {
			return false;
		}

		return (bool) wp_mail( $mail['to'], (string) $mail['subject'], (string) $mail['body'] );
	}
}
