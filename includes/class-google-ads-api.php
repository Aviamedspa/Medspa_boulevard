<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Google_Ads_Api {

	const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const API_VERSION     = 'v26';
	const OAUTH_SCOPES    = 'https://www.googleapis.com/auth/adwords https://www.googleapis.com/auth/datamanager';

	public static function has_read_credentials() {
		$settings = Avia_Attribution_Settings::get_all();

		return ! empty( $settings['google_ads_client_id'] )
			&& ! empty( $settings['google_ads_client_secret'] )
			&& ! empty( $settings['google_ads_refresh_token'] )
			&& ! empty( $settings['google_ads_developer_token'] );
	}

	public static function has_upload_credentials() {
		$settings = Avia_Attribution_Settings::get_all();

		return ! empty( $settings['google_ads_customer_id'] )
			&& ! empty( $settings['google_ads_developer_token'] )
			&& ! empty( $settings['google_ads_client_id'] )
			&& ! empty( $settings['google_ads_client_secret'] )
			&& ! empty( $settings['google_ads_refresh_token'] )
			&& ! empty( $settings['google_ads_conversion_action_id'] );
	}

	public static function get_access_token_for_api() {
		return self::get_access_token();
	}

	public static function get_conversion_action_resource() {
		$settings    = Avia_Attribution_Settings::get_all();
		$customer_id = self::normalize_customer_id( $settings['google_ads_customer_id'] ?? '' );
		$action_id   = preg_replace( '/\D+/', '', (string) ( $settings['google_ads_conversion_action_id'] ?? '' ) );

		if ( '' === $customer_id || '' === $action_id ) {
			return '';
		}

		return sprintf( 'customers/%s/conversionActions/%s', $customer_id, $action_id );
	}

	public static function upload_click_conversion( array $conversion ) {
		if ( ! self::has_upload_credentials() ) {
			return new WP_Error( 'avia_google_ads_missing_credentials', 'Google Ads API credentials are not configured.' );
		}

		$customer_id = self::normalize_customer_id( Avia_Attribution_Settings::get( 'google_ads_customer_id' ) );
		$token       = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$click_field = self::resolve_click_field( $conversion );
		if ( is_wp_error( $click_field ) ) {
			return $click_field;
		}

		$conversion_action = self::get_conversion_action_resource();
		if ( '' === $conversion_action ) {
			return new WP_Error( 'avia_google_ads_invalid_action', 'Google Ads conversion action is not configured.' );
		}

		$payload = array(
			'conversionAction'   => $conversion_action,
			'conversionDateTime' => $conversion['conversion_datetime'],
		);

		if ( isset( $conversion['conversion_value'] ) && null !== $conversion['conversion_value'] ) {
			$payload['conversionValue'] = (float) $conversion['conversion_value'];
			$payload['currencyCode']    = $conversion['currency_code'] ?? 'USD';
		}

		$payload[ $click_field['key'] ] = $click_field['value'];

		$body = wp_json_encode(
			array(
				'conversions'    => array( $payload ),
				'partialFailure' => true,
			)
		);

		$url = sprintf(
			'https://googleads.googleapis.com/%s/customers/%s:uploadClickConversions',
			self::API_VERSION,
			rawurlencode( $customer_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => self::build_api_headers( $token ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'avia_google_ads_http_error',
				self::format_api_error( $code, $raw, $data )
			);
		}

		if ( ! empty( $data['partialFailureError'] ) ) {
			$message = is_array( $data['partialFailureError'] )
				? wp_json_encode( $data['partialFailureError'] )
				: (string) $data['partialFailureError'];

			return new WP_Error( 'avia_google_ads_upload_failed', $message );
		}

		return $data;
	}

	private static function resolve_click_field( array $conversion ) {
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $key ) {
			if ( empty( $conversion[ $key ] ) ) {
				continue;
			}
			$value = (string) $conversion[ $key ];
			if ( ! Avia_Attribution_Ads_Integrity::is_valid_click_id( $value, $key ) ) {
				continue;
			}
			return array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return new WP_Error( 'avia_google_ads_no_click_id', 'No valid gclid, gbraid, or wbraid available for upload.' );
	}

	private static function get_access_token() {
		$cached = get_transient( 'avia_google_ads_access_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$settings = Avia_Attribution_Settings::get_all();
		if ( empty( $settings['google_ads_refresh_token'] ) ) {
			return new WP_Error(
				'avia_google_ads_missing_refresh',
				'OAuth refresh token is missing. Use Reconnect Google Ads in Settings to authorize again.'
			);
		}

		$response = wp_remote_post(
			self::OAUTH_TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $settings['google_ads_client_id'],
					'client_secret' => $settings['google_ads_client_secret'],
					'refresh_token' => $settings['google_ads_refresh_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			self::clear_token_cache();
			return new WP_Error(
				'avia_google_ads_auth_failed',
				self::format_oauth_token_error( $data, $raw )
			);
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$ttl        = max( 300, $expires_in - 120 );
		set_transient( 'avia_google_ads_access_token', $data['access_token'], $ttl );

		return $data['access_token'];
	}

	/**
	 * Explain which Google credential failed. Access tokens last ~1h; refresh tokens are long-lived but not permanent.
	 */
	private static function format_oauth_token_error( $data, $raw = '' ) {
		$error            = is_array( $data ) ? (string) ( $data['error'] ?? '' ) : '';
		$error_description = is_array( $data ) && ! empty( $data['error_description'] )
			? (string) $data['error_description']
			: '';
		$combined = strtolower( $error . ' ' . $error_description . ' ' . (string) $raw );

		if (
			false !== strpos( $combined, 'expired' )
			|| false !== strpos( $combined, 'revoked' )
			|| false !== strpos( $combined, 'invalid_grant' )
		) {
			return 'Google Ads OAuth refresh token has expired or been revoked. Click Reconnect Google Ads in Settings to authorize again.';
		}

		if ( '' !== $error_description ) {
			return 'Google OAuth error: ' . $error_description;
		}

		if ( '' !== $error ) {
			return 'Google OAuth error: ' . $error;
		}

		return 'Could not refresh Google Ads access token. ' . substr( (string) $raw, 0, 300 );
	}

	public static function clear_token_cache() {
		delete_transient( 'avia_google_ads_access_token' );
	}

	/**
	 * Whether an API/OAuth error means the admin should reconnect (refresh token expired/revoked/missing).
	 */
	public static function error_needs_reconnect( $error ) {
		if ( is_wp_error( $error ) ) {
			$code = $error->get_error_code();
			if ( in_array( $code, array( 'avia_google_ads_auth_failed', 'avia_google_ads_missing_refresh' ), true ) ) {
				return true;
			}
			$message = $error->get_error_message();
		} else {
			$message = (string) $error;
		}

		$haystack = strtolower( $message );
		$needles  = array(
			'expired',
			'revoked',
			'invalid_grant',
			'refresh token',
			'reconnect google ads',
			'oauth refresh token',
			'unauthorized',
			'unauthenticated',
			'invalid_rapt',
			'invalid credentials',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	public static function get_reconnect_url() {
		return wp_nonce_url(
			admin_url( 'admin.php?page=avia-attribution&tab=settings&avia_google_oauth_start=1' ),
			'avia_google_oauth_start'
		);
	}

	public static function get_oauth_redirect_uri() {
		// Single query param — avoids redirect_uri_mismatch when & in nested URLs is parsed incorrectly.
		return admin_url( 'admin-post.php?action=avia_attribution_google_oauth' );
	}

	public static function get_authorization_url() {
		$settings = Avia_Attribution_Settings::get_all();
		if ( empty( $settings['google_ads_client_id'] ) || empty( $settings['google_ads_client_secret'] ) ) {
			return new WP_Error(
				'avia_google_ads_oauth_missing_client',
				'Save OAuth client ID and client secret in Settings before reconnecting.'
			);
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( 'avia_google_ads_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$query = http_build_query(
			array(
				'client_id'              => $settings['google_ads_client_id'],
				'redirect_uri'           => self::get_oauth_redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => self::OAUTH_SCOPES,
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return self::OAUTH_AUTH_URL . '?' . $query;
	}

	public static function exchange_authorization_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code ) {
			return new WP_Error( 'avia_google_ads_oauth_missing_code', 'Authorization code is missing.' );
		}

		$settings = Avia_Attribution_Settings::get_all();
		$response = wp_remote_post(
			self::OAUTH_TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $settings['google_ads_client_id'],
					'client_secret' => $settings['google_ads_client_secret'],
					'redirect_uri'  => self::get_oauth_redirect_uri(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $http < 200 || $http >= 300 || empty( $data['refresh_token'] ) ) {
			$message = is_array( $data ) && ! empty( $data['error_description'] )
				? (string) $data['error_description']
				: substr( (string) $raw, 0, 400 );
			if ( empty( $data['refresh_token'] ) && ! empty( $data['access_token'] ) ) {
				$message = 'Google returned an access token but no refresh token. Revoke prior app access at '
					. 'https://myaccount.google.com/permissions then click Reconnect Google Ads again (prompt=consent).';
			}
			return new WP_Error( 'avia_google_ads_oauth_exchange_failed', $message );
		}

		Avia_Attribution_Settings::save(
			array(
				'google_ads_refresh_token' => (string) $data['refresh_token'],
			)
		);
		self::clear_token_cache();

		if ( ! empty( $data['access_token'] ) ) {
			$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
			set_transient( 'avia_google_ads_access_token', $data['access_token'], max( 300, $expires_in - 120 ) );
		}

		return array(
			'refresh_token_saved' => true,
			'scope'               => (string) ( $data['scope'] ?? '' ),
		);
	}

	public static function test_connection() {
		if ( ! self::has_read_credentials() ) {
			return new WP_Error(
				'avia_google_ads_missing_credentials',
				'Google Ads API read credentials are not configured. Save OAuth client ID/secret, refresh token, and developer token.'
			);
		}

		$customer_id = self::normalize_customer_id( Avia_Attribution_Settings::get( 'google_ads_customer_id' ) );
		if ( '' === $customer_id ) {
			return new WP_Error( 'avia_google_ads_missing_customer', 'Save the Google Ads customer ID first.' );
		}

		self::clear_token_cache();
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$details = self::search_customer_details( $customer_id, $token );
		if ( is_wp_error( $details ) ) {
			return $details;
		}

		$result = array(
			'customer_id'   => $customer_id,
			'customer_name' => $details['name'],
		);

		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			$dm_test = Avia_Attribution_Google_Data_Manager_Api::test_connection();
			if ( is_wp_error( $dm_test ) ) {
				$result['data_manager'] = array(
					'ok'      => false,
					'message' => $dm_test->get_error_message(),
				);
			} else {
				$result['data_manager'] = array(
					'ok'      => true,
					'message' => 'Data Manager validate-only request succeeded.',
				);
			}
		}

		return $result;
	}

	private static function build_api_headers( $access_token ) {
		$headers = array(
			'Authorization'   => 'Bearer ' . $access_token,
			'developer-token' => Avia_Attribution_Settings::get( 'google_ads_developer_token' ),
			'Content-Type'    => 'application/json',
		);

		$login_customer_id = self::normalize_customer_id(
			Avia_Attribution_Settings::get( 'google_ads_login_customer_id', '' )
		);
		if ( '' !== $login_customer_id ) {
			$headers['login-customer-id'] = $login_customer_id;
		}

		return $headers;
	}

	public static function list_accessible_customers() {
		if ( ! self::has_read_credentials() ) {
			return new WP_Error( 'avia_google_ads_missing_credentials', 'Google Ads API read credentials are not configured.' );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = sprintf(
			'https://googleads.googleapis.com/%s/customers:listAccessibleCustomers',
			self::API_VERSION
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => self::build_api_headers( $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'avia_google_ads_http_error',
				self::format_api_error( $code, $raw, $data )
			);
		}

		$customers = array();
		foreach ( (array) ( $data['resourceNames'] ?? array() ) as $resource_name ) {
			if ( preg_match( '/customers\/(\d+)/', (string) $resource_name, $matches ) ) {
				$customers[] = $matches[1];
			}
		}

		return $customers;
	}

	public static function list_accessible_customers_with_details() {
		$customers = self::list_accessible_customers();
		if ( is_wp_error( $customers ) ) {
			return $customers;
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$name_map = self::fetch_mcc_customer_names( $token );
		$accounts = array();

		foreach ( $customers as $customer_id ) {
			$name = isset( $name_map[ $customer_id ] ) ? $name_map[ $customer_id ] : '';
			if ( '' === $name ) {
				$name = self::get_customer_name( $customer_id, $token );
			}

			$accounts[] = array(
				'id'   => $customer_id,
				'name' => $name,
			);
		}

		return $accounts;
	}

	public static function get_customer_name( $customer_id, $token = null ) {
		$customer_id = self::normalize_customer_id( $customer_id );
		if ( '' === $customer_id ) {
			return '';
		}

		if ( null === $token ) {
			$token = self::get_access_token();
			if ( is_wp_error( $token ) ) {
				return '';
			}
		}

		$details = self::search_customer_details( $customer_id, $token );
		if ( is_wp_error( $details ) ) {
			return '';
		}

		return $details['name'];
	}

	public static function format_customer_id_display( $customer_id ) {
		$customer_id = self::normalize_customer_id( $customer_id );
		if ( 10 === strlen( $customer_id ) ) {
			return substr( $customer_id, 0, 3 ) . '-' . substr( $customer_id, 3, 3 ) . '-' . substr( $customer_id, 6 );
		}

		return $customer_id;
	}

	private static function search_customer_details( $customer_id, $token ) {
		$url = sprintf(
			'https://googleads.googleapis.com/%s/customers/%s/googleAds:search',
			self::API_VERSION,
			rawurlencode( self::normalize_customer_id( $customer_id ) )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => self::build_api_headers( $token ),
				'body'    => wp_json_encode(
					array(
						'query' => 'SELECT customer.id, customer.descriptive_name FROM customer LIMIT 1',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'avia_google_ads_http_error',
				self::format_api_error( $code, $raw, $data )
			);
		}

		$name = '';
		if ( ! empty( $data['results'][0]['customer']['descriptiveName'] ) ) {
			$name = (string) $data['results'][0]['customer']['descriptiveName'];
		}

		return array(
			'id'   => $customer_id,
			'name' => $name,
		);
	}

	private static function fetch_mcc_customer_names( $token ) {
		$login_customer_id = self::normalize_customer_id(
			Avia_Attribution_Settings::get( 'google_ads_login_customer_id', '' )
		);
		if ( '' === $login_customer_id ) {
			return array();
		}

		$url = sprintf(
			'https://googleads.googleapis.com/%s/customers/%s/googleAds:search',
			self::API_VERSION,
			rawurlencode( $login_customer_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => self::build_api_headers( $token ),
				'body'    => wp_json_encode(
					array(
						'query' => 'SELECT customer_client.client_customer, customer_client.descriptive_name FROM customer_client',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 || empty( $data['results'] ) ) {
			return array();
		}

		$names = array();
		foreach ( (array) $data['results'] as $row ) {
			$resource = $row['customerClient']['clientCustomer'] ?? '';
			$name     = $row['customerClient']['descriptiveName'] ?? '';
			if ( preg_match( '/customers\/(\d+)/', (string) $resource, $matches ) ) {
				$names[ $matches[1] ] = (string) $name;
			}
		}

		return $names;
	}

	public static function format_public_api_error( $code, $raw, $data, $label = 'Google Ads API' ) {
		return self::format_api_error( $code, $raw, $data, $label );
	}

	private static function extract_google_ads_error_details( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$details = $data['error']['details'] ?? array();
		if ( empty( $details ) && ! empty( $data[0]['error']['details'] ) ) {
			$details = $data[0]['error']['details'];
		}

		$parts = array();
		foreach ( (array) $details as $detail ) {
			if ( empty( $detail['errors'] ) || ! is_array( $detail['errors'] ) ) {
				continue;
			}
			foreach ( $detail['errors'] as $err ) {
				$msg = (string) ( $err['message'] ?? '' );
				if ( '' !== $msg ) {
					$parts[] = $msg;
				}
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '(' . implode( ' ', array_slice( array_unique( $parts ), 0, 2 ) ) . ')';
	}

	private static function format_api_error( $code, $raw, $data, $label = 'Google Ads API' ) {
		$message = '';

		if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
			$message = (string) $data['error']['message'];
		} elseif ( is_array( $data ) && ! empty( $data[0]['error']['message'] ) ) {
			$message = (string) $data[0]['error']['message'];
		}

		if ( '' !== $message ) {
			$detail_text = self::extract_google_ads_error_details( $data );
			if ( '' !== $detail_text ) {
				$message .= ' ' . $detail_text;
			}
			if ( 403 === (int) $code ) {
				$message .= ' Set Login customer ID (MCC) in Google Ads settings if you access AVIA through a manager account.';
			}

			return sprintf( '%1$s HTTP %2$d: %3$s', $label, (int) $code, $message );
		}

		if ( is_array( $data ) && ! empty( $data[0]['error']['message'] ) ) {
			return sprintf( '%1$s HTTP %2$d: %3$s', $label, (int) $code, (string) $data[0]['error']['message'] );
		}

		if ( is_string( $raw ) && false !== stripos( $raw, '<html' ) ) {
			if ( preg_match( '/<code>([^<]+)<\/code>/i', $raw, $matches ) ) {
				return sprintf(
					'%1$s HTTP %2$d: %3$s (API version %4$s may be invalid or sunset.)',
					$label,
					(int) $code,
					$matches[1],
					self::API_VERSION
				);
			}

			return sprintf(
				'%1$s HTTP %2$d: Unexpected HTML error response. Check API version %3$s and login-customer-id.',
				$label,
				(int) $code,
				self::API_VERSION
			);
		}

		return sprintf( '%1$s HTTP %2$d: %3$s', $label, (int) $code, substr( (string) $raw, 0, 500 ) );
	}

	public static function normalize_customer_id( $customer_id ) {
		return preg_replace( '/\D+/', '', (string) $customer_id );
	}

	public static function format_conversion_datetime( $mysql_datetime ) {
		try {
			$timezone = wp_timezone();
			$dt       = new DateTime( (string) $mysql_datetime, $timezone );
		} catch ( Exception $exception ) {
			$dt = new DateTime( 'now', wp_timezone() );
		}

		return $dt->format( 'Y-m-d H:i:sP' );
	}

	public static function search( $query, $customer_id = null ) {
		if ( ! self::has_read_credentials() ) {
			return new WP_Error(
				'avia_google_ads_missing_credentials',
				'Google Ads API credentials are not configured.'
			);
		}

		if ( null === $customer_id ) {
			$customer_id = self::normalize_customer_id( Avia_Attribution_Settings::get( 'google_ads_customer_id' ) );
		} else {
			$customer_id = self::normalize_customer_id( $customer_id );
		}

		if ( '' === $customer_id ) {
			return new WP_Error( 'avia_google_ads_missing_customer', 'Save the Google Ads customer ID first.' );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = sprintf(
			'https://googleads.googleapis.com/%s/customers/%s/googleAds:search',
			self::API_VERSION,
			rawurlencode( $customer_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => self::build_api_headers( $token ),
				'body'    => wp_json_encode(
					array(
						'query' => (string) $query,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'avia_google_ads_http_error',
				self::format_api_error( $code, $raw, $data, 'Google Ads reporting' )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	public static function get_ads_date_literal( $range_key ) {
		$map = array(
			'last_7_days'  => 'LAST_7_DAYS',
			'last_30_days' => 'LAST_30_DAYS',
			'last_90_days' => 'LAST_90_DAYS',
		);

		$range_key = sanitize_key( (string) $range_key );
		return isset( $map[ $range_key ] ) ? $map[ $range_key ] : 'LAST_30_DAYS';
	}

	public static function build_segments_date_clause( $range_key = 'last_30_days', $start_date = '', $end_date = '' ) {
		$range = Avia_Attribution_Dashboard::resolve_range( $range_key, $start_date, $end_date );

		// Explicit BETWEEN works for 7/30/90-day presets and custom (LAST_90_DAYS literals can 400 on some accounts).
		return sprintf(
			"segments.date BETWEEN '%s' AND '%s'",
			$range['start_date'],
			$range['end_date']
		);
	}

	public static function sanitize_campaign_ids( $ids ) {
		$ids = is_array( $ids ) ? $ids : array();
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), static function ( $id ) { return (bool) preg_match( '/^[0-9]+$/', $id ); } ) ) );
		sort( $ids, SORT_STRING );
		return $ids;
	}

	public static function get_campaign_filter_options() {
		$result = self::search( "SELECT campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.status != 'REMOVED' ORDER BY campaign.name" );
		if ( is_wp_error( $result ) ) { return $result; }
		$options = array();
		foreach ( $result['results'] ?? array() as $row ) {
			$options[] = array( 'id' => (string) ( $row['campaign']['id'] ?? '' ), 'name' => (string) ( $row['campaign']['name'] ?? '' ), 'status' => (string) ( $row['campaign']['status'] ?? '' ) );
		}
		return $options;
	}

	/**
	 * Local Services system campaigns report ~$1/lead as conversion value — not booking revenue.
	 *
	 * @param string $name Campaign name.
	 * @return bool
	 */
	public static function is_local_services_campaign_name( $name ) {
		$name = (string) $name;
		return (bool) preg_match( '/^LocalServicesCampaign/i', $name )
			|| (bool) preg_match( '/Local\s*Services/i', $name );
	}

	public static function get_account_performance( $range_key = 'last_30_days', $start_date = '', $end_date = '', $campaign_ids = array() ) {
		$campaign_ids = self::sanitize_campaign_ids( $campaign_ids );
		$range     = Avia_Attribution_Dashboard::resolve_range( $range_key, $start_date, $end_date );
		// v27: campaign-level rollup so Local Services placeholder conversion value can be excluded.
		$cache_key = 'avia_gads_perf_v27_' . md5( $range['start_date'] . $range['end_date'] . implode( ',', $campaign_ids ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$date_clause = self::build_segments_date_clause( $range['key'], $range['start_date'], $range['end_date'] );
		$campaign_clause = empty( $campaign_ids ) ? '' : ' AND campaign.id IN (' . implode( ',', $campaign_ids ) . ')';
		// Always query campaigns so we can drop Local Services fake conversion value from Key metrics.
		$query       = "SELECT
			segments.date,
			campaign.id,
			campaign.name,
			metrics.clicks,
			metrics.impressions,
			metrics.cost_micros,
			metrics.conversions,
			metrics.conversions_value
		FROM campaign
		WHERE {$date_clause}{$campaign_clause}
			AND campaign.status != 'REMOVED'";

		$data = self::search( $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$totals = array(
			'clicks'            => 0,
			'impressions'       => 0,
			'cost_micros'       => 0,
			'conversions'       => 0.0,
			'conversions_value' => 0.0,
		);

		foreach ( (array) ( $data['results'] ?? array() ) as $row ) {
			$metrics = $row['metrics'] ?? array();
			$name    = (string) ( $row['campaign']['name'] ?? '' );
			$totals['clicks']      += (int) ( $metrics['clicks'] ?? 0 );
			$totals['impressions'] += (int) ( $metrics['impressions'] ?? 0 );
			$totals['cost_micros'] += (int) ( $metrics['costMicros'] ?? 0 );
			$totals['conversions'] += (float) ( $metrics['conversions'] ?? 0 );
			if ( ! self::is_local_services_campaign_name( $name ) ) {
				$totals['conversions_value'] += (float) ( $metrics['conversionsValue'] ?? 0 );
			}
		}

		$spend = round( $totals['cost_micros'] / 1000000, 2 );
		$cpc   = $totals['clicks'] > 0 ? round( $spend / $totals['clicks'], 2 ) : 0.0;
		$roas  = $spend > 0 ? round( $totals['conversions_value'] / $spend, 2 ) : null;

		$result = array(
			'range'             => $range['key'],
			'range_label'       => $range['label'],
			'start_date'        => $range['start_date'],
			'end_date'          => $range['end_date'],
			'clicks'            => $totals['clicks'],
			'impressions'       => $totals['impressions'],
			'spend'             => $spend,
			'conversions'       => round( $totals['conversions'], 2 ),
			'conversions_value' => round( $totals['conversions_value'], 2 ),
			'cpc'               => $cpc,
			'roas'              => $roas,
			'fetched_at'        => current_time( 'mysql' ),
		);

		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );

		return $result;
	}

	public static function get_campaign_conversion_details( $campaign_id, $range_key, $start_date = '', $end_date = '' ) {
		$campaign_id = (string) $campaign_id;
		if ( ! preg_match( '/^[0-9]+$/', $campaign_id ) ) {
			return new WP_Error( 'avia_invalid_campaign', 'Invalid campaign ID.' );
		}
		$range = Avia_Attribution_Dashboard::resolve_range( $range_key, $start_date, $end_date );
		$cache_key = 'avia_gads_conv_v26_' . md5( $campaign_id . '|' . $range['start_date'] . '|' . $range['end_date'] );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$date_clause = self::build_segments_date_clause( $range['key'], $range['start_date'], $range['end_date'] );
		$query = "SELECT campaign.id, campaign.name, segments.date,
			segments.conversion_action, segments.conversion_action_name, segments.conversion_action_category,
			metrics.conversions, metrics.conversions_value
			FROM campaign WHERE campaign.id = {$campaign_id} AND {$date_clause}
			ORDER BY segments.date DESC";
		$data = self::search( $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$actions = array();
		$daily = array();
		$total = 0.0;
		$value = 0.0;
		$name = '';
		foreach ( (array) ( $data['results'] ?? array() ) as $row ) {
			$segments = $row['segments'] ?? array();
			$metrics = $row['metrics'] ?? array();
			$count = (float) ( $metrics['conversions'] ?? 0 );
			$amount = (float) ( $metrics['conversionsValue'] ?? 0 );
			if ( 0.0 === $count && 0.0 === $amount ) {
				continue;
			}
			$name = (string) ( $row['campaign']['name'] ?? '' );
			$key = (string) ( $segments['conversionAction'] ?? '' );
			if ( ! isset( $actions[ $key ] ) ) {
				$actions[ $key ] = array( 'name' => (string) ( $segments['conversionActionName'] ?? '' ), 'category' => (string) ( $segments['conversionActionCategory'] ?? '' ), 'conversions' => 0.0, 'value' => 0.0 );
			}
			$actions[ $key ]['conversions'] += $count;
			$actions[ $key ]['value'] += $amount;
			$daily[] = array( 'date' => (string) ( $segments['date'] ?? '' ), 'action' => $actions[ $key ]['name'], 'conversions' => $count, 'value' => $amount );
			$total += $count;
			$value += $amount;
		}
		$result = array( 'campaign_id' => $campaign_id, 'name' => $name, 'range' => $range, 'conversions' => $total, 'value' => $value, 'actions' => array_values( $actions ), 'daily' => $daily );
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	public static function get_campaign_performance( $range_key = 'last_30_days', $start_date = '', $end_date = '', $campaign_ids = array() ) {
		$campaign_ids = self::sanitize_campaign_ids( $campaign_ids );
		$range     = Avia_Attribution_Dashboard::resolve_range( $range_key, $start_date, $end_date );
		$cache_key = 'avia_gads_camps_v26_' . md5( $range['start_date'] . $range['end_date'] . implode( ',', $campaign_ids ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$date_clause = self::build_segments_date_clause( $range['key'], $range['start_date'], $range['end_date'] );
		$campaign_clause = empty( $campaign_ids ) ? '' : ' AND campaign.id IN (' . implode( ',', $campaign_ids ) . ')';
		$query       = "SELECT
			segments.date,
			campaign.id,
			campaign.name,
			campaign.status,
			metrics.clicks,
			metrics.impressions,
			metrics.cost_micros,
			metrics.conversions,
			metrics.conversions_value
		FROM campaign
		WHERE {$date_clause}{$campaign_clause}
			AND campaign.status != 'REMOVED'";

		$data = self::search( $query );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$aggregated = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $row ) {
			$campaign = $row['campaign'] ?? array();
			$metrics  = $row['metrics'] ?? array();
			$id       = (string) ( $campaign['id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			if ( ! isset( $aggregated[ $id ] ) ) {
				$aggregated[ $id ] = array(
					'id'                => $id,
					'name'              => (string) ( $campaign['name'] ?? '' ),
					'status'            => (string) ( $campaign['status'] ?? '' ),
					'clicks'            => 0,
					'impressions'       => 0,
					'cost_micros'       => 0,
					'conversions'       => 0.0,
					'conversions_value' => 0.0,
				);
			}

			$aggregated[ $id ]['clicks']            += (int) ( $metrics['clicks'] ?? 0 );
			$aggregated[ $id ]['impressions']       += (int) ( $metrics['impressions'] ?? 0 );
			$aggregated[ $id ]['cost_micros']       += (int) ( $metrics['costMicros'] ?? 0 );
			$aggregated[ $id ]['conversions']       += (float) ( $metrics['conversions'] ?? 0 );
			$aggregated[ $id ]['conversions_value'] += (float) ( $metrics['conversionsValue'] ?? 0 );
		}

		$campaigns = array_values( $aggregated );
		usort(
			$campaigns,
			static function ( $a, $b ) {
				return $b['cost_micros'] <=> $a['cost_micros'];
			}
		);


		$formatted = array();
		foreach ( $campaigns as $campaign ) {
			$spend = round( $campaign['cost_micros'] / 1000000, 2 );
			$formatted[] = array(
				'id'                => $campaign['id'],
				'name'              => $campaign['name'],
				'status'            => $campaign['status'],
				'clicks'            => $campaign['clicks'],
				'impressions'       => $campaign['impressions'],
				'spend'             => $spend,
				'conversions'       => round( $campaign['conversions'], 2 ),
				'conversions_value' => round( $campaign['conversions_value'], 2 ),
				'cpc'               => $campaign['clicks'] > 0 ? round( $spend / $campaign['clicks'], 2 ) : 0.0,
				'roas'              => $spend > 0 ? round( $campaign['conversions_value'] / $spend, 2 ) : null,
			);
		}

		set_transient( $cache_key, $formatted, 15 * MINUTE_IN_SECONDS );

		return $formatted;
	}
}
