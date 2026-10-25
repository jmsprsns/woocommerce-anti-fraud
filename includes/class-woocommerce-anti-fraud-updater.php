<?php
/**
 * Automatic updates from GitHub through the bundled Plugin Update Checker.
 *
 * The repo comes from the "Update URI" header in the main plugin file. PUC
 * checks it for new releases (every 12 hours) and feeds them into WordPress's
 * native update system: the update badge, one-click updates, the "Enable
 * auto-updates" link, background auto-updates and the "View details" popup,
 * which is built from readme.txt.
 *
 * The icon on the update screens comes from assets/icon.svg (or
 * assets/icon-128x128.png and icon-256x256.png), which PUC picks up by itself.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update checker wiring.
 */
final class WooCommerce_Anti_Fraud_Updater {

	/**
	 * Placeholder left in the "Update URI" header until a repo is set.
	 */
	const PLACEHOLDER = 'REPO-NAME';

	/**
	 * Branch to follow. On main, PUC installs the latest GitHub release,
	 * falling back to the highest version tag, then the branch itself.
	 */
	const BRANCH = 'main';

	/**
	 * PUC update checker instance.
	 *
	 * @var object|null
	 */
	private static $checker = null;

	/**
	 * Build the update checker, unless there is no repo yet or this is a dev copy.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::has_repo() ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_repo_notice' ) );
			return;
		}

		if ( self::is_dev_copy() ) {
			return;
		}

		require_once WOOCOMMERCE_ANTI_FRAUD_DIR . 'plugin-update-checker/plugin-update-checker.php';

		self::$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			WOOCOMMERCE_ANTI_FRAUD_REPO,
			WOOCOMMERCE_ANTI_FRAUD_FILE,
			'woocommerce-anti-fraud'
		);

		self::$checker->setBranch( self::BRANCH );

		// Private repos only: define WOOCOMMERCE_ANTI_FRAUD_GITHUB_TOKEN in each site's
		// wp-config.php. Never hardcode a token in the plugin itself.
		if ( defined( 'WOOCOMMERCE_ANTI_FRAUD_GITHUB_TOKEN' ) && WOOCOMMERCE_ANTI_FRAUD_GITHUB_TOKEN ) {
			self::$checker->setAuthentication( WOOCOMMERCE_ANTI_FRAUD_GITHUB_TOKEN );
		}
	}

	/**
	 * Whether the "Update URI" header points at a real repo.
	 *
	 * @return bool
	 */
	public static function has_repo() {
		return '' !== WOOCOMMERCE_ANTI_FRAUD_REPO && false === strpos( WOOCOMMERCE_ANTI_FRAUD_REPO, self::PLACEHOLDER );
	}

	/**
	 * Whether this copy is a development checkout that updates should skip.
	 *
	 * Installing an update replaces the whole plugin folder, which would wipe
	 * a git working tree, so a folder with .git in it is treated as a dev copy.
	 * Define WOOCOMMERCE_ANTI_FRAUD_DEV_MODE in wp-config.php to override either way.
	 *
	 * @return bool
	 */
	public static function is_dev_copy() {
		if ( defined( 'WOOCOMMERCE_ANTI_FRAUD_DEV_MODE' ) ) {
			return (bool) WOOCOMMERCE_ANTI_FRAUD_DEV_MODE;
		}

		return file_exists( WOOCOMMERCE_ANTI_FRAUD_DIR . '.git' );
	}

	/**
	 * The PUC instance, for callers that want to force a check.
	 *
	 * @return object|null Null when update checks are off.
	 */
	public static function get_checker() {
		return self::$checker;
	}

	/**
	 * Link that runs PUC's "Check for updates" action right away.
	 *
	 * @return string Empty when update checks are off.
	 */
	public static function check_now_url() {
		if ( null === self::$checker ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'puc_check_for_updates' => 1,
					'puc_slug'              => self::$checker->slug,
				),
				self_admin_url( 'plugins.php' )
			),
			'puc_check_for_updates'
		);
	}

	/**
	 * Remind admins on the Plugins screen that no repo is set.
	 *
	 * @return void
	 */
	public static function missing_repo_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: 1: plugin name, 2: header name, 3: main plugin file. */
				esc_html__( '%1$s can\'t check for updates yet. Set the %2$s header in %3$s to its GitHub repo.', 'woocommerce-anti-fraud' ),
				'<strong>' . esc_html__( 'WooCommerce Anti Fraud', 'woocommerce-anti-fraud' ) . '</strong>',
				'<code>Update URI</code>',
				'<code>' . esc_html( plugin_basename( WOOCOMMERCE_ANTI_FRAUD_FILE ) ) . '</code>'
			)
		);
	}
}
