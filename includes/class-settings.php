<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Settings {

	const OPTION_KEY  = 'avia_attribution_settings';
	const DB_VERSION  = '1.5.0';

	public static function defaults() {
		return array(
			'blvd_client_id'     => '',
			'blvd_client_secret' => '',
			'blvd_business_id'   => '',
			'capture_enabled'    => false,
			'debug_enabled'      => false,
			'webhooks_synced_at' => '',
			'google_ads_customer_id'           => '4631467546',
			'google_ads_login_customer_id'     => '',
			'google_ads_conversion_action_id'  => '7766637097',
			'google_ads_conversion_name'       => 'Appointment Completed (Primary)',
			'google_ads_conversion_id'         => '626150478',
			'google_ads_conversion_label'      => '3I9LCKn0tfccEM6YyaoC',
			'google_ads_default_conversion_value' => '1',
			'google_ads_developer_token'       => '',
			'google_ads_client_id'             => '',
			'google_ads_client_secret'         => '',
			'google_ads_refresh_token'         => '',
			'google_ads_auto_upload_enabled'   => false,
		);
	}

	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function get( $key, $default = '' ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function save( array $input ) {
		$current = self::get_all();
		$next    = $current;

		if ( isset( $input['blvd_client_id'] ) ) {
			$next['blvd_client_id'] = sanitize_text_field( $input['blvd_client_id'] );
		}
		if ( isset( $input['blvd_business_id'] ) ) {
			$next['blvd_business_id'] = sanitize_text_field( $input['blvd_business_id'] );
		}
		if ( ! empty( $input['blvd_client_secret'] ) ) {
			$next['blvd_client_secret'] = sanitize_text_field( $input['blvd_client_secret'] );
		}
		if ( isset( $input['capture_enabled'] ) ) {
			$next['capture_enabled'] = (bool) $input['capture_enabled'];
		}
		if ( isset( $input['debug_enabled'] ) ) {
			$next['debug_enabled'] = (bool) $input['debug_enabled'];
		}
		if ( isset( $input['google_ads_customer_id'] ) ) {
			$next['google_ads_customer_id'] = sanitize_text_field( $input['google_ads_customer_id'] );
		}
		if ( isset( $input['google_ads_login_customer_id'] ) ) {
			$next['google_ads_login_customer_id'] = sanitize_text_field( $input['google_ads_login_customer_id'] );
		}
		if ( isset( $input['google_ads_conversion_action_id'] ) ) {
			$next['google_ads_conversion_action_id'] = sanitize_text_field( $input['google_ads_conversion_action_id'] );
		}
		if ( isset( $input['google_ads_conversion_name'] ) ) {
			$next['google_ads_conversion_name'] = sanitize_text_field( $input['google_ads_conversion_name'] );
		}
		if ( isset( $input['google_ads_conversion_id'] ) ) {
			$next['google_ads_conversion_id'] = sanitize_text_field( $input['google_ads_conversion_id'] );
		}
		if ( isset( $input['google_ads_conversion_label'] ) ) {
			$next['google_ads_conversion_label'] = sanitize_text_field( $input['google_ads_conversion_label'] );
		}
		if ( isset( $input['google_ads_default_conversion_value'] ) ) {
			$next['google_ads_default_conversion_value'] = sanitize_text_field( $input['google_ads_default_conversion_value'] );
		}
		if ( isset( $input['google_ads_developer_token'] ) && '' !== $input['google_ads_developer_token'] ) {
			$next['google_ads_developer_token'] = sanitize_text_field( $input['google_ads_developer_token'] );
		}
		if ( isset( $input['google_ads_client_id'] ) ) {
			$next['google_ads_client_id'] = sanitize_text_field( $input['google_ads_client_id'] );
		}
		if ( ! empty( $input['google_ads_client_secret'] ) ) {
			$next['google_ads_client_secret'] = sanitize_text_field( $input['google_ads_client_secret'] );
		}
		if ( ! empty( $input['google_ads_refresh_token'] ) ) {
			$next['google_ads_refresh_token'] = sanitize_text_field( $input['google_ads_refresh_token'] );
		}
		if ( isset( $input['google_ads_auto_upload_enabled'] ) ) {
			$next['google_ads_auto_upload_enabled'] = (bool) $input['google_ads_auto_upload_enabled'];
		}

		update_option( self::OPTION_KEY, $next, false );
		return $next;
	}

	public static function has_credentials() {
		$all = self::get_all();
		return ! empty( $all['blvd_client_id'] )
			&& ! empty( $all['blvd_client_secret'] )
			&& ! empty( $all['blvd_business_id'] );
	}

	public static function is_capture_enabled() {
		return (bool) self::get( 'capture_enabled', false );
	}

	public static function is_debug_enabled() {
		return (bool) self::get( 'debug_enabled', false );
	}

	public static function get_webhook_secret() {
		return self::get( 'blvd_client_secret', '' );
	}

	public static function mark_webhooks_synced() {
		$all = self::get_all();
		$all['webhooks_synced_at'] = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function has_google_ads_credentials() {
		return Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials();
	}

	public static function is_google_ads_auto_upload_enabled() {
		return (bool) self::get( 'google_ads_auto_upload_enabled', false );
	}
}
