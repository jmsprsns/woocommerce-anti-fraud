<?php
/**
 * Main plugin orchestrator: owns the settings and boots every component.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class WooCommerce_Anti_Fraud_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'woocommerce_anti_fraud_settings';

	/**
	 * Daily housekeeping: prune old records, refresh Cloudflare's IP ranges.
	 */
	const CRON_DAILY = 'woocommerce_anti_fraud_daily';

	/**
	 * Single shared instance.
	 *
	 * @var WooCommerce_Anti_Fraud_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings with defaults filled in, cached for the request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return WooCommerce_Anti_Fraud_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		WooCommerce_Anti_Fraud_Install::maybe_upgrade();

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( self::CRON_DAILY, array( $this, 'daily' ) );
		add_action( WooCommerce_Anti_Fraud_Scorer::CRON_RESCORE, array( 'WooCommerce_Anti_Fraud_Scorer', 'cron_rescore' ), 10, 3 );

		if ( is_admin() ) {
			new WooCommerce_Anti_Fraud_Admin( $this );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		new WooCommerce_Anti_Fraud_Privacy();

		if ( is_admin() ) {
			new WooCommerce_Anti_Fraud_Order_Admin( $this );
		}

		if ( $this->is_enabled() ) {
			new WooCommerce_Anti_Fraud_Collector( $this );
			new WooCommerce_Anti_Fraud_Declines( $this );
			new WooCommerce_Anti_Fraud_Actions( $this );
		}

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'          => 1,

			// Which orders get checked: every order, or only orders with
			// these products or categories in them.
			'scope'            => 'all',
			'product_ids'      => array(),
			'product_cats'     => array(),

			// Browser checks the collector script runs on checkout.
			'fingerprint'      => 1,
			'webrtc'           => 1,
			'paste'            => 1,

			// Signals that count towards the score (factor key => on/off).
			'factors'          => array_fill_keys( array_keys( WooCommerce_Anti_Fraud_Scorer::FACTOR_MAX ), 1 ),

			// Score boundaries: below "review" needs a look, below "high" is high risk.
			'review_below'     => 80,
			'high_below'       => 50,

			// Visitor IP and IP lookups.
			'trusted_proxies'  => '',
			'proxy_header'     => 'x-forwarded-for',
			'trust_private'    => 1,
			'ipinfo'           => 1,
			'ipinfo_token'     => '',
			'stun_server'      => 'stun:stun.l.google.com:19302',

			// Actions.
			'hold_high_risk'   => 0,
			'block_declines'   => 0,
			'block_threshold'  => 5,
			'decline_window'   => 24,
			'block_message'    => '',
			'alert_emails'     => '',
			'alert_high_risk'  => 1,
			'alert_threshold'  => 4,

			// Data.
			'retention_months' => 12,
			'keep_data'        => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved    = get_option( self::OPTION, array() );
			$defaults = self::defaults();
			$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

			// New factors added in later versions start switched on.
			$settings['factors'] = wp_parse_args( is_array( $settings['factors'] ) ? $settings['factors'] : array(), $defaults['factors'] );

			$this->settings = $settings;
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after they're saved.
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}

	/**
	 * Whether fraud checks run at all. The WOOCOMMERCE_ANTI_FRAUD_DISABLED
	 * constant turns them off from wp-config.php, whatever the setting says.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( defined( 'WOOCOMMERCE_ANTI_FRAUD_DISABLED' ) && WOOCOMMERCE_ANTI_FRAUD_DISABLED ) {
			return false;
		}

		return (bool) $this->get_setting( 'enabled' );
	}

	/**
	 * Whether a scoring factor is switched on.
	 *
	 * @param string $factor Factor key from WooCommerce_Anti_Fraud_Scorer::factors().
	 * @return bool
	 */
	public function factor_on( $factor ) {
		$factors = (array) $this->get_setting( 'factors' );

		return ! empty( $factors[ $factor ] );
	}

	/**
	 * Daily housekeeping. Never throws: a failure here mustn't stop other cron jobs.
	 *
	 * @return void
	 */
	public function daily() {
		try {
			WooCommerce_Anti_Fraud_Install::prune( (int) $this->get_setting( 'retention_months' ) );
			WooCommerce_Anti_Fraud_Request::maybe_refresh_cloudflare_ranges();
		} catch ( \Throwable $e ) {
			self::log( 'daily', $e );
		}
	}

	/**
	 * Admin notice when WooCommerce isn't active.
	 *
	 * @return void
	 */
	public function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'WooCommerce Anti Fraud needs WooCommerce. Activate WooCommerce to start checking orders.', 'woocommerce-anti-fraud' )
		);
	}

	/**
	 * Log a caught error when WP_DEBUG is on. Every checkout-path callback
	 * catches its own errors and ends up here, so a bug in this plugin can
	 * never stop an order from going through.
	 *
	 * @param string     $context Where it happened.
	 * @param \Throwable $error   The caught error.
	 * @return void
	 */
	public static function log( $context, $error ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging.
			sprintf(
				'[WooCommerce Anti Fraud] %s: %s in %s:%d',
				$context,
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			)
		);
	}
}
