<?php
/**
 * The "Submit Feedback" and "Report a Bug" pages under the shared Content Powered menu.
 *
 * Every Content Powered plugin carries this same file. Each plugin adds itself
 * to a shared list (the content_powered_feedback_plugins filter), and only one
 * copy of this class, the newest by VERSION (the first loaded on a tie), adds
 * the two pages and handles the form. So the menu shows one pair of links
 * however many of our plugins are active, and the form asks which plugin the
 * message is about.
 *
 * Messages go from this server to contentpowered.com, never from the browser.
 * Each one carries a single-use secret, and for a few minutes this site serves
 * the secret's hash from admin-ajax.php. contentpowered.com fetches that hash
 * to confirm the message really came from this site. It accepts one message
 * per site every 24 hours. This page shows the same wait, so nobody writes a
 * message that can't be sent.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feedback and bug report pages.
 */
final class WooCommerce_Anti_Fraud_Feedback {

	/**
	 * Version of this shared file. Bump it whenever the file changes, so the
	 * newest copy among the installed plugins is the one that runs.
	 */
	const VERSION = '1.0.0';

	/**
	 * Where messages go. Define CONTENT_POWERED_FEEDBACK_ENDPOINT in
	 * wp-config.php to send them somewhere else, e.g. a local copy of the site.
	 */
	const ENDPOINT = 'https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/';

	/**
	 * Page slugs. They're the same in every plugin, so the links can't appear twice.
	 */
	const PAGE_FEEDBACK = 'content-powered-feedback';
	const PAGE_BUG      = 'content-powered-bug-report';

	/**
	 * admin-post.php action (and nonce action) for the form.
	 */
	const ACTION = 'content_powered_feedback';

	/**
	 * admin-ajax.php action contentpowered.com calls to verify a message.
	 */
	const VERIFY_ACTION = 'content_powered_feedback_verify';

	/**
	 * Shared option holding when this site may send its next message.
	 */
	const OPTION = 'content_powered_feedback';

	/**
	 * Message length limits, in characters. contentpowered.com enforces the same.
	 */
	const MIN_LENGTH = 20;
	const MAX_LENGTH = 5000;

	/**
	 * Whether init() has run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Class name of the copy that handles the pages, once worked out.
	 *
	 * @var string|null
	 */
	private static $handler = null;

	/**
	 * Hook suffixes of the two pages.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Hook everything in. Safe to call from every plugin.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_filter( 'content_powered_feedback_plugins', array( __CLASS__, 'add_plugin' ) );
		add_filter( 'content_powered_feedback_handler', array( __CLASS__, 'claim' ) );

		// Every copy hooks in; all but the elected one return straight away.
		// After the plugins' own pages (priority 10), so the links come last.
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ), 20 );
		add_action( 'admin_head', array( __CLASS__, 'print_menu_style' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'wp_ajax_' . self::VERIFY_ACTION, array( __CLASS__, 'verify' ) );
		add_action( 'wp_ajax_nopriv_' . self::VERIFY_ACTION, array( __CLASS__, 'verify' ) );
	}

	/**
	 * Add this plugin to the list the form offers.
	 *
	 * @param mixed $plugins Plugins added so far, slug => name and version.
	 * @return array
	 */
	public static function add_plugin( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();

		$plugins['woocommerce-anti-fraud'] = array(
			'name'    => __( 'WooCommerce Anti Fraud', 'woocommerce-anti-fraud' ),
			'version' => WOOCOMMERCE_ANTI_FRAUD_VERSION,
		);

		return $plugins;
	}

	/**
	 * Take over the pages unless a newer (or equal, earlier-loaded) copy has.
	 *
	 * @param mixed $handler Class name chosen so far.
	 * @return string
	 */
	public static function claim( $handler ) {
		if ( is_string( $handler ) && class_exists( $handler, false ) && defined( $handler . '::VERSION' )
			&& version_compare( constant( $handler . '::VERSION' ), self::VERSION, '>=' ) ) {
			return $handler;
		}

		return __CLASS__;
	}

	/**
	 * Whether this copy is the one that runs the pages.
	 *
	 * Only asked from admin hooks, by which time every plugin has added its
	 * filters on plugins_loaded.
	 *
	 * @return bool
	 */
	private static function is_handler() {
		if ( null === self::$handler ) {
			self::$handler = (string) apply_filters( 'content_powered_feedback_handler', '' );
		}

		return __CLASS__ === self::$handler;
	}

	/**
	 * Every active Content Powered plugin, sorted by name.
	 *
	 * @return array<string,array{name:string,version:string}>
	 */
	private static function plugins() {
		$plugins = array();

		foreach ( (array) apply_filters( 'content_powered_feedback_plugins', array() ) as $slug => $plugin ) {
			$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';

			if ( '' === $slug || ! is_array( $plugin ) || empty( $plugin['name'] ) ) {
				continue;
			}

			$plugins[ $slug ] = array(
				'name'    => (string) $plugin['name'],
				'version' => isset( $plugin['version'] ) ? (string) $plugin['version'] : '',
			);
		}

		uasort(
			$plugins,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $plugins;
	}

	/**
	 * The two pages, keyed by slug.
	 *
	 * @return array<string,array{type:string,title:string,icon:string}>
	 */
	private static function pages() {
		return array(
			self::PAGE_FEEDBACK => array(
				'type'  => 'feedback',
				'title' => __( 'Submit Feedback', 'woocommerce-anti-fraud' ),
				'icon'  => 'message-square-heart',
			),
			self::PAGE_BUG      => array(
				'type'  => 'bug',
				'title' => __( 'Report a Bug', 'woocommerce-anti-fraud' ),
				'icon'  => 'bug',
			),
		);
	}

	/**
	 * Add the two pages at the bottom of the Content Powered menu.
	 *
	 * @return void
	 */
	public static function register_pages() {
		if ( ! self::is_handler() ) {
			return;
		}

		foreach ( self::pages() as $slug => $page ) {
			$hook = add_submenu_page(
				WooCommerce_Anti_Fraud_Menu::SLUG,
				$page['title'],
				WooCommerce_Anti_Fraud_Icons::get( $page['icon'], 17, 'cp-feedback-menu-icon' ) . '<span>' . esc_html( $page['title'] ) . '</span>',
				'manage_options',
				$slug,
				array( __CLASS__, 'render_page' )
			);

			if ( $hook ) {
				self::$hooks[] = $hook;
			}
		}

		if ( self::$hooks ) {
			add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		}
	}

	/**
	 * Menu icon alignment, plus a divider above the two links so they read as
	 * their own group below the plugins' pages.
	 *
	 * @return void
	 */
	public static function print_menu_style() {
		if ( ! self::is_handler() ) {
			return;
		}

		echo '<style id="cp-feedback-menu-style">'
			. '#adminmenu a:has(>.cp-feedback-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .cp-feedback-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .cp-feedback-menu-icon,#adminmenu a:hover .cp-feedback-menu-icon,#adminmenu a:focus .cp-feedback-menu-icon{opacity:1}'
			. '#adminmenu .wp-submenu li:has(>a[href$="page=' . esc_attr( self::PAGE_FEEDBACK ) . '"]){margin-top:6px;padding-top:6px;box-shadow:inset 0 1px 0 rgba(140,143,148,.35)}'
			. '</style>';
	}

	/**
	 * Body class for the two pages, which hide the usual left padding.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, self::$hooks, true ) ? $classes . ' cp-feedback-page' : $classes;
	}

	/**
	 * Seconds until this site may send another message (0 = now).
	 *
	 * @return int
	 */
	private static function wait() {
		$state = get_option( self::OPTION, array() );
		$next  = is_array( $state ) && isset( $state['next'] ) ? (int) $state['next'] : 0;

		return max( 0, $next - time() );
	}

	/**
	 * Remember not to offer the form again for a while.
	 *
	 * @param int $seconds How long to wait.
	 * @return void
	 */
	private static function set_wait( $seconds ) {
		$seconds = min( max( (int) $seconds, 0 ), DAY_IN_SECONDS );

		update_option( self::OPTION, array( 'next' => time() + $seconds ), false );
	}

	/**
	 * Where messages are sent.
	 *
	 * @return string
	 */
	private static function endpoint() {
		return defined( 'CONTENT_POWERED_FEEDBACK_ENDPOINT' ) && CONTENT_POWERED_FEEDBACK_ENDPOINT
			? (string) CONTENT_POWERED_FEEDBACK_ENDPOINT
			: self::ENDPOINT;
	}

	/**
	 * The current visitor's IP address as this site sees it. Only a hint for
	 * contentpowered.com, which records the address the message comes from.
	 *
	 * @return string Empty when unknown.
	 */
	private static function visitor_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

		if ( filter_var( $remote, FILTER_VALIDATE_IP, $public ) ) {
			return $remote;
		}

		// Behind a proxy on the same network, the proxy passes the real address along.
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) as $candidate ) {
				$candidate = trim( $candidate );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP, $public ) ) {
					return $candidate;
				}
			}
		}

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/**
	 * Technical details sent with a message. Versions always go; a bug report
	 * can also include the theme, active plugins and server details.
	 *
	 * @param bool $full Include the bug report extras.
	 * @return array<string,mixed>
	 */
	private static function environment( $full ) {
		global $wpdb;

		$versions = array();
		foreach ( self::plugins() as $slug => $plugin ) {
			$versions[ $slug ] = $plugin['version'];
		}

		$environment = array(
			'wordpress'       => get_bloginfo( 'version' ),
			'php'             => PHP_VERSION,
			'content_powered' => $versions,
		);

		if ( ! $full ) {
			return $environment;
		}

		$theme = wp_get_theme();

		$environment['database']     = is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
		$environment['theme']        = trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
		$environment['locale']       = get_locale();
		$environment['multisite']    = is_multisite();
		$environment['plugins']      = self::active_plugins();

		return $environment;
	}

	/**
	 * Names and versions of the active plugins, for spotting conflicts.
	 *
	 * @return string[]
	 */
	private static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$installed = get_plugins();
		$list      = array();

		foreach ( array_unique( $active ) as $file ) {
			if ( isset( $installed[ $file ] ) ) {
				$list[] = trim( $installed[ $file ]['Name'] . ' ' . $installed[ $file ]['Version'] );
			}
		}

		sort( $list, SORT_NATURAL | SORT_FLAG_CASE );

		return array_slice( $list, 0, 100 );
	}

	/**
	 * Per-user transient key (form drafts and notices).
	 *
	 * @param string $what "draft" or "notice".
	 * @return string
	 */
	private static function user_key( $what ) {
		return 'content_powered_feedback_' . $what . '_' . get_current_user_id();
	}

	/**
	 * Render either page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pages   = self::pages();
		$slug    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$slug    = isset( $pages[ $slug ] ) ? $slug : self::PAGE_FEEDBACK;
		$page    = $pages[ $slug ];
		$is_bug  = 'bug' === $page['type'];
		$plugins = self::plugins();
		$wait    = self::wait();

		$notice = get_transient( self::user_key( 'notice' ) );
		if ( false !== $notice ) {
			delete_transient( self::user_key( 'notice' ) );
		}

		// A message that couldn't be sent comes back filled in.
		$draft = get_transient( self::user_key( 'draft' ) );
		$draft = is_array( $draft ) && isset( $draft['type'] ) && $draft['type'] === $page['type'] ? $draft : array();

		$selected = isset( $draft['plugin'] ) ? $draft['plugin'] : '';
		if ( '' === $selected && isset( $_GET['plugin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preselects a choice only.
			$selected = sanitize_key( wp_unslash( $_GET['plugin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 1 === count( $plugins ) ) {
			$slugs    = array_keys( $plugins );
			$selected = (string) $slugs[0];
		}

		$message = isset( $draft['message'] ) ? $draft['message'] : '';
		$email   = isset( $draft['email'] ) ? $draft['email'] : wp_get_current_user()->user_email;
		$details = isset( $draft['details'] ) ? (bool) $draft['details'] : true;

		self::print_styles();
		?>
		<div class="cp-feedback-header">
			<div class="cp-feedback-header-title">
				<span class="cp-feedback-header-icon"><?php echo WooCommerce_Anti_Fraud_Icons::get( $page['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( $page['title'] ); ?></h1>
			</div>
			<nav class="cp-feedback-tabs" aria-label="<?php esc_attr_e( 'Feedback and bug reports', 'woocommerce-anti-fraud' ); ?>">
				<?php foreach ( $pages as $tab_slug => $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab_slug ) ); ?>" class="cp-feedback-tab<?php echo $tab_slug === $slug ? ' is-active' : ''; ?>"<?php echo $tab_slug === $slug ? ' aria-current="page"' : ''; ?>>
						<?php echo WooCommerce_Anti_Fraud_Icons::get( $tab['icon'], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $tab['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap cp-feedback-wrap">
			<hr class="wp-header-end">

			<?php if ( is_array( $notice ) && ! empty( $notice['text'] ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?>">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $wait && ! ( is_array( $notice ) && ! empty( $notice['text'] ) ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %s: time left, e.g. "21 hours". */
							esc_html__( 'Content Powered accepts one message per site every 24 hours. You can send the next one in %s.', 'woocommerce-anti-fraud' ),
							esc_html( human_time_diff( time(), time() + $wait ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div class="postbox">
							<div class="postbox-header">
								<h2 class="hndle">
									<?php echo WooCommerce_Anti_Fraud_Icons::get( $is_bug ? 'bug' : 'message-square-heart', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
									<?php echo esc_html( $is_bug ? __( 'What went wrong?', 'woocommerce-anti-fraud' ) : __( 'Tell us what you think', 'woocommerce-anti-fraud' ) ); ?>
								</h2>
							</div>
							<div class="inside">
								<p class="cp-feedback-intro">
									<?php
									echo esc_html(
										$is_bug
											? __( 'Something not working the way it should? Tell us what happened and we\'ll look into it. The more detail you give, the faster we can fix it.', 'woocommerce-anti-fraud' )
											: __( 'Tell us what you like, what\'s missing, or what would make our plugins better. We read every message.', 'woocommerce-anti-fraud' )
									);
									?>
								</p>

								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
									<input type="hidden" name="type" value="<?php echo esc_attr( $page['type'] ); ?>">
									<?php wp_nonce_field( self::ACTION ); ?>

									<fieldset<?php echo $wait ? ' disabled' : ''; ?>>
										<div class="cp-feedback-field">
											<?php if ( count( $plugins ) > 1 ) : ?>
												<label class="cp-feedback-label" for="cp-feedback-plugin"><?php esc_html_e( 'Plugin', 'woocommerce-anti-fraud' ); ?></label>
												<select id="cp-feedback-plugin" name="plugin" required>
													<option value=""><?php esc_html_e( 'Choose a plugin', 'woocommerce-anti-fraud' ); ?></option>
													<?php foreach ( $plugins as $plugin_slug => $plugin ) : ?>
														<option value="<?php echo esc_attr( $plugin_slug ); ?>" <?php selected( $selected, $plugin_slug ); ?>><?php echo esc_html( $plugin['name'] ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( isset( $plugins[ $selected ] ) ) : ?>
												<span class="cp-feedback-label"><?php esc_html_e( 'Plugin', 'woocommerce-anti-fraud' ); ?></span>
												<input type="hidden" name="plugin" value="<?php echo esc_attr( $selected ); ?>">
												<span class="cp-feedback-plugin">
													<?php
													echo esc_html( $plugins[ $selected ]['name'] );
													if ( '' !== $plugins[ $selected ]['version'] ) {
														echo ' <span class="cp-feedback-muted">' . esc_html( 'v' . $plugins[ $selected ]['version'] ) . '</span>';
													}
													?>
												</span>
											<?php endif; ?>
										</div>

										<div class="cp-feedback-field">
											<label class="cp-feedback-label" for="cp-feedback-message"><?php echo esc_html( $is_bug ? __( 'Describe the problem', 'woocommerce-anti-fraud' ) : __( 'Your feedback', 'woocommerce-anti-fraud' ) ); ?></label>
											<textarea id="cp-feedback-message" name="message" rows="9" required minlength="<?php echo esc_attr( self::MIN_LENGTH ); ?>" maxlength="<?php echo esc_attr( self::MAX_LENGTH ); ?>" placeholder="<?php echo esc_attr( $is_bug ? __( 'What happened, what you expected to happen, and the steps to make it happen again.', 'woocommerce-anti-fraud' ) : __( 'What do you like? What would you change or add?', 'woocommerce-anti-fraud' ) ); ?>"><?php echo esc_textarea( $message ); ?></textarea>
											<p class="description">
												<?php
												printf(
													/* translators: %s: maximum number of characters. */
													esc_html__( 'Up to %s characters. Please don\'t include passwords or other private information.', 'woocommerce-anti-fraud' ),
													esc_html( number_format_i18n( self::MAX_LENGTH ) )
												);
												?>
											</p>
										</div>

										<div class="cp-feedback-field">
											<label class="cp-feedback-label" for="cp-feedback-email"><?php esc_html_e( 'Reply-to email (optional)', 'woocommerce-anti-fraud' ); ?></label>
											<input type="email" id="cp-feedback-email" name="email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" autocomplete="email">
											<p class="description"><?php esc_html_e( 'Only used to reply to you. Leave it empty to send anonymously.', 'woocommerce-anti-fraud' ); ?></p>
										</div>

										<?php if ( $is_bug ) : ?>
											<div class="cp-feedback-field">
												<label>
													<input type="checkbox" name="details" value="1" <?php checked( $details ); ?>>
													<?php esc_html_e( 'Include technical details (recommended)', 'woocommerce-anti-fraud' ); ?>
												</label>
												<details class="cp-feedback-details">
													<summary><?php esc_html_e( 'See what\'s included', 'woocommerce-anti-fraud' ); ?></summary>
													<?php self::render_environment( self::environment( true ) ); ?>
												</details>
											</div>
										<?php endif; ?>

										<div class="cp-feedback-actions">
											<button type="submit" class="button button-primary">
												<?php echo WooCommerce_Anti_Fraud_Icons::get( 'send', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
												<?php echo esc_html( $is_bug ? __( 'Send Bug Report', 'woocommerce-anti-fraud' ) : __( 'Send Feedback', 'woocommerce-anti-fraud' ) ); ?>
											</button>
											<span class="cp-feedback-muted"><?php esc_html_e( 'One message per site every 24 hours.', 'woocommerce-anti-fraud' ); ?></span>
										</div>
									</fieldset>
								</form>
							</div>
						</div>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<?php self::render_sent_box( $is_bug ); ?>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar box listing exactly what leaves the site.
	 *
	 * @param bool $is_bug Whether this is the bug report page.
	 * @return void
	 */
	private static function render_sent_box( $is_bug ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$ip   = self::visitor_ip();
		$rows = array(
			array( 'message-square-text', __( 'Your message, and your email if you add one', 'woocommerce-anti-fraud' ) ),
			/* translators: %s: this site's domain. */
			array( 'globe', sprintf( __( 'This site\'s address (%s)', 'woocommerce-anti-fraud' ), $host ) ),
			/* translators: %s: the visitor's IP address. */
			array( 'map-pin', '' !== $ip ? sprintf( __( 'Your IP address (%s)', 'woocommerce-anti-fraud' ), $ip ) : __( 'Your IP address', 'woocommerce-anti-fraud' ) ),
			array( 'package', __( 'Plugin, WordPress and PHP versions', 'woocommerce-anti-fraud' ) ),
		);

		if ( $is_bug ) {
			$rows[] = array( 'settings', __( 'Technical details, if ticked: theme, active plugins, database version and language', 'woocommerce-anti-fraud' ) );
		}
		?>
		<div class="postbox cp-feedback-sent">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo WooCommerce_Anti_Fraud_Icons::get( 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'What gets sent', 'woocommerce-anti-fraud' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul>
					<?php foreach ( $rows as $row ) : ?>
						<li>
							<?php echo WooCommerce_Anti_Fraud_Icons::get( $row[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<span><?php echo esc_html( $row[1] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="cp-feedback-muted"><?php esc_html_e( 'It goes to Content Powered, the plugin\'s developer, only when you press Send, and is used only to improve our plugins and reply to you.', 'woocommerce-anti-fraud' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * The technical details as a list, so people see exactly what's sent.
	 *
	 * @param array<string,mixed> $environment From {@see environment()}.
	 * @return void
	 */
	private static function render_environment( $environment ) {
		$labels = array(
			'wordpress'       => __( 'WordPress', 'woocommerce-anti-fraud' ),
			'php'             => __( 'PHP', 'woocommerce-anti-fraud' ),
			'database'        => __( 'Database', 'woocommerce-anti-fraud' ),
			'theme'           => __( 'Theme', 'woocommerce-anti-fraud' ),
			'locale'          => __( 'Language', 'woocommerce-anti-fraud' ),
			'multisite'       => __( 'Multisite', 'woocommerce-anti-fraud' ),
			'content_powered' => __( 'Our plugins', 'woocommerce-anti-fraud' ),
			'plugins'         => __( 'Active plugins', 'woocommerce-anti-fraud' ),
		);
		$names  = array();
		foreach ( self::plugins() as $slug => $plugin ) {
			$names[ $slug ] = $plugin['name'];
		}
		?>
		<dl>
			<?php foreach ( $labels as $key => $label ) : ?>
				<?php
				if ( ! isset( $environment[ $key ] ) ) {
					continue;
				}

				$value = $environment[ $key ];

				if ( 'content_powered' === $key ) {
					$list = array();
					foreach ( (array) $value as $slug => $version ) {
						$list[] = trim( ( isset( $names[ $slug ] ) ? $names[ $slug ] : $slug ) . ' ' . $version );
					}
					$value = implode( ', ', $list );
				} elseif ( is_bool( $value ) ) {
					$value = $value ? __( 'Yes', 'woocommerce-anti-fraud' ) : __( 'No', 'woocommerce-anti-fraud' );
				} elseif ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				?>
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd><?php echo esc_html( '' !== (string) $value ? $value : '—' ); ?></dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/**
	 * Handle the form: check it, send it, and go back to the page with the result.
	 *
	 * @return void
	 */
	public static function handle_submit() {
		if ( ! self::is_handler() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce-anti-fraud' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked just above.
		$type  = isset( $_POST['type'] ) && 'bug' === $_POST['type'] ? 'bug' : 'feedback';
		$draft = array(
			'type'    => $type,
			'plugin'  => isset( $_POST['plugin'] ) ? sanitize_key( wp_unslash( $_POST['plugin'] ) ) : '',
			// Kept as typed (not tag-stripped), so error output and code survive.
			// contentpowered.com escapes it when showing it.
			'message' => isset( $_POST['message'] ) ? trim( str_replace( "\r\n", "\n", wp_check_invalid_utf8( wp_unslash( $_POST['message'] ), true ) ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'email'   => isset( $_POST['email'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : '',
			'details' => 'bug' === $type && ! empty( $_POST['details'] ),
		);
		// phpcs:enable

		$error = self::validate( $draft );
		$sent  = '' === $error ? self::send( $draft ) : array(
			'ok'      => false,
			'message' => $error,
		);

		if ( $sent['ok'] ) {
			delete_transient( self::user_key( 'draft' ) );
		} else {
			set_transient( self::user_key( 'draft' ), $draft, HOUR_IN_SECONDS );
		}

		set_transient(
			self::user_key( 'notice' ),
			array(
				'type' => $sent['ok'] ? 'success' : 'error',
				'text' => $sent['message'],
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . ( 'bug' === $type ? self::PAGE_BUG : self::PAGE_FEEDBACK ) ) );
		exit;
	}

	/**
	 * Check a submitted form.
	 *
	 * @param array $draft The submitted values.
	 * @return string Error message, or empty when it's fine.
	 */
	private static function validate( $draft ) {
		$plugins = self::plugins();
		$length  = mb_strlen( $draft['message'] );

		if ( ! isset( $plugins[ $draft['plugin'] ] ) ) {
			return __( 'Please choose which plugin your message is about.', 'woocommerce-anti-fraud' );
		}

		if ( $length < self::MIN_LENGTH ) {
			/* translators: %d: minimum number of characters. */
			return sprintf( __( 'Please write a little more (at least %d characters), so we can understand what you mean.', 'woocommerce-anti-fraud' ), self::MIN_LENGTH );
		}

		if ( $length > self::MAX_LENGTH ) {
			/* translators: %s: maximum number of characters. */
			return sprintf( __( 'Please shorten your message to %s characters or fewer.', 'woocommerce-anti-fraud' ), number_format_i18n( self::MAX_LENGTH ) );
		}

		if ( '' !== $draft['email'] && ! is_email( $draft['email'] ) ) {
			return __( 'That email address doesn\'t look right. Fix it or leave it empty.', 'woocommerce-anti-fraud' );
		}

		$wait = self::wait();
		if ( $wait ) {
			/* translators: %s: time left, e.g. "21 hours". */
			return sprintf( __( 'Content Powered accepts one message per site every 24 hours. You can send the next one in %s.', 'woocommerce-anti-fraud' ), human_time_diff( time(), time() + $wait ) );
		}

		return '';
	}

	/**
	 * Send a checked message to contentpowered.com.
	 *
	 * @param array $draft The submitted values.
	 * @return array{ok:bool,message:string}
	 */
	private static function send( $draft ) {
		$plugins = self::plugins();
		$plugin  = $plugins[ $draft['plugin'] ];
		$is_bug  = 'bug' === $draft['type'];

		// The receiver fetches this hash back from verify() while the request
		// below is waiting, which proves the message comes from this site.
		$id     = wp_generate_password( 20, false );
		$secret = wp_generate_password( 40, false );
		set_transient( 'content_powered_feedback_' . $id, hash( 'sha256', $secret ), 5 * MINUTE_IN_SECONDS );

		$response = wp_remote_post(
			self::endpoint(),
			array(
				'timeout'     => 25,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'type'           => $draft['type'],
						'plugin'         => $draft['plugin'],
						'plugin_name'    => $plugin['name'],
						'plugin_version' => $plugin['version'],
						'message'        => $draft['message'],
						'email'          => $draft['email'],
						'site_url'       => home_url( '/' ),
						'site_name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
						'visitor_ip'     => self::visitor_ip(),
						'environment'    => self::environment( $is_bug && $draft['details'] ),
						'verify'         => array(
							'url'    => admin_url( 'admin-ajax.php' ),
							'action' => self::VERIFY_ACTION,
							'id'     => $id,
							'secret' => $secret,
						),
						'client'         => self::VERSION,
					)
				),
			)
		);

		delete_transient( 'content_powered_feedback_' . $id );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'We couldn\'t reach contentpowered.com just now. Your message is saved below; please try again in a few minutes.', 'woocommerce-anti-fraud' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code >= 200 && $code < 300 && ! empty( $data['ok'] ) ) {
			self::set_wait( isset( $data['next_in'] ) ? (int) $data['next_in'] : DAY_IN_SECONDS );

			if ( '' !== $draft['email'] ) {
				/* translators: %s: email address. */
				$text = sprintf( __( 'Thanks! Your message is on its way to Content Powered. If we need to follow up, we\'ll write to %s.', 'woocommerce-anti-fraud' ), $draft['email'] );
			} else {
				$text = __( 'Thanks! Your message is on its way to Content Powered.', 'woocommerce-anti-fraud' );
			}

			return array(
				'ok'      => true,
				'message' => $text,
			);
		}

		// Too soon for this site or its server: wait as long as contentpowered.com says, so the page
		// shows the right time. Its overall caps aren't about this site, so they don't lock the form.
		if ( 429 === $code && isset( $data['code'], $data['data']['retry_after'] )
			&& in_array( $data['code'], array( 'cp_feedback_site_limit', 'cp_feedback_ip_limit' ), true ) ) {
			self::set_wait( (int) $data['data']['retry_after'] );
		}

		$text = isset( $data['message'] ) && is_string( $data['message'] ) ? trim( wp_strip_all_tags( $data['message'] ) ) : '';

		if ( '' === $text ) {
			/* translators: %d: HTTP status code. */
			$text = sprintf( __( 'contentpowered.com couldn\'t take your message (error %d). Your message is saved below; please try again later.', 'woocommerce-anti-fraud' ), $code );
		}

		return array(
			'ok'      => false,
			'message' => mb_substr( $text, 0, 300 ),
		);
	}

	/**
	 * Answer contentpowered.com's check with the hash of a pending message's
	 * secret. Each hash can be read once and expires after a few minutes.
	 *
	 * @return void
	 */
	public static function verify() {
		if ( ! self::is_handler() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public check; the id is the secret.
		$id   = isset( $_GET['id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['id'] ) ) : '';
		$hash = 20 === strlen( $id ) ? get_transient( 'content_powered_feedback_' . $id ) : false;

		nocache_headers();

		if ( ! is_string( $hash ) || '' === $hash ) {
			wp_send_json( array( 'hash' => '' ), 404 );
		}

		delete_transient( 'content_powered_feedback_' . $id );

		wp_send_json( array( 'hash' => $hash ) );
	}

	/**
	 * Page styles, printed inline so this one file is all a plugin needs.
	 *
	 * @return void
	 */
	private static function print_styles() {
		?>
		<style id="cp-feedback-style">
			.cp-feedback-page { --cp-feedback-accent: var(--wp-admin-theme-color, #3858e9); }
			body.cp-feedback-page #wpcontent { padding-left: 0; }
			.cp-feedback-header { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 0 24px; padding: 16px 20px 0; background: #fff; border-bottom: 1px solid #dcdcde; }
			.cp-feedback-header-title { display: flex; align-items: center; gap: 12px; padding-bottom: 16px; }
			.cp-feedback-header-title h1 { margin: 0; padding: 0; color: #1d2327; font-size: 20px; font-weight: 600; line-height: 1.3; }
			.cp-feedback-header-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; background: var(--cp-feedback-accent); color: #fff; }
			.cp-feedback-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: -1px; }
			.cp-feedback-tab { display: inline-flex; align-items: center; gap: 7px; padding: 12px 12px 13px; border-bottom: 3px solid transparent; color: #50575e; font-size: 14px; font-weight: 500; text-decoration: none; transition: color .15s, border-color .15s; }
			.cp-feedback-tab:hover { color: var(--cp-feedback-accent); }
			.cp-feedback-tab:focus { box-shadow: none; outline: 0; }
			.cp-feedback-tab:focus-visible { outline: 2px solid var(--cp-feedback-accent); outline-offset: -2px; }
			.cp-feedback-tab.is-active { border-bottom-color: var(--cp-feedback-accent); color: #1d2327; font-weight: 600; }
			.cp-feedback-tab.is-active svg { color: var(--cp-feedback-accent); }
			.cp-feedback-page .cp-feedback-wrap { margin: 20px 20px 0; }
			.cp-feedback-wrap #poststuff { padding-top: 0; }
			.cp-feedback-wrap .notice { margin: 0 0 16px; }
			.cp-feedback-wrap .postbox { border-color: #dcdcde; border-radius: 6px; }
			.cp-feedback-wrap .postbox-header { border-bottom-color: #f0f0f1; border-radius: 6px 6px 0 0; }
			.cp-feedback-wrap .postbox .hndle { justify-content: flex-start; gap: 8px; padding: 12px 16px; color: #1d2327; font-size: 14px; font-weight: 600; cursor: default; }
			.cp-feedback-wrap .postbox .hndle svg { flex: none; color: var(--cp-feedback-accent); }
			.cp-feedback-wrap .postbox .inside { margin: 0; padding: 4px 16px 16px; color: #1d2327; font-size: 13px; }
			.cp-feedback-wrap fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
			.cp-feedback-wrap fieldset[disabled] { opacity: .6; }
			.cp-feedback-intro { margin: 12px 0 0; color: #50575e; font-size: 13px; line-height: 1.6; }
			.cp-feedback-field { margin-top: 20px; }
			.cp-feedback-label { display: block; margin-bottom: 6px; color: #1d2327; font-weight: 600; }
			.cp-feedback-field select { min-width: 280px; max-width: 100%; }
			.cp-feedback-field textarea { display: block; width: 100%; min-height: 200px; padding: 8px 10px; line-height: 1.6; }
			.cp-feedback-field .description { margin-top: 6px; color: #646970; }
			.cp-feedback-muted { color: #646970; }
			.cp-feedback-details { margin-top: 8px; }
			.cp-feedback-details summary { width: max-content; color: var(--cp-feedback-accent); cursor: pointer; }
			.cp-feedback-details dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 10px 0 0; padding: 12px 14px; border-radius: 4px; background: #f6f7f7; font-size: 12px; }
			.cp-feedback-details dt { color: #646970; }
			.cp-feedback-details dd { margin: 0; word-break: break-word; }
			.cp-feedback-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #f0f0f1; }
			.cp-feedback-actions .button { display: inline-flex; align-items: center; gap: 6px; }
			.cp-feedback-sent ul { margin: 6px 0 12px; }
			.cp-feedback-sent li { display: flex; gap: 10px; margin: 0; padding: 8px 0; border-bottom: 1px solid #f0f0f1; line-height: 1.5; }
			.cp-feedback-sent li svg { flex: none; margin-top: 2px; color: #8c8f94; }
			.cp-feedback-sent p { margin: 0; line-height: 1.6; }
			@media (max-width: 782px) {
				.cp-feedback-header { padding: 12px 12px 0; }
				.cp-feedback-tabs { flex-wrap: nowrap; width: 100%; overflow-x: auto; }
				.cp-feedback-tab { padding: 10px 8px 11px; white-space: nowrap; }
				.cp-feedback-page .cp-feedback-wrap { margin: 12px 12px 0; }
				.cp-feedback-field select { min-width: 0; width: 100%; }
			}
		</style>
		<?php
	}
}
