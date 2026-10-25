<?php
/**
 * Order screens: the Fraud Risk box on each order, with every signal and the
 * evidence behind it, and a Fraud column on the orders list. Works with
 * both order storage modes (HPOS and posts).
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order screen UI.
 */
final class WooCommerce_Anti_Fraud_Order_Admin {

	/**
	 * Meta box ID.
	 */
	const BOX = 'woocommerce-anti-fraud';

	/**
	 * admin-post action for "Check now" / "Recalculate".
	 */
	const CHECK_ACTION = 'woocommerce_anti_fraud_check';

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

		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check' ) );

		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_posts' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 20, 2 );
	}

	/**
	 * The order edit screen's ID.
	 *
	 * @return string
	 */
	private function edit_screen() {
		return WooCommerce_Anti_Fraud_Scorer::hpos() && function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * Whether the current admin screen shows orders.
	 *
	 * @return bool
	 */
	private function on_order_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Load the order screen styles and script.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->on_order_screen() ) {
			return;
		}

		wp_enqueue_style( 'woocommerce-anti-fraud-order', WOOCOMMERCE_ANTI_FRAUD_URL . 'assets/order.css', array(), WOOCOMMERCE_ANTI_FRAUD_VERSION );
		wp_enqueue_script( 'woocommerce-anti-fraud-order', WOOCOMMERCE_ANTI_FRAUD_URL . 'assets/order.js', array(), WOOCOMMERCE_ANTI_FRAUD_VERSION, true );
	}

	/**
	 * Add the Fraud Risk box to the top of the order sidebar.
	 *
	 * @return void
	 */
	public function add_box() {
		$screen = $this->edit_screen();

		add_meta_box( self::BOX, __( 'Fraud Risk', 'woocommerce-anti-fraud' ), array( $this, 'render_box' ), $screen, 'side', 'high' );

		// First in the sidebar, above WooCommerce's own boxes.
		global $wp_meta_boxes;
		if ( isset( $wp_meta_boxes[ $screen ]['side']['high'][ self::BOX ] ) ) {
			$box = $wp_meta_boxes[ $screen ]['side']['high'][ self::BOX ];
			unset( $wp_meta_boxes[ $screen ]['side']['high'][ self::BOX ] );
			$wp_meta_boxes[ $screen ]['side']['high'] = array( self::BOX => $box ) + $wp_meta_boxes[ $screen ]['side']['high']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering our own box.
		}
	}

	/**
	 * A WC_Order from what the meta box callback receives.
	 *
	 * @param WP_Post|WC_Order|mixed $post_or_order Post or order.
	 * @return WC_Order|null
	 */
	private function order_from( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}

		return null;
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name  Lucide icon.
	 * @param int    $size  Size in px.
	 * @param string $class Extra class.
	 * @return string
	 */
	private static function icon( $name, $size = 16, $class = '' ) {
		return WooCommerce_Anti_Fraud_Icons::get( $name, $size, $class );
	}

	/**
	 * A value from a nested array, or a default.
	 *
	 * @param array        $data    Array.
	 * @param string|array $path    Key or keys.
	 * @param mixed        $fallback Value when it's missing.
	 * @return mixed
	 */
	private static function get( $data, $path, $fallback = '' ) {
		foreach ( (array) $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return $fallback;
			}
			$data = $data[ $key ];
		}

		return null === $data ? $fallback : $data;
	}

	/**
	 * Icon and color for a risk level.
	 *
	 * @param string $risk low, medium or high.
	 * @return string
	 */
	private static function risk_icon( $risk ) {
		$icons = array(
			'low'    => 'shield-check',
			'medium' => 'shield-alert',
			'high'   => 'shield-x',
		);

		return isset( $icons[ $risk ] ) ? $icons[ $risk ] : 'shield-question-mark';
	}

	/**
	 * Render the Fraud Risk box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 * @return void
	 */
	public function render_box( $post_or_order ) {
		$order = $this->order_from( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$report = WooCommerce_Anti_Fraud_Scorer::report( $order );

		if ( ! $report ) {
			$this->render_unchecked( $order );
			return;
		}

		$score   = (int) $report['score'];
		$risk    = in_array( $report['risk'], array( 'low', 'medium', 'high' ), true ) ? $report['risk'] : 'medium';
		$factors = WooCommerce_Anti_Fraud_Scorer::factors();
		$initial = (string) $order->get_meta( '_wcaf_initial' );
		?>
		<div class="wcaf-box wcaf-risk-<?php echo esc_attr( $risk ); ?>">
			<div class="wcaf-head">
				<div class="wcaf-ring" style="--wcaf-score: <?php echo esc_attr( (string) $score ); ?>" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: score. */ __( 'Fraud score %d out of 100', 'woocommerce-anti-fraud' ), $score ) ); ?>">
					<span class="wcaf-ring-num"><?php echo esc_html( (string) $score ); ?></span>
					<span class="wcaf-ring-out">/100</span>
				</div>
				<div class="wcaf-head-text">
					<span class="wcaf-badge">
						<?php echo self::icon( self::risk_icon( $risk ), 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( WooCommerce_Anti_Fraud_Scorer::risk_label( $risk ) ); ?>
					</span>
					<span class="wcaf-sub">
						<?php
						if ( '' !== $initial && (int) $initial !== $score ) {
							/* translators: %d: first score. */
							echo esc_html( sprintf( __( 'Was %d at checkout', 'woocommerce-anti-fraud' ), (int) $initial ) );
						} elseif ( empty( $report['browser'] ) ) {
							esc_html_e( 'No browser checks for this order', 'woocommerce-anti-fraud' );
						} elseif ( 'high' === $risk ) {
							esc_html_e( 'Check this order before you ship it', 'woocommerce-anti-fraud' );
						} elseif ( 'medium' === $risk ) {
							esc_html_e( 'Worth a look before you ship it', 'woocommerce-anti-fraud' );
						} elseif ( 100 === $score ) {
							esc_html_e( 'Nothing looked wrong', 'woocommerce-anti-fraud' );
						} else {
							esc_html_e( 'Only minor warning signs', 'woocommerce-anti-fraud' );
						}
						?>
					</span>
					<?php if ( $order->get_meta( '_wcaf_held' ) ) : ?>
						<span class="wcaf-sub wcaf-held"><?php echo self::icon( 'circle-pause', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Held for review', 'woocommerce-anti-fraud' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<ul class="wcaf-factors">
				<?php
				foreach ( $factors as $key => $factor ) {
					$points = self::get( $report, array( 'factors', $key ), null );
					$this->render_factor_row( $key, $factor, $points, $report );
				}
				?>
			</ul>

			<?php $this->render_connection( $report ); ?>
			<?php $this->render_device( $report ); ?>

			<div class="wcaf-foot">
				<span>
					<?php
					$when = (string) $report['scored_at'];
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Checked %s', 'woocommerce-anti-fraud' ), '' !== $when ? get_date_from_gmt( $when, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '' ) );
					?>
				</span>
				<?php if ( current_user_can( 'edit_shop_orders' ) ) : ?>
					<a href="<?php echo esc_url( $this->check_url( $order ) ); ?>"><?php echo self::icon( 'refresh-cw', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Recalculate', 'woocommerce-anti-fraud' ); ?></a>
				<?php endif; ?>
			</div>

			<?php $this->render_dialogs( $order, $report ); ?>
		</div>
		<?php
	}

	/**
	 * Box for an order that hasn't been checked.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function render_unchecked( WC_Order $order ) {
		?>
		<div class="wcaf-box wcaf-unchecked">
			<p class="wcaf-empty">
				<?php echo self::icon( 'shield-question-mark', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php esc_html_e( 'This order hasn\'t been checked. It was placed before the plugin was active, outside the checkout, or isn\'t one of the products you check.', 'woocommerce-anti-fraud' ); ?></span>
			</p>
			<?php if ( current_user_can( 'edit_shop_orders' ) && $order->get_customer_ip_address() ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( $this->check_url( $order ) ); ?>"><?php echo self::icon( 'scan-search', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check now', 'woocommerce-anti-fraud' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Uses the IP saved with the order. The browser checks only run at checkout, so they\'ll be missing.', 'woocommerce-anti-fraud' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One signal row.
	 *
	 * @param string   $key    Factor key.
	 * @param array    $factor Factor definition.
	 * @param int|null $points Points taken off (null when the signal is switched off).
	 * @param array    $report Report.
	 * @return void
	 */
	private function render_factor_row( $key, array $factor, $points, array $report ) {
		$state = null === $points ? 'off' : ( (int) $points > 0 ? 'hit' : 'ok' );
		?>
		<li class="wcaf-factor is-<?php echo esc_attr( $state ); ?>">
			<span class="wcaf-factor-icon"><?php echo self::icon( $factor['icon'], 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<span class="wcaf-factor-text">
				<span class="wcaf-factor-label"><?php echo esc_html( $factor['label'] ); ?></span>
				<span class="wcaf-factor-detail"><?php echo esc_html( null === $points ? __( 'Switched off', 'woocommerce-anti-fraud' ) : $this->factor_detail( $key, $report ) ); ?></span>
			</span>
			<?php if ( 'hit' === $state ) : ?>
				<b class="wcaf-points">&minus;<?php echo esc_html( (string) (int) $points ); ?></b>
			<?php elseif ( 'ok' === $state ) : ?>
				<span class="wcaf-pass"><?php echo self::icon( 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<?php endif; ?>
			<button type="button" class="wcaf-info" data-wcaf-open="wcaf-dialog-<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: signal name. */ __( 'Details: %s', 'woocommerce-anti-fraud' ), $factor['label'] ) ); ?>">
				<?php echo self::icon( 'info', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			</button>
		</li>
		<?php
	}

	/**
	 * Short text under a signal's name.
	 *
	 * @param string $key    Factor key.
	 * @param array  $report Report.
	 * @return string
	 */
	private function factor_detail( $key, array $report ) {
		$counts = (array) self::get( $report, 'counts', array() );
		$checks = (array) self::get( $report, 'checks', array() );

		switch ( $key ) {
			case 'proxy':
				$type   = (string) self::get( $report, array( 'ip', 'info', 'type' ) );
				$webrtc = (string) self::get( $report, array( 'webrtc', 'status' ), 'none' );
				$parts  = array( '' !== $type ? WooCommerce_Anti_Fraud_IP_Intel::type_label( $type ) : __( 'Connection type unknown', 'woocommerce-anti-fraud' ) );
				if ( 'mismatch' === $webrtc || 'mobile_mismatch' === $webrtc ) {
					$parts[] = __( 'WebRTC leak', 'woocommerce-anti-fraud' );
				}
				return implode( ' · ', $parts );

			case 'declines':
				$n = (int) self::get( $counts, 'declines', 0 );
				/* translators: %d: failed payments. */
				return sprintf( _n( '%d failed payment', '%d failed payments', $n, 'woocommerce-anti-fraud' ), $n );

			case 'ip_change':
				$hits = array_filter(
					array(
						! empty( $checks['changed_on_page'] ),
						! empty( $checks['changed_session'] ),
						(int) self::get( $counts, 'devices_on_ip', 0 ) >= 4,
						! empty( $checks['new_country'] ),
					)
				);
				/* translators: %d: number of IP change signals. */
				return $hits ? sprintf( _n( '%d warning sign', '%d warning signs', count( $hits ), 'woocommerce-anti-fraud' ), count( $hits ) ) : __( 'Steady connection', 'woocommerce-anti-fraud' );

			case 'velocity':
				$n = (int) self::get( $counts, 'orders', 1 );
				/* translators: %d: orders. */
				return sprintf( _n( '%d order in 48 hours', '%d orders in 48 hours', $n, 'woocommerce-anti-fraud' ), $n );

			case 'addresses':
				$n = (int) self::get( $counts, 'addresses', 0 );
				/* translators: %d: addresses. */
				return $n ? sprintf( _n( '%d address on this IP', '%d addresses on this IP', $n, 'woocommerce-anti-fraud' ), $n ) : __( 'Not linked to other orders', 'woocommerce-anti-fraud' );

			case 'geo':
				$labels = array(
					'match'    => __( 'Matches the billing address', 'woocommerce-anti-fraud' ),
					'partial'  => __( 'Partly matches', 'woocommerce-anti-fraud' ),
					'mismatch' => __( 'Different place', 'woocommerce-anti-fraud' ),
				);
				$match  = (string) self::get( $checks, array( 'geo', 'match' ), 'unknown' );
				if ( ! empty( $checks['geo']['country'] ) ) {
					return __( 'Different country', 'woocommerce-anti-fraud' );
				}
				if ( ! empty( $checks['geo']['region'] ) ) {
					return __( 'Different state or region', 'woocommerce-anti-fraud' );
				}
				return isset( $labels[ $match ] ) ? $labels[ $match ] : __( 'IP location unknown', 'woocommerce-anti-fraud' );

			case 'ip_spread':
				$n = (int) self::get( $counts, 'ips', 0 );
				/* translators: %d: IP addresses. */
				return sprintf( _n( '%d IP for this device', '%d IPs for this device', $n, 'woocommerce-anti-fraud' ), $n );

			case 'paste':
				$n = (int) self::get( $checks, 'paste', 0 );
				if ( empty( $report['fields'] ) ) {
					return __( 'Not captured', 'woocommerce-anti-fraud' );
				}
				/* translators: %d: fields. */
				return $n ? sprintf( _n( '%d field pasted', '%d fields pasted', $n, 'woocommerce-anti-fraud' ), $n ) : __( 'Typed or autofilled', 'woocommerce-anti-fraud' );

			case 'timezone':
				$match = (string) self::get( $checks, array( 'timezone', 'match' ), 'unknown' );
				return 'match' === $match ? __( 'Matches the IP location', 'woocommerce-anti-fraud' ) : ( 'mismatch' === $match ? __( 'Doesn\'t match the IP location', 'woocommerce-anti-fraud' ) : __( 'Not enough data', 'woocommerce-anti-fraud' ) );

			case 'phone':
				$match = (string) self::get( $checks, array( 'phone', 'match' ), 'unknown' );
				return 'match' === $match ? __( 'Matches the billing state', 'woocommerce-anti-fraud' ) : ( 'mismatch' === $match ? __( 'Different state', 'woocommerce-anti-fraud' ) : __( 'Not a US or Canadian number', 'woocommerce-anti-fraud' ) );
		}

		return '';
	}

	/**
	 * The IP and connection section.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	private function render_connection( array $report ) {
		$ip     = (string) self::get( $report, array( 'ip', 'ip' ) );
		$info   = (array) self::get( $report, array( 'ip', 'info' ), array() );
		$source = (string) self::get( $report, array( 'ip', 'source' ) );
		$webrtc = (array) self::get( $report, 'webrtc', array() );

		$sources = array(
			'direct'     => __( 'direct connection', 'woocommerce-anti-fraud' ),
			'cloudflare' => __( 'verified via Cloudflare', 'woocommerce-anti-fraud' ),
			'proxy'      => __( 'via your proxy', 'woocommerce-anti-fraud' ),
			'order'      => __( 'from the order, unverified', 'woocommerce-anti-fraud' ),
		);
		?>
		<div class="wcaf-section">
			<h4><?php echo self::icon( 'earth', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Connection', 'woocommerce-anti-fraud' ); ?></h4>
			<dl class="wcaf-kv">
				<dt><?php esc_html_e( 'IP', 'woocommerce-anti-fraud' ); ?></dt>
				<dd>
					<?php if ( '' !== $ip ) : ?>
						<code><?php echo esc_html( $ip ); ?></code>
						<?php if ( isset( $sources[ $source ] ) ) : ?>
							<span class="wcaf-muted"><?php echo esc_html( $sources[ $source ] ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span class="wcaf-muted"><?php esc_html_e( 'Unknown', 'woocommerce-anti-fraud' ); ?></span>
					<?php endif; ?>
				</dd>

				<dt><?php esc_html_e( 'Type', 'woocommerce-anti-fraud' ); ?></dt>
				<dd><?php echo $this->type_tag( (string) self::get( $info, 'type' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in type_tag(). ?></dd>

				<?php if ( '' !== (string) self::get( $info, 'org' ) ) : ?>
					<dt><?php esc_html_e( 'Network', 'woocommerce-anti-fraud' ); ?></dt>
					<dd><?php echo esc_html( trim( self::get( $info, 'org' ) . ( '' !== (string) self::get( $info, 'asn' ) ? ' (' . self::get( $info, 'asn' ) . ')' : '' ) ) ); ?></dd>
				<?php endif; ?>

				<?php if ( '' !== WooCommerce_Anti_Fraud_IP_Intel::place( $info ) ) : ?>
					<dt><?php esc_html_e( 'Location', 'woocommerce-anti-fraud' ); ?></dt>
					<dd><?php echo esc_html( WooCommerce_Anti_Fraud_IP_Intel::place( $info ) ); ?></dd>
				<?php endif; ?>

				<dt><?php esc_html_e( 'WebRTC', 'woocommerce-anti-fraud' ); ?></dt>
				<dd><?php echo $this->webrtc_tag( $webrtc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in webrtc_tag(). ?></dd>
			</dl>

			<?php if ( in_array( self::get( $webrtc, 'status' ), array( 'mismatch', 'mobile_mismatch' ), true ) && '' !== (string) self::get( $webrtc, 'leak' ) ) : ?>
				<div class="wcaf-leak">
					<?php echo self::icon( 'eye', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<div>
						<strong><?php esc_html_e( 'Likely real connection', 'woocommerce-anti-fraud' ); ?></strong>
						<code><?php echo esc_html( (string) $webrtc['leak'] ); ?></code>
						<?php
						$leak_info = (array) self::get( $webrtc, 'leak_info', array() );
						$leak_text = array_filter( array( WooCommerce_Anti_Fraud_IP_Intel::place( $leak_info ), (string) self::get( $leak_info, 'org' ) ) );
						if ( $leak_text ) :
							?>
							<span><?php echo esc_html( implode( ' · ', $leak_text ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The device section.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	private function render_device( array $report ) {
		$device  = (array) self::get( $report, 'device', array() );
		$details = (array) self::get( $device, 'details', array() );
		$fp      = (string) self::get( $device, 'fingerprint' );
		$tz      = (array) self::get( $report, 'tz', array() );
		$elapsed = (int) self::get( $report, array( 'session', 'elapsed' ), 0 );

		$summary = array_filter(
			array(
				(string) self::get( $device, 'type' ),
				(string) self::get( $details, 'platform' ),
				(string) self::get( $details, 'screen' ),
			)
		);
		?>
		<div class="wcaf-section">
			<h4><?php echo self::icon( 'fingerprint-pattern', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Device', 'woocommerce-anti-fraud' ); ?></h4>
			<dl class="wcaf-kv">
				<?php if ( '' !== $fp ) : ?>
					<dt><?php esc_html_e( 'Fingerprint', 'woocommerce-anti-fraud' ); ?></dt>
					<dd>
						<code title="<?php echo esc_attr( $fp ); ?>"><?php echo esc_html( substr( $fp, 0, 12 ) ); ?>&hellip;</code>
						<span class="wcaf-muted"><?php echo 'browser' === self::get( $device, 'source' ) ? esc_html__( 'browser', 'woocommerce-anti-fraud' ) : esc_html__( 'headers only', 'woocommerce-anti-fraud' ); ?></span>
					</dd>
				<?php endif; ?>

				<?php if ( $summary ) : ?>
					<dt><?php esc_html_e( 'Device', 'woocommerce-anti-fraud' ); ?></dt>
					<dd><?php echo esc_html( implode( ' · ', $summary ) ); ?></dd>
				<?php endif; ?>

				<?php if ( '' !== (string) self::get( $tz, 'name' ) ) : ?>
					<dt><?php esc_html_e( 'Clock', 'woocommerce-anti-fraud' ); ?></dt>
					<dd><?php echo esc_html( (string) $tz['name'] ); ?></dd>
				<?php endif; ?>

				<?php if ( $elapsed > 0 ) : ?>
					<dt><?php esc_html_e( 'On page', 'woocommerce-anti-fraud' ); ?></dt>
					<dd><?php echo esc_html( self::duration( $elapsed ) ); ?></dd>
				<?php endif; ?>
			</dl>
			<?php if ( empty( $report['browser'] ) ) : ?>
				<p class="wcaf-muted wcaf-note"><?php esc_html_e( 'No browser data arrived with this order: it was placed without the checkout page (an express button or the API), or the browser blocked the checks.', 'woocommerce-anti-fraud' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * "1m 12s".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%ds', 'woocommerce-anti-fraud' ), $seconds );
		}

		/* translators: 1: minutes, 2: seconds. */
		return sprintf( __( '%1$dm %2$ds', 'woocommerce-anti-fraud' ), intdiv( $seconds, 60 ), $seconds % 60 );
	}

	/**
	 * Colored tag for a connection type.
	 *
	 * @param string $type Connection type.
	 * @return string Escaped HTML.
	 */
	private function type_tag( $type ) {
		$tone = in_array( $type, WooCommerce_Anti_Fraud_IP_Intel::RISKY_TYPES, true ) ? 'bad' : ( '' === $type ? 'none' : 'good' );

		return '<span class="wcaf-tag is-' . esc_attr( $tone ) . '">' . esc_html( WooCommerce_Anti_Fraud_IP_Intel::type_label( $type ) ) . '</span>';
	}

	/**
	 * Colored tag for the WebRTC verdict.
	 *
	 * @param array $webrtc WebRTC result.
	 * @return string Escaped HTML.
	 */
	private function webrtc_tag( array $webrtc ) {
		$status = (string) self::get( $webrtc, 'status', 'none' );

		if ( empty( $webrtc['checked'] ) ) {
			return '<span class="wcaf-tag is-none">' . esc_html__( 'Not checked', 'woocommerce-anti-fraud' ) . '</span>';
		}
		if ( 'none' === $status ) {
			$text = empty( $webrtc['available'] ) ? __( 'Blocked in the browser', 'woocommerce-anti-fraud' ) : __( 'No public IP found', 'woocommerce-anti-fraud' );
			return '<span class="wcaf-tag is-none">' . esc_html( $text ) . '</span>';
		}

		$labels = array(
			'match'           => array( 'good', __( 'Same connection', 'woocommerce-anti-fraud' ) ),
			'relay'           => array( 'good', __( 'iCloud Private Relay', 'woocommerce-anti-fraud' ) ),
			'mobile_hop'      => array( 'good', __( 'Same mobile carrier', 'woocommerce-anti-fraud' ) ),
			'mobile_mismatch' => array( 'bad', __( 'Different mobile carrier', 'woocommerce-anti-fraud' ) ),
			'mismatch'        => array( 'bad', __( 'Different IP: leak', 'woocommerce-anti-fraud' ) ),
		);
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : array( 'none', $status );

		return '<span class="wcaf-tag is-' . esc_attr( $label[0] ) . '">' . esc_html( $label[1] ) . '</span>';
	}

	/**
	 * Link to (re)check an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function check_url( WC_Order $order ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::CHECK_ACTION,
					'order_id' => (int) $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			self::CHECK_ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * Handle "Check now" and "Recalculate".
	 *
	 * @return void
	 */
	public function handle_check() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked just below.

		check_admin_referer( self::CHECK_ACTION . '_' . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce-anti-fraud' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'That order doesn\'t exist.', 'woocommerce-anti-fraud' ), 404 );
		}

		try {
			if ( WooCommerce_Anti_Fraud_Scorer::report( $order ) ) {
				WooCommerce_Anti_Fraud_Scorer::rescore( $order );
			} else {
				WooCommerce_Anti_Fraud_Scorer::score_offline( $order, 'manual' );
			}
		} catch ( \Throwable $e ) {
			WooCommerce_Anti_Fraud_Plugin::log( 'order_admin.check', $e );
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * The per-signal detail dialogs, opened by the info buttons.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function render_dialogs( WC_Order $order, array $report ) {
		foreach ( WooCommerce_Anti_Fraud_Scorer::factors() as $key => $factor ) {
			$points = self::get( $report, array( 'factors', $key ), null );
			?>
			<dialog class="wcaf-dialog" id="wcaf-dialog-<?php echo esc_attr( $key ); ?>" aria-labelledby="wcaf-dialog-title-<?php echo esc_attr( $key ); ?>">
				<div class="wcaf-dialog-head">
					<h3 id="wcaf-dialog-title-<?php echo esc_attr( $key ); ?>"><?php echo self::icon( $factor['icon'], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $factor['label'] ); ?></h3>
					<button type="button" class="wcaf-dialog-close" data-wcaf-close aria-label="<?php esc_attr_e( 'Close', 'woocommerce-anti-fraud' ); ?>"><?php echo self::icon( 'x', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
				</div>
				<div class="wcaf-dialog-body">
					<p class="wcaf-dialog-help"><?php echo esc_html( $factor['help'] ); ?></p>
					<p class="wcaf-dialog-points">
						<?php
						if ( null === $points ) {
							esc_html_e( 'This signal is switched off in the settings.', 'woocommerce-anti-fraud' );
						} elseif ( (int) $points > 0 ) {
							/* translators: 1: points taken off, 2: maximum. */
							echo esc_html( sprintf( __( 'Took %1$d points off (up to %2$d).', 'woocommerce-anti-fraud' ), (int) $points, (int) $factor['max'] ) );
						} else {
							/* translators: %d: maximum points. */
							echo esc_html( sprintf( __( 'Nothing wrong here (it can take up to %d points off).', 'woocommerce-anti-fraud' ), (int) $factor['max'] ) );
						}
						?>
					</p>
					<?php call_user_func( array( $this, 'dialog_' . $key ), $order, $report ); ?>
				</div>
			</dialog>
			<?php
		}
	}

	/**
	 * Key/value table.
	 *
	 * @param array<string,string> $rows Label => escaped HTML value.
	 * @return void
	 */
	private static function kv_table( array $rows ) {
		echo '<table class="wcaf-table wcaf-table-kv"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . wp_kses_post( '' !== (string) $value ? $value : '&mdash;' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Link to an order, marking the current one.
	 *
	 * @param int $order_id Order ID.
	 * @param int $current  Current order ID.
	 * @return string Escaped HTML.
	 */
	private static function order_link( $order_id, $current ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return '#' . esc_html( (string) (int) $order_id );
		}

		$text = '#' . $order->get_order_number() . ( (int) $order_id === (int) $current ? ' ' . __( '(this order)', 'woocommerce-anti-fraud' ) : '' );

		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * GMT datetime for display.
	 *
	 * @param string $gmt GMT datetime.
	 * @return string
	 */
	private static function when( $gmt ) {
		return '' !== (string) $gmt ? get_date_from_gmt( (string) $gmt, 'M j, g:i a' ) : '';
	}

	/**
	 * Dialog: VPN, proxy and WebRTC.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_proxy( WC_Order $order, array $report ) {
		$info   = (array) self::get( $report, array( 'ip', 'info' ), array() );
		$webrtc = (array) self::get( $report, 'webrtc', array() );
		$ips    = (array) self::get( $webrtc, 'ips', array() );
		$leak   = (array) self::get( $webrtc, 'leak_info', array() );

		$rows = array(
			__( 'Order IP', 'woocommerce-anti-fraud' )     => '<code>' . esc_html( (string) self::get( $report, array( 'ip', 'ip' ) ) ) . '</code>',
			__( 'Connection type', 'woocommerce-anti-fraud' ) => $this->type_tag( (string) self::get( $info, 'type' ) ),
			__( 'Network', 'woocommerce-anti-fraud' )      => esc_html( trim( self::get( $info, 'org' ) . ' ' . self::get( $info, 'asn' ) ) ),
			__( 'Location', 'woocommerce-anti-fraud' )     => esc_html( WooCommerce_Anti_Fraud_IP_Intel::place( $info ) ),
			__( 'IP timezone', 'woocommerce-anti-fraud' )  => esc_html( (string) self::get( $info, 'timezone' ) ),
			__( 'WebRTC check', 'woocommerce-anti-fraud' ) => $this->webrtc_tag( $webrtc ),
			__( 'IPs WebRTC found', 'woocommerce-anti-fraud' ) => esc_html( implode( ', ', array_map( 'strval', $ips ) ) ),
		);

		if ( '' !== (string) self::get( $webrtc, 'leak' ) ) {
			$rows[ __( 'Leaked IP', 'woocommerce-anti-fraud' ) ]          = '<code>' . esc_html( (string) $webrtc['leak'] ) . '</code> ' . $this->type_tag( (string) self::get( $leak, 'type' ) );
			$rows[ __( 'Leaked IP network', 'woocommerce-anti-fraud' ) ]  = esc_html( trim( self::get( $leak, 'org' ) . ' ' . self::get( $leak, 'asn' ) ) );
			$rows[ __( 'Leaked IP location', 'woocommerce-anti-fraud' ) ] = esc_html( WooCommerce_Anti_Fraud_IP_Intel::place( $leak ) );
		}

		$order_ip = (string) self::get( $report, array( 'ip', 'order_ip' ) );
		if ( '' !== $order_ip && (string) self::get( $report, array( 'ip', 'ip' ) ) !== $order_ip ) {
			$rows[ __( 'IP WooCommerce saved', 'woocommerce-anti-fraud' ) ] = '<code>' . esc_html( $order_ip ) . '</code> ' . esc_html__( '(read from headers the browser can set)', 'woocommerce-anti-fraud' );
		}

		self::kv_table( $rows );

		echo '<p class="wcaf-dialog-note">' . esc_html__( 'The WebRTC check asks a STUN server for the browser\'s public address. VPNs and proxies often don\'t cover that request, so a different address there is usually the customer\'s real connection.', 'woocommerce-anti-fraud' ) . '</p>';
	}

	/**
	 * Dialog: failed payments.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_declines( WC_Order $order, array $report ) {
		$rows = WooCommerce_Anti_Fraud_Declines::rows( (string) self::get( $report, array( 'device', 'fingerprint' ) ), WooCommerce_Anti_Fraud_Scorer::links( $report ), WooCommerce_Anti_Fraud_Declines::window() );

		if ( ! $rows ) {
			/* translators: %d: hours. */
			echo '<p class="wcaf-dialog-empty">' . esc_html( sprintf( __( 'No failed payments from this device or IP in the last %d hours.', 'woocommerce-anti-fraud' ), WooCommerce_Anti_Fraud_Declines::window() ) ) . '</p>';
			return;
		}

		echo '<table class="wcaf-table"><thead><tr><th>' . esc_html__( 'When', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Reason', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Gateway', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'IP', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Order', 'woocommerce-anti-fraud' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( self::when( $row->created_at ) ) . '</td><td><code>' . esc_html( $row->reason ) . '</code></td><td>' . esc_html( $row->gateway ) . '</td><td>' . esc_html( $row->ip ) . '</td><td>' . ( $row->order_id ? self::order_link( (int) $row->order_id, (int) $order->get_id() ) : '&mdash;' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- order_link() escapes.
		}
		echo '</tbody></table>';
	}

	/**
	 * Dialog: IP changes.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_ip_change( WC_Order $order, array $report ) {
		$checks  = (array) self::get( $report, 'checks', array() );
		$session = (array) self::get( $report, 'session', array() );
		$devices = (int) self::get( $report, array( 'counts', 'devices_on_ip' ), 0 );
		$others  = (array) self::get( $checks, 'other_countries', array() );

		$items = array(
			array(
				! empty( $checks['changed_on_page'] ),
				'' !== (string) self::get( $session, 'page_ip' ) && (string) self::get( $session, 'page_ip' ) !== (string) self::get( $report, array( 'ip', 'ip' ) )
					/* translators: %s: IP the page was loaded from. */
					? sprintf( __( 'Checkout page loaded from %s, order placed from a different IP', 'woocommerce-anti-fraud' ), $session['page_ip'] )
					: __( 'Same IP from loading the checkout page to placing the order', 'woocommerce-anti-fraud' ),
			),
			array(
				! empty( $checks['changed_session'] ),
				! empty( $checks['changed_session'] )
					/* translators: %s: earlier IP. */
					? sprintf( __( 'Was on %s less than an hour earlier', 'woocommerce-anti-fraud' ), (string) self::get( $session, 'last_ip' ) )
					: __( 'No recent switch from another IP', 'woocommerce-anti-fraud' ),
			),
			array(
				$devices >= 4,
				/* translators: %d: devices. */
				sprintf( _n( '%d device checked out from this IP recently', '%d devices checked out from this IP recently', max( 1, $devices ), 'woocommerce-anti-fraud' ), max( 1, $devices ) ),
			),
			array(
				! empty( $checks['new_country'] ),
				! empty( $checks['new_country'] )
					/* translators: %s: country codes. */
					? sprintf( __( 'This device was in %s in the last 30 days', 'woocommerce-anti-fraud' ), implode( ', ', array_map( 'strval', $others ) ) )
					: __( 'No sudden change of country', 'woocommerce-anti-fraud' ),
			),
		);

		echo '<ul class="wcaf-checklist">';
		foreach ( $items as $item ) {
			echo '<li class="' . ( $item[0] ? 'is-hit' : 'is-ok' ) . '">' . self::icon( $item[0] ? 'triangle-alert' : 'check', 15 ) . '<span>' . esc_html( $item[1] ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		}
		echo '</ul>';
	}

	/**
	 * Dialog: order velocity.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_velocity( WC_Order $order, array $report ) {
		$ids = WooCommerce_Anti_Fraud_Scorer::related_order_ids( (string) self::get( $report, array( 'device', 'fingerprint' ) ), WooCommerce_Anti_Fraud_Scorer::links( $report ), 48, 30, (int) $order->get_id() );

		if ( count( $ids ) < 2 ) {
			echo '<p class="wcaf-dialog-empty">' . esc_html__( 'No other orders from this device or IP in the last 48 hours.', 'woocommerce-anti-fraud' ) . '</p>';
			return;
		}

		echo '<table class="wcaf-table"><thead><tr><th>' . esc_html__( 'Order', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Status', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Placed', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Customer', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Score', 'woocommerce-anti-fraud' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$other = wc_get_order( $id );
			if ( ! $other instanceof WC_Order ) {
				continue;
			}
			$created = $other->get_date_created();
			echo '<tr><td>' . self::order_link( $id, (int) $order->get_id() ) . '</td><td>' . esc_html( wc_get_order_status_name( $other->get_status() ) ) . '</td><td>' . esc_html( $created ? $created->date_i18n( 'M j, g:i a' ) : '' ) . '</td><td>' . esc_html( $other->get_formatted_billing_full_name() ) . '</td><td>' . $this->pill( $other ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- order_link() and pill() escape.
		}
		echo '</tbody></table>';
	}

	/**
	 * Dialog: addresses per IP.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_addresses( WC_Order $order, array $report ) {
		$list = (array) self::get( $report, array( 'counts', 'address_list' ), array() );

		if ( count( $list ) < 2 ) {
			echo '<p class="wcaf-dialog-empty">' . esc_html__( 'No other addresses used from this IP in the last 7 days.', 'woocommerce-anti-fraud' ) . '</p>';
			return;
		}

		echo '<table class="wcaf-table"><thead><tr><th>' . esc_html__( 'Order', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Address', 'woocommerce-anti-fraud' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $row ) {
			echo '<tr><td>' . self::order_link( (int) self::get( $row, 'order', 0 ), (int) $order->get_id() ) . '</td><td>' . esc_html( (string) self::get( $row, 'address' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- order_link() escapes.
		}
		echo '</tbody></table>';
	}

	/**
	 * Dialog: IP location vs billing.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_geo( WC_Order $order, array $report ) {
		$info    = (array) self::get( $report, array( 'ip', 'info' ), array() );
		$billing = array_filter( array( $order->get_billing_city(), $order->get_billing_state(), $order->get_billing_country() ) );

		self::kv_table(
			array(
				__( 'IP location', 'woocommerce-anti-fraud' )     => esc_html( '' !== WooCommerce_Anti_Fraud_IP_Intel::place( $info ) ? WooCommerce_Anti_Fraud_IP_Intel::place( $info ) : __( 'Unknown: no location data for this IP', 'woocommerce-anti-fraud' ) ),
				__( 'Billing address', 'woocommerce-anti-fraud' ) => esc_html( implode( ', ', $billing ) ),
				__( 'Location source', 'woocommerce-anti-fraud' ) => esc_html( (string) self::get( $info, 'source' ) ),
			)
		);

		echo '<p class="wcaf-dialog-note">' . esc_html__( 'IP locations are approximate, often off by a city or two, so only a different state or country counts.', 'woocommerce-anti-fraud' ) . '</p>';
	}

	/**
	 * Dialog: IPs per device.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_ip_spread( WC_Order $order, array $report ) {
		$rows = WooCommerce_Anti_Fraud_Identity::ips( (int) self::get( $report, array( 'device', 'visitor' ), 0 ) );

		if ( ! $rows ) {
			echo '<p class="wcaf-dialog-empty">' . esc_html__( 'No IP history for this device.', 'woocommerce-anti-fraud' ) . '</p>';
			return;
		}

		echo '<table class="wcaf-table"><thead><tr><th>' . esc_html__( 'IP', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Type', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Network', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Location', 'woocommerce-anti-fraud' ) . '</th><th>' . esc_html__( 'Last seen', 'woocommerce-anti-fraud' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$place = implode( ', ', array_filter( array( $row->city, $row->region, $row->country ) ) );
			echo '<tr><td><code>' . esc_html( $row->ip ) . '</code></td><td>' . $this->type_tag( (string) $row->conn_type ) . '</td><td>' . esc_html( $row->org ) . '</td><td>' . esc_html( $place ) . '</td><td>' . esc_html( self::when( $row->last_seen ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- type_tag() escapes.
		}
		echo '</tbody></table>';
	}

	/**
	 * Dialog: pasted details.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_paste( WC_Order $order, array $report ) {
		$fields = (array) self::get( $report, 'fields', array() );

		if ( ! $fields ) {
			echo '<p class="wcaf-dialog-empty">' . esc_html__( 'No field data arrived with this order (the check is off, or the browser checks didn\'t run).', 'woocommerce-anti-fraud' ) . '</p>';
			return;
		}

		$labels = array(
			'typed'    => __( 'Typed', 'woocommerce-anti-fraud' ),
			'pasted'   => __( 'Pasted', 'woocommerce-anti-fraud' ),
			'autofill' => __( 'Autofilled', 'woocommerce-anti-fraud' ),
		);

		echo '<ul class="wcaf-fields">';
		foreach ( $fields as $field => $method ) {
			$name = ucfirst( str_replace( '_', ' ', (string) $field ) );
			echo '<li class="is-' . esc_attr( 'pasted' === $method ? 'hit' : 'ok' ) . '"><span>' . esc_html( $name ) . '</span><b class="wcaf-tag is-' . esc_attr( 'pasted' === $method ? 'bad' : ( 'autofill' === $method ? 'none' : 'good' ) ) . '">' . esc_html( isset( $labels[ $method ] ) ? $labels[ $method ] : (string) $method ) . '</b></li>';
		}
		echo '</ul>';

		echo '<p class="wcaf-dialog-note">' . esc_html__( 'Only how each field was filled in is recorded, never what was typed. Card fields live inside the payment gateway\'s frame and can\'t be seen.', 'woocommerce-anti-fraud' ) . '</p>';
	}

	/**
	 * Dialog: browser timezone.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_timezone( WC_Order $order, array $report ) {
		$check  = (array) self::get( $report, array( 'checks', 'timezone' ), array() );
		$format = function ( $minutes ) {
			if ( null === $minutes || '' === $minutes ) {
				return '';
			}
			$minutes = (int) $minutes;
			return 'UTC' . ( $minutes < 0 ? '-' : '+' ) . sprintf( '%d:%02d', intdiv( abs( $minutes ), 60 ), abs( $minutes ) % 60 );
		};

		self::kv_table(
			array(
				__( 'Browser clock', 'woocommerce-anti-fraud' ) => esc_html( trim( (string) self::get( $report, array( 'tz', 'name' ) ) . ' ' . $format( self::get( $check, 'browser', null ) ) ) ),
				__( 'IP location', 'woocommerce-anti-fraud' )   => esc_html( trim( (string) self::get( $report, array( 'ip', 'info', 'timezone' ) ) . ' ' . $format( self::get( $check, 'ip', null ) ) ) ),
			)
		);

		echo '<p class="wcaf-dialog-note">' . esc_html__( 'A difference of an hour or more counts. Travellers\' devices normally switch to the local time on their own.', 'woocommerce-anti-fraud' ) . '</p>';
	}

	/**
	 * Dialog: phone area code.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $report Report.
	 * @return void
	 */
	private function dialog_phone( WC_Order $order, array $report ) {
		$check = (array) self::get( $report, array( 'checks', 'phone' ), array() );

		if ( '' === (string) self::get( $check, 'area_code' ) ) {
			echo '<p class="wcaf-dialog-empty">' . esc_html__( 'No US or Canadian phone number on this order, so there\'s nothing to compare.', 'woocommerce-anti-fraud' ) . '</p>';
			return;
		}

		self::kv_table(
			array(
				__( 'Area code', 'woocommerce-anti-fraud' )     => '<code>' . esc_html( (string) $check['area_code'] ) . '</code>',
				__( 'Area code is in', 'woocommerce-anti-fraud' ) => esc_html( '' !== (string) self::get( $check, 'phone_state' ) ? (string) $check['phone_state'] : __( 'Unknown or non-geographic', 'woocommerce-anti-fraud' ) ),
				__( 'Billing state', 'woocommerce-anti-fraud' ) => esc_html( (string) $order->get_billing_state() ),
			)
		);
	}

	/**
	 * Add the Fraud column after Status.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$out = array();

		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['wcaf_risk'] = __( 'Fraud', 'woocommerce-anti-fraud' );
			}
		}

		if ( ! isset( $out['wcaf_risk'] ) ) {
			$out['wcaf_risk'] = __( 'Fraud', 'woocommerce-anti-fraud' );
		}

		return $out;
	}

	/**
	 * Column cell, posts storage.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order ID.
	 * @return void
	 */
	public function render_column_posts( $column, $post_id ) {
		if ( 'wcaf_risk' === $column ) {
			$order = wc_get_order( $post_id );
			echo $order instanceof WC_Order ? $this->pill( $order ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill() escapes.
		}
	}

	/**
	 * Column cell, HPOS.
	 *
	 * @param string       $column Column.
	 * @param WC_Order|int $order  Order.
	 * @return void
	 */
	public function render_column_hpos( $column, $order ) {
		if ( 'wcaf_risk' === $column ) {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
			echo $order instanceof WC_Order ? $this->pill( $order ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill() escapes.
		}
	}

	/**
	 * Score pill for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string Escaped HTML.
	 */
	private function pill( WC_Order $order ) {
		$score = (string) $order->get_meta( '_wcaf_score' );

		if ( '' === $score ) {
			return '<span class="wcaf-pill is-none" title="' . esc_attr__( 'Not checked', 'woocommerce-anti-fraud' ) . '">&ndash;</span>';
		}

		$risk  = (string) $order->get_meta( '_wcaf_risk' );
		$risk  = in_array( $risk, array( 'low', 'medium', 'high' ), true ) ? $risk : 'medium';
		$title = WooCommerce_Anti_Fraud_Scorer::risk_label( $risk );

		return '<span class="wcaf-pill is-' . esc_attr( $risk ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $score ) . '</span>';
	}
}
