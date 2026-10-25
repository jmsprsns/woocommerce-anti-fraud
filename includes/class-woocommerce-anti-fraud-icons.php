<?php
/**
 * Loads Lucide SVG icons bundled in the plugin's /icons/ folder.
 *
 * Lucide is ISC licensed (some icons MIT, via Feather). Keep icons/LICENSE
 * with the icons. Browse the set at https://lucide.dev/icons.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Icon helper.
 */
class WooCommerce_Anti_Fraud_Icons {

	/**
	 * Return an inline SVG icon, sized and ready to echo.
	 *
	 * The bundled Lucide icons use `stroke="currentColor"`, so the icon
	 * inherits the colour of whatever element it is placed inside.
	 *
	 * @param string $name  Icon file name (without extension), e.g. "sliders-horizontal".
	 * @param int    $size  Width/height in pixels.
	 * @param string $class Extra CSS class(es) to add to the <svg>.
	 * @return string Inline SVG markup, or an empty string if the icon is missing.
	 */
	public static function get( $name, $size = 20, $class = '' ) {
		$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $name ) );

		if ( '' === $name ) {
			return '';
		}

		$file = WOOCOMMERCE_ANTI_FRAUD_DIR . 'icons/' . $name . '.svg';

		if ( ! is_readable( $file ) ) {
			return '';
		}

		$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $svg ) {
			return '';
		}

		// Collapse whitespace between tags so the markup is safe to drop anywhere.
		$svg = preg_replace( '/>\s+</', '><', trim( $svg ) );

		// Drop the icon's own width/height so our size wins.
		$svg = preg_replace( '/\s(?:width|height)="[^"]*"/', '', $svg, 2 );

		$size    = absint( $size );
		$classes = trim( 'woocommerce-anti-fraud-icon ' . $class );
		$attrs   = sprintf(
			' width="%1$d" height="%1$d" class="%2$s" aria-hidden="true" focusable="false"',
			$size,
			esc_attr( $classes )
		);

		return preg_replace( '/<svg/', '<svg' . $attrs, $svg, 1 );
	}
}
