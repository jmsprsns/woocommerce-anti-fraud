<?php
/**
 * What an IP address is: where it is, who runs the network, and what kind of
 * connection it is (home broadband, mobile carrier, data center, VPN, Tor,
 * iCloud Private Relay).
 *
 * Sources, best first: ipinfo.io (when IP lookups are on; works without a
 * token, a free token raises the limit), Cloudflare's visitor location
 * headers, and WooCommerce's own MaxMind geolocation when it's set up.
 * Lookups are cached for a day per IP and fail open: when nothing answers,
 * the fields are just empty and the checks that need them score nothing.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * IP lookups and classification.
 */
final class WooCommerce_Anti_Fraud_IP_Intel {

	/**
	 * Connection types: anonymising or automated networks.
	 */
	const RISKY_TYPES = array( 'VPN', 'TOR', 'DCH' );

	/**
	 * Connection types whose IPs are shared by many unrelated people, so an
	 * IP match there doesn't link two orders to one person.
	 */
	const SHARED_TYPES = array( 'CPN', 'MOB' );

	/**
	 * Per-request memo.
	 *
	 * @var array<string,array>
	 */
	private static $memo = array();

	/**
	 * An empty result.
	 *
	 * @param string $ip IP address.
	 * @return array<string,string>
	 */
	public static function blank( $ip = '' ) {
		return array(
			'ip'       => (string) $ip,
			'country'  => '',
			'region'   => '',
			'city'     => '',
			'postal'   => '',
			'timezone' => '',
			'asn'      => '',
			'org'      => '',
			'carrier'  => '',
			'type'     => '',
			'source'   => '',
		);
	}

	/**
	 * Everything known about an IP.
	 *
	 * @param string $ip      IP address.
	 * @param bool   $current Whether $ip is the current request's client IP,
	 *                        so Cloudflare's location headers describe it.
	 * @return array<string,string> See blank().
	 */
	public static function lookup( $ip, $current = false ) {
		$ip = WooCommerce_Anti_Fraud_Request::valid_ip( $ip );

		if ( '' === $ip || ! WooCommerce_Anti_Fraud_Request::is_public( $ip ) ) {
			return self::blank( $ip );
		}

		$key = $ip . ( $current ? '|current' : '' );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$info = self::ipinfo( $ip );

		if ( '' === $info['country'] && $current ) {
			$info = self::fill( $info, self::cloudflare_headers(), 'cloudflare' );
		}

		if ( '' === $info['country'] ) {
			$info = self::fill( $info, self::woocommerce_geo( $ip ), 'woocommerce' );
		}

		/**
		 * Filter what the plugin knows about an IP. Add your own source here
		 * (e.g. a paid proxy-detection API) by filling in or overriding fields.
		 * `type` is one of MOB, RES, BIZ, DCH, VPN, TOR, CPN, or ''.
		 *
		 * @param array  $info Fields from blank().
		 * @param string $ip   IP address.
		 */
		$info = apply_filters( 'woocommerce_anti_fraud_ip_info', $info, $ip );
		$info = self::clean( is_array( $info ) ? $info : array(), $ip );

		self::$memo[ $key ] = $info;

		return $info;
	}

	/**
	 * What's already known about an IP, without asking ipinfo.io. For IPs a
	 * browser reports, so a visitor can't make the site look up addresses of
	 * their choosing.
	 *
	 * @param string $ip IP address.
	 * @return array<string,string> See blank().
	 */
	public static function cached( $ip ) {
		$ip = WooCommerce_Anti_Fraud_Request::valid_ip( $ip );

		if ( '' === $ip ) {
			return self::blank();
		}
		if ( isset( self::$memo[ $ip ] ) ) {
			return self::$memo[ $ip ];
		}

		$cached = self::enabled() ? get_transient( 'wcaf_ip_' . md5( $ip ) ) : false;

		return is_array( $cached ) ? self::clean( $cached, $ip ) : self::blank( $ip );
	}

	/**
	 * Whether this visitor may trigger another fresh lookup. Caps lookups at
	 * 30 an hour per visitor IP on the front end, so a script can't burn
	 * through the ipinfo.io allowance.
	 *
	 * @return bool
	 */
	private static function within_budget() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}

		$client = WooCommerce_Anti_Fraud_Request::ip();
		$key    = 'wcaf_lk_' . md5( $client );
		$used   = (int) get_transient( $key );

		/**
		 * Filter how many fresh IP lookups one visitor may cause per hour.
		 *
		 * @param int $limit Default 30.
		 */
		if ( $used >= (int) apply_filters( 'woocommerce_anti_fraud_lookups_per_hour', 30 ) ) {
			return false;
		}

		set_transient( $key, $used + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Whether ipinfo.io lookups are switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'ipinfo' );
	}

	/**
	 * ipinfo.io record for an IP, cached for a day.
	 *
	 * @param string $ip    Public IP address.
	 * @param bool   $fresh Skip the cache (the settings page's Test button).
	 * @return array<string,string> See blank(); empty fields on any failure.
	 */
	public static function ipinfo( $ip, $fresh = false ) {
		$info = self::blank( $ip );

		if ( ! self::enabled() ) {
			return $info;
		}

		$cache_key = 'wcaf_ip_' . md5( $ip );
		$cached    = $fresh ? false : get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::clean( $cached, $ip );
		}

		if ( ! $fresh && ! self::within_budget() ) {
			return $info;
		}

		$body = self::fetch( $ip );
		if ( null === $body ) {
			// Remember the miss briefly, so an outage doesn't add a timeout to every checkout.
			set_transient( $cache_key, $info, 10 * MINUTE_IN_SECONDS );
			return $info;
		}

		$info = self::from_ipinfo( $body, $ip );
		set_transient( $cache_key, $info, DAY_IN_SECONDS );

		return $info;
	}

	/**
	 * Call ipinfo.io.
	 *
	 * @param string $ip Public IP address.
	 * @return array|null Decoded body, or null on any failure.
	 */
	private static function fetch( $ip ) {
		$url   = 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json';
		$token = trim( (string) WooCommerce_Anti_Fraud_Plugin::instance()->get_setting( 'ipinfo_token' ) );
		$args  = array(
			'timeout'     => 3,
			'redirection' => 0,
			'user-agent'  => 'WooCommerce-Anti-Fraud/' . WOOCOMMERCE_ANTI_FRAUD_VERSION . '; ' . home_url( '/' ),
			'headers'     => array( 'Accept' => 'application/json' ),
		);

		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! empty( $body['bogon'] ) || ! empty( $body['error'] ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Turn an ipinfo.io response into our fields, including the connection type.
	 *
	 * @param array  $body Decoded ipinfo.io JSON.
	 * @param string $ip   IP address.
	 * @return array<string,string>
	 */
	public static function from_ipinfo( array $body, $ip ) {
		$info           = self::blank( $ip );
		$info['source'] = 'ipinfo';

		foreach ( array( 'country', 'region', 'city', 'postal', 'timezone' ) as $field ) {
			if ( isset( $body[ $field ] ) && is_scalar( $body[ $field ] ) ) {
				$info[ $field ] = (string) $body[ $field ];
			}
		}

		// "org" is "AS15169 Google LLC" on the free API; paid plans add an asn object.
		if ( isset( $body['asn'] ) && is_array( $body['asn'] ) ) {
			$info['asn'] = isset( $body['asn']['asn'] ) ? (string) $body['asn']['asn'] : '';
			$info['org'] = isset( $body['asn']['name'] ) ? (string) $body['asn']['name'] : '';
		}
		if ( ( '' === $info['asn'] || '' === $info['org'] ) && isset( $body['org'] ) && is_string( $body['org'] ) ) {
			if ( preg_match( '/^(AS\d+)\s+(.+)$/i', trim( $body['org'] ), $m ) ) {
				$info['asn'] = '' !== $info['asn'] ? $info['asn'] : strtoupper( $m[1] );
				$info['org'] = '' !== $info['org'] ? $info['org'] : $m[2];
			} elseif ( '' === $info['org'] ) {
				$info['org'] = trim( $body['org'] );
			}
		}

		if ( isset( $body['carrier']['name'] ) && is_string( $body['carrier']['name'] ) ) {
			$info['carrier'] = $body['carrier']['name'];
		}

		$info['type'] = self::classify(
			$info,
			isset( $body['privacy'] ) && is_array( $body['privacy'] ) ? $body['privacy'] : array(),
			isset( $body['asn']['type'] ) ? (string) $body['asn']['type'] : ( isset( $body['company']['type'] ) ? (string) $body['company']['type'] : '' )
		);

		return self::clean( $info, $ip );
	}

	/**
	 * Work out the connection type from whatever the data source gave us.
	 *
	 * @param array  $info     Fields from blank() (asn, org, carrier used).
	 * @param array  $privacy  ipinfo privacy flags (paid plans), if any.
	 * @param string $net_type ipinfo network type (isp, hosting, business, education), if any.
	 * @return string MOB, RES, BIZ, DCH, VPN, TOR, CPN, or ''.
	 */
	public static function classify( array $info, array $privacy = array(), $net_type = '' ) {
		$asn      = strtoupper( trim( (string) $info['asn'] ) );
		$org      = strtolower( trim( (string) $info['org'] ) );
		$net_type = strtolower( (string) $net_type );

		// Privacy flags are authoritative when the plan includes them.
		if ( ! empty( $privacy['tor'] ) ) {
			return 'TOR';
		}
		if ( ! empty( $privacy['relay'] ) ) {
			return 'CPN';
		}
		if ( ! empty( $privacy['vpn'] ) || ! empty( $privacy['proxy'] ) ) {
			return 'VPN';
		}
		if ( ! empty( $privacy['hosting'] ) || 'hosting' === $net_type ) {
			return 'DCH';
		}
		if ( '' !== (string) $info['carrier'] ) {
			return 'MOB';
		}

		if ( '' === $asn && '' === $org ) {
			return '';
		}

		/**
		 * Filter the words that mark a network as a VPN or proxy provider.
		 *
		 * @param string[] $keywords Lowercase fragments matched against the network name.
		 */
		$vpn = apply_filters( 'woocommerce_anti_fraud_vpn_keywords', array( 'vpn', 'proxy', 'mullvad', 'windscribe', 'surfshark', 'private internet access', 'tefincom', 'anonymizer', 'tor exit', 'tunnelbear', 'privado', 'cyberghost', 'hide.me', 'protonvpn', 'proton ag', 'packethub', 'datacamp' ) );
		foreach ( (array) $vpn as $word ) {
			if ( '' !== $word && false !== strpos( $org, (string) $word ) ) {
				return 'VPN';
			}
		}

		/**
		 * Filter the networks iCloud Private Relay (and similar privacy relays) exit from.
		 *
		 * @param string[] $asns Uppercase AS numbers, e.g. AS13335.
		 */
		$relay = apply_filters( 'woocommerce_anti_fraud_relay_asns', array( 'AS13335', 'AS54113', 'AS20940', 'AS16625', 'AS36183' ) );
		if ( in_array( $asn, (array) $relay, true ) ) {
			return 'CPN';
		}

		/**
		 * Filter the AS numbers of cloud and hosting providers.
		 *
		 * @param string[] $asns Uppercase AS numbers.
		 */
		$hosting = apply_filters(
			'woocommerce_anti_fraud_hosting_asns',
			array(
				'AS15169', // Google.
				'AS396982', // Google Cloud.
				'AS16509', // Amazon AWS.
				'AS14618', // Amazon AWS.
				'AS8075', // Microsoft Azure.
				'AS14061', // DigitalOcean.
				'AS16276', // OVH.
				'AS24940', // Hetzner.
				'AS63949', // Linode / Akamai.
				'AS20473', // Vultr.
				'AS9009', // M247.
				'AS212238', // CDN77 / Datacamp.
				'AS45102', // Alibaba Cloud.
				'AS31898', // Oracle Cloud.
				'AS46606', // Unified Layer.
				'AS26496', // GoDaddy.
				'AS51167', // Contabo.
				'AS62240', // Clouvider.
				'AS60068', // Datacamp.
				'AS208091', // Xhost.
			)
		);
		if ( in_array( $asn, (array) $hosting, true ) ) {
			return 'DCH';
		}

		if ( self::looks_mobile( $org ) ) {
			return 'MOB';
		}

		/**
		 * Filter the words that mark a network as hosting or a data center.
		 *
		 * @param string[] $keywords Lowercase fragments matched against the network name.
		 */
		$dch = apply_filters( 'woocommerce_anti_fraud_hosting_keywords', array( 'hosting', 'datacenter', 'data center', 'dedicated', 'server', 'vps', 'colocation', 'cloud', 'digitalocean', 'linode', 'vultr', 'choopa', 'hetzner', 'ovh', 'contabo', 'scaleway', 'leaseweb', 'frantech', 'buyvm', 'colocrossing', 'quadranet', 'psychz', 'sharktech', 'serverius', 'worldstream', 'ponynet', 'hostinger', 'ionos' ) );
		foreach ( (array) $dch as $word ) {
			if ( '' !== $word && false !== strpos( $org, (string) $word ) ) {
				return 'DCH';
			}
		}

		if ( in_array( $net_type, array( 'business', 'education' ), true ) ) {
			return 'BIZ';
		}

		// Any other named network is an ordinary home or office ISP.
		return '' !== $org ? 'RES' : '';
	}

	/**
	 * Whether a network name reads like a mobile carrier.
	 *
	 * @param string $org Lowercase network name.
	 * @return bool
	 */
	private static function looks_mobile( $org ) {
		/**
		 * Filter the words that mark a network as a mobile carrier.
		 *
		 * @param string[] $keywords Lowercase fragments matched against the network name.
		 */
		$words = apply_filters(
			'woocommerce_anti_fraud_mobile_keywords',
			array( 'wireless', 'mobile', 'mobility', 'cellular', 'cellco', 't-mobile', 'tmobile', 'metropcs', 'sprint', 'cricket', 'tracfone', 'vodafone', 'telefonica', 'movistar', 'telenor', 'tele2', 'airtel', 'reliance jio', 'rogers', 'telus', 'megafon', 'beeline', 'ee limited', 'hutchison' )
		);

		foreach ( (array) $words as $word ) {
			if ( '' !== $word && false !== strpos( $org, (string) $word ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Location from Cloudflare's visitor headers. Only describes the current
	 * request, and only when the request really came through Cloudflare.
	 *
	 * @return array<string,string>
	 */
	private static function cloudflare_headers() {
		$client = WooCommerce_Anti_Fraud_Request::client();
		$out    = array();

		if ( 'cloudflare' !== $client['source'] ) {
			return $out;
		}

		$map = array(
			'country'  => 'HTTP_CF_IPCOUNTRY',
			'region'   => 'HTTP_CF_REGION',
			'city'     => 'HTTP_CF_IPCITY',
			'postal'   => 'HTTP_CF_POSTAL_CODE',
			'timezone' => 'HTTP_CF_TIMEZONE',
		);

		foreach ( $map as $field => $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Cloudflare sends XX for unknown and T1 for Tor.
				if ( 'country' === $field && 'T1' === $value ) {
					$out['type'] = 'TOR';
					continue;
				}
				if ( '' !== $value && 'XX' !== $value ) {
					$out[ $field ] = $value;
				}
			}
		}

		return $out;
	}

	/**
	 * Location from WooCommerce's MaxMind integration, when it's configured.
	 *
	 * @param string $ip IP address.
	 * @return array<string,string>
	 */
	private static function woocommerce_geo( $ip ) {
		$settings = get_option( 'woocommerce_maxmind_geolocation_settings' );

		if ( ! is_array( $settings ) || empty( $settings['license_key'] ) ) {
			return array();
		}

		$geo = apply_filters(
			'woocommerce_get_geolocation',
			array(
				'country'  => '',
				'state'    => '',
				'city'     => '',
				'postcode' => '',
			),
			$ip
		);

		if ( ! is_array( $geo ) ) {
			return array();
		}

		return array(
			'country' => isset( $geo['country'] ) ? (string) $geo['country'] : '',
			'region'  => isset( $geo['state'] ) ? (string) $geo['state'] : '',
			'city'    => isset( $geo['city'] ) ? (string) $geo['city'] : '',
			'postal'  => isset( $geo['postcode'] ) ? (string) $geo['postcode'] : '',
		);
	}

	/**
	 * Fill empty fields from another source.
	 *
	 * @param array  $info   Current fields.
	 * @param array  $extra  More fields.
	 * @param string $source Name of the other source.
	 * @return array
	 */
	private static function fill( array $info, array $extra, $source ) {
		$used = false;

		foreach ( $extra as $field => $value ) {
			if ( array_key_exists( $field, $info ) && '' === $info[ $field ] && '' !== (string) $value ) {
				$info[ $field ] = (string) $value;
				$used           = true;
			}
		}

		if ( $used && '' === $info['source'] ) {
			$info['source'] = $source;
		}

		return $info;
	}

	/**
	 * Keep only known fields, as short plain strings.
	 *
	 * @param array  $info Raw fields.
	 * @param string $ip   IP address.
	 * @return array<string,string>
	 */
	private static function clean( array $info, $ip ) {
		$out = self::blank( $ip );

		foreach ( $out as $field => $default ) {
			if ( 'ip' !== $field && isset( $info[ $field ] ) && is_scalar( $info[ $field ] ) ) {
				$out[ $field ] = substr( sanitize_text_field( (string) $info[ $field ] ), 0, 'org' === $field ? 128 : 64 );
			}
		}

		$out['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['country'] ), 0, 2 ) );
		$out['asn']     = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $out['asn'] ) );
		$out['type']    = in_array( $out['type'], array( 'MOB', 'RES', 'BIZ', 'DCH', 'VPN', 'TOR', 'CPN' ), true ) ? $out['type'] : '';

		return $out;
	}

	/**
	 * Human label for a connection type.
	 *
	 * @param string $type MOB, RES, BIZ, DCH, VPN, TOR, CPN or ''.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'MOB' => __( 'Mobile carrier', 'woocommerce-anti-fraud' ),
			'RES' => __( 'Home or office ISP', 'woocommerce-anti-fraud' ),
			'BIZ' => __( 'Business network', 'woocommerce-anti-fraud' ),
			'DCH' => __( 'Hosting / data center', 'woocommerce-anti-fraud' ),
			'VPN' => __( 'VPN or proxy', 'woocommerce-anti-fraud' ),
			'TOR' => __( 'Tor exit node', 'woocommerce-anti-fraud' ),
			'CPN' => __( 'iCloud Private Relay', 'woocommerce-anti-fraud' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : __( 'Unknown', 'woocommerce-anti-fraud' );
	}

	/**
	 * "City, Region, CC" for display.
	 *
	 * @param array $info Fields from blank().
	 * @return string
	 */
	public static function place( array $info ) {
		$parts = array();

		foreach ( array( 'city', 'region', 'country' ) as $field ) {
			if ( ! empty( $info[ $field ] ) && ! in_array( $info[ $field ], $parts, true ) ) {
				$parts[] = $info[ $field ];
			}
		}

		return implode( ', ', $parts );
	}
}
