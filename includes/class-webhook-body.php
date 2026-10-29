<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Webhook_Body {

	const ROUTE_FRAGMENT = '/wp-json/avia/v1/boulevard-webhook';

	/**
	 * Boulevard header names by role. v2026-06 M2M apps use x-blvd-oauth-hmac-* ;
	 * legacy apps use x-blvd-hmac-*.
	 *
	 * @var array<string, array<string, string>>
	 */
	const HMAC_HEADER_ALIASES = array(
		'salt' => array(
			'x-blvd-hmac-salt'       => 'HTTP_X_BLVD_HMAC_SALT',
			'x-blvd-oauth-hmac-salt' => 'HTTP_X_BLVD_OAUTH_HMAC_SALT',
		),
		'signature' => array(
			'x-blvd-hmac-sha256'       => 'HTTP_X_BLVD_HMAC_SHA256',
			'x-blvd-oauth-hmac-sha256' => 'HTTP_X_BLVD_OAUTH_HMAC_SHA256',
		),
	);

	/** @var string|null */
	private static $raw_body = null;

	/** @var bool */
	private static $captured = false;

	/** @var bool */
	private static $headers_hydrated = false;

	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'capture_on_rest_dispatch' ), 0, 3 );
	}

	public static function maybe_capture_early() {
		if ( ! self::is_webhook_request() ) {
			return;
		}

		self::ensure_hmac_headers_in_server();

		if ( self::$captured ) {
			return;
		}

		$raw = file_get_contents( 'php://input' );
		if ( false !== $raw ) {
			self::$raw_body = $raw;
			self::$captured = true;
		}
	}

	public static function capture_on_rest_dispatch( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( '/avia/v1/boulevard-webhook' !== $request->get_route() ) {
			return $result;
		}

		self::ensure_hmac_headers_in_server();
		self::patch_request_headers( $request );
		self::capture_from_request( $request );

		return $result;
	}

	public static function capture_from_request( WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( '' !== $body ) {
			self::$raw_body = $body;
			self::$captured = true;
			return;
		}

		if ( self::$captured ) {
			return;
		}

		$raw = file_get_contents( 'php://input' );
		if ( false !== $raw && '' !== $raw ) {
			self::$raw_body = $raw;
			self::$captured = true;
		}
	}

	public static function reset_capture() {
		self::$raw_body         = null;
		self::$captured         = false;
		self::$headers_hydrated = false;
	}

	public static function get_raw_body( $fallback = '' ) {
		if ( null !== self::$raw_body && '' !== self::$raw_body ) {
			return self::$raw_body;
		}

		return $fallback;
	}

	public static function ensure_hmac_headers_in_server() {
		if ( self::$headers_hydrated || ! self::is_webhook_request() ) {
			return;
		}

		foreach ( self::HMAC_HEADER_ALIASES as $aliases ) {
			foreach ( $aliases as $header_name => $server_key ) {
				if ( ! empty( $_SERVER[ $server_key ] ) ) {
					continue;
				}

				if ( ! empty( $_SERVER[ 'REDIRECT_' . $server_key ] ) ) {
					$_SERVER[ $server_key ] = wp_unslash( $_SERVER[ 'REDIRECT_' . $server_key ] );
					continue;
				}

				$value = self::lookup_raw_header( $header_name );
				if ( '' !== $value ) {
					$_SERVER[ $server_key ] = $value;
				}
			}
		}

		self::$headers_hydrated = true;
	}

	public static function patch_request_headers( WP_REST_Request $request ) {
		self::ensure_hmac_headers_in_server();

		foreach ( self::HMAC_HEADER_ALIASES as $role => $aliases ) {
			$canonical = array_key_first( $aliases );
			if ( $request->get_header( $canonical ) ) {
				continue;
			}

			$value = self::get_hmac_value( $role, $request );
			if ( '' !== $value ) {
				$request->set_header( $canonical, $value );
			}
		}
	}

	public static function get_hmac_value( $role, ?WP_REST_Request $request = null ) {
		self::ensure_hmac_headers_in_server();

		$aliases = self::HMAC_HEADER_ALIASES[ $role ] ?? array();
		foreach ( $aliases as $header_name => $server_key ) {
			if ( $request instanceof WP_REST_Request ) {
				$value = $request->get_header( $header_name );
				if ( $value ) {
					return trim( (string) $value );
				}
			}

			$value = self::read_header_value( $header_name, $server_key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	public static function get_detected_hmac_header_keys() {
		$found = array();

		foreach ( self::HMAC_HEADER_ALIASES as $aliases ) {
			foreach ( array_keys( $aliases ) as $header_name ) {
				if ( '' !== self::read_header_value( $header_name, $aliases[ $header_name ] ) ) {
					$found[] = $header_name;
				}
			}
		}

		foreach ( self::get_raw_headers() as $key => $value ) {
			$key_lower = strtolower( (string) $key );
			if ( false !== strpos( $key_lower, 'blvd' ) && false !== strpos( $key_lower, 'hmac' ) ) {
				$found[] = $key_lower;
			}
		}

		foreach ( array_keys( $_SERVER ) as $key ) {
			if ( 0 === strpos( $key, 'HTTP_' ) && false !== stripos( $key, 'BLVD' ) && false !== stripos( $key, 'HMAC' ) ) {
				$found[] = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
			}
		}

		return array_values( array_unique( $found ) );
	}

	private static function read_header_value( $header_name, $server_key ) {
		if ( ! empty( $_SERVER[ $server_key ] ) ) {
			return trim( (string) wp_unslash( $_SERVER[ $server_key ] ) );
		}

		if ( ! empty( $_SERVER[ 'REDIRECT_' . $server_key ] ) ) {
			return trim( (string) wp_unslash( $_SERVER[ 'REDIRECT_' . $server_key ] ) );
		}

		return self::lookup_raw_header( $header_name );
	}

	private static function lookup_raw_header( $name ) {
		$needle = strtolower( trim( (string) $name ) );

		foreach ( self::get_raw_headers() as $key => $value ) {
			if ( strtolower( (string) $key ) === $needle && '' !== (string) $value ) {
				return trim( (string) wp_unslash( $value ) );
			}
		}

		return '';
	}

	private static function get_raw_headers() {
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}

		return array();
	}

	public static function is_webhook_request() {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return false;
		}

		$uri = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		return false !== strpos( $uri, self::ROUTE_FRAGMENT );
	}
}
