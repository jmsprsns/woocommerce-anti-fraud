<?php
/**
 * The shared top-level "Content Powered" admin menu.
 *
 * Every Content Powered plugin carries this same code and puts its settings
 * page under this menu (Content Powered > Plugin Name). Whichever plugin loads
 * first registers the menu; the rest see it's already there and only add
 * their own page to it.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu wiring.
 */
final class WooCommerce_Anti_Fraud_Menu {

	/**
	 * Menu slug shared by every Content Powered plugin. Never change it, or
	 * plugins built from older copies of the skeleton end up in two menus.
	 */
	const SLUG = 'content-powered';

	/**
	 * Capability needed to see the menu.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Position in the admin menu, just below Settings.
	 */
	const POSITION = 81;

	/**
	 * Hook the menu in. Safe to call from every plugin.
	 *
	 * @return void
	 */
	public static function init() {
		// Before the default priority (10), where plugins add their pages.
		add_action( 'admin_menu', array( __CLASS__, 'register' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_duplicate_item' ), 999 );
	}

	/**
	 * Register the top-level menu, unless another plugin already has.
	 *
	 * @return void
	 */
	public static function register() {
		global $admin_page_hooks;

		if ( ! empty( $admin_page_hooks[ self::SLUG ] ) ) {
			return;
		}

		add_menu_page(
			__( 'Content Powered', 'woocommerce-anti-fraud' ),
			__( 'Content Powered', 'woocommerce-anti-fraud' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			self::icon(),
			self::POSITION
		);
	}

	/**
	 * Drop the "Content Powered" item WordPress repeats as the first submenu
	 * entry, so the top-level link opens the first plugin's page instead.
	 *
	 * @return void
	 */
	public static function remove_duplicate_item() {
		global $submenu;

		if ( isset( $submenu[ self::SLUG ] ) && count( $submenu[ self::SLUG ] ) > 1 ) {
			remove_submenu_page( self::SLUG, self::SLUG );
		}
	}

	/**
	 * The sumo logo as a data URI. WordPress repaints it to match the admin
	 * colour scheme, so it only needs a single fill colour.
	 *
	 * @return string Data URI, or a Dashicon if the logo file is missing.
	 */
	public static function icon() {
		$file = WOOCOMMERCE_ANTI_FRAUD_DIR . 'assets/content-powered.svg';
		$svg  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! $svg ) {
			return 'dashicons-admin-generic';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI.
	}

	/**
	 * Fallback page for anyone who lands on the top-level slug directly:
	 * a list of the installed Content Powered plugins.
	 *
	 * @return void
	 */
	public static function render_page() {
		global $submenu;

		$items = isset( $submenu[ self::SLUG ] ) ? $submenu[ self::SLUG ] : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Powered', 'woocommerce-anti-fraud' ); ?></h1>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<?php
					if ( self::SLUG === $item[2] || ! current_user_can( $item[1] ) ) {
						continue;
					}
					?>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item[2] ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $item[0] ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
