/**
 * WooCommerce Anti Fraud: browser checks on the checkout and pay-for-order pages.
 *
 * - Device fingerprint: screen, graphics, fonts, audio and canvas traits,
 *   plus a random ID kept in localStorage. Sent as short hashes and labels.
 * - WebRTC: asks a STUN server for the browser's public IPs, which can show
 *   the real connection behind a VPN or proxy.
 * - Field entry: whether each checkout field was typed, pasted or
 *   autofilled. Never the values.
 * - The IP this browser had on its last checkout visit, and the timezone.
 *
 * Everything rides along with the order: a hidden field on the classic
 * checkout and pay-for-order forms, Store API extension data on the block
 * checkout. Settings come from PHP (WooCommerce_Anti_Fraud_Collector::enqueue).
 */

/* global wcafCollector */
( function () {
	'use strict';

	var cfg = window.wcafCollector;

	if ( ! cfg || ! window.JSON || ! document.querySelectorAll ) {
		return;
	}

	var checks = cfg.checks || {};
	var started = Date.now();
	var payload = { v: 1, t: cfg.token || '', tzo: new Date().getTimezoneOffset() };
	var timer = null;

	// Logical field names the server accepts (see WooCommerce_Anti_Fraud_Checks::FIELDS).
	var FIELDS = [
		'billing_email', 'billing_first_name', 'billing_last_name', 'billing_company',
		'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
		'billing_postcode', 'billing_phone', 'shipping_first_name', 'shipping_last_name',
		'shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_city',
		'shipping_state', 'shipping_postcode', 'shipping_phone', 'order_comments'
	];
	var RANK = { t: 1, a: 2, p: 3 };

	function safe( fn, fallback ) {
		try {
			return fn();
		} catch ( e ) {
			return fallback;
		}
	}

	// cyrb53: a fast 53-bit string hash (public domain), as 14 hex characters.
	function hash( str ) {
		var h1 = 0xdeadbeef;
		var h2 = 0x41c6ce57;
		for ( var i = 0; i < str.length; i++ ) {
			var ch = str.charCodeAt( i );
			h1 = Math.imul( h1 ^ ch, 2654435761 );
			h2 = Math.imul( h2 ^ ch, 1597334677 );
		}
		h1 = Math.imul( h1 ^ ( h1 >>> 16 ), 2246822507 ) ^ Math.imul( h2 ^ ( h2 >>> 13 ), 3266489909 );
		h2 = Math.imul( h2 ^ ( h2 >>> 16 ), 2246822507 ) ^ Math.imul( h1 ^ ( h1 >>> 13 ), 3266489909 );
		var n = 4294967296 * ( 2097151 & h2 ) + ( h1 >>> 0 );
		return ( '00000000000000' + n.toString( 16 ) ).slice( -14 );
	}

	var store = safe( function () {
		var s = window.localStorage;
		s.setItem( 'wcaf_test', '1' );
		s.removeItem( 'wcaf_test' );
		return s;
	}, null );

	payload.tz = safe( function () {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	}, '' );

	// The IP from this browser's last checkout visit, then remember this one.
	if ( store ) {
		var prev = safe( function () {
			return JSON.parse( store.getItem( 'wcaf_ip' ) || 'null' );
		}, null );
		if ( prev && typeof prev.ip === 'string' && typeof prev.t === 'number' ) {
			payload.li = prev.ip;
			payload.la = Math.max( 0, Math.round( ( Date.now() - prev.t ) / 1000 ) );
		}
		if ( cfg.ip ) {
			safe( function () {
				store.setItem( 'wcaf_ip', JSON.stringify( { ip: cfg.ip, t: Date.now() } ) );
			} );
		}
	}

	/* Delivery ---------------------------------------------------------------- */

	function deliver() {
		payload.e = Math.round( ( Date.now() - started ) / 1000 );

		var json = JSON.stringify( payload );

		// Classic checkout and pay-for-order: a hidden field in the form.
		var forms = document.querySelectorAll( 'form.checkout, form#order_review' );
		for ( var i = 0; i < forms.length; i++ ) {
			var input = forms[ i ].querySelector( 'input[name="' + cfg.field + '"]' );
			if ( ! input ) {
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = cfg.field;
				forms[ i ].appendChild( input );
			}
			input.value = json;
		}

		// Block checkout: extension data on the Store API checkout request.
		if ( document.querySelector( '.wp-block-woocommerce-checkout, .wc-block-checkout' ) ) {
			safe( function () {
				var checkout = window.wp && window.wp.data && window.wp.data.dispatch( 'wc/store/checkout' );
				if ( checkout && typeof checkout.setExtensionData === 'function' ) {
					checkout.setExtensionData( cfg.ns, { payload: json } );
				} else if ( checkout && typeof checkout.__internalSetExtensionData === 'function' ) {
					checkout.__internalSetExtensionData( cfg.ns, { payload: json } );
				}
			} );
		}
	}

	function deliverSoon() {
		clearTimeout( timer );
		timer = setTimeout( deliver, 250 );
	}

	/* Device fingerprint ------------------------------------------------------ */

	function deviceId() {
		if ( ! store ) {
			return '';
		}
		var id = safe( function () {
			return store.getItem( 'wcaf_id' );
		}, '' );
		if ( id && /^[a-f0-9]{32}$/.test( id ) ) {
			return id;
		}
		var bytes = new Uint8Array( 16 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var i = 0; i < 16; i++ ) {
				bytes[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		id = '';
		for ( var j = 0; j < 16; j++ ) {
			id += ( '0' + bytes[ j ].toString( 16 ) ).slice( -2 );
		}
		safe( function () {
			store.setItem( 'wcaf_id', id );
		} );
		return id;
	}

	function canvasTrait() {
		return safe( function () {
			var c = document.createElement( 'canvas' );
			c.width = 240;
			c.height = 60;
			var ctx = c.getContext( '2d' );
			ctx.textBaseline = 'top';
			ctx.font = '16px Arial';
			ctx.fillStyle = '#f60';
			ctx.fillRect( 10, 10, 100, 30 );
			ctx.fillStyle = '#069';
			ctx.fillText( 'Wcaf ⚡ 7µ éß', 12, 14 );
			ctx.strokeStyle = 'rgba(0,80,160,0.6)';
			ctx.beginPath();
			ctx.arc( 60, 30, 20, 0, Math.PI * 2 );
			ctx.stroke();
			return hash( c.toDataURL() );
		}, '' );
	}

	function webglTrait() {
		return safe( function () {
			var c = document.createElement( 'canvas' );
			var gl = c.getContext( 'webgl' ) || c.getContext( 'experimental-webgl' );
			if ( ! gl ) {
				return '';
			}
			var info = gl.getExtension( 'WEBGL_debug_renderer_info' );
			var vendor = info ? gl.getParameter( info.UNMASKED_VENDOR_WEBGL ) : gl.getParameter( gl.VENDOR );
			var renderer = info ? gl.getParameter( info.UNMASKED_RENDERER_WEBGL ) : gl.getParameter( gl.RENDERER );
			return String( vendor + ' ~ ' + renderer ).slice( 0, 150 );
		}, '' );
	}

	function fontsTrait() {
		return safe( function () {
			var base = [ 'monospace', 'sans-serif', 'serif' ];
			var test = [ 'Arial', 'Calibri', 'Cambria', 'Comic Sans MS', 'Courier New', 'Georgia', 'Helvetica', 'Helvetica Neue', 'Segoe UI', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Roboto', 'Ubuntu', 'San Francisco', 'Menlo', 'Consolas', 'Lucida Grande', 'Noto Sans' ];
			var span = document.createElement( 'span' );
			span.style.cssText = 'position:absolute;left:-9999px;top:0;font-size:72px;visibility:hidden';
			span.textContent = 'mmmmmmmmmmlli';
			document.body.appendChild( span );
			var widths = {};
			base.forEach( function ( b ) {
				span.style.fontFamily = b;
				widths[ b ] = span.offsetWidth + 'x' + span.offsetHeight;
			} );
			var found = test.filter( function ( font ) {
				return base.some( function ( b ) {
					span.style.fontFamily = "'" + font + "'," + b;
					return span.offsetWidth + 'x' + span.offsetHeight !== widths[ b ];
				} );
			} );
			document.body.removeChild( span );
			return found.join( ',' );
		}, '' );
	}

	function audioTrait() {
		return new Promise( function ( resolve ) {
			var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
			if ( ! Ctx ) {
				resolve( '' );
				return;
			}
			var done = false;
			var finish = function ( value ) {
				if ( ! done ) {
					done = true;
					resolve( value );
				}
			};
			setTimeout( function () {
				finish( '' );
			}, 1000 );
			safe( function () {
				var ctx = new Ctx( 1, 44100, 44100 );
				var osc = ctx.createOscillator();
				var comp = ctx.createDynamicsCompressor();
				osc.type = 'triangle';
				osc.frequency.value = 10000;
				osc.connect( comp );
				comp.connect( ctx.destination );
				osc.start( 0 );
				ctx.oncomplete = function ( event ) {
					var data = event.renderedBuffer.getChannelData( 0 );
					var sum = 0;
					for ( var i = 4500; i < 5000; i++ ) {
						sum += Math.abs( data[ i ] );
					}
					finish( sum.toFixed( 6 ) );
				};
				var rendering = ctx.startRendering();
				if ( rendering && rendering.catch ) {
					rendering.catch( function () {
						finish( '' );
					} );
				}
			} );
		} );
	}

	function fingerprint() {
		var nav = window.navigator || {};
		var scr = window.screen || {};

		payload.d = deviceId();
		payload.fp = {
			c: canvasTrait(),
			g: webglTrait(),
			f: fontsTrait(),
			s: ( scr.width || 0 ) + 'x' + ( scr.height || 0 ),
			cd: String( scr.colorDepth || '' ),
			pr: String( Math.round( ( window.devicePixelRatio || 1 ) * 100 ) / 100 ),
			l: String( nav.language || '' ),
			ls: ( nav.languages || [] ).slice( 0, 5 ).join( ',' ),
			p: String( ( nav.userAgentData && nav.userAgentData.platform ) || nav.platform || '' ),
			hc: String( nav.hardwareConcurrency || '' ),
			dm: String( nav.deviceMemory || '' ),
			mt: String( nav.maxTouchPoints || 0 ),
			tz: payload.tz
		};

		return audioTrait().then( function ( audio ) {
			payload.fp.a = audio;
		} );
	}

	/* WebRTC ----------------------------------------------------------------- */

	function isPrivate( ip ) {
		if ( ip.indexOf( ':' ) !== -1 ) {
			return /^(::1$|fe[89ab]|f[cd])/i.test( ip );
		}
		var p = ip.split( '.' ).map( Number );
		return p.length !== 4 || p[ 0 ] === 10 || p[ 0 ] === 127 || p[ 0 ] === 0 || p[ 0 ] >= 224 ||
			( p[ 0 ] === 192 && p[ 1 ] === 168 ) || ( p[ 0 ] === 172 && p[ 1 ] >= 16 && p[ 1 ] <= 31 ) ||
			( p[ 0 ] === 169 && p[ 1 ] === 254 ) || ( p[ 0 ] === 100 && p[ 1 ] >= 64 && p[ 1 ] <= 127 );
	}

	function webrtc() {
		var RTC = window.RTCPeerConnection || window.webkitRTCPeerConnection || window.mozRTCPeerConnection;
		var result = { on: 1, ok: RTC ? 1 : 0, ips: [] };

		payload.r = result;

		if ( ! RTC ) {
			return Promise.resolve();
		}

		return new Promise( function ( resolve ) {
			var pc = null;
			var finish = function () {
				safe( function () {
					pc.close();
				} );
				resolve();
			};

			var add = function ( candidate ) {
				var address = candidate.address || candidate.ip || '';
				if ( ! address && candidate.candidate ) {
					address = String( candidate.candidate ).split( ' ' )[ 4 ] || '';
				}
				address = String( address ).replace( /^\[|\]$/g, '' );
				if ( ! address || address.indexOf( '.local' ) !== -1 || ! /^[0-9a-f.:]+$/i.test( address ) || isPrivate( address ) ) {
					return;
				}
				if ( result.ips.indexOf( address ) === -1 && result.ips.length < 8 ) {
					result.ips.push( address );
					deliverSoon();
				}
			};

			safe( function () {
				pc = new RTC( { iceServers: [ { urls: cfg.stun } ] } );
				pc.createDataChannel( 'wcaf' );
				pc.onicecandidate = function ( event ) {
					if ( event && event.candidate ) {
						add( event.candidate );
					} else if ( event && ! event.candidate ) {
						finish();
					}
				};
				pc.createOffer()
					.then( function ( offer ) {
						return pc.setLocalDescription( offer );
					} )
					.catch( finish );
			} );

			setTimeout( finish, 2500 );
		} );
	}

	/* Field entry ------------------------------------------------------------ */

	function fieldKey( el ) {
		if ( ! el || ! el.tagName ) {
			return '';
		}
		var tag = el.tagName.toLowerCase();
		var type = String( el.type || 'text' ).toLowerCase();
		if ( ( tag !== 'input' && tag !== 'textarea' ) || [ 'password', 'hidden', 'checkbox', 'radio', 'submit', 'button', 'file', 'image', 'range', 'color' ].indexOf( type ) !== -1 ) {
			return '';
		}
		// Classic checkout uses names like billing_email; the block checkout
		// uses ids like email, billing-first_name and shipping-address_1.
		var key = String( el.name || el.id || '' ).toLowerCase().replace( /-/g, '_' );
		if ( key === 'email' || key === 'contact_email' ) {
			key = 'billing_email';
		}
		return FIELDS.indexOf( key ) !== -1 ? key : '';
	}

	function mark( el, method ) {
		var key = fieldKey( el );
		if ( ! key ) {
			return;
		}
		payload.f = payload.f || {};
		var current = payload.f[ key ];
		// A paste beats autofill, and both beat typing over them afterwards.
		if ( ! current || RANK[ method ] > RANK[ current ] ) {
			payload.f[ key ] = method;
			deliverSoon();
		}
	}

	function watchFields() {
		document.addEventListener( 'paste', function ( event ) {
			mark( event.target, 'p' );
		}, true );

		document.addEventListener( 'drop', function ( event ) {
			mark( event.target, 'p' );
		}, true );

		document.addEventListener( 'input', function ( event ) {
			var type = event.inputType;
			if ( type === 'insertFromPaste' || type === 'insertFromDrop' || type === 'insertFromPasteAsQuotation' ) {
				mark( event.target, 'p' );
			} else if ( type === 'insertReplacementText' || ! type ) {
				// Autofill and password managers fill fields without a typing event.
				mark( event.target, 'a' );
			} else if ( type.indexOf( 'insert' ) === 0 ) {
				mark( event.target, 't' );
			}
		}, true );

		// Chrome and Safari autofill, spotted through a CSS animation.
		document.addEventListener( 'animationstart', function ( event ) {
			if ( event.animationName === 'wcafAutofill' ) {
				mark( event.target, 'a' );
			}
		}, true );

		var style = document.createElement( 'style' );
		style.textContent = '@keyframes wcafAutofill{from{opacity:1}to{opacity:1}}input:-webkit-autofill{animation-name:wcafAutofill;animation-duration:.001s}';
		( document.head || document.documentElement ).appendChild( style );
	}

	/* Start -------------------------------------------------------------------- */

	function start() {
		var waits = [];

		if ( checks.paste ) {
			watchFields();
		}
		if ( checks.fingerprint ) {
			waits.push( fingerprint() );
		}
		if ( checks.webrtc ) {
			waits.push( webrtc() );
		}

		deliver();

		Promise.all( waits ).then( deliver, deliver );

		// Last update right before the order goes: classic form submit, or the
		// block checkout's Place Order button.
		document.addEventListener( 'submit', function ( event ) {
			if ( event.target && event.target.matches && event.target.matches( 'form.checkout, form#order_review' ) ) {
				deliver();
			}
		}, true );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target && event.target.closest && event.target.closest( '.wc-block-components-checkout-place-order-button, #place_order' ) ) {
				deliver();
			}
		}, true );

		// The classic checkout rebuilds parts of the form on every update.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', deliverSoon );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
