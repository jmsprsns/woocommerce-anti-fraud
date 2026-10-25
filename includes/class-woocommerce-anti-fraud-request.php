<?php
/**
 * The visitor's real IP address, worked out so it can't be spoofed.
 *
 * Headers like X-Forwarded-For and CF-Connecting-IP are just text any client
 * can send, so they're only believed when the connection itself comes from
 * Cloudflare (checked against Cloudflare's published IP ranges) or from a
 * proxy the site owner trusts. Everything else gets REMOTE_ADDR.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request helpers.
 */
final class WooCommerce_Anti_Fraud_Request {

	/**
	 * Option holding Cloudflare's IP ranges as last fetched.
	 */
	const CF_OPTION = 'woocommerce_anti_fraud_cf_ranges';

	/**
	 * Headers a trusted proxy may carry the client IP in (setting value => $_SERVER key).
	 */
	const PROXY_HEADERS = array(
		'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR',
		'x-real-ip'       => 'HTTP_X_REAL_IP',
		'true-client-ip'  => 'HTTP_TRUE_CLIENT_IP',
	);

	/**
	 * Resolved client IP for this request.
	 *
	 * @var array<string,string>|null
	 */
	private static $client = null;

	/**
	 * The client IP and how it was found.
	 *
	 * @return array{ip:string,source:string,remote:string} source is direct,
	 *               cloudflare or proxy; ip is '' when nothing valid was found.
	 */
	public static function client() {
		if ( null !== self::$client ) {
			return self::$client;
		}

		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );
		$client = array(
			'ip'     => $remote,
			'source' => 'direct',
			'remote' => $remote,
		);

		if ( '' !== $remote ) {
			if ( self::is_cloudflare( $remote ) ) {
				$cf = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
				if ( '' !== $cf ) {
					$client['ip']     = $cf;
					$client['source'] = 'cloudflare';
				}
			} elseif ( self::is_trusted_proxy( $remote ) ) {
				$forwarded = self::from_proxy_header();
				if ( '' !== $forwarded ) {
					$client['ip']     = $forwarded;
					$client['source'] = 'proxy';
				}
			}
		}

		/**
		 * Filter the visitor IP the plugin scores against.
		 *
		 * @param array $client {ip, source, remote}.
		 */
		$filtered = apply_filters( 'woocommerce_anti_fraud_client_ip', $client );
		if ( is_array( $filtered ) && isset( $filtered['ip'] ) ) {
			$client['ip']     = self::valid_ip( (string) $filtered['ip'] );
			$client['source'] = isset( $filtered['source'] ) ? sanitize_key( (string) $filtered['source'] ) : $client['source'];
		}

		self::$client = $client;

		return $client;
	}

	/**
	 * The client IP, or '' if it couldn't be worked out.
	 *
	 * @return string
	 */
	public static function ip() {
		$client = self::client();

		return $client['ip'];
	}

	/**
	 * Forget the resolved IP (tests, settings changes within one request).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$client = null;
	}

	/**
	 * The client IP from the configured proxy header. X-Forwarded-For is read
	 * right to left, skipping our own proxies, so a client can't push a fake
	 * address in by sending its own X-Forwarded-For.
	 *
	 * @return string
	 */
	private static function from_proxy_header() {
		$header = (string) WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'proxy_header' );
		$key    = isset( self::PROXY_HEADERS[ $header ] ) ? self::PROXY_HEADERS[ $header ] : 'HTTP_X_FORWARDED_FOR';
		$value  = self::server( $key );

		if ( '' === $value ) {
			return '';
		}

		if ( 'HTTP_X_FORWARDED_FOR' !== $key ) {
			return self::valid_ip( $value );
		}

		$hops = array_slice( array_map( 'trim', explode( ',', $value ) ), -20 );
		$last = '';

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$hop = self::valid_ip( $hops[ $i ] );
			if ( '' === $hop ) {
				break;
			}
			$last = $hop;
			if ( ! self::is_trusted_proxy( $hop ) && ! self::is_cloudflare( $hop ) ) {
				return $hop;
			}
		}

		return $last;
	}

	/**
	 * Whether an address is one of the site's own proxies: listed in the
	 * settings, or (when allowed) on a private or loopback network, which
	 * nobody on the internet can connect from.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_trusted_proxy( $ip ) {
		$plugin = WooCommerce_Anti_Fraud_Plugin::instance();

		if ( $plugin->get_setting( 'trust_private' ) && self::is_private( $ip ) ) {
			return true;
		}

		foreach ( self::parse_ranges( (string) $plugin->get_setting( 'trusted_proxies' ) ) as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split a list of IPs and CIDR ranges (one per line, or comma-separated)
	 * into the valid entries.
	 *
	 * @param string $raw Raw list.
	 * @return string[]
	 */
	public static function parse_ranges( $raw ) {
		$out = array();

		foreach ( preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
			if ( self::valid_range( $entry ) ) {
				$out[] = $entry;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a string is an IP or a CIDR range.
	 *
	 * @param string $range IP or CIDR.
	 * @return bool
	 */
	public static function valid_range( $range ) {
		$parts = explode( '/', (string) $range );

		if ( count( $parts ) > 2 || '' === self::valid_ip( $parts[0] ) ) {
			return false;
		}

		if ( 1 === count( $parts ) ) {
			return true;
		}

		$max = false === strpos( $parts[0], ':' ) ? 32 : 128;

		return ctype_digit( $parts[1] ) && (int) $parts[1] <= $max;
	}

	/**
	 * Whether an IP falls inside an IP or CIDR range.
	 *
	 * @param string $ip    IP address.
	 * @param string $range IP or CIDR.
	 * @return bool
	 */
	public static function in_range( $ip, $range ) {
		$parts  = explode( '/', (string) $range, 2 );
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input just means "no match".
		$sn_bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ip_bin || false === $sn_bin || strlen( $ip_bin ) !== strlen( $sn_bin ) ) {
			return false;
		}

		$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $ip_bin ) * 8;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $sn_bin, 0, $bytes ) ) {
			return false;
		}

		if ( $rest > 0 ) {
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $sn_bin[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A trimmed, valid IP address, or ''.
	 *
	 * @param mixed $ip Candidate.
	 * @return string
	 */
	public static function valid_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		return '' !== $ip && strlen( $ip ) <= 45 && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP is publicly routable (not private, loopback, link-local,
	 * reserved or carrier-grade NAT).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public( $ip ) {
		if ( '' === self::valid_ip( $ip ) || self::is_private( $ip ) ) {
			return false;
		}

		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether an IP is on a private, loopback, link-local or CGNAT network.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_private( $ip ) {
		$ranges = array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10', '::1/128', 'fc00::/7', 'fe80::/10' );

		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two IPs of the same family share a provider-sized network:
	 * /16 for IPv4, /32 for IPv6.
	 *
	 * @param string $a IP address.
	 * @param string $b IP address.
	 * @return bool
	 */
	public static function same_network( $a, $b ) {
		$a_is_v6 = false !== strpos( $a, ':' );
		$b_is_v6 = false !== strpos( $b, ':' );

		if ( $a_is_v6 !== $b_is_v6 ) {
			return false;
		}

		return self::in_range( $b, $a . ( $a_is_v6 ? '/32' : '/16' ) );
	}

	/**
	 * Whether an IP belongs to Cloudflare's edge network.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_cloudflare( $ip ) {
		foreach ( self::cloudflare_ranges() as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cloudflare's IP ranges: the last fetched copy, or the list shipped
	 * with the plugin (from https://www.cloudflare.com/ips/).
	 *
	 * @return string[]
	 */
	public static function cloudflare_ranges() {
		$stored = get_option( self::CF_OPTION );

		if ( is_array( $stored ) && ! empty( $stored['ranges'] ) && is_array( $stored['ranges'] ) ) {
			return $stored['ranges'];
		}

		return array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
	}

	/**
	 * Re-fetch Cloudflare's IP ranges about once a week. Keeps the current
	 * list when the fetch fails or returns anything that isn't a range.
	 *
	 * @return void
	 */
	public static function maybe_refresh_cloudflare_ranges() {
		$stored = get_option( self::CF_OPTION );

		if ( is_array( $stored ) && ! empty( $stored['time'] ) && (int) $stored['time'] > time() - WEEK_IN_SECONDS ) {
			return;
		}

		$ranges = array();
		foreach ( array( 'https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6' ) as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 8 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return;
			}
			$lines = preg_split( '/\s+/', trim( (string) wp_remote_retrieve_body( $response ) ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $lines as $line ) {
				if ( ! self::valid_range( $line ) || false === strpos( $line, '/' ) ) {
					return;
				}
				$ranges[] = $line;
			}
		}

		if ( count( $ranges ) < 5 || count( $ranges ) > 200 ) {
			return;
		}

		update_option(
			self::CF_OPTION,
			array(
				'ranges' => $ranges,
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * The visitor's browser user agent, trimmed.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return substr( sanitize_text_field( self::server( 'HTTP_USER_AGENT' ) ), 0, 255 );
	}

	/**
	 * What the server sees about the current connection, for the settings
	 * page: which headers are present and which IP the plugin would use.
	 *
	 * @return array<string,mixed>
	 */
	public static function probe() {
		$client  = self::client();
		$headers = array();

		foreach ( array(
			'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
			'X-Forwarded-For'  => 'HTTP_X_FORWARDED_FOR',
			'X-Real-IP'        => 'HTTP_X_REAL_IP',
			'True-Client-IP'   => 'HTTP_TRUE_CLIENT_IP',
		) as $label => $key ) {
			$value = self::server( $key );
			if ( '' !== $value ) {
				$headers[ $label ] = substr( sanitize_text_field( $value ), 0, 200 );
			}
		}

		$warning = '';
		if ( 'direct' === $client['source'] && $headers && ( self::is_private( $client['remote'] ) || ! self::is_public( $client['remote'] ) ) ) {
			$warning = 'private';
		} elseif ( 'direct' === $client['source'] && $headers && ! isset( $headers['CF-Connecting-IP'] ) ) {
			$warning = 'untrusted';
		}

		return array(
			'ip'      => $client['ip'],
			'source'  => $client['source'],
			'remote'  => $client['remote'],
			'headers' => $headers,
			'warning' => $warning,
		);
	}

	/**
	 * A $_SERVER value as a plain string.
	 *
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	private static function server( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		return trim( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by each caller (valid_ip(), sanitize_text_field()).
	}
}
