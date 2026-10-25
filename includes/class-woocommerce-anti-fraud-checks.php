<?php
/**
 * The individual checks, as plain functions of their inputs: WebRTC leak,
 * IP location vs billing address, browser timezone vs IP timezone, phone
 * area code vs billing state, and pasted checkout fields.
 *
 * @package WooCommerce_Anti_Fraud
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless checks.
 */
final class WooCommerce_Anti_Fraud_Checks {

	/**
	 * Checkout fields whose entry method is tracked, as the logical names
	 * the collector reports (classic checkout names; block checkout ids are
	 * mapped onto these in collector.js).
	 */
	const FIELDS = array(
		'billing_email',
		'billing_first_name',
		'billing_last_name',
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_phone',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_phone',
		'order_comments',
	);

	/**
	 * Fields that count when pasted: who the buyer is and where it ships.
	 */
	const PASTE_FIELDS = array(
		'billing_email',
		'billing_first_name',
		'billing_last_name',
		'billing_address_1',
		'billing_city',
		'billing_postcode',
		'billing_phone',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_address_1',
		'shipping_city',
		'shipping_postcode',
		'shipping_phone',
	);

	/**
	 * Billing countries on the North American Numbering Plan.
	 */
	const NANP_COUNTRIES = array( 'US', 'CA', 'PR', 'VI', 'GU', 'AS', 'MP' );

	/**
	 * Compare the IPs WebRTC found in the browser with the IP the server saw.
	 *
	 * A browser behind a VPN or proxy usually still asks the STUN server for
	 * its address over plain UDP, outside the proxy, so a different public
	 * address there is the visitor's real connection showing through.
	 *
	 * @param string   $server_ip   IP the order came from.
	 * @param array    $server_info IP_Intel::lookup() for $server_ip.
	 * @param string[] $found       Public IPs the browser reported.
	 * @param callable $lookup      Returns IP_Intel::lookup() data for an IP.
	 * @return array{status:string,leak:string,leak_info:array}
	 *   status: none (nothing to compare), match, relay (iCloud Private
	 *   Relay), mobile_hop (same mobile carrier), mobile_mismatch (two
	 *   different carriers) or mismatch.
	 */
	public static function webrtc( $server_ip, array $server_info, array $found, $lookup ) {
		$result = array(
			'status'    => 'none',
			'leak'      => '',
			'leak_info' => array(),
		);

		$found = array_values( array_filter( $found, array( 'WooCommerce_Anti_Fraud_Request', 'is_public' ) ) );

		if ( '' === $server_ip || ! $found || ! WooCommerce_Anti_Fraud_Request::is_public( $server_ip ) ) {
			return $result;
		}

		if ( in_array( $server_ip, $found, true ) ) {
			$result['status'] = 'match';
			return $result;
		}

		// Compare like with like: an IPv6 order IP against the browser's IPv6
		// address. Dual-stack visitors often report only the other family.
		$v6   = false !== strpos( $server_ip, ':' );
		$same = array();
		foreach ( $found as $ip ) {
			if ( ( false !== strpos( $ip, ':' ) ) === $v6 ) {
				$same[] = $ip;
			}
		}

		$leak      = $same ? $same[0] : $found[0];
		$leak_info = call_user_func( $lookup, $leak );
		$leak_info = is_array( $leak_info ) ? $leak_info : WooCommerce_Anti_Fraud_IP_Intel::blank( $leak );

		$result['leak']      = $leak;
		$result['leak_info'] = $leak_info;

		$server_type = isset( $server_info['type'] ) ? $server_info['type'] : '';
		$leak_type   = isset( $leak_info['type'] ) ? $leak_info['type'] : '';
		$same_isp    = self::same_isp( $server_info, $leak_info );

		if ( ! $same ) {
			// Only the other IP family to go on: the same network operator on
			// both is an ordinary dual-stack connection.
			if ( true === $same_isp && ! in_array( $server_type, WooCommerce_Anti_Fraud_IP_Intel::RISKY_TYPES, true ) ) {
				$result['status'] = 'match';
			} elseif ( false === $same_isp ) {
				$result['status'] = in_array( 'CPN', array( $server_type, $leak_type ), true ) ? 'relay' : 'mismatch';
			}
			return $result;
		}

		// An anonymising network on either side is a mismatch even inside one block.
		if ( in_array( $server_type, WooCommerce_Anti_Fraud_IP_Intel::RISKY_TYPES, true ) || in_array( $leak_type, WooCommerce_Anti_Fraud_IP_Intel::RISKY_TYPES, true ) ) {
			$result['status'] = 'mismatch';
			return $result;
		}

		// iCloud Private Relay rotates its exit address by design.
		if ( 'CPN' === $server_type || 'CPN' === $leak_type ) {
			$result['status'] = 'relay';
			return $result;
		}

		// Phones hop between a carrier's addresses mid-session; switching carriers is a 4G proxy farm's signature.
		if ( 'MOB' === $server_type && 'MOB' === $leak_type ) {
			$result['status'] = false === $same_isp ? 'mobile_mismatch' : 'mobile_hop';
			return $result;
		}

		// The same provider block (and, when known, the same operator) is DHCP or NAT churn.
		if ( WooCommerce_Anti_Fraud_Request::same_network( $server_ip, $leak ) && false !== $same_isp ) {
			$result['status'] = 'match';
			return $result;
		}

		$result['status'] = 'mismatch';

		return $result;
	}

	/**
	 * Whether two IPs belong to the same network operator.
	 *
	 * @param array $a IP_Intel data.
	 * @param array $b IP_Intel data.
	 * @return bool|null Null when there's nothing to compare.
	 */
	public static function same_isp( array $a, array $b ) {
		if ( ! empty( $a['asn'] ) && ! empty( $b['asn'] ) ) {
			return strtoupper( $a['asn'] ) === strtoupper( $b['asn'] );
		}

		$name_a = strtolower( trim( ! empty( $a['carrier'] ) ? $a['carrier'] : ( isset( $a['org'] ) ? $a['org'] : '' ) ) );
		$name_b = strtolower( trim( ! empty( $b['carrier'] ) ? $b['carrier'] : ( isset( $b['org'] ) ? $b['org'] : '' ) ) );

		if ( '' === $name_a || '' === $name_b ) {
			return null;
		}

		return $name_a === $name_b;
	}

	/**
	 * Compare an IP's location with the order's billing address.
	 *
	 * @param array    $info  IP_Intel data.
	 * @param WC_Order $order Order.
	 * @return array{match:string,country:bool,region:bool,city:bool} match is
	 *               match, partial, mismatch or unknown; the booleans flag
	 *               which parts differ.
	 */
	public static function geo( array $info, $order ) {
		$result = array(
			'match'   => 'unknown',
			'country' => false,
			'region'  => false,
			'city'    => false,
		);

		$ip_country   = strtoupper( (string) ( isset( $info['country'] ) ? $info['country'] : '' ) );
		$bill_country = strtoupper( (string) $order->get_billing_country() );

		if ( '' === $ip_country || '' === $bill_country ) {
			return $result;
		}

		$checked = 1;
		$matched = 0;

		if ( $ip_country === $bill_country ) {
			++$matched;
		} else {
			$result['country'] = true;
		}

		$ip_region  = self::normalize( isset( $info['region'] ) ? $info['region'] : '' );
		$bill_state = (string) $order->get_billing_state();

		if ( ! $result['country'] && '' !== $ip_region && '' !== $bill_state ) {
			++$checked;
			$candidates = array( self::normalize( $bill_state ), self::normalize( self::state_name( $bill_country, $bill_state ) ) );
			if ( in_array( $ip_region, array_filter( $candidates ), true ) ) {
				++$matched;
			} else {
				$result['region'] = true;
			}
		}

		$ip_city   = self::normalize( isset( $info['city'] ) ? $info['city'] : '' );
		$bill_city = self::normalize( (string) $order->get_billing_city() );

		if ( ! $result['country'] && '' !== $ip_city && '' !== $bill_city ) {
			++$checked;
			if ( $ip_city === $bill_city ) {
				++$matched;
			} else {
				$result['city'] = true;
			}
		}

		if ( $matched === $checked ) {
			$result['match'] = 'match';
		} elseif ( $matched > 0 ) {
			$result['match'] = 'partial';
		} else {
			$result['match'] = 'mismatch';
		}

		return $result;
	}

	/**
	 * Compare the browser's clock with the timezone of the IP's location.
	 * Residential proxies sell US addresses to browsers whose clocks are
	 * set somewhere else entirely.
	 *
	 * @param string   $browser_tz     IANA zone the browser reports, e.g. Europe/Berlin.
	 * @param int|null $browser_offset JavaScript getTimezoneOffset() in minutes (UTC minus local).
	 * @param string   $ip_tz          IANA zone of the IP's location.
	 * @return array{match:string,browser:int|null,ip:int|null} match is
	 *               match, mismatch or unknown; offsets are minutes east of UTC.
	 */
	public static function timezone( $browser_tz, $browser_offset, $ip_tz ) {
		$result = array(
			'match'   => 'unknown',
			'browser' => null,
			'ip'      => null,
		);

		$ip_offset = self::zone_offset( $ip_tz );
		if ( null === $ip_offset ) {
			return $result;
		}

		$local = self::zone_offset( $browser_tz );
		if ( null === $local && null !== $browser_offset && is_numeric( $browser_offset ) ) {
			$local = -1 * (int) $browser_offset;
		}
		if ( null === $local ) {
			return $result;
		}

		$result['browser'] = $local;
		$result['ip']      = $ip_offset;
		$result['match']   = abs( $local - $ip_offset ) >= 60 ? 'mismatch' : 'match';

		return $result;
	}

	/**
	 * Current UTC offset of an IANA timezone, in minutes.
	 *
	 * @param string $zone Timezone name.
	 * @return int|null
	 */
	private static function zone_offset( $zone ) {
		$zone = (string) $zone;

		if ( '' === $zone || ! preg_match( '#^[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+){0,2}$#', $zone ) ) {
			return null;
		}

		try {
			$tz = new DateTimeZone( $zone );
		} catch ( \Exception $e ) {
			return null;
		}

		return (int) ( $tz->getOffset( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) ) / 60 );
	}

	/**
	 * Compare a North American phone number's area code with the billing
	 * state. Unknown, toll-free and foreign numbers are "unknown", never a
	 * mismatch. People keep their numbers when they move, so this is a weak
	 * signal on its own.
	 *
	 * @param string $phone   Billing phone.
	 * @param string $country Billing country.
	 * @param string $state   Billing state code.
	 * @return array{match:string,area_code:string,phone_state:string}
	 */
	public static function phone( $phone, $country, $state ) {
		$result = array(
			'match'       => 'unknown',
			'area_code'   => '',
			'phone_state' => '',
		);

		$country = strtoupper( (string) $country );
		if ( ! in_array( $country, self::NANP_COUNTRIES, true ) ) {
			return $result;
		}

		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		if ( 10 !== strlen( $digits ) || in_array( $digits[0], array( '0', '1' ), true ) ) {
			return $result;
		}

		$code = substr( $digits, 0, 3 );
		$map  = self::area_codes();

		$result['area_code'] = $code;

		if ( ! isset( $map[ $code ] ) ) {
			return $result;
		}

		$states                = (array) $map[ $code ];
		$result['phone_state'] = implode( '/', $states );
		$targets               = array_filter( array( strtoupper( trim( (string) $state ) ), $country ) );
		$result['match']       = array_intersect( $states, $targets ) ? 'match' : 'mismatch';

		return $result;
	}

	/**
	 * How many of the identity and address fields were pasted.
	 *
	 * @param array<string,string> $fields Field => typed, pasted or autofill.
	 * @return int
	 */
	public static function paste_count( array $fields ) {
		$count = 0;

		foreach ( $fields as $field => $method ) {
			if ( 'pasted' === $method && in_array( $field, self::PASTE_FIELDS, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Desktop, Mobile or Tablet from a user agent ('' when there isn't one).
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function device_type( $ua ) {
		$ua = (string) $ua;

		if ( '' === trim( $ua ) ) {
			return '';
		}
		if ( preg_match( '/ipad|tablet|kindle|silk|playbook/i', $ua ) || ( false !== stripos( $ua, 'android' ) && false === stripos( $ua, 'mobile' ) ) ) {
			return 'Tablet';
		}
		if ( preg_match( '/mobi|iphone|ipod|android|blackberry|opera mini|iemobile|windows phone/i', $ua ) ) {
			return 'Mobile';
		}

		return 'Desktop';
	}

	/**
	 * Lowercase letters and digits only, accents removed.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function normalize( $value ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( trim( (string) $value ) ) ) );
	}

	/**
	 * WooCommerce's full name for a state code ("CA" => "California").
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 * @return string
	 */
	private static function state_name( $country, $state ) {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}

		$states = WC()->countries->get_states( $country );

		return is_array( $states ) && isset( $states[ $state ] ) ? html_entity_decode( (string) $states[ $state ], ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * North American area code => state or province code. Toll-free and
	 * non-geographic codes are left out on purpose.
	 *
	 * @return array<string,string|string[]>
	 */
	private static function area_codes() {
		$groups = array(
			'AL' => '205 251 256 334 659 938',
			'AK' => '907',
			'AZ' => '480 520 602 623 928',
			'AR' => '479 501 870',
			'CA' => '209 213 279 310 323 341 350 369 408 415 424 442 510 530 559 562 619 626 628 650 657 661 669 707 714 738 747 760 805 818 820 831 840 858 909 916 925 949 951',
			'CO' => '303 719 720 748 970 983',
			'CT' => '203 475 860 959',
			'DE' => '302',
			'DC' => '202 771',
			'FL' => '239 305 321 324 352 386 407 448 561 645 656 689 727 728 754 772 786 813 850 863 904 941 954',
			'GA' => '229 404 470 478 678 706 762 770 912 943',
			'HI' => '808',
			'ID' => '208 986',
			'IL' => '217 224 309 312 331 447 464 618 630 708 730 773 779 815 847 861 872',
			'IN' => '219 260 317 463 574 765 812 930',
			'IA' => '319 515 563 641 712',
			'KS' => '316 620 785 913',
			'KY' => '270 364 502 606 859',
			'LA' => '225 318 337 504 985',
			'ME' => '207',
			'MD' => '227 240 301 410 443 667',
			'MA' => '339 351 413 508 617 774 781 857 978',
			'MI' => '231 248 269 313 517 586 616 679 734 810 906 947 989',
			'MN' => '218 320 507 612 651 763 924 952',
			'MS' => '228 601 662 769',
			'MO' => '235 314 417 557 573 636 660 816',
			'MT' => '406',
			'NE' => '308 402 531',
			'NV' => '702 725 775',
			'NH' => '603',
			'NJ' => '201 551 609 640 732 848 856 862 908 973',
			'NM' => '505 575',
			'NY' => '212 315 329 332 347 363 516 518 585 607 624 631 646 680 716 718 838 845 914 917 929 934',
			'NC' => '252 336 472 704 743 828 910 919 980 984',
			'ND' => '701',
			'OH' => '216 220 234 283 326 330 380 419 436 440 513 567 614 740 937',
			'OK' => '405 539 572 580 918',
			'OR' => '458 503 541 971',
			'PA' => '215 223 267 272 412 445 484 570 582 610 717 724 814 835 878',
			'RI' => '401',
			'SC' => '803 821 839 843 854 864',
			'SD' => '605',
			'TN' => '423 615 629 731 865 901 931',
			'TX' => '210 214 254 281 325 346 361 409 430 432 469 512 682 713 726 737 806 817 830 832 903 915 936 940 945 956 972 979',
			'UT' => '385 435 801',
			'VT' => '802',
			'VA' => '276 434 540 571 686 703 757 804 826 948',
			'WA' => '206 253 360 425 509 564',
			'WV' => '304 681',
			'WI' => '262 274 353 414 534 608 715 920',
			'WY' => '307',
			'PR' => '787 939',
			'VI' => '340',
			'GU' => '671',
			'AS' => '684',
			'MP' => '670',
			'MB' => '204 431 584',
			'ON' => '226 249 289 343 365 382 416 437 519 548 613 647 683 705 742 753 807 905 942',
			'BC' => '236 250 257 604 672 778',
			'QC' => '263 354 367 418 438 450 468 514 579 581 819 873',
			'SK' => '306 474 639',
			'AB' => '368 403 587 780 825',
			'NB' => '428 506',
			'NL' => '709 879',
		);

		$map = array();
		foreach ( $groups as $state => $codes ) {
			foreach ( explode( ' ', $codes ) as $code ) {
				$map[ $code ] = $state;
			}
		}

		// Codes shared by more than one province or territory.
		$map['782'] = array( 'NS', 'PE' );
		$map['902'] = array( 'NS', 'PE' );
		$map['867'] = array( 'NT', 'NU', 'YT' );

		return $map;
	}
}
