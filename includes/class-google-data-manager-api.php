<?php



if ( ! defined( 'ABSPATH' ) ) {

	exit;

}



class Avia_Attribution_Google_Data_Manager_Api {



	const INGEST_URL = 'https://datamanager.googleapis.com/v1/events:ingest';



	public static function has_upload_credentials() {

		$settings = Avia_Attribution_Settings::get_all();



		return ! empty( $settings['google_ads_client_id'] )

			&& ! empty( $settings['google_ads_client_secret'] )

			&& ! empty( $settings['google_ads_refresh_token'] )

			&& ! empty( $settings['google_ads_customer_id'] )

			&& ! empty( $settings['google_ads_conversion_action_id'] );

	}



	public static function ingest_offline_conversion( array $conversion, $validate_only = false ) {
		if ( ! isset( $conversion['conversion_value'] ) || '' === $conversion['conversion_value'] ) {
			return new WP_Error( 'avia_revenue_pending', 'Actual conversion revenue is required. No fallback value is used.' );
		}


		if ( ! self::has_upload_credentials() ) {

			return new WP_Error( 'avia_data_manager_missing_credentials', 'Google Data Manager credentials are not configured.' );

		}



		$token = Avia_Attribution_Google_Ads_Api::get_access_token_for_api();

		if ( is_wp_error( $token ) ) {

			return $token;

		}



		$click_field = self::resolve_click_field( $conversion );

		if ( is_wp_error( $click_field ) ) {

			return $click_field;

		}



		$customer_id = Avia_Attribution_Google_Ads_Api::normalize_customer_id(

			Avia_Attribution_Settings::get( 'google_ads_customer_id' )

		);

		$login_id    = Avia_Attribution_Google_Ads_Api::normalize_customer_id(

			Avia_Attribution_Settings::get( 'google_ads_login_customer_id', '' )

		);

		if ( '' === $login_id ) {

			$login_id = $customer_id;

		}



		$action_id = preg_replace(

			'/\D+/',

			'',

			(string) Avia_Attribution_Settings::get( 'google_ads_conversion_action_id', '' )

		);



		$event = array(

			'adIdentifiers'  => array(

				$click_field['key'] => $click_field['value'],

			),

			'conversionValue' => (float) $conversion['conversion_value'],

			'currency'        => $conversion['currency_code'] ?? 'USD',

			'eventTimestamp'  => self::format_event_timestamp( $conversion['conversion_datetime'] ?? '' ),

			'transactionId'   => (string) ( $conversion['transaction_id'] ?? '' ),

			'eventSource'     => 'WEB',

		);



		if ( '' === $event['transactionId'] ) {

			return new WP_Error( 'avia_data_manager_missing_transaction', 'Transaction ID is required for Data Manager upload.' );

		}



		$payload = array(

			'destinations' => array(

				array(

					'operatingAccount'     => array(

						'accountType' => 'GOOGLE_ADS',

						'accountId'   => $customer_id,

					),

					'loginAccount'         => array(

						'accountType' => 'GOOGLE_ADS',

						'accountId'   => $login_id,

					),

					'productDestinationId' => $action_id,

				),

			),

			'events'       => array( $event ),

			'consent'      => array(
				'adUserData' => 'CONSENT_GRANTED',
			),

			'validateOnly' => (bool) $validate_only,

		);



		$response = wp_remote_post(

			self::INGEST_URL,

			array(

				'timeout' => 30,

				'headers' => array(

					'Authorization' => 'Bearer ' . $token,

					'Content-Type'  => 'application/json',

				),

				'body'    => wp_json_encode( $payload ),

			)

		);



		if ( is_wp_error( $response ) ) {

			return $response;

		}



		$code = wp_remote_retrieve_response_code( $response );

		$raw  = wp_remote_retrieve_body( $response );

		$data = json_decode( $raw, true );



		if ( $code < 200 || $code >= 300 ) {
			if ( $validate_only && in_array( (int) $code, array( 400, 422 ), true ) ) {
				return array(
					'validated'  => true,
					'request_id' => is_array( $data ) ? ( $data['requestId'] ?? '' ) : '',
				);
			}

			return new WP_Error(
				'avia_data_manager_http_error',
				Avia_Attribution_Google_Ads_Api::format_public_api_error( $code, $raw, $data, 'Data Manager API' )
			);
		}



		return $data;

	}



	public static function test_connection() {

		$result = self::ingest_offline_conversion(

			array(

				'gclid'               => 'validate_only_test_gclid',

				'conversion_datetime' => gmdate( 'c' ),

				'conversion_value'    => 1.0,

				'currency_code'       => 'USD',

				'transaction_id'      => 'avia-validate-only-' . time(),

			),

			true

		);



		if ( is_wp_error( $result ) ) {

			$message = $result->get_error_message();

			if ( false !== stripos( $message, 'insufficient' ) || false !== stripos( $message, 'scope' ) ) {

				$message .= ' Regenerate the refresh token with scope https://www.googleapis.com/auth/datamanager';

			}

			return new WP_Error( $result->get_error_code(), $message );

		}



		return true;

	}



	private static function resolve_click_field( array $conversion ) {

		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $key ) {

			if ( ! empty( $conversion[ $key ] ) ) {

				return array(

					'key'   => $key,

					'value' => (string) $conversion[ $key ],

				);

			}

		}



		return new WP_Error( 'avia_data_manager_no_click_id', 'No gclid, gbraid, or wbraid available for upload.' );

	}



	public static function format_event_timestamp( $datetime ) {

		try {

			$timezone = wp_timezone();

			$dt       = new DateTime( (string) $datetime, $timezone );

		} catch ( Exception $exception ) {

			$dt = new DateTime( 'now', wp_timezone() );

		}



		return $dt->format( 'c' );

	}

}


