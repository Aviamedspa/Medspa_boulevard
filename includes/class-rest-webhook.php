<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Rest_Webhook {

	const SALT_MAX_AGE_SECONDS = 300;

	/**
	 * Set true only around admin rest_do_request ping — never trust client payload alone.
	 *
	 * @var bool
	 */
	public static $allow_internal_ping = false;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		Avia_Attribution_Webhook_Body::init();
	}

	public static function register_routes() {
		register_rest_route(
			'avia/v1',
			'/boulevard-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle_webhook( WP_REST_Request $request ) {
		Avia_Attribution_Webhook_Body::patch_request_headers( $request );
		$raw_body = Avia_Attribution_Webhook_Body::get_raw_body( $request->get_body() );
		$secret   = Avia_Attribution_Settings::get_webhook_secret();
		$payload  = json_decode( $raw_body, true );
		$is_ping  = self::is_ping_event( $payload, $raw_body );
		$skip_sig = self::is_internal_admin_ping( $payload );

		if ( ! Avia_Attribution_Database::tables_exist() ) {
			Avia_Attribution_Webhook_Log::log( 'error', 503, 'plugin_not_ready', null, $raw_body );
			return new WP_REST_Response( array( 'error' => 'plugin_not_ready' ), 503 );
		}

		$signature_ok = null;
		if ( ! $skip_sig ) {
			if ( ! $secret ) {
				Avia_Attribution_Webhook_Log::log( 'error', 401, 'webhook_secret_missing', false, $raw_body );
				return new WP_REST_Response( array( 'error' => 'webhook_secret_missing' ), 401 );
			}
			$verify_result = self::verify_signature( $request, $raw_body, $secret );
			$signature_ok  = ! empty( $verify_result['valid'] );

			if ( ! $signature_ok ) {
				Avia_Attribution_Webhook_Log::log(
					self::resolve_event_type( is_array( $payload ) ? $payload : array() ),
					401,
					self::format_signature_failure_message( $verify_result ),
					false,
					$raw_body
				);
				return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
			}
		} else {
			$signature_ok = true;
		}

		if ( $is_ping ) {
			self::store_event(
				is_array( $payload ) ? $payload : array( 'eventType' => 'PING' ),
				$signature_ok
			);
			Avia_Attribution_Webhook_Log::log( 'PING', 200, 'ping_received', $signature_ok, $raw_body );
			return new WP_REST_Response( array( 'received' => true, 'ping' => true ), 200 );
		}

		if ( ! is_array( $payload ) ) {
			Avia_Attribution_Webhook_Log::log( 'error', 400, 'invalid_json', $signature_ok, $raw_body );
			return new WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
		}

		$stored = self::store_event( $payload, $signature_ok );
		if ( is_wp_error( $stored ) ) {
			Avia_Attribution_Webhook_Log::log(
				self::resolve_event_type( $payload ),
				500,
				$stored->get_error_message(),
				$signature_ok,
				$raw_body
			);
			return new WP_REST_Response( array( 'error' => $stored->get_error_code() ), 500 );
		}

		$event_type = self::resolve_event_type( $payload );

		if ( is_array( $stored ) && ! empty( $stored['writeback_message'] ) ) {
			$stored_message = $stored['writeback_message'];
			if ( 'duplicate_skipped' === $stored_message ) {
				return new WP_REST_Response( array( 'received' => true, 'skipped' => true ), 200 );
			}

			// APPOINTMENT_UPDATED fires very often; keep it out of the delivery log. CLIENT_UPDATED is shown.
			if ( 'writeback_skipped' === $stored_message && 'APPOINTMENT_UPDATED' === $event_type ) {
				return new WP_REST_Response( array( 'received' => true, 'skipped' => true ), 200 );
			}
		}

		$log_message = 'stored';
		if ( is_array( $stored ) && ! empty( $stored['writeback_message'] ) ) {
			$log_message = str_replace(
				array( '+ads_uploaded', '+ads_queued', '+upload_deferred_order', '+upload_duplicate_skipped' ),
				array( ' + ads_uploaded', ' + ads_queued', ' + upload_deferred_order', ' + upload_duplicate_skipped' ),
				$stored['writeback_message']
			);
		}

		if ( 'APPOINTMENT_CANCELLED' === $event_type ) {
			$reason_label = Avia_Attribution_Boulevard_Api::format_cancellation_reason(
				Avia_Attribution_Boulevard_Api::cancellation_reason_from_payload( $payload )
			);
			if ( $reason_label ) {
				$log_message .= ' · ' . $reason_label;
			}
		}

		if (
			is_array( $stored )
			&& in_array( $event_type, array( 'ORDER_COMPLETED', 'APPOINTMENT_COMPLETED' ), true )
			&& null !== ( $stored['revenue_cents'] ?? null )
		) {
			$log_message .= ' · $' . number_format( ( (int) $stored['revenue_cents'] ) / 100, 2 );
		}

		Avia_Attribution_Webhook_Log::log(
			$event_type,
			200,
			$log_message,
			$signature_ok,
			$raw_body
		);

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function store_event( array $payload, $signature_ok ) {
		$event_type      = self::resolve_event_type( $payload );
		$event_id        = self::resolve_event_id( $payload, $event_type );
		$revenue_context = array();
		if ( in_array( $event_type, array( 'ORDER_COMPLETED', 'APPOINTMENT_COMPLETED' ), true ) ) {
			$revenue_context = Avia_Attribution_Boulevard_Api::enrich_order_revenue_context( $payload, $event_type );
		}

		$location_id = self::resolve_path( $payload, array( 'data.node.locationId', 'data.node.location.id', 'data.locationId', 'data.location.id', 'locationId' ) );
		if ( empty( $location_id ) && ! empty( $revenue_context['location_id'] ) ) {
			$location_id = $revenue_context['location_id'];
		}

		$client_id = self::resolve_client_id( $payload );
		if ( empty( $client_id ) && ! empty( $revenue_context['client_id'] ) ) {
			$client_id = $revenue_context['client_id'];
		}

		$appointment = self::resolve_appointment_id( $payload );
		if ( empty( $appointment ) && ! empty( $revenue_context['order_id'] ) ) {
			$appointment = $revenue_context['order_id'];
		}

		$email  = self::resolve_path( $payload, array( 'data.node.email', 'data.node.client.email', 'data.client.email', 'data.email', 'data.appointment.client.email' ) );
		$phone  = self::resolve_path( $payload, array( 'data.node.mobilePhone', 'data.node.phoneNumber', 'data.node.client.mobilePhone', 'data.node.client.phoneNumber', 'data.client.mobilePhone', 'data.client.phoneNumber', 'data.phone' ) );
		$status = self::resolve_path( $payload, array( 'data.node.state', 'data.status', 'data.appointment.state', 'data.state' ) );

		// One Admin API fetch on create/complete paths: email/phone + Book Now externalId (att_id).
		$blvd_client = null;
		$lookup_events = array( 'CLIENT_CREATED', 'APPOINTMENT_CREATED', 'APPOINTMENT_COMPLETED', 'ORDER_COMPLETED' );
		if ( ! empty( $client_id ) && Avia_Attribution_Settings::has_credentials() && in_array( $event_type, $lookup_events, true ) ) {
			$fetched = Avia_Attribution_Boulevard_Api::get_client_by_id( $client_id );
			if ( ! is_wp_error( $fetched ) && is_array( $fetched ) ) {
				$blvd_client = $fetched;
				if ( empty( $email ) && ! empty( $blvd_client['email'] ) ) {
					$email = $blvd_client['email'];
				}
				if ( empty( $phone ) && ! empty( $blvd_client['mobile_phone'] ) ) {
					$phone = $blvd_client['mobile_phone'];
				}
			}
		}

		$revenue_cents = self::resolve_revenue_cents( $payload );
		if ( null === $revenue_cents && array_key_exists( 'revenue_cents', $revenue_context ) && null !== $revenue_context['revenue_cents'] ) {
			$revenue_cents = (int) $revenue_context['revenue_cents'];
		}

		$referral_parts = self::collect_referral_attribution_parts( $payload );

		// Appointment referral_source custom field (CreateCartInput.referralSource) — often missing from webhook JSON.
		if ( empty( $referral_parts['att_id'] ) && ! empty( $appointment ) && Avia_Attribution_Settings::has_credentials()
			&& in_array( $event_type, $lookup_events, true ) ) {
			$appt_blob = Avia_Attribution_Boulevard_Api::fetch_appointment_attribution_blob( $appointment );
			if ( '' !== $appt_blob ) {
				$from_appt = self::parse_referral_attribution( $appt_blob );
				if ( ! empty( $from_appt['att_id'] ) ) {
					$referral_parts = array_merge( $referral_parts, $from_appt );
				}
			}
		}

		$att_id = self::resolve_att_id( $payload, $email, $phone, $client_id );
		if ( empty( $att_id ) && ! empty( $revenue_context['att_id'] ) ) {
			$att_id = $revenue_context['att_id'];
		}
		if ( empty( $att_id ) && ! empty( $referral_parts['att_id'] ) ) {
			$att_id = $referral_parts['att_id'];
		}
		// Live Boulevard client.externalId (Book Now stamps avia att_id) — concurrent-safe, order-independent.
		if ( empty( $att_id ) && is_array( $blvd_client ) ) {
			$from_external = self::att_id_from_blvd_client_node( $blvd_client );
			if ( $from_external ) {
				$att_id = $from_external;
			}
		}
		// Overlay often never echoes urlParams — Book Now beacon. Prefer identity among pending intents
		// when email/phone present so two concurrent bookings do not miss or swap.
		if ( empty( $att_id ) && in_array( $event_type, array( 'CLIENT_CREATED' ), true ) ) {
			$claimed = Avia_Attribution_Capture::claim_book_intent( null, $email, $phone );
			if ( $claimed ) {
				$att_id = $claimed;
			}
		} elseif ( ! empty( $att_id ) ) {
			// Proof already resolved — still consume matching beacon so it cannot attach to someone else.
			Avia_Attribution_Capture::claim_book_intent( $att_id, $email, $phone );
		}
		// Injector overlay cannot set CreateCart externalId/referral — concurrent Book Now leaves 2+
		// intents unclaimed. Queue unmatched clients and pair by Boulevard createdAt ↔ Book Now ts.
		// Only when ads Book Now intents are waiting (never attach abandoned ads click to organic client).
		if ( empty( $att_id ) && ! empty( $client_id ) && in_array( $event_type, array( 'CLIENT_CREATED' ), true )
			&& Avia_Attribution_Capture::has_claimable_book_intents() ) {
			$created_at = is_array( $blvd_client ) ? ( $blvd_client['created_at'] ?? '' ) : '';
			Avia_Attribution_Capture::record_pending_blvd_client( $client_id, $email, $phone, $created_at );
			$paired = Avia_Attribution_Capture::pair_book_intents_with_pending_clients( $client_id );
			if ( $paired ) {
				$att_id = $paired;
			}
		}
		// After CLIENT_CREATED write-back/link, later appointment/order webhooks often omit custom fields.
		// Reuse blvd_client_id → session only when that session still passes the ads upload gate.
		if ( empty( $att_id ) && ! empty( $client_id ) ) {
			$linked = self::match_att_id_by_blvd_client_id( $client_id );
			if ( $linked && Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $linked ) ) {
				$att_id = $linked;
			}
		}
		// Bridge proof may outlive a missed REST persist (cache/race). Hydrate session before the ads gate.
		if ( ! empty( $att_id ) && ( ! empty( $referral_parts['gclid'] ) || ! empty( $referral_parts['utm_campaign'] ) || ! empty( $referral_parts['gbraid'] ) || ! empty( $referral_parts['wbraid'] ) ) ) {
			Avia_Attribution_Capture::hydrate_session_from_referral( $att_id, $referral_parts );
		}
		// Drop att_id unless the session is a real fresh Google Ads click.
		// Prevents staff/organic events from inheriting a stale CRM avia_att_id / organic cookie.
		if ( ! empty( $att_id ) && ! Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $att_id ) ) {
			$att_id = null;
		}

		global $wpdb;
		$table = Avia_Attribution_Database::events_table();
		$now   = current_time( 'mysql' );

		if ( $event_id && self::is_duplicate_event( $event_id ) ) {
			return array(
				'ledger_event_id'   => 0,
				'event_type'        => $event_type,
				'att_id'            => null,
				'writeback_message' => 'duplicate_skipped',
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'event_source'        => 'boulevard',
				'event_type'          => $event_type,
				'blvd_event_id'       => $event_id,
				'blvd_location_id'    => $location_id,
				'blvd_client_id'      => $client_id,
				'blvd_appointment_id' => $appointment,
				'att_id'              => $att_id,
				'status'              => $status,
				'revenue_cents'       => $revenue_cents,
				'payload_json'        => wp_json_encode( $payload ),
				'processed'           => 0,
				'created_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			if ( $event_id && self::is_duplicate_event( $event_id ) ) {
				return array(
					'ledger_event_id'   => 0,
					'event_type'        => $event_type,
					'att_id'            => null,
					'writeback_message' => 'duplicate_skipped',
				);
			}

			$message = 'Could not store webhook event.';
			if ( Avia_Attribution_Settings::is_debug_enabled() && ! empty( $wpdb->last_error ) ) {
				$message .= ' db=' . $wpdb->last_error;
			}

			return new WP_Error( 'db_insert_failed', $message );
		}

		if ( ! empty( $client_id ) ) {
			foreach ( Avia_Attribution_Boulevard_Api::blvd_client_id_lookup_values( $client_id ) as $lid ) {
				delete_transient( 'avia_blvd_client_v8_' . md5( $lid ) );
			}
		}

		$writeback_message = 'unmatched';
		if ( $att_id ) {
			self::link_session_to_boulevard( $att_id, $client_id, $appointment, $email, $phone );
			if ( $client_id ) {
				if ( self::should_skip_client_writeback( $event_type, $att_id, $client_id ) ) {
					$writeback_message = 'writeback_skipped';
				} else {
					$writeback_result = Avia_Attribution_Boulevard_Api::schedule_client_writeback( $client_id, $att_id );
					if ( is_wp_error( $writeback_result ) ) {
						$writeback_message = 'writeback_failed:' . $writeback_result->get_error_code();
						if ( Avia_Attribution_Settings::is_debug_enabled() ) {
							error_log( '[AVIA Attribution] Client write-back failed: ' . $writeback_result->get_error_message() );
						}
					} else {
						$writeback_message = 'writeback_ok';
						self::mark_client_writeback_done( $client_id, $att_id );
					}
				}
			}
		}

		if ( Avia_Attribution_Settings::is_debug_enabled() ) {
			error_log( '[AVIA Attribution] Webhook stored: ' . $event_type . ' att_id=' . ( $att_id ? $att_id : 'unmatched' ) . ' writeback=' . $writeback_message );
		}

		$stored_result = array(
			'ledger_event_id'   => (int) $wpdb->insert_id,
			'event_type'        => $event_type,
			'att_id'            => $att_id,
			'revenue_cents'     => $revenue_cents,
			'writeback_message' => $writeback_message,
		);

		if ( class_exists( 'Avia_Attribution_Google_Ads_Offline' ) ) {
			$upload_result = Avia_Attribution_Google_Ads_Offline::maybe_queue_from_stored_event( $stored_result, $payload );
			if ( is_wp_error( $upload_result ) ) {
				$error_code = $upload_result->get_error_code();
				if ( 'avia_upload_deferred' === $error_code ) {
					$stored_result['writeback_message'] .= '+upload_deferred_order';
				} elseif ( 'avia_upload_duplicate' === $error_code ) {
					$stored_result['writeback_message'] .= '+upload_duplicate_skipped';
				} elseif ( Avia_Attribution_Settings::is_debug_enabled() ) {
					error_log( '[AVIA Attribution] Google Ads queue failed: ' . $upload_result->get_error_message() );
				}
			} elseif ( true === $upload_result ) {
				$stored_result['writeback_message'] .= '+ads_uploaded';
			} elseif ( is_int( $upload_result ) ) {
				$stored_result['writeback_message'] .= '+ads_queued';
			}
		}

		return $stored_result;
	}

	public static function verify_signature( WP_REST_Request $request, $raw_body, $secret ) {
		$body_length = strlen( (string) $raw_body );
		$salt     = Avia_Attribution_Webhook_Body::get_hmac_value( 'salt', $request );
		$expected = Avia_Attribution_Webhook_Body::get_hmac_value( 'signature', $request );

		if ( ! $salt || ! $expected ) {
			return array(
				'valid'        => false,
				'reason'       => 'missing_headers',
				'salt_prefix'  => '',
				'body_length'  => $body_length,
				'header_keys'  => Avia_Attribution_Webhook_Body::get_detected_hmac_header_keys(),
				'missing_salt' => ! $salt,
				'missing_sig'  => ! $expected,
			);
		}

		$timestamp_check = self::validate_salt_timestamp( $salt );
		if ( ! $timestamp_check['valid'] ) {
			return array(
				'valid'       => false,
				'reason'      => $timestamp_check['reason'],
				'salt_prefix' => substr( $salt, 0, 20 ),
				'body_length' => $body_length,
			);
		}

		$key = base64_decode( trim( (string) $secret ), true );
		if ( false === $key || '' === $key ) {
			if ( Avia_Attribution_Settings::is_debug_enabled() ) {
				$raw_key = trim( (string) $secret );
				if ( '' !== $raw_key ) {
					$key = $raw_key;
				}
			}
		}

		if ( false === $key || '' === $key ) {
			return array(
				'valid'       => false,
				'reason'      => 'invalid_secret',
				'salt_prefix' => substr( $salt, 0, 20 ),
				'body_length' => $body_length,
			);
		}

		$payload  = $salt . ':' . $raw_body;
		$computed = base64_encode( hash_hmac( 'sha256', $payload, $key, true ) );

		if ( hash_equals( $expected, $computed ) ) {
			return array(
				'valid'       => true,
				'reason'      => null,
				'salt_prefix' => substr( $salt, 0, 20 ),
				'body_length' => $body_length,
			);
		}

		return array(
			'valid'       => false,
			'reason'      => 'signature_mismatch',
			'salt_prefix' => substr( $salt, 0, 20 ),
			'body_length' => $body_length,
		);
	}

	private static function validate_salt_timestamp( $salt ) {
		$parts = explode( ':', (string) $salt );
		if ( count( $parts ) < 3 ) {
			return array(
				'valid'  => true,
				'reason' => null,
			);
		}

		$timestamp = (int) end( $parts );
		if ( $timestamp <= 0 ) {
			return array(
				'valid'  => true,
				'reason' => null,
			);
		}

		$now = time();

		if ( $timestamp < ( $now - self::SALT_MAX_AGE_SECONDS ) ) {
			return array(
				'valid'  => false,
				'reason' => 'stale_timestamp',
			);
		}

		if ( $timestamp > ( $now + 60 ) ) {
			return array(
				'valid'  => false,
				'reason' => 'future_timestamp',
			);
		}

		return array(
			'valid'  => true,
			'reason' => null,
		);
	}

	private static function format_signature_failure_message( array $verify_result ) {
		$reason  = ! empty( $verify_result['reason'] ) ? $verify_result['reason'] : 'signature_mismatch';
		$message = 'invalid_signature:' . $reason;

		if ( Avia_Attribution_Settings::is_debug_enabled() ) {
			$message .= ' body_len=' . (int) ( $verify_result['body_length'] ?? 0 );
			if ( ! empty( $verify_result['salt_prefix'] ) ) {
				$message .= ' salt_prefix=' . substr( (string) $verify_result['salt_prefix'], 0, 20 );
			}
			if ( 'missing_headers' === $reason ) {
				$header_keys = $verify_result['header_keys'] ?? array();
				$message    .= ' headers_found=' . ( empty( $header_keys ) ? 'none' : implode( ',', $header_keys ) );
				if ( ! empty( $verify_result['missing_salt'] ) ) {
					$message .= ' need=x-blvd-hmac-salt|x-blvd-oauth-hmac-salt';
				}
				if ( ! empty( $verify_result['missing_sig'] ) ) {
					$message .= ' need=x-blvd-hmac-sha256|x-blvd-oauth-hmac-sha256';
				}
			}
		}

		return $message;
	}

	private static function is_internal_admin_ping( $payload ) {
		// Payload flag alone is not enough — must be set by admin dispatch_internal_webhook_test().
		return self::$allow_internal_ping
			&& is_array( $payload )
			&& ! empty( $payload['source'] )
			&& 'avia-admin-test' === (string) $payload['source'];
	}

	private static function is_ping_event( $payload, $raw_body ) {
		if ( is_array( $payload ) ) {
			if ( ! empty( $payload['eventType'] ) && 'PING' === strtoupper( (string) $payload['eventType'] ) ) {
				return true;
			}
			if ( ! empty( $payload['event'] ) && 'ping' === strtolower( (string) $payload['event'] ) ) {
				return true;
			}
			if ( ! empty( $payload['ping'] ) && self::is_internal_admin_ping( $payload ) ) {
				return true;
			}
		}

		return false !== stripos( (string) $raw_body, '"eventType":"PING"' );
	}

	private static function resolve_event_type( array $payload ) {
		foreach ( array( 'eventType', 'event', 'type', 'topic' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				return sanitize_text_field( (string) $payload[ $key ] );
			}
		}
		if ( ! empty( $payload['data']['node']['__typename'] ) ) {
			return sanitize_text_field( (string) $payload['data']['node']['__typename'] );
		}
		if ( ! empty( $payload['data']['__typename'] ) ) {
			return sanitize_text_field( (string) $payload['data']['__typename'] );
		}
		return 'unknown';
	}

	private static function resolve_event_id( array $payload, $event_type = '' ) {
		$direct_id = self::resolve_path(
			$payload,
			array( 'idempotencyKey', 'eventId', 'id', 'data.eventId', 'data.id' )
		);
		if ( $direct_id ) {
			return substr( sanitize_text_field( (string) $direct_id ), 0, 128 );
		}

		if ( ! $event_type ) {
			$event_type = self::resolve_event_type( $payload );
		}

		$timestamp = self::resolve_path( $payload, array( 'timestamp', 'createdAt', 'data.timestamp' ) );
		$resource  = self::resolve_path( $payload, array( 'resource', 'data.resource' ) );
		$node_id   = self::resolve_path( $payload, array( 'data.node.id', 'data.id' ) );

		if ( $event_type && ( $timestamp || $node_id ) ) {
			$composite = implode(
				':',
				array_filter(
					array(
						$event_type,
						$timestamp,
						$resource,
						$node_id,
					)
				)
			);

			return substr( hash( 'sha256', $composite ), 0, 64 );
		}

		return substr( hash( 'sha256', (string) wp_json_encode( $payload ) ), 0, 64 );
	}

	private static function resolve_node_typename( array $payload ) {
		$typename = self::resolve_path( $payload, array( 'data.node.__typename', 'data.__typename' ) );
		return $typename ? strtoupper( (string) $typename ) : '';
	}

	private static function resolve_client_id( array $payload ) {
		if ( 'CLIENT' === self::resolve_node_typename( $payload ) ) {
			$client_id = self::resolve_path( $payload, array( 'data.node.id' ) );
			if ( $client_id ) {
				return Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $client_id );
			}
		}

		$client_id = self::resolve_path(
			$payload,
			array(
				'data.node.clientId',
				'data.node.client.id',
				'data.clientId',
				'data.client.id',
				'data.appointment.clientId',
			)
		);

		return Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $client_id );
	}

	private static function resolve_appointment_id( array $payload ) {
		$typename = self::resolve_node_typename( $payload );
		if ( in_array( $typename, array( 'CLIENT', 'ORDER', 'REFERRAL' ), true ) ) {
			return null;
		}

		return self::resolve_path(
			$payload,
			array(
				'data.node.id',
				'data.appointmentId',
				'data.appointment.id',
				'data.id',
			)
		);
	}

	private static function resolve_path( array $payload, array $paths ) {
		foreach ( $paths as $path ) {
			$value = self::get_nested_value( $payload, $path );
			if ( null !== $value && '' !== $value ) {
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}
		}
		return null;
	}

	private static function get_nested_value( array $payload, $path ) {
		$current = $payload;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	private static function resolve_revenue_cents( array $payload ) {
		$amount = self::resolve_path(
			$payload,
			array(
				'data.node.summary.currentTotal.amount',
				'data.node.order.total',
				'data.total',
				'data.totalAmount',
				'data.order.total',
				'data.payment.amount',
			)
		);
		if ( null === $amount || ! is_numeric( $amount ) ) {
			return null;
		}

		return Avia_Attribution_Boulevard_Api::money_to_cents( array( 'amount' => $amount ) );
	}

	private static function resolve_att_id( array $payload, $email, $phone, $client_id = null ) {
		$att_id = self::resolve_path(
			$payload,
			array(
				'data.node.client.customFields.avia_att_id',
				'data.client.customFields.avia_att_id',
				'data.customFields.avia_att_id',
				'data.attributionId',
				'data.node.client.externalId',
				'data.node.client.external_id',
				'data.client.externalId',
				'data.client.external_id',
				'data.node.externalId',
				'data.node.external_id',
				'data.externalId',
				'data.external_id',
			)
		);
		$att_id = self::normalize_att_id_candidate( $att_id );

		if ( ! $att_id ) {
			$att_id = self::normalize_att_id_candidate( self::resolve_custom_field_value( $payload, 'avia_att_id' ) );
		}

		if ( ! $att_id ) {
			$att_id = self::resolve_att_id_from_referral_source(
				self::resolve_path(
					$payload,
					array(
						'data.node.referralSource',
						'data.node.referral_source',
						'data.referralSource',
						'data.referral_source',
						'data.node.clientMessage',
						'data.node.client_message',
						'data.clientMessage',
					)
				)
			);
		}

		// Production rule: only website Book Now / CRM write-back / externalId proof may attach an ads session.
		// Do NOT match by email/phone alone against all sessions (Baylee-style false Click IDs).

		return $att_id ? (string) $att_id : null;
	}

	/**
	 * @param mixed $value Raw externalId / custom field.
	 * @return string|null UUID att_id.
	 */
	private static function normalize_att_id_candidate( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			return strtolower( $value );
		}
		if ( preg_match( '/avia:([0-9a-f-]{36})/i', $value, $matches ) ) {
			return strtolower( $matches[1] );
		}
		return null;
	}

	/**
	 * att_id from a normalized Boulevard client node (externalId or avia_att_id custom field).
	 *
	 * @param array $client Normalized client.
	 * @return string|null
	 */
	private static function att_id_from_blvd_client_node( array $client ) {
		$candidate = self::normalize_att_id_candidate( $client['external_id'] ?? '' );
		if ( ! $candidate && ! empty( $client['custom_fields']['avia_att_id'] ) ) {
			$candidate = self::normalize_att_id_candidate( $client['custom_fields']['avia_att_id'] );
		}
		if ( ! $candidate ) {
			return null;
		}
		if ( ! Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $candidate ) ) {
			return null;
		}
		return $candidate;
	}

	private static function should_skip_client_writeback( $event_type, $att_id, $client_id ) {
		if ( empty( $att_id ) || empty( $client_id ) ) {
			return true;
		}

		// Boulevard UPDATE webhooks do not include customFields and fire very frequently.
		if ( in_array( $event_type, array( 'CLIENT_UPDATED', 'APPOINTMENT_UPDATED' ), true ) ) {
			return true;
		}

		if ( ! in_array( $event_type, array( 'CLIENT_CREATED', 'APPOINTMENT_CREATED' ), true ) ) {
			return true;
		}

		return self::client_writeback_already_done( $client_id, $att_id );
	}

	private static function client_writeback_cache_key( $client_id, $att_id ) {
		return 'avia_wb_' . md5( (string) $client_id . '|' . (string) $att_id );
	}

	private static function client_writeback_already_done( $client_id, $att_id ) {
		return (bool) get_transient( self::client_writeback_cache_key( $client_id, $att_id ) );
	}

	private static function mark_client_writeback_done( $client_id, $att_id ) {
		set_transient(
			self::client_writeback_cache_key( $client_id, $att_id ),
			'1',
			DAY_IN_SECONDS * 90
		);
	}

	private static function resolve_custom_field_value( array $payload, $field_key ) {
		$paths = array(
			'data.node.customFields',
			'data.node.client.customFields',
			'data.client.customFields',
			'data.customFields',
		);

		foreach ( $paths as $path ) {
			$fields = self::get_nested_value( $payload, $path );
			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$key = $field['key'] ?? '';
				if ( $field_key !== $key ) {
					continue;
				}

				$value = $field['textValue'] ?? ( $field['value'] ?? ( $field['stringValue'] ?? '' ) );
				if ( null !== $value && '' !== $value ) {
					return (string) $value;
				}
			}
		}

		return null;
	}

	private static function resolve_att_id_from_referral_source( $referral_source ) {
		$parts = self::parse_referral_attribution( $referral_source );
		return ! empty( $parts['att_id'] ) ? $parts['att_id'] : null;
	}

	/**
	 * Gather bridge attribution from webhook paths, appointment custom fields, CRM fields, and raw JSON.
	 *
	 * @param array $payload Webhook payload.
	 * @return array{att_id?:string,gclid?:string,gbraid?:string,wbraid?:string,utm_campaign?:string,utm_source?:string,utm_medium?:string}
	 */
	private static function collect_referral_attribution_parts( array $payload ) {
		$candidates = array();

		$raw_paths = array(
			'data.node.referralSource',
			'data.node.referral_source',
			'data.node.client.referralSource',
			'data.node.client.referral_source',
			'data.client.referralSource',
			'data.client.referral_source',
			'data.referralSource',
			'data.referral_source',
			'data.node.clientMessage',
			'data.node.client_message',
			'data.clientMessage',
		);
		foreach ( $raw_paths as $path ) {
			$val = self::resolve_path( $payload, array( $path ) );
			if ( null !== $val && '' !== $val ) {
				$candidates[] = $val;
			}
		}

		foreach ( array( 'referral_source', 'referralSource', 'clientMessage', 'client_message' ) as $cf_key ) {
			$cf = self::resolve_custom_field_value( $payload, $cf_key );
			if ( $cf ) {
				$candidates[] = $cf;
			}
		}

		$parts = array();
		foreach ( $candidates as $candidate ) {
			$parsed = self::parse_referral_attribution( $candidate );
			if ( ! empty( $parsed ) ) {
				$parts = array_merge( $parts, $parsed );
			}
		}

		$scanned = self::scan_payload_for_referral_attribution( $payload );
		if ( ! empty( $scanned ) ) {
			$parts = array_merge( $parts, $scanned );
		}

		// Direct custom field copies from prior write-back / URL mapping.
		$map = array(
			'att_id'       => 'avia_att_id',
			'gclid'        => 'avia_gclid',
			'gbraid'       => 'avia_gbraid',
			'wbraid'       => 'avia_wbraid',
			'utm_campaign' => 'avia_utm_campaign',
			'utm_source'   => 'avia_utm_source',
			'utm_medium'   => 'avia_utm_medium',
		);
		foreach ( $map as $part_key => $field_key ) {
			if ( ! empty( $parts[ $part_key ] ) ) {
				continue;
			}
			$val = self::resolve_custom_field_value( $payload, $field_key );
			if ( $val ) {
				$parts[ $part_key ] = $val;
			}
		}

		return $parts;
	}

	/**
	 * Last-resort: find avia:…|gclid:…|campaign:… anywhere in the webhook JSON.
	 *
	 * @param array $payload Webhook payload.
	 * @return array
	 */
	private static function scan_payload_for_referral_attribution( array $payload ) {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || false === stripos( $json, 'avia:' ) ) {
			return array();
		}
		if ( ! preg_match( '/avia:[0-9a-f-]{36}(?:\|[^"\\\\]*)?/i', $json, $matches ) ) {
			return array();
		}

		return self::parse_referral_attribution( stripcslashes( $matches[0] ) );
	}

	/**
	 * Parse bridge referral_source: avia:UUID|gclid:…|campaign:… (campaign may be encodeURIComponent'd).
	 *
	 * @param mixed $referral_source Raw referral (string or Boulevard {name} object).
	 * @return array{att_id?:string,gclid?:string,gbraid?:string,wbraid?:string,utm_campaign?:string}
	 */
	private static function parse_referral_attribution( $referral_source ) {
		if ( is_array( $referral_source ) ) {
			$referral_source = $referral_source['name'] ?? ( $referral_source['textValue'] ?? ( $referral_source['value'] ?? '' ) );
		}
		$referral_source = trim( (string) $referral_source );
		$out             = array();
		// Plain CRM labels ("Google Ads") are not bridge proof — require avia: token.
		if ( '' === $referral_source || false === stripos( $referral_source, 'avia:' ) ) {
			return $out;
		}

		if ( preg_match( '/avia:([0-9a-f-]{36})/i', $referral_source, $matches ) ) {
			$out['att_id'] = $matches[1];
		}
		if ( preg_match( '/(?:^|\|)gclid:([A-Za-z0-9_-]+)/', $referral_source, $matches ) ) {
			$out['gclid'] = $matches[1];
		}
		if ( preg_match( '/(?:^|\|)gbraid:([A-Za-z0-9_-]+)/', $referral_source, $matches ) ) {
			$out['gbraid'] = $matches[1];
		}
		if ( preg_match( '/(?:^|\|)wbraid:([A-Za-z0-9_-]+)/', $referral_source, $matches ) ) {
			$out['wbraid'] = $matches[1];
		}
		// Encoded campaign is a single pipe segment; legacy unencoded = remainder after "campaign:".
		if ( preg_match( '/(?:^|\|)campaign:([^|]*)/', $referral_source, $matches ) ) {
			$campaign = rawurldecode( $matches[1] );
			// Legacy: campaign name itself contained "|" — take everything after first "campaign:".
			if ( false !== strpos( $referral_source, '|' ) && preg_match( '/\|campaign:(.+)$/s', $referral_source, $legacy ) ) {
				$legacy_campaign = $legacy[1];
				if ( strlen( $legacy_campaign ) > strlen( $campaign ) && false !== strpos( $legacy_campaign, '|' ) ) {
					$campaign = $legacy_campaign;
				}
			}
			$campaign = trim( $campaign );
			if ( '' !== $campaign ) {
				$out['utm_campaign'] = $campaign;
			}
		}

		return $out;
	}

	private static function match_att_id_by_identity( $email, $phone ) {
		global $wpdb;
		$table      = Avia_Attribution_Database::sessions_table();
		$email_hash = Avia_Attribution_Capture::hash_email( $email );
		$phone_hash = Avia_Attribution_Capture::hash_phone( $phone );
		$cutoff     = Avia_Attribution_Ads_Integrity::match_cutoff_mysql();

		if ( $email_hash ) {
			$att_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT att_id FROM {$table} WHERE client_email_hash = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
					$email_hash,
					$cutoff
				)
			);
			if ( $att_id ) {
				return $att_id;
			}
		}

		if ( $phone_hash ) {
			$att_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT att_id FROM {$table} WHERE client_phone_hash = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
					$phone_hash,
					$cutoff
				)
			);
			if ( $att_id ) {
				return $att_id;
			}
		}

		return null;
	}

	private static function match_att_id_by_blvd_client_id( $client_id ) {
		$ids = Avia_Attribution_Boulevard_Api::blvd_client_id_lookup_values( $client_id );
		if ( empty( $ids ) ) {
			return null;
		}

		global $wpdb;
		$table        = Avia_Attribution_Database::sessions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$cutoff       = Avia_Attribution_Ads_Integrity::match_cutoff_mysql();
		$params       = array_merge( $ids, array( $cutoff ) );

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT att_id FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND created_at >= %s
				ORDER BY id DESC
				LIMIT 1",
				...$params
			)
		);
	}

	private static function link_session_to_boulevard( $att_id, $client_id, $appointment_id, $email, $phone ) {
		global $wpdb;
		$table = Avia_Attribution_Database::sessions_table();
		$data  = array(
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $client_id ) {
			$data['blvd_client_id'] = $client_id;
		}
		if ( $appointment_id ) {
			$data['blvd_appointment_id'] = $appointment_id;
		}
		$email_hash = Avia_Attribution_Capture::hash_email( $email );
		$phone_hash = Avia_Attribution_Capture::hash_phone( $phone );
		if ( $email_hash ) {
			$data['client_email_hash'] = $email_hash;
		}
		if ( $phone_hash ) {
			$data['client_phone_hash'] = $phone_hash;
		}

		$wpdb->update( $table, $data, array( 'att_id' => $att_id ) );
	}

	private static function is_duplicate_event( $event_id ) {
		global $wpdb;
		$table = Avia_Attribution_Database::events_table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE blvd_event_id = %s LIMIT 1",
				$event_id
			)
		);
	}
}
