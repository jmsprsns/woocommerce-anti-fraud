<?php
/**
 * Admin side: the Content Powered > WooCommerce Anti Fraud page and the Plugins-screen link.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class WooCommerce_Anti_Fraud_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'woocommerce-anti-fraud';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'shield-check';

	/**
	 * Settings tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview'  => 'layout-dashboard',
		'detection' => 'scan-search',
		'network'   => 'earth',
		'actions'   => 'zap',
		'advanced'  => 'sliders-horizontal',
	);

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'woocommerce_anti_fraud_admin';

	/**
	 * Plugin instance.
	 *
	 * @var WooCommerce_Anti_Fraud_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param WooCommerce_Anti_Fraud_Plugin $plugin Plugin instance.
	 */
	public function __construct( WooCommerce_Anti_Fraud_Plugin $plugin ) {
		$this->plugin = $plugin;

		WooCommerce_Anti_Fraud_Menu::init();
		WooCommerce_Anti_Fraud_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_woocommerce_anti_fraud_test_ipinfo', array( $this, 'ajax_test_ipinfo' ) );
		add_action( 'wp_ajax_woocommerce_anti_fraud_clear', array( $this, 'ajax_clear' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( WOOCOMMERCE_ANTI_FRAUD_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'woocommerce-anti-fraud' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = WooCommerce_Anti_Fraud_Icons::get( self::ICON, 17, 'woocommerce-anti-fraud-menu-icon' );
		$menu_title = $icon . '<span class="woocommerce-anti-fraud-menu-label">' . esc_html__( 'WooCommerce Anti Fraud', 'woocommerce-anti-fraud' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			WooCommerce_Anti_Fraud_Menu::SLUG,
			__( 'WooCommerce Anti Fraud', 'woocommerce-anti-fraud' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' wcaf-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one
	 * form saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			WooCommerce_Anti_Fraud_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => WooCommerce_Anti_Fraud_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = WooCommerce_Anti_Fraud_Plugin::defaults();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$number   = function ( $key, $min, $max ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};
		$ids      = function ( $key ) use ( $in ) {
			$list = isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? array_filter( $in[ $key ], 'is_scalar' ) : array();
			return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) ), 0, 500 );
		};
		// options.php has already unslashed the values.
		$text = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
		};

		$out = array(
			'enabled'          => $flag( 'enabled' ),
			'scope'            => isset( $in['scope'] ) && 'products' === $in['scope'] ? 'products' : 'all',
			'product_ids'      => $ids( 'product_ids' ),
			'product_cats'     => $ids( 'product_cats' ),
			'fingerprint'      => $flag( 'fingerprint' ),
			'webrtc'           => $flag( 'webrtc' ),
			'paste'            => $flag( 'paste' ),
			'factors'          => array(),
			'review_below'     => $number( 'review_below', 1, 100 ),
			'high_below'       => $number( 'high_below', 0, 100 ),
			'trusted_proxies'  => '',
			'proxy_header'     => isset( WooCommerce_Anti_Fraud_Request::PROXY_HEADERS[ $text( 'proxy_header' ) ] ) ? $text( 'proxy_header' ) : $defaults['proxy_header'],
			'trust_private'    => $flag( 'trust_private' ),
			'ipinfo'           => $flag( 'ipinfo' ),
			'ipinfo_token'     => '',
			'stun_server'      => $defaults['stun_server'],
			'hold_high_risk'   => $flag( 'hold_high_risk' ),
			'block_declines'   => $flag( 'block_declines' ),
			'block_threshold'  => $number( 'block_threshold', 2, 50 ),
			'decline_window'   => $number( 'decline_window', 1, 720 ),
			'block_message'    => substr( sanitize_text_field( $text( 'block_message' ) ), 0, 300 ),
			'alert_emails'     => '',
			'alert_high_risk'  => $flag( 'alert_high_risk' ),
			'alert_threshold'  => $number( 'alert_threshold', 2, 100 ),
			'retention_months' => $number( 'retention_months', 0, 60 ),
			'keep_data'        => $flag( 'keep_data' ),
		);

		$factors = isset( $in['factors'] ) && is_array( $in['factors'] ) ? $in['factors'] : array();
		foreach ( array_keys( WooCommerce_Anti_Fraud_Scorer::FACTOR_MAX ) as $key ) {
			$out['factors'][ $key ] = empty( $factors[ $key ] ) ? 0 : 1;
		}

		// "High risk below" can't be above "needs review below".
		$out['high_below'] = min( $out['high_below'], $out['review_below'] );

		if ( WooCommerce_Anti_Fraud_Declines::default_block_message() === $out['block_message'] ) {
			$out['block_message'] = '';
		}

		// Trusted proxies: keep the valid IPs and ranges, say which were dropped.
		$raw_proxies = $text( 'trusted_proxies' );
		$entries     = preg_split( '/[\s,]+/', $raw_proxies, -1, PREG_SPLIT_NO_EMPTY );
		$valid       = WooCommerce_Anti_Fraud_Request::parse_ranges( $raw_proxies );
		$invalid     = array_diff( $entries, $valid );

		$out['trusted_proxies'] = implode( "\n", array_slice( $valid, 0, 100 ) );
		if ( $invalid ) {
			add_settings_error(
				self::PAGE,
				'wcaf-proxies',
				sprintf(
					/* translators: %s: rejected entries. */
					__( 'These trusted proxies aren\'t valid IPs or ranges and were left out: %s', 'woocommerce-anti-fraud' ),
					implode( ', ', array_map( 'sanitize_text_field', array_slice( $invalid, 0, 10 ) ) )
				),
				'warning'
			);
		}

		$token = trim( sanitize_text_field( $text( 'ipinfo_token' ) ) );
		if ( preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $token ) ) {
			$out['ipinfo_token'] = $token;
		} elseif ( '' !== $token ) {
			add_settings_error( self::PAGE, 'wcaf-token', __( 'That ipinfo.io token doesn\'t look right (letters and numbers only), so it wasn\'t saved.', 'woocommerce-anti-fraud' ), 'warning' );
		}

		$stun = trim( sanitize_text_field( $text( 'stun_server' ) ) );
		if ( WooCommerce_Anti_Fraud_Collector::valid_stun( $stun ) ) {
			$out['stun_server'] = $stun;
		} elseif ( '' !== $stun ) {
			add_settings_error( self::PAGE, 'wcaf-stun', __( 'The STUN server should look like stun:stun.example.com:3478. The default was kept.', 'woocommerce-anti-fraud' ), 'warning' );
		}

		$emails = array();
		foreach ( preg_split( '/[\s,;]+/', $text( 'alert_emails' ), -1, PREG_SPLIT_NO_EMPTY ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) && ! in_array( $email, $emails, true ) ) {
				$emails[] = $email;
			}
		}
		$out['alert_emails'] = implode( ', ', array_slice( $emails, 0, 20 ) );

		return $out;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="woocommerce-anti-fraud-menu-icon-style">'
			. '#adminmenu a:has(>.woocommerce-anti-fraud-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .woocommerce-anti-fraud-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .woocommerce-anti-fraud-menu-icon,#adminmenu a:hover .woocommerce-anti-fraud-menu-icon,#adminmenu a:focus .woocommerce-anti-fraud-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'woocommerce-anti-fraud-admin', WOOCOMMERCE_ANTI_FRAUD_URL . 'assets/admin.css', array(), WOOCOMMERCE_ANTI_FRAUD_VERSION );
		wp_enqueue_script( 'woocommerce-anti-fraud-admin', WOOCOMMERCE_ANTI_FRAUD_URL . 'assets/admin.js', array(), WOOCOMMERCE_ANTI_FRAUD_VERSION, true );

		// WooCommerce's product and category pickers.
		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		wp_localize_script(
			'woocommerce-anti-fraud-admin',
			'woocommerce_anti_fraud_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'testing' => __( 'Testing...', 'woocommerce-anti-fraud' ),
					'failed'  => __( 'Couldn\'t reach ipinfo.io from this server.', 'woocommerce-anti-fraud' ),
					'cleared' => __( 'Cleared', 'woocommerce-anti-fraud' ),
					'confirm' => __( 'Stop counting these failed payments? New failures will count again.', 'woocommerce-anti-fraud' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return WooCommerce_Anti_Fraud_Icons::get( $name, $size );
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'stats'    => array(
				'panel'  => 'overview',
				'icon'   => 'chart-column',
				'title'  => __( 'Last 30 days', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_stats',
			),
			'risky'    => array(
				'panel'  => 'overview',
				'icon'   => 'shield-alert',
				'title'  => __( 'Orders to review', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'Recent orders that scored "needs review" or "high risk". Open one to see exactly why in its Fraud Risk box.', 'woocommerce-anti-fraud' ),
				'render' => 'render_risky_orders',
			),
			'repeat'   => array(
				'panel'  => 'overview',
				'icon'   => 'credit-card',
				'title'  => __( 'Repeated failed payments', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'Devices, IPs and emails with two or more failed payments in the failed-payment window. If one of them is a real customer you\'ve spoken to, clear it.', 'woocommerce-anti-fraud' ),
				'render' => 'render_repeat_offenders',
			),
			'browser'  => array(
				'panel'  => 'detection',
				'icon'   => 'scan-eye',
				'title'  => __( 'Browser checks', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'A small script on the checkout and pay-for-order pages. It never records what customers type, and never touches card fields.', 'woocommerce-anti-fraud' ),
				'render' => 'render_browser_checks',
			),
			'signals'  => array(
				'panel'  => 'detection',
				'icon'   => 'list-checks',
				'title'  => __( 'What counts towards the score', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'Every order starts at 100. Each signal that looks wrong takes points off, up to the amount shown. Untick a signal that doesn\'t suit your store.', 'woocommerce-anti-fraud' ),
				'render' => 'render_signals',
			),
			'levels'   => array(
				'panel'  => 'detection',
				'icon'   => 'gauge',
				'title'  => __( 'Risk levels', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_levels',
			),
			'scope'    => array(
				'panel'  => 'detection',
				'icon'   => 'package-search',
				'title'  => __( 'Which orders to check', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_scope',
			),
			'visitor'  => array(
				'panel'  => 'network',
				'icon'   => 'router',
				'title'  => __( 'Your visitors\' real IP', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'Headers like X-Forwarded-For can be faked by anyone, so they\'re only believed from Cloudflare (checked against Cloudflare\'s published addresses) or from proxies you list here.', 'woocommerce-anti-fraud' ),
				'render' => 'render_visitor_ip',
			),
			'lookups'  => array(
				'panel'  => 'network',
				'icon'   => 'satellite-dish',
				'title'  => __( 'IP lookups', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'Location, network and connection type (home, mobile, data center, VPN, Tor, iCloud Private Relay) for each order\'s IP, from ipinfo.io. Answers are cached for a day per IP.', 'woocommerce-anti-fraud' ),
				'render' => 'render_lookups',
			),
			'webrtc'   => array(
				'panel'  => 'network',
				'icon'   => 'radar',
				'title'  => __( 'WebRTC leak check', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'The browser asks a STUN server for its own public address. Many VPNs and proxies don\'t cover that request, so a different address is usually the customer\'s real connection.', 'woocommerce-anti-fraud' ),
				'render' => 'render_webrtc',
			),
			'hold'     => array(
				'panel'  => 'actions',
				'icon'   => 'circle-pause',
				'title'  => __( 'Hold risky orders', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_hold',
			),
			'block'    => array(
				'panel'  => 'actions',
				'icon'   => 'ban',
				'title'  => __( 'Stop card testing', 'woocommerce-anti-fraud' ),
				'intro'  => __( 'Card testers run hundreds of stolen cards through checkout to find the ones that work, which costs you fees and can get your payment account shut down.', 'woocommerce-anti-fraud' ),
				'render' => 'render_block',
			),
			'alerts'   => array(
				'panel'  => 'actions',
				'icon'   => 'bell-ring',
				'title'  => __( 'Email alerts', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_alerts',
			),
			'data'     => array(
				'panel'  => 'advanced',
				'icon'   => 'database',
				'title'  => __( 'Data', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_data',
			),
			'devnotes' => array(
				'panel'  => 'advanced',
				'icon'   => 'code',
				'title'  => __( 'For developers', 'woocommerce-anti-fraud' ),
				'intro'  => '',
				'render' => 'render_developer',
			),
		);
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'overview'  => __( 'Overview', 'woocommerce-anti-fraud' ),
			'detection' => __( 'Detection', 'woocommerce-anti-fraud' ),
			'network'   => __( 'IP & Location', 'woocommerce-anti-fraud' ),
			'actions'   => __( 'Actions', 'woocommerce-anti-fraud' ),
			'advanced'  => __( 'Advanced', 'woocommerce-anti-fraud' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wcaf-header">
			<div class="wcaf-header-title">
				<span class="wcaf-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="wcaf-version"><?php echo esc_html( 'v' . WOOCOMMERCE_ANTI_FRAUD_VERSION ); ?></span>
			</div>
			<nav class="wcaf-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'woocommerce-anti-fraud' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="wcaf-tab" role="tab" data-wcaf-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap wcaf-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();

			if ( ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'WooCommerce isn\'t active, so no orders are being checked.', 'woocommerce-anti-fraud' ) . '</p></div>';
			}
			?>

			<form action="options.php" method="post" class="wcaf-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="wcaf-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings box.
	 *
	 * @param string               $id      Section ID.
	 * @param array<string,string> $section Section details from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox wcaf-box" id="wcaf-<?php echo esc_attr( $id ); ?>" data-wcaf-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="wcaf-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section['render'] ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return WooCommerce_Anti_Fraud_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="wcaf-check"><input type="checkbox" name="%1$s" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Number input.
	 *
	 * @param string $key    Setting key.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @return void
	 */
	private function number( $key, $min, $max, $suffix = '' ) {
		printf(
			'<input type="number" class="small-text" id="wcaf-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $this->plugin->get_setting( $key ),
			(int) $min,
			(int) $max,
			esc_html( $suffix )
		);
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label   Row label.
	 * @param callable $content Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $depends Setting that has to be on for this row to apply.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '', $depends = '' ) {
		echo '<tr' . ( '' !== $depends ? ' data-wcaf-depends="' . esc_attr( $depends ) . '"' : '' ) . '><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Overview: counts for the last 30 days.
	 *
	 * @return void
	 */
	public function render_stats() {
		$risk   = $this->risk_counts( 30 );
		$totals = WooCommerce_Anti_Fraud_Declines::totals( 24 );
		$tiles  = array(
			array( 'scan-search', array_sum( $risk ), __( 'Orders checked', 'woocommerce-anti-fraud' ), '' ),
			array( 'shield-alert', $risk['medium'], __( 'Need review', 'woocommerce-anti-fraud' ), 'medium' ),
			array( 'shield-x', $risk['high'], __( 'High risk', 'woocommerce-anti-fraud' ), 'high' ),
			array( 'credit-card', $totals['declines'], __( 'Failed payments, 24h', 'woocommerce-anti-fraud' ), '' ),
			array( 'ban', $totals['blocked'], __( 'Checkouts stopped, 24h', 'woocommerce-anti-fraud' ), '' ),
		);
		?>
		<div class="wcaf-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="wcaf-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>">
					<span class="wcaf-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="wcaf-stat-num"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
					<span class="wcaf-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $this->plugin->is_enabled() ) : ?>
			<p class="wcaf-callout is-warning"><?php echo self::icon( 'power', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Fraud checks are switched off, so new orders aren\'t being checked. Turn them on in the Save box.', 'woocommerce-anti-fraud' ); ?></span></p>
		<?php elseif ( 0 === array_sum( $risk ) ) : ?>
			<p class="wcaf-callout"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'No orders checked yet. Each new order is scored when it\'s placed; open any order to see its Fraud Risk box, or use "Check now" there on older orders.', 'woocommerce-anti-fraud' ); ?></span></p>
			<?php
		endif;
	}

	/**
	 * Orders scored in a period, by risk level.
	 *
	 * @param int $days Days back.
	 * @return array{low:int,medium:int,high:int}
	 */
	private function risk_counts( $days ) {
		global $wpdb;

		$out = array(
			'low'    => 0,
			'medium' => 0,
			'high'   => 0,
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $out;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Aggregate over order meta; no API for it.
		if ( WooCommerce_Anti_Fraud_Scorer::hpos() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS risk, COUNT(*) AS total FROM {$wpdb->prefix}wc_orders o INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_wcaf_risk' WHERE o.type = 'shop_order' AND o.date_created_gmt >= %s GROUP BY m.meta_value", $since ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS risk, COUNT(*) AS total FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wcaf_risk' WHERE p.post_type = 'shop_order' AND p.post_date_gmt >= %s GROUP BY m.meta_value", $since ) );
		}
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->risk ] ) ) {
				$out[ $row->risk ] = (int) $row->total;
			}
		}

		return $out;
	}

	/**
	 * Overview: recent risky orders.
	 *
	 * @return void
	 */
	public function render_risky_orders() {
		global $wpdb;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Filter on our own order meta across both storages.
		if ( WooCommerce_Anti_Fraud_Scorer::hpos() ) {
			$ids = $wpdb->get_col( "SELECT o.id FROM {$wpdb->prefix}wc_orders o INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_wcaf_risk' WHERE o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'wc-checkout-draft' ) AND m.meta_value IN ( 'medium', 'high' ) ORDER BY o.id DESC LIMIT 12" );
		} else {
			$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wcaf_risk' WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ( 'trash', 'wc-checkout-draft' ) AND m.meta_value IN ( 'medium', 'high' ) ORDER BY p.ID DESC LIMIT 12" );
		}
		// phpcs:enable

		if ( ! $ids ) {
			echo '<p class="wcaf-empty">' . self::icon( 'circle-check', 16 ) . '<span>' . esc_html__( 'Nothing to review right now.', 'woocommerce-anti-fraud' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$labels = WooCommerce_Anti_Fraud_Scorer::factors();
		?>
		<table class="widefat striped wcaf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'woocommerce-anti-fraud' ); ?></th>
					<th><?php esc_html_e( 'Score', 'woocommerce-anti-fraud' ); ?></th>
					<th><?php esc_html_e( 'Main reasons', 'woocommerce-anti-fraud' ); ?></th>
					<th><?php esc_html_e( 'Status', 'woocommerce-anti-fraud' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) :
					$order = wc_get_order( (int) $id );
					if ( ! $order instanceof WC_Order ) {
						continue;
					}
					$report  = WooCommerce_Anti_Fraud_Scorer::report( $order );
					$factors = $report ? array_filter( (array) $report['factors'] ) : array();
					arsort( $factors );
					$reasons = array();
					foreach ( array_slice( $factors, 0, 2, true ) as $key => $points ) {
						$reasons[] = $labels[ $key ]['label'] . ' (-' . (int) $points . ')';
					}
					$risk = (string) $order->get_meta( '_wcaf_risk' );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></a>
							<span class="wcaf-muted"><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></span>
						</td>
						<td><span class="wcaf-pill is-<?php echo esc_attr( 'high' === $risk ? 'high' : 'medium' ); ?>"><?php echo esc_html( (string) (int) $order->get_meta( '_wcaf_score' ) ); ?></span></td>
						<td><?php echo esc_html( implode( ', ', $reasons ) ); ?></td>
						<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Overview: repeated failed payments, with a Clear button each.
	 *
	 * @return void
	 */
	public function render_repeat_offenders() {
		$rows = WooCommerce_Anti_Fraud_Declines::repeat_offenders( WooCommerce_Anti_Fraud_Declines::window() );

		if ( ! $rows ) {
			echo '<p class="wcaf-empty">' . self::icon( 'circle-check', 16 ) . '<span>' . esc_html__( 'No repeated failed payments.', 'woocommerce-anti-fraud' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$types     = array(
			'fingerprint' => array( 'fingerprint-pattern', __( 'Device', 'woocommerce-anti-fraud' ) ),
			'ip'          => array( 'network', __( 'IP', 'woocommerce-anti-fraud' ) ),
			'email_hash'  => array( 'mail', __( 'Email', 'woocommerce-anti-fraud' ) ),
		);
		$threshold = max( 2, (int) $this->plugin->get_setting( 'block_threshold' ) );
		$blocking  = (bool) $this->plugin->get_setting( 'block_declines' );
		?>
		<table class="widefat striped wcaf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Who', 'woocommerce-anti-fraud' ); ?></th>
					<th><?php esc_html_e( 'Failed', 'woocommerce-anti-fraud' ); ?></th>
					<th><?php esc_html_e( 'Last', 'woocommerce-anti-fraud' ); ?></th>
					<th><?php esc_html_e( 'Latest order', 'woocommerce-anti-fraud' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( array_slice( $rows, 0, 20 ) as $row ) :
					$type  = $types[ $row['type'] ];
					$order = $row['order_id'] && function_exists( 'wc_get_order' ) ? wc_get_order( $row['order_id'] ) : null;
					$shown = 'ip' === $row['type'] ? $row['value'] : substr( $row['value'], 0, 12 ) . '…';
					if ( 'email_hash' === $row['type'] && $order instanceof WC_Order ) {
						$shown = $order->get_billing_email();
					}
					?>
					<tr>
						<td>
							<span class="wcaf-who"><?php echo self::icon( $type[0], 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $type[1] ); ?></span>
							<code><?php echo esc_html( $shown ); ?></code>
							<?php if ( $blocking && $row['failures'] >= $threshold ) : ?>
								<span class="wcaf-tag is-bad"><?php esc_html_e( 'Blocked', 'woocommerce-anti-fraud' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $row['failures'] ) ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row['last'], 'M j, g:i a' ) ); ?></td>
						<td>
							<?php if ( $order instanceof WC_Order ) : ?>
								<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td class="wcaf-actions-cell">
							<button type="button" class="button button-small" data-wcaf-clear data-type="<?php echo esc_attr( $row['type'] ); ?>" data-value="<?php echo esc_attr( $row['value'] ); ?>">
								<?php echo self::icon( 'eraser', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Clear', 'woocommerce-anti-fraud' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Detection: browser checks.
	 *
	 * @return void
	 */
	public function render_browser_checks() {
		$checks = array(
			'fingerprint' => array( 'fingerprint-pattern', __( 'Device fingerprint', 'woocommerce-anti-fraud' ), __( 'Recognises the same device across orders, even when it changes IP, email or name, from its screen, graphics card, fonts, language and a random ID kept in the browser.', 'woocommerce-anti-fraud' ) ),
			'webrtc'      => array( 'radar', __( 'WebRTC leak check', 'woocommerce-anti-fraud' ), __( 'Finds the real IP behind many VPNs and proxies. Set the STUN server on the IP & Location tab.', 'woocommerce-anti-fraud' ) ),
			'paste'       => array( 'clipboard-paste', __( 'Copy and paste detection', 'woocommerce-anti-fraud' ), __( 'Notes whether name, address, email and phone were typed, pasted or autofilled. Fraudsters paste stolen details from a list; real customers type or autofill their own.', 'woocommerce-anti-fraud' ) ),
		);
		?>
		<div class="wcaf-options">
			<?php foreach ( $checks as $key => $check ) : ?>
				<label class="wcaf-option">
					<input type="checkbox" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="1" <?php checked( 1, (int) $this->plugin->get_setting( $key ) ); ?>>
					<span class="wcaf-option-icon"><?php echo self::icon( $check[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="wcaf-option-text">
						<strong><?php echo esc_html( $check[1] ); ?></strong>
						<span><?php echo esc_html( $check[2] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Detection: which signals count.
	 *
	 * @return void
	 */
	public function render_signals() {
		$factors = (array) $this->plugin->get_setting( 'factors' );
		?>
		<div class="wcaf-signals">
			<?php foreach ( WooCommerce_Anti_Fraud_Scorer::factors() as $key => $factor ) : ?>
				<label class="wcaf-signal">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'factors' ) . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $factors[ $key ] ) ); ?>>
					<span class="wcaf-signal-icon"><?php echo self::icon( $factor['icon'], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="wcaf-signal-text">
						<strong><?php echo esc_html( $factor['label'] ); ?></strong>
						<span><?php echo esc_html( $factor['help'] ); ?></span>
					</span>
					<span class="wcaf-signal-max">
						<?php
						/* translators: %d: points. */
						echo esc_html( sprintf( __( 'up to -%d', 'woocommerce-anti-fraud' ), (int) $factor['max'] ) );
						?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Detection: risk level boundaries.
	 *
	 * @return void
	 */
	public function render_levels() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Needs review below', 'woocommerce-anti-fraud' ),
			function () {
				$this->number( 'review_below', 1, 100, __( 'points', 'woocommerce-anti-fraud' ) );
				echo '<p class="description">' . esc_html__( 'Orders under this score get a "Needs review" label and an order note.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-review_below'
		);

		$this->row(
			__( 'High risk below', 'woocommerce-anti-fraud' ),
			function () {
				$this->number( 'high_below', 0, 100, __( 'points', 'woocommerce-anti-fraud' ) );
				echo '<p class="description">' . esc_html__( 'Orders under this score are high risk: these are the ones held and alerted on, when those are on.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-high_below'
		);

		echo '</table>';

		$high   = (int) $this->plugin->get_setting( 'high_below' );
		$review = (int) $this->plugin->get_setting( 'review_below' );
		?>
		<div class="wcaf-scale" aria-hidden="true">
			<span class="is-high" style="flex: <?php echo esc_attr( (string) max( 1, $high ) ); ?>"><?php esc_html_e( 'High risk', 'woocommerce-anti-fraud' ); ?></span>
			<span class="is-medium" style="flex: <?php echo esc_attr( (string) max( 1, $review - $high ) ); ?>"><?php esc_html_e( 'Review', 'woocommerce-anti-fraud' ); ?></span>
			<span class="is-low" style="flex: <?php echo esc_attr( (string) max( 1, 100 - $review ) ); ?>"><?php esc_html_e( 'Low risk', 'woocommerce-anti-fraud' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Detection: scope.
	 *
	 * @return void
	 */
	public function render_scope() {
		$scope = (string) $this->plugin->get_setting( 'scope' );
		$ids   = array_map( 'absint', (array) $this->plugin->get_setting( 'product_ids' ) );
		$cats  = array_map( 'absint', (array) $this->plugin->get_setting( 'product_cats' ) );
		?>
		<fieldset class="wcaf-radios">
			<label><input type="radio" name="<?php echo esc_attr( self::name( 'scope' ) ); ?>" value="all" <?php checked( 'products' !== $scope ); ?> data-wcaf-scope> <?php esc_html_e( 'Every order', 'woocommerce-anti-fraud' ); ?></label>
			<label><input type="radio" name="<?php echo esc_attr( self::name( 'scope' ) ); ?>" value="products" <?php checked( 'products' === $scope ); ?> data-wcaf-scope> <?php esc_html_e( 'Only orders with these products or categories (say, gift cards and other easy-to-resell items)', 'woocommerce-anti-fraud' ); ?></label>
		</fieldset>

		<table class="form-table" role="presentation" data-wcaf-scope-fields>
			<?php
			$this->row(
				__( 'Products', 'woocommerce-anti-fraud' ),
				function () use ( $ids ) {
					echo '<select class="wc-product-search" multiple="multiple" style="width: 100%; max-width: 480px;" id="wcaf-products" name="' . esc_attr( self::name( 'product_ids' ) ) . '[]" data-placeholder="' . esc_attr__( 'Search for a product...', 'woocommerce-anti-fraud' ) . '" data-action="woocommerce_json_search_products_and_variations">';
					foreach ( $ids as $id ) {
						$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
						if ( $product ) {
							echo '<option value="' . esc_attr( (string) $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
						}
					}
					echo '</select>';
				},
				'wcaf-products'
			);

			$this->row(
				__( 'Categories', 'woocommerce-anti-fraud' ),
				function () use ( $cats ) {
					$terms = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'hide_empty' => false,
							'number'     => 500,
						)
					);
					echo '<select class="wc-enhanced-select" multiple="multiple" style="width: 100%; max-width: 480px;" id="wcaf-cats" name="' . esc_attr( self::name( 'product_cats' ) ) . '[]" data-placeholder="' . esc_attr__( 'Choose categories...', 'woocommerce-anti-fraud' ) . '">';
					foreach ( is_array( $terms ) ? $terms : array() as $term ) {
						echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, $cats, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
					}
					echo '</select>';
					echo '<p class="description">' . esc_html__( 'Subcategories are included.', 'woocommerce-anti-fraud' ) . '</p>';
				},
				'wcaf-cats'
			);
			?>
		</table>
		<?php
	}

	/**
	 * IP & Location: what the server sees, and trusted proxies.
	 *
	 * @return void
	 */
	public function render_visitor_ip() {
		$probe   = WooCommerce_Anti_Fraud_Request::probe();
		$seen    = WooCommerce_Anti_Fraud_Request::is_public( $probe['ip'] ) ? WooCommerce_Anti_Fraud_IP_Intel::lookup( $probe['ip'] ) : WooCommerce_Anti_Fraud_IP_Intel::blank();
		$sources = array(
			'direct'     => __( 'Direct connection (REMOTE_ADDR)', 'woocommerce-anti-fraud' ),
			'cloudflare' => __( 'Cloudflare, verified', 'woocommerce-anti-fraud' ),
			'proxy'      => __( 'Your trusted proxy', 'woocommerce-anti-fraud' ),
		);
		?>
		<div class="wcaf-probe">
			<h3><?php echo self::icon( 'monitor-smartphone', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Your own visit, right now', 'woocommerce-anti-fraud' ); ?></h3>
			<table class="wcaf-table wcaf-table-kv">
				<tbody>
					<tr><th><?php esc_html_e( 'IP the plugin uses', 'woocommerce-anti-fraud' ); ?></th><td><code><?php echo esc_html( '' !== $probe['ip'] ? $probe['ip'] : '?' ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Found from', 'woocommerce-anti-fraud' ); ?></th><td><?php echo esc_html( isset( $sources[ $probe['source'] ] ) ? $sources[ $probe['source'] ] : $probe['source'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Connected from', 'woocommerce-anti-fraud' ); ?></th><td><code><?php echo esc_html( $probe['remote'] ); ?></code></td></tr>
					<?php foreach ( $probe['headers'] as $header => $value ) : ?>
						<tr><th><?php echo esc_html( $header ); ?></th><td><code><?php echo esc_html( $value ); ?></code></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( 'private' === $probe['warning'] ) : ?>
				<p class="wcaf-callout is-warning"><?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Your server sits behind a proxy on a private network, and every visitor will show up with its address. Tick "Trust proxies on private networks" below.', 'woocommerce-anti-fraud' ); ?></span></p>
			<?php elseif ( in_array( $seen['type'], array( 'DCH', 'CPN' ), true ) ) : ?>
				<p class="wcaf-callout is-warning"><?php echo self::icon( 'triangle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: network name. */
							__( 'The IP the plugin sees for your visit belongs to %s, a hosting or CDN network. Unless you\'re on a VPN right now, your site is behind a CDN or firewall service that isn\'t set up here yet: add its IP ranges to Trusted proxies, or every customer will look like they come from the same few addresses.', 'woocommerce-anti-fraud' ),
							'' !== $seen['org'] ? $seen['org'] : WooCommerce_Anti_Fraud_IP_Intel::type_label( $seen['type'] )
						)
					);
					?>
				</span></p>
			<?php elseif ( 'untrusted' === $probe['warning'] ) : ?>
				<p class="wcaf-callout"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'This request carries a forwarding header from an address that isn\'t trusted. If your site is behind a load balancer or a firewall service like Sucuri, add its IP or range below; if not, this is just your browser or network adding it, and ignoring it is right.', 'woocommerce-anti-fraud' ); ?></span></p>
			<?php endif; ?>
		</div>

		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Trusted proxies', 'woocommerce-anti-fraud' ),
				function () {
					echo '<textarea class="large-text code" rows="3" id="wcaf-trusted_proxies" name="' . esc_attr( self::name( 'trusted_proxies' ) ) . '" placeholder="203.0.113.10&#10;198.51.100.0/24">' . esc_textarea( (string) $this->plugin->get_setting( 'trusted_proxies' ) ) . '</textarea>';
					echo '<p class="description">' . esc_html__( 'IPs or CIDR ranges of your load balancers or proxies, one per line. Cloudflare is recognised automatically.', 'woocommerce-anti-fraud' ) . '</p>';
				},
				'wcaf-trusted_proxies'
			);

			$this->row(
				__( 'Header they send', 'woocommerce-anti-fraud' ),
				function () {
					$current = (string) $this->plugin->get_setting( 'proxy_header' );
					echo '<select id="wcaf-proxy_header" name="' . esc_attr( self::name( 'proxy_header' ) ) . '">';
					foreach ( array(
						'x-forwarded-for' => 'X-Forwarded-For',
						'x-real-ip'       => 'X-Real-IP',
						'true-client-ip'  => 'True-Client-IP',
					) as $value => $label ) {
						echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
				},
				'wcaf-proxy_header'
			);

			$this->row(
				__( 'Private networks', 'woocommerce-anti-fraud' ),
				function () {
					$this->checkbox( 'trust_private', __( 'Trust proxies on private networks', 'woocommerce-anti-fraud' ), __( 'A connection from a 10.x, 172.16-31.x, 192.168.x or loopback address can only come from inside your hosting, so its forwarding header is believed. Most managed WordPress hosts need this.', 'woocommerce-anti-fraud' ) );
				}
			);
			?>
		</table>
		<?php
	}

	/**
	 * IP & Location: ipinfo.io.
	 *
	 * @return void
	 */
	public function render_lookups() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'ipinfo.io', 'woocommerce-anti-fraud' ),
			function () {
				$this->checkbox( 'ipinfo', __( 'Look up each order\'s IP', 'woocommerce-anti-fraud' ), __( 'Without lookups, location comes only from Cloudflare\'s headers or WooCommerce\'s MaxMind database (if you\'ve set them up), and VPN, proxy and data center detection is off.', 'woocommerce-anti-fraud' ) );
			}
		);

		$this->row(
			__( 'Access token', 'woocommerce-anti-fraud' ),
			function () {
				echo '<div class="wcaf-inline">';
				echo '<input type="password" class="regular-text code" id="wcaf-ipinfo_token" name="' . esc_attr( self::name( 'ipinfo_token' ) ) . '" value="' . esc_attr( (string) $this->plugin->get_setting( 'ipinfo_token' ) ) . '" autocomplete="off" spellcheck="false">';
				echo '<button type="button" class="button" data-wcaf-test>' . self::icon( 'flashlight', 15 ) . esc_html__( 'Test', 'woocommerce-anti-fraud' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '</div>';
				echo '<p class="wcaf-test-result" data-wcaf-test-result aria-live="polite"></p>';
				echo '<p class="description">';
				printf(
					/* translators: %s: link to ipinfo.io. */
					esc_html__( 'Optional. Lookups work without one at low volume; a free token from %s covers 50,000 a month, and paid plans add mobile carrier and VPN flags the plugin uses automatically.', 'woocommerce-anti-fraud' ),
					'<a href="https://ipinfo.io/signup" target="_blank" rel="noopener noreferrer">ipinfo.io</a>'
				);
				echo '</p>';
			},
			'wcaf-ipinfo_token',
			'ipinfo'
		);

		echo '</table>';
	}

	/**
	 * IP & Location: STUN server.
	 *
	 * @return void
	 */
	public function render_webrtc() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'STUN server', 'woocommerce-anti-fraud' ),
			function () {
				echo '<input type="text" class="regular-text code" id="wcaf-stun_server" name="' . esc_attr( self::name( 'stun_server' ) ) . '" value="' . esc_attr( (string) $this->plugin->get_setting( 'stun_server' ) ) . '" spellcheck="false">';
				echo '<p class="description">' . esc_html__( 'Google\'s public server is the default. stun:stun.cloudflare.com:3478 works too, or use your own.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-stun_server',
			'webrtc'
		);

		echo '</table>';
	}

	/**
	 * Actions: hold.
	 *
	 * @return void
	 */
	public function render_hold() {
		$this->checkbox( 'hold_high_risk', __( 'Put paid high-risk orders on hold', 'woocommerce-anti-fraud' ), __( 'Instead of going to Processing, a paid order that scores high risk goes to On hold with a note, so it isn\'t shipped until you\'ve looked. The payment is still taken: refund it if the order turns out to be fraud, or move it to Processing if it\'s genuine. Works with every gateway that marks orders paid, and with cash on delivery.', 'woocommerce-anti-fraud' ) );
	}

	/**
	 * Actions: card-testing guard.
	 *
	 * @return void
	 */
	public function render_block() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Block', 'woocommerce-anti-fraud' ),
			function () {
				$this->checkbox( 'block_declines', __( 'Stop checkout after repeated failed payments', 'woocommerce-anti-fraud' ), __( 'Once one device or IP has this many failed payments, checkout is refused before the payment gateway is called. Clear a customer on the Overview tab if they\'re genuine.', 'woocommerce-anti-fraud' ) );
			}
		);

		$this->row(
			__( 'After', 'woocommerce-anti-fraud' ),
			function () {
				$this->number( 'block_threshold', 2, 50, __( 'failed payments', 'woocommerce-anti-fraud' ) );
			},
			'wcaf-block_threshold',
			'block_declines'
		);

		$this->row(
			__( 'Within', 'woocommerce-anti-fraud' ),
			function () {
				$this->number( 'decline_window', 1, 720, __( 'hours', 'woocommerce-anti-fraud' ) );
				echo '<p class="description">' . esc_html__( 'Also the window the "Failed payments" signal counts over.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-decline_window'
		);

		$this->row(
			__( 'Message', 'woocommerce-anti-fraud' ),
			function () {
				$message = (string) $this->plugin->get_setting( 'block_message' );
				echo '<input type="text" class="large-text" id="wcaf-block_message" name="' . esc_attr( self::name( 'block_message' ) ) . '" value="' . esc_attr( $message ) . '" placeholder="' . esc_attr( WooCommerce_Anti_Fraud_Declines::default_block_message() ) . '">';
				echo '<p class="description">' . esc_html__( 'What the customer sees. Leave empty for the default.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-block_message',
			'block_declines'
		);

		echo '</table>';
	}

	/**
	 * Actions: email alerts.
	 *
	 * @return void
	 */
	public function render_alerts() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Send alerts to', 'woocommerce-anti-fraud' ),
			function () {
				echo '<input type="text" class="large-text" id="wcaf-alert_emails" name="' . esc_attr( self::name( 'alert_emails' ) ) . '" value="' . esc_attr( (string) $this->plugin->get_setting( 'alert_emails' ) ) . '" placeholder="you@example.com, team@example.com">';
				echo '<p class="description">' . esc_html__( 'Email addresses, separated by commas. Leave empty for no alerts.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-alert_emails'
		);

		$this->row(
			__( 'High-risk orders', 'woocommerce-anti-fraud' ),
			function () {
				$this->checkbox( 'alert_high_risk', __( 'Email me about every high-risk order', 'woocommerce-anti-fraud' ) );
			}
		);

		$this->row(
			__( 'Repeat activity', 'woocommerce-anti-fraud' ),
			function () {
				$this->number( 'alert_threshold', 2, 100, __( 'failed payments, or orders in 48 hours, from one device or IP', 'woocommerce-anti-fraud' ) );
				echo '<p class="description">' . esc_html__( 'One email per person and trigger, however long they keep trying.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-alert_threshold'
		);

		echo '</table>';
	}

	/**
	 * Advanced: retention.
	 *
	 * @return void
	 */
	public function render_data() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Keep device and IP records', 'woocommerce-anti-fraud' ),
			function () {
				$this->number( 'retention_months', 0, 60, __( 'months (0 = forever)', 'woocommerce-anti-fraud' ) );
				echo '<p class="description">' . esc_html__( 'Older device, IP and failed-payment records are deleted daily. Each order keeps its own score and reasons.', 'woocommerce-anti-fraud' ) . '</p>';
			},
			'wcaf-retention_months'
		);

		$this->row(
			__( 'When deleting the plugin', 'woocommerce-anti-fraud' ),
			function () {
				$this->checkbox( 'keep_data', __( 'Keep the plugin\'s data and settings', 'woocommerce-anti-fraud' ), __( 'Normally, deleting the plugin from the Plugins screen removes its tables, settings and the fraud scores saved on orders.', 'woocommerce-anti-fraud' ) );
			}
		);

		echo '</table>';
	}

	/**
	 * Advanced: hooks reference.
	 *
	 * @return void
	 */
	public function render_developer() {
		$hooks = array(
			'woocommerce_anti_fraud_deduction'      => __( 'Change the points a signal takes off.', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_score'          => __( 'Change the final score.', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_order_scored'   => __( 'Action after an order is scored (order, score, risk, report).', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_ip_info'        => __( 'Add your own IP data source, or correct a result.', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_client_ip'      => __( 'Override the visitor IP.', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_in_scope'       => __( 'Choose which orders and carts are checked.', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_block_checkout' => __( 'Decide whether a checkout is refused.', 'woocommerce-anti-fraud' ),
			'woocommerce_anti_fraud_alert_email'    => __( 'Change an alert email before it\'s sent.', 'woocommerce-anti-fraud' ),
		);
		?>
		<table class="widefat striped wcaf-table wcaf-hooks">
			<tbody>
				<?php foreach ( $hooks as $hook => $what ) : ?>
					<tr><td><code><?php echo esc_html( $hook ); ?></code></td><td><?php echo esc_html( $what ); ?></td></tr>
				<?php endforeach; ?>
				<tr><td><code>WOOCOMMERCE_ANTI_FRAUD_DISABLED</code></td><td><?php esc_html_e( 'Define as true in wp-config.php to switch every check off.', 'woocommerce-anti-fraud' ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Each order\'s full report is saved as JSON in the _wcaf_report order meta; the score and risk level are in _wcaf_score and _wcaf_risk.', 'woocommerce-anti-fraud' ); ?></p>
		<?php
	}

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin = $this->plugin;
		$probe  = WooCommerce_Anti_Fraud_Request::probe();
		$checks = array_filter( array( $plugin->get_setting( 'fingerprint' ), $plugin->get_setting( 'webrtc' ), $plugin->get_setting( 'paste' ) ) );
		$status = array(
			array( $plugin->is_enabled(), $plugin->is_enabled() ? __( 'Checking new orders', 'woocommerce-anti-fraud' ) : __( 'Fraud checks are off', 'woocommerce-anti-fraud' ) ),
			/* translators: %d: number of browser checks switched on (of 3). */
			array( count( $checks ) > 0, sprintf( __( '%d of 3 browser checks on', 'woocommerce-anti-fraud' ), count( $checks ) ) ),
			array( (bool) $plugin->get_setting( 'ipinfo' ), $plugin->get_setting( 'ipinfo' ) ? ( '' !== (string) $plugin->get_setting( 'ipinfo_token' ) ? __( 'IP lookups on, with token', 'woocommerce-anti-fraud' ) : __( 'IP lookups on, no token', 'woocommerce-anti-fraud' ) ) : __( 'IP lookups off', 'woocommerce-anti-fraud' ) ),
			array( 'private' !== $probe['warning'], 'cloudflare' === $probe['source'] ? __( 'Visitor IPs via Cloudflare', 'woocommerce-anti-fraud' ) : ( 'proxy' === $probe['source'] ? __( 'Visitor IPs via your proxy', 'woocommerce-anti-fraud' ) : ( 'private' === $probe['warning'] ? __( 'Visitor IPs: check the proxy settings', 'woocommerce-anti-fraud' ) : __( 'Visitor IPs: direct', 'woocommerce-anti-fraud' ) ) ) ),
			array( (bool) $plugin->get_setting( 'block_declines' ), $plugin->get_setting( 'block_declines' ) ? __( 'Card-testing block on', 'woocommerce-anti-fraud' ) : __( 'Card-testing block off', 'woocommerce-anti-fraud' ) ),
			array( (bool) $plugin->get_setting( 'hold_high_risk' ), $plugin->get_setting( 'hold_high_risk' ) ? __( 'Holding high-risk orders', 'woocommerce-anti-fraud' ) : __( 'Not holding orders', 'woocommerce-anti-fraud' ) ),
			array( (bool) WooCommerce_Anti_Fraud_Actions::recipients(), WooCommerce_Anti_Fraud_Actions::recipients() ? __( 'Email alerts on', 'woocommerce-anti-fraud' ) : __( 'No email alerts', 'woocommerce-anti-fraud' ) ),
		);
		?>
		<div class="postbox wcaf-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'woocommerce-anti-fraud' ); ?>
				</h2>
			</div>
			<div class="inside">
				<label class="wcaf-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $plugin->get_setting( 'enabled' ) ); ?>>
					<span><?php esc_html_e( 'Fraud checks on', 'woocommerce-anti-fraud' ); ?></span>
				</label>
				<?php if ( defined( 'WOOCOMMERCE_ANTI_FRAUD_DISABLED' ) && WOOCOMMERCE_ANTI_FRAUD_DISABLED ) : ?>
					<p class="wcaf-callout is-warning"><?php esc_html_e( 'Switched off by WOOCOMMERCE_ANTI_FRAUD_DISABLED in wp-config.php.', 'woocommerce-anti-fraud' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Settings on every tab are saved together.', 'woocommerce-anti-fraud' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'woocommerce-anti-fraud' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'woocommerce-anti-fraud' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="wcaf-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( WOOCOMMERCE_ANTI_FRAUD_REPO ) );
		$check_url  = WooCommerce_Anti_Fraud_Updater::check_now_url();
		?>
		<div class="postbox wcaf-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'woocommerce-anti-fraud' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'woocommerce-anti-fraud' ), '<strong>' . esc_html( WOOCOMMERCE_ANTI_FRAUD_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! WooCommerce_Anti_Fraud_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'woocommerce-anti-fraud' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( WOOCOMMERCE_ANTI_FRAUD_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( WooCommerce_Anti_Fraud_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'woocommerce-anti-fraud' ),
							'<a href="' . esc_url( WOOCOMMERCE_ANTI_FRAUD_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>WOOCOMMERCE_ANTI_FRAUD_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'woocommerce-anti-fraud' ),
							'<a href="' . esc_url( WOOCOMMERCE_ANTI_FRAUD_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'woocommerce-anti-fraud' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: test ipinfo.io with the token in the form (saved or not).
	 *
	 * @return void
	 */
	public function ajax_test_ipinfo() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'woocommerce-anti-fraud' ) ), 403 );
		}

		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( '' !== $token && ! preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'That token doesn\'t look right.', 'woocommerce-anti-fraud' ) ) );
		}

		$args = array(
			'timeout'     => 6,
			'redirection' => 0,
			'headers'     => array( 'Accept' => 'application/json' ),
		);
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_get( 'https://ipinfo.io/8.8.8.8/json', $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error. */ __( 'Couldn\'t reach ipinfo.io: %s', 'woocommerce-anti-fraud' ), $response->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$messages = array(
				401 => __( 'ipinfo.io didn\'t accept that token.', 'woocommerce-anti-fraud' ),
				403 => __( 'ipinfo.io didn\'t accept that token.', 'woocommerce-anti-fraud' ),
				429 => __( 'ipinfo.io says the limit is used up for now. A free token raises it.', 'woocommerce-anti-fraud' ),
			);
			/* translators: %d: HTTP status. */
			wp_send_json_error( array( 'message' => isset( $messages[ $code ] ) ? $messages[ $code ] : sprintf( __( 'ipinfo.io answered with HTTP %d.', 'woocommerce-anti-fraud' ), $code ) ) );
		}

		$info = WooCommerce_Anti_Fraud_IP_Intel::from_ipinfo( $body, '8.8.8.8' );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: sample location, 2: sample network, 3: connection type. */
					__( 'Working. 8.8.8.8 is %1$s, %2$s (%3$s).', 'woocommerce-anti-fraud' ),
					WooCommerce_Anti_Fraud_IP_Intel::place( $info ),
					$info['org'],
					WooCommerce_Anti_Fraud_IP_Intel::type_label( $info['type'] )
				) . ( '' === $token ? ' ' . __( 'No token: fine for low volume.', 'woocommerce-anti-fraud' ) : '' ),
			)
		);
	}

	/**
	 * AJAX: stop counting a device's, IP's or email's failed payments.
	 *
	 * @return void
	 */
	public function ajax_clear() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'woocommerce-anti-fraud' ) ), 403 );
		}

		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( ! in_array( $type, array( 'fingerprint', 'ip', 'email_hash' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown record.', 'woocommerce-anti-fraud' ) ) );
		}

		$cleared = WooCommerce_Anti_Fraud_Declines::clear( $type, $value );

		// The cleared failures no longer count against this customer's orders.
		if ( $cleared && 'fingerprint' === $type ) {
			WooCommerce_Anti_Fraud_Scorer::schedule_rescore( 0, $value, array() );
		} elseif ( $cleared && 'ip' === $type ) {
			WooCommerce_Anti_Fraud_Scorer::schedule_rescore(
				0,
				'',
				array(
					'verified' => array( $value ),
					'claimed'  => array( $value ),
				)
			);
		}

		wp_send_json_success( array( 'cleared' => $cleared ) );
	}
}
