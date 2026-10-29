<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Boulevard_Api {

	const TOKEN_URL      = 'https://dashboard.boulevard.io/oauth2/token';
	const ADMIN_API_URL  = 'https://dashboard.boulevard.io/api/2026-06/admin';
	const ADMIN_RESOURCE = 'https://dashboard.boulevard.io/api/2026-06/admin';

	const WEBHOOK_EVENT_TYPES = array(
		'APPOINTMENT_CREATED',
		'APPOINTMENT_UPDATED',
		'APPOINTMENT_CANCELLED',
		'APPOINTMENT_COMPLETED',
		'ORDER_COMPLETED',
		'CLIENT_CREATED',
		'CLIENT_UPDATED',
		'REFERRAL_CREATED',
		'REFERRAL_UPDATED',
	);

	public static function get_locations( $include_timezone = false ) {
		$query = wp_json_encode( array( 'query' => 'query { locations(first: 50) { edges { node { id name ' . ( $include_timezone ? 'tz' : '' ) . ' } } } }' ) );
		$result = self::graphql( $query );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$edges = $result['data']['locations']['edges'] ?? array();
		$list  = array();

		foreach ( $edges as $edge ) {
			if ( empty( $edge['node']['id'] ) ) {
				continue;
			}
			$list[] = array(
				'id'   => $edge['node']['id'],
				'name' => $edge['node']['name'] ?? '',
				'tz' => $edge['node']['tz'] ?? '',
			);
		}

		return $list;
	}

	public static function register_webhooks_for_all_locations() {
		$locations = self::get_locations();
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}

		if ( empty( $locations ) ) {
			return new WP_Error( 'avia_no_locations', 'No Boulevard locations returned for this business.' );
		}

		self::clean_duplicate_webhooks();

		$report      = self::get_webhook_health_report();
		$webhook_url = rest_url( 'avia/v1/boulevard-webhook' );
		$results     = array();

		foreach ( $locations as $location ) {
			if ( ! is_wp_error( $report ) ) {
				$existing = self::find_best_webhook_for_location( $report, $location['name'] );
				if ( $existing && self::is_webhook_healthy( $existing ) ) {
					Avia_Attribution_Database::upsert_webhook(
						$location['id'],
						$location['name'],
						$existing['id'],
						$webhook_url
					);
					$results[] = array(
						'location_id'   => $location['id'],
						'location_name' => $location['name'],
						'success'       => true,
						'webhook_id'    => $existing['id'],
						'skipped'       => true,
						'message'       => 'Healthy webhook already exists for this location.',
					);
					continue;
				}
			}

			$mutation = self::build_create_webhook_mutation( $location['id'], $location['name'], $webhook_url );
			$result   = self::graphql( $mutation );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'location_id'   => $location['id'],
					'location_name' => $location['name'],
					'success'       => false,
					'message'       => $result->get_error_message(),
				);
				continue;
			}

			if ( ! empty( $result['errors'] ) ) {
				$results[] = array(
					'location_id'   => $location['id'],
					'location_name' => $location['name'],
					'success'       => false,
					'message'       => wp_json_encode( $result['errors'] ),
				);
				continue;
			}

			$webhook = $result['data']['createWebhook']['webhook'] ?? array();
			Avia_Attribution_Database::upsert_webhook(
				$location['id'],
				$location['name'],
				$webhook['id'] ?? '',
				$webhook_url
			);

			$results[] = array(
				'location_id'   => $location['id'],
				'location_name' => $location['name'],
				'success'       => true,
				'webhook_id'    => $webhook['id'] ?? '',
			);
		}

		Avia_Attribution_Settings::mark_webhooks_synced();
		return $results;
	}

	public static function test_connection() {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$locations = self::get_locations();
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}

		return array(
			'token_ok'  => true,
			'locations' => $locations,
		);
	}

	public static function get_webhook_health_report() {
		$result = self::graphql(
			'{ "query": "query { webhooks(first: 50) { edges { node { id name url subscriptions { id eventType enabled } } } } }" }'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_blvd_graphql_error', wp_json_encode( $result['errors'] ) );
		}

		$stored     = Avia_Attribution_Database::get_webhooks();
		$by_blvd_id = array();
		foreach ( $stored as $row ) {
			$by_blvd_id[ $row['blvd_webhook_id'] ] = $row['location_name'];
		}

		$webhooks        = array();
		$disabled_count  = 0;
		$missing_events  = array();

		foreach ( $result['data']['webhooks']['edges'] ?? array() as $edge ) {
			$node = $edge['node'] ?? array();
			if ( empty( $node['id'] ) ) {
				continue;
			}

			$subscriptions = array();
			$enabled_types = array();

			foreach ( $node['subscriptions'] ?? array() as $subscription ) {
				$event_type = $subscription['eventType'] ?? '';
				$enabled    = ! empty( $subscription['enabled'] );
				if ( ! $enabled ) {
					++$disabled_count;
				} else {
					$enabled_types[] = $event_type;
				}

				$subscriptions[] = array(
					'id'        => $subscription['id'] ?? '',
					'eventType' => $event_type,
					'enabled'   => $enabled,
				);
			}

			$missing_for_webhook = array_diff( self::WEBHOOK_EVENT_TYPES, $enabled_types );
			if ( ! empty( $missing_for_webhook ) ) {
				$missing_events[ $node['id'] ] = array_values( $missing_for_webhook );
			}

			$webhooks[] = array(
				'id'            => $node['id'],
				'name'          => $node['name'] ?? '',
				'url'           => $node['url'] ?? '',
				'location_name' => $by_blvd_id[ $node['id'] ] ?? '',
				'subscriptions' => $subscriptions,
			);
		}

		return array(
			'webhooks'       => $webhooks,
			'disabled_count' => $disabled_count,
			'missing_events' => $missing_events,
			'checked_at'     => current_time( 'mysql' ),
		);
	}

	public static function repair_disabled_subscriptions() {
		$report = self::get_webhook_health_report();
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$results = array();
		foreach ( $report['webhooks'] as $webhook ) {
			$disabled = array_values(
				array_filter(
					$webhook['subscriptions'],
					static function ( $subscription ) {
						return empty( $subscription['enabled'] );
					}
				)
			);

			if ( empty( $disabled ) ) {
				$results[] = array(
					'webhook_id'   => $webhook['id'],
					'webhook_name' => $webhook['name'],
					'success'      => true,
					'message'      => 'No disabled subscriptions.',
					'repaired'     => 0,
				);
				continue;
			}

			$mutation = self::build_repair_webhook_mutation( $webhook['id'], $disabled );
			$result   = self::graphql( $mutation );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'webhook_id'   => $webhook['id'],
					'webhook_name' => $webhook['name'],
					'success'      => false,
					'message'      => $result->get_error_message(),
					'repaired'     => 0,
				);
				continue;
			}

			if ( ! empty( $result['errors'] ) ) {
				$results[] = array(
					'webhook_id'   => $webhook['id'],
					'webhook_name' => $webhook['name'],
					'success'      => false,
					'message'      => wp_json_encode( $result['errors'] ),
					'repaired'     => 0,
				);
				continue;
			}

			$results[] = array(
				'webhook_id'   => $webhook['id'],
				'webhook_name' => $webhook['name'],
				'success'      => true,
				'message'      => 'Disabled subscriptions recreated.',
				'repaired'     => count( $disabled ),
			);
		}

		return $results;
	}

	public static function re_register_all_webhooks() {
		$report = self::get_webhook_health_report();
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$deleted = array();
		foreach ( $report['webhooks'] as $webhook ) {
			$delete_result = self::delete_webhook( $webhook['id'] );
			$deleted[]     = array(
				'webhook_id' => $webhook['id'],
				'success'    => ! is_wp_error( $delete_result ),
				'message'    => is_wp_error( $delete_result ) ? $delete_result->get_error_message() : 'Deleted',
			);
		}

		$created = self::register_webhooks_for_all_locations();
		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'avia_reregister_failed',
				$created->get_error_message(),
				array( 'deleted' => $deleted )
			);
		}

		return array(
			'deleted' => $deleted,
			'created' => $created,
		);
	}

	private static function delete_webhook( $webhook_id ) {
		$id     = self::escape_gql_string( $webhook_id );
		$body   = wp_json_encode(
			array(
				'query' => 'mutation { deleteWebhook(input: { id: "' . $id . '" }) { webhook { id } } }',
			)
		);
		$result = self::graphql( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_delete_webhook_failed', wp_json_encode( $result['errors'] ) );
		}

		$deleted_id = $result['data']['deleteWebhook']['webhook']['id'] ?? '';
		if ( '' === $deleted_id ) {
			return new WP_Error(
				'avia_delete_webhook_unconfirmed',
				'Boulevard did not confirm webhook deletion. It may belong to a previous app install and cannot be removed with the current API credentials.'
			);
		}

		return $deleted_id;
	}

	private static function build_repair_webhook_mutation( $webhook_id, array $disabled_subscriptions ) {
		$subscription_parts = array();
		foreach ( $disabled_subscriptions as $subscription ) {
			if ( empty( $subscription['id'] ) || empty( $subscription['eventType'] ) ) {
				continue;
			}
			$subscription_parts[] = '{ id: "' . self::escape_gql_string( $subscription['id'] ) . '", delete: true }';
			$subscription_parts[] = '{ eventType: ' . $subscription['eventType'] . ' }';
		}

		$query = 'mutation { updateWebhook(input: { id: "' . self::escape_gql_string( $webhook_id ) . '", subscriptions: [ ' . implode( ', ', $subscription_parts ) . ' ] }) { webhook { id subscriptions { id eventType enabled } } } }';

		return wp_json_encode( array( 'query' => $query ) );
	}

	private static function build_create_webhook_mutation( $location_id, $location_name, $webhook_url ) {
		$name = self::escape_gql_string( 'AVIA Google Attribution - ' . $location_name );
		$url  = self::escape_gql_string( esc_url_raw( $webhook_url ) );
		$id   = self::escape_gql_string( $location_id );

		$subscription_parts = array();
		foreach ( self::WEBHOOK_EVENT_TYPES as $event_type ) {
			$subscription_parts[] = '{ eventType: ' . $event_type . ' }';
		}

		$query = 'mutation { createWebhook(input: { locationId: "' . $id . '", url: "' . $url . '", name: "' . $name . '", subscriptions: [ ' . implode( ', ', $subscription_parts ) . ' ] }) { webhook { id url subscriptions { id eventType enabled } } } }';

		return wp_json_encode( array( 'query' => $query ) );
	}

	private static function escape_gql_string( $value ) {
		return addcslashes( (string) $value, "\\\"\n\r\t" );
	}

	private static function graphql( $body ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::ADMIN_API_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
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
				'avia_blvd_http_error',
				sprintf( 'Boulevard API HTTP %d: %s', $code, $raw )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'avia_blvd_invalid_json', 'Boulevard API returned invalid JSON.' );
		}

		return $data;
	}

	private static function get_access_token() {
		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			return new WP_Error( 'avia_missing_credentials', 'Boulevard credentials are not configured.' );
		}

		$cached = get_transient( 'avia_blvd_access_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$settings = Avia_Attribution_Settings::get_all();
		$body     = wp_json_encode(
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $settings['blvd_client_id'],
				'client_secret' => $settings['blvd_client_secret'],
				'business_id'   => $settings['blvd_business_id'],
				'resource'      => self::ADMIN_RESOURCE,
			)
		);

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			$message = is_array( $data ) && ! empty( $data['error_description'] )
				? $data['error_description']
				: $raw;
			return new WP_Error( 'avia_blvd_auth_failed', $message );
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$ttl        = max( 300, $expires_in - 120 );
		set_transient( 'avia_blvd_access_token', $data['access_token'], $ttl );

		return $data['access_token'];
	}

	public static function clear_token_cache() {
		delete_transient( 'avia_blvd_access_token' );
	}

	public static function clean_duplicate_webhooks() {
		$report = self::get_webhook_health_report();
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$before_count = self::count_our_webhooks( $report['webhooks'] ?? array() );
		$stored       = Avia_Attribution_Database::get_webhooks();
		$stored_ids   = array();
		foreach ( $stored as $row ) {
			if ( ! empty( $row['blvd_webhook_id'] ) ) {
				$stored_ids[ $row['blvd_webhook_id'] ] = true;
			}
		}

		$groups = self::group_our_webhooks_by_location( $report['webhooks'] ?? array() );

		$results          = array();
		$kept_by_location = array();

		foreach ( $groups as $location_key => $webhooks ) {
			if ( count( $webhooks ) <= 1 ) {
				$kept_by_location[ $location_key ] = $webhooks[0]['id'];
				continue;
			}

			$keep = self::pick_webhook_to_keep( $webhooks, $stored_ids );
			if ( empty( $keep['id'] ) ) {
				continue;
			}

			$kept_by_location[ $location_key ] = $keep['id'];

			foreach ( $webhooks as $webhook ) {
				if ( $webhook['id'] === $keep['id'] ) {
					continue;
				}

				$delete_result = self::delete_webhook( $webhook['id'] );
				$results[]     = array(
					'webhook_id'   => $webhook['id'],
					'webhook_name' => $webhook['name'] ?? '',
					'location_key' => $location_key,
					'kept'         => false,
					'success'      => ! is_wp_error( $delete_result ),
					'message'      => is_wp_error( $delete_result ) ? $delete_result->get_error_message() : 'Deleted duplicate',
				);
			}

			$results[] = array(
				'webhook_id'   => $keep['id'],
				'webhook_name' => $keep['name'] ?? '',
				'location_key' => $location_key,
				'kept'         => true,
				'success'      => true,
				'message'      => sprintf(
					'Kept webhook (%d enabled subscriptions)',
					self::count_enabled_subscriptions( $keep )
				),
			);
		}

		if ( ! empty( $kept_by_location ) ) {
			self::sync_stored_webhook_ids( $kept_by_location );
		}

		$after_report = self::get_webhook_health_report();
		$after_count  = is_wp_error( $after_report ) ? $before_count : self::count_our_webhooks( $after_report['webhooks'] ?? array() );

		return array(
			'results' => $results,
			'summary' => array(
				'before_count' => $before_count,
				'after_count'  => $after_count,
				'expected_count' => max( 1, count( $groups ) ),
			),
		);
	}

	private static function count_our_webhooks( array $webhooks ) {
		$count = 0;
		foreach ( $webhooks as $webhook ) {
			if ( empty( $webhook['url'] ) || ! self::is_our_webhook_url( $webhook['url'] ) ) {
				continue;
			}
			++$count;
		}

		return $count;
	}

	private static function group_our_webhooks_by_location( array $webhooks ) {
		$groups = array();
		foreach ( $webhooks as $webhook ) {
			if ( empty( $webhook['id'] ) || empty( $webhook['url'] ) ) {
				continue;
			}
			if ( ! self::is_our_webhook_url( $webhook['url'] ) ) {
				continue;
			}

			$location_key = self::extract_location_key_from_webhook_name( $webhook['name'] ?? '' );
			if ( '' === $location_key ) {
				$location_key = $webhook['id'];
			}
			if ( ! isset( $groups[ $location_key ] ) ) {
				$groups[ $location_key ] = array();
			}
			$groups[ $location_key ][] = $webhook;
		}

		return $groups;
	}

	private static function location_keys_match( $left, $right ) {
		$left  = trim( (string) $left );
		$right = trim( (string) $right );
		if ( '' === $left || '' === $right ) {
			return false;
		}
		if ( $left === $right ) {
			return true;
		}

		return 0 === strpos( $left, $right ) || 0 === strpos( $right, $left );
	}

	private static function find_best_webhook_for_location( array $report, $location_name ) {
		$best = null;
		foreach ( $report['webhooks'] ?? array() as $webhook ) {
			if ( empty( $webhook['url'] ) || ! self::is_our_webhook_url( $webhook['url'] ) ) {
				continue;
			}

			$location_key = self::extract_location_key_from_webhook_name( $webhook['name'] ?? '' );
			if ( ! self::location_keys_match( $location_key, $location_name ) ) {
				continue;
			}

			if ( null === $best || self::count_enabled_subscriptions( $webhook ) > self::count_enabled_subscriptions( $best ) ) {
				$best = $webhook;
			}
		}

		return $best;
	}

	private static function is_webhook_healthy( array $webhook ) {
		return self::count_enabled_subscriptions( $webhook ) >= count( self::WEBHOOK_EVENT_TYPES );
	}

	private static function count_enabled_subscriptions( array $webhook ) {
		$count = 0;
		foreach ( $webhook['subscriptions'] ?? array() as $subscription ) {
			if ( ! empty( $subscription['enabled'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function pick_webhook_to_keep( array $webhooks, array $stored_ids ) {
		usort(
			$webhooks,
			static function ( $a, $b ) use ( $stored_ids ) {
				$a_enabled = self::count_enabled_subscriptions( $a );
				$b_enabled = self::count_enabled_subscriptions( $b );
				if ( $a_enabled !== $b_enabled ) {
					return $b_enabled <=> $a_enabled;
				}

				$a_stored = ! empty( $stored_ids[ $a['id'] ?? '' ] ) ? 1 : 0;
				$b_stored = ! empty( $stored_ids[ $b['id'] ?? '' ] ) ? 1 : 0;
				if ( $a_stored !== $b_stored ) {
					return $b_stored <=> $a_stored;
				}

				return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
			}
		);

		return $webhooks[0] ?? array();
	}

	private static function sync_stored_webhook_ids( array $kept_by_location ) {
		$locations = self::get_locations();
		if ( is_wp_error( $locations ) ) {
			return;
		}

		$webhook_url = rest_url( 'avia/v1/boulevard-webhook' );
		foreach ( $kept_by_location as $location_key => $webhook_id ) {
			foreach ( $locations as $location ) {
				$name = (string) ( $location['name'] ?? '' );
				if ( $name !== $location_key && 0 !== strpos( $name, $location_key ) && 0 !== strpos( $location_key, $name ) ) {
					continue;
				}

				Avia_Attribution_Database::upsert_webhook(
					$location['id'],
					$location['name'],
					$webhook_id,
					$webhook_url
				);
				break;
			}
		}
	}

	private static function is_our_webhook_url( $url ) {
		return false !== strpos( strtolower( (string) $url ), '/wp-json/avia/v1/boulevard-webhook' );
	}

	private static function normalize_webhook_url( $url ) {
		$url = untrailingslashit( strtolower( (string) $url ) );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}

		$host = preg_replace( '/^www\./', '', $parts['host'] );
		$path = $parts['path'] ?? '';

		return $host . $path;
	}

	private static function extract_location_key_from_webhook_name( $name ) {
		$prefix = 'AVIA Google Attribution - ';
		if ( 0 === strpos( (string) $name, $prefix ) ) {
			return trim( substr( (string) $name, strlen( $prefix ) ) );
		}

		return trim( (string) $name );
	}

	public static function attribution_custom_field_keys() {
		return array(
			'avia_att_id',
			'avia_gclid',
			'avia_gbraid',
			'avia_wbraid',
			'avia_utm_source',
			'avia_utm_medium',
			'avia_utm_campaign',
		);
	}

	public static function lookup_client_for_verification( $email = '', $gclid = '', $att_id = '' ) {
		$email  = sanitize_email( trim( (string) $email ) );
		$gclid  = sanitize_text_field( trim( (string) $gclid ) );
		$att_id = sanitize_text_field( trim( (string) $att_id ) );

		if ( '' === $email && '' === $gclid && '' === $att_id ) {
			return new WP_Error( 'avia_lookup_empty', 'Enter an email, gclid, or att_id.' );
		}

		$lookup_methods = array();
		$local_sessions = array();
		$client_map     = array();
		$att_ids        = array();

		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'avia_lookup_invalid_email', 'Enter a valid email address.' );
			}

			$lookup_methods[] = 'email';
			$by_email         = self::search_clients_by_email( $email );
			if ( is_wp_error( $by_email ) ) {
				return $by_email;
			}

			foreach ( $by_email as $client ) {
				$client_map[ $client['id'] ] = $client;
			}
		}

		if ( '' !== $gclid ) {
			$lookup_methods[] = 'gclid';
			$local_sessions   = array_merge( $local_sessions, self::find_local_sessions( array( 'gclid' => $gclid ) ) );
		}

		if ( '' !== $att_id ) {
			$lookup_methods[] = 'att_id';
			$local_sessions   = array_merge( $local_sessions, self::find_local_sessions( array( 'att_id' => $att_id ) ) );
		}

		$local_sessions = self::dedupe_sessions( $local_sessions );

		foreach ( $local_sessions as $session ) {
			if ( ! empty( $session['att_id'] ) ) {
				$att_ids[] = $session['att_id'];
			}
			if ( ! empty( $session['blvd_client_id'] ) ) {
				$client_map[ $session['blvd_client_id'] ] = null;
			}
		}

		foreach ( self::find_event_client_links( $att_ids ) as $link ) {
			if ( ! empty( $link['blvd_client_id'] ) ) {
				$client_map[ $link['blvd_client_id'] ] = null;
			}
		}

		$clients = array();
		foreach ( array_keys( $client_map ) as $client_id ) {
			$client = self::get_client_by_id( $client_id );
			if ( is_wp_error( $client ) ) {
				return $client;
			}
			if ( ! empty( $client ) ) {
				$clients[] = self::attach_local_context( $client, $local_sessions );
			}
		}

		$event_links       = self::find_event_client_links( $att_ids );
		$client_timelines  = array();
		foreach ( array_keys( $client_map ) as $client_id ) {
			if ( is_string( $client_id ) && '' !== $client_id ) {
				$client_timelines[ $client_id ] = self::get_client_event_timeline( $client_id );
			}
		}

		return array(
			'lookup_methods'   => array_values( array_unique( $lookup_methods ) ),
			'clients'          => $clients,
			'local_sessions'   => array_map( array( __CLASS__, 'format_local_session' ), $local_sessions ),
			'event_links'      => $event_links,
			'client_timelines' => $client_timelines,
			'writeback_ok'     => self::assess_writeback_status( $clients ),
		);
	}

	public static function get_client_event_timeline( $blvd_client_id, $limit = 50 ) {
		global $wpdb;

		$blvd_client_id = sanitize_text_field( (string) $blvd_client_id );
		if ( '' === $blvd_client_id ) {
			return array();
		}

		$table = Avia_Attribution_Database::events_table();
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, blvd_client_id, blvd_appointment_id, att_id, status, revenue_cents, payload_json, created_at
				FROM {$table}
				WHERE blvd_client_id = %s
				ORDER BY created_at ASC, id ASC
				LIMIT %d",
				$blvd_client_id,
				$limit
			),
			ARRAY_A
		);

		$timeline = array();
		foreach ( (array) $rows as $row ) {
			$timeline[] = self::summarize_ledger_event( $row );
		}

		return $timeline;
	}

	public static function summarize_ledger_event( array $row ) {
		$payload = json_decode( $row['payload_json'] ?? '', true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$node       = $payload['data']['node'] ?? array();
		$event_type = (string) ( $row['event_type'] ?? '' );
		$tag        = '';
		$tag_class  = '';
		$detail     = '';

		switch ( $event_type ) {
			case 'APPOINTMENT_CANCELLED':
				$reason     = is_array( $node['cancellation'] ?? null ) ? (string) ( $node['cancellation']['reason'] ?? '' ) : '';
				$tag        = self::format_cancellation_reason( $reason );
				$tag_class  = 'avia-event-cancelled';
				$detail     = $tag ? 'Cancelled · ' . $tag : 'Cancelled';
				break;
			case 'APPOINTMENT_COMPLETED':
				$detail = 'Appointment completed';
				if ( ! empty( $row['revenue_cents'] ) ) {
					$detail .= ' · $' . number_format( ( (int) $row['revenue_cents'] ) / 100, 2 );
				}
				break;
			case 'APPOINTMENT_CREATED':
				$detail = 'Appointment booked';
				break;
			case 'APPOINTMENT_UPDATED':
				$detail = 'Appointment updated';
				break;
			case 'CLIENT_CREATED':
				$detail = 'Client created';
				break;
			case 'CLIENT_UPDATED':
				$detail = 'Client profile updated';
				break;
			case 'ORDER_COMPLETED':
				$detail = 'Order completed';
				if ( ! empty( $row['revenue_cents'] ) ) {
					$detail .= ' · $' . number_format( ( (int) $row['revenue_cents'] ) / 100, 2 );
				}
				break;
			default:
				$detail = '';
		}

		return array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'event_type'          => $event_type,
			'event_badge_class'   => self::ledger_event_badge_class( $event_type ),
			'created_at'          => $row['created_at'] ?? '',
			'blvd_appointment_id' => $row['blvd_appointment_id'] ?? '',
			'att_id'              => $row['att_id'] ?? '',
			'detail'              => $detail,
			'tag'                 => $tag,
			'tag_class'           => $tag_class,
		);
	}

	public static function format_cancellation_reason( $reason ) {
		$reason = strtoupper( sanitize_text_field( (string) $reason ) );
		$labels = array(
			'NO_SHOW'            => 'No show',
			'CLIENT_CANCEL'      => 'Client cancelled',
			'MISTAKE'            => 'Mistake or error',
			'STAFF_CANCEL'       => 'Staff cancelled',
			'CLIENT_LATE_CANCEL' => 'Client late cancelled',
		);

		if ( isset( $labels[ $reason ] ) ) {
			return $labels[ $reason ];
		}

		if ( '' === $reason ) {
			return '';
		}

		return ucwords( str_replace( '_', ' ', strtolower( $reason ) ) );
	}

	public static function cancellation_reason_from_payload( array $payload ) {
		$node = $payload['data']['node'] ?? array();
		if ( is_array( $node['cancellation'] ?? null ) && ! empty( $node['cancellation']['reason'] ) ) {
			return (string) $node['cancellation']['reason'];
		}

		return '';
	}

	private static function ledger_event_badge_class( $event_type ) {
		$map = array(
			'APPOINTMENT_CANCELLED' => 'avia-event-cancelled',
			'APPOINTMENT_COMPLETED' => 'avia-event-completed',
			'APPOINTMENT_CREATED'   => 'avia-event-created',
			'CLIENT_CREATED'          => 'avia-event-client',
			'ORDER_COMPLETED'       => 'avia-event-order',
			'APPOINTMENT_UPDATED'     => 'avia-event-updated',
			'CLIENT_UPDATED'          => 'avia-event-updated',
		);

		return $map[ $event_type ] ?? 'avia-event-default';
	}

	public static function search_clients_by_email( $email ) {
		$safe_email = self::escape_gql_string( $email );
		$result     = self::graphql_client_query_variants(
			'query { clients(first: 10, emails: ["' . $safe_email . '"]) { edges { node { %s } } } }'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_client_search_failed', wp_json_encode( $result['errors'] ) );
		}

		$clients = array();
		foreach ( $result['data']['clients']['edges'] ?? array() as $edge ) {
			$node = self::normalize_client_node( $edge['node'] ?? array() );
			if ( ! empty( $node['id'] ) ) {
				$clients[] = self::enrich_client_with_ledger_crm_meta( $node );
			}
		}

		return $clients;
	}

	public static function get_client_by_id( $client_id ) {
		$client_id = trim( (string) $client_id );
		if ( '' === $client_id ) {
			return new WP_Error( 'avia_client_id_required', 'Client ID is required.' );
		}

		$safe_id = self::escape_gql_string( $client_id );
		$result  = self::graphql_client_query_variants(
			'query { client(id: "' . $safe_id . '") { %s } }'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_client_lookup_failed', wp_json_encode( $result['errors'] ) );
		}

		$node = $result['data']['client'] ?? null;
		if ( empty( $node['id'] ) ) {
			return null;
		}

		return self::enrich_client_with_ledger_crm_meta( self::normalize_client_node( $node ) );
	}

	/**
	 * Try client GraphQL selections from richest → leanest until one succeeds.
	 *
	 * @param string $query_template Query with "%s" placeholder for field selection.
	 * @return array|WP_Error
	 */
	private static function graphql_client_query_variants( $query_template ) {
		$want_tags = ! get_option( 'avia_attribution_blvd_tags_unavailable', false );
		$selections = array(
			self::client_node_selection( $want_tags, true ),
			self::client_node_selection( false, true ),
			self::client_node_selection( $want_tags, false ),
			self::client_node_selection( false, false ),
		);

		$last = null;
		foreach ( array_values( array_unique( $selections ) ) as $selection ) {
			$query  = sprintf( $query_template, $selection );
			$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );
			$last   = $result;
			if ( is_wp_error( $result ) ) {
				continue;
			}
			if ( empty( $result['errors'] ) ) {
				return $result;
			}
			$blob = strtolower( wp_json_encode( $result['errors'] ) );
			if ( false !== strpos( $blob, 'tag' ) ) {
				update_option( 'avia_attribution_blvd_tags_unavailable', 1, false );
			}
		}

		return $last;
	}

	/**
	 * Client fields. Note: staff "Referral Source" is NOT on Client — it lives on Referral
	 * (often urn:blvd:Referral:{same-uuid-as-client}). See fetch_client_referral_source().
	 *
	 * @param bool $include_tags        Include tags { id name symbol }.
	 * @param bool $include_external_id Include externalId (Book Now stamps avia att_id).
	 * @return string
	 */
	private static function client_node_selection( $include_tags = true, $include_external_id = true ) {
		$keys  = self::build_custom_field_keys_gql();
		$extra = $include_tags ? ' tags { id name symbol }' : '';
		$id    = $include_external_id ? 'id externalId' : 'id';

		return $id . ' firstName lastName email mobilePhone createdAt' . $extra
			. ' customFields(keys: [' . $keys . ']) { key ... on NativeFieldTextValue { textValue } }';
	}

	/**
	 * Fill referral from ledger when live Client GraphQL has none.
	 * Do NOT merge ledger tags into live client — empty CRM tags means staff removed them
	 * (e.g. Avia Leads). List views still read tags from ledger/payload separately.
	 *
	 * @param array $client Normalized client node.
	 * @return array
	 */
	private static function enrich_client_with_ledger_crm_meta( array $client ) {
		$client_id = self::normalize_blvd_client_id( $client['id'] ?? '' );
		if ( '' !== $client_id ) {
			$client['id'] = $client_id;
		}

		// Keep whatever GraphQL returned (including []). Never re-add historical webhook tags.
		if ( ! isset( $client['tags'] ) || ! is_array( $client['tags'] ) ) {
			$client['tags'] = array();
		}

		if ( '' !== (string) ( $client['referral_source'] ?? '' ) ) {
			return $client;
		}

		$meta = self::get_client_crm_meta_from_ledger( $client_id );
		if ( '' !== ( $meta['referral_source'] ?? '' ) ) {
			$client['referral_source'] = $meta['referral_source'];
		}

		return $client;
	}

	/**
	 * Boulevard stores profile Referral Source on a Referral node, not Client.referralSource.
	 * Referral id is often a distinct UUID (not always the client UUID). Prefer ledger REFERRAL_*
	 * payloads; GraphQL tries Referral-by-mirrored-id, client.referral, and referrals(clientId).
	 * Requires Boulevard app scope referral:read (manifest v1.0.3+).
	 *
	 * @param string $client_id Client URN.
	 * @return string Referral source display name or empty.
	 */
	public static function fetch_client_referral_source( $client_id ) {
		$client_id = self::normalize_blvd_client_id( $client_id );
		if ( '' === $client_id ) {
			return '';
		}

		if ( get_option( 'avia_attribution_blvd_referral_unavailable', false ) ) {
			return '';
		}

		$uuid        = substr( $client_id, strlen( 'urn:blvd:Client:' ) );
		$referral_id = 'urn:blvd:Referral:' . $uuid;
		$safe_ref     = self::escape_gql_string( $referral_id );
		$safe_client  = self::escape_gql_string( $client_id );
		$referral_sel = 'id referralSource { id name } referrerClient { id firstName lastName }';

		# List pages never call this; details use it as last resort only (keep short).
		$queries = array(
			'query { node(id: "' . $safe_ref . '") { __typename ... on Referral { ' . $referral_sel . ' } } }',
			'query { client(id: "' . $safe_client . '") { id referral { ' . $referral_sel . ' } } }',
		);

		foreach ( $queries as $query ) {
			$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			if ( ! empty( $result['errors'] ) ) {
				$blob = strtolower( wp_json_encode( $result['errors'] ) );
				if ( false !== strpos( $blob, 'referral' ) && ( false !== strpos( $blob, 'scope' ) || false !== strpos( $blob, 'permission' ) || false !== strpos( $blob, 'unauthorized' ) || false !== strpos( $blob, 'forbidden' ) ) ) {
					update_option( 'avia_attribution_blvd_referral_unavailable', 1, false );
					return '';
				}
				continue;
			}

			$node = $result['data']['node'] ?? null;
			if ( ! is_array( $node ) && isset( $result['data']['referral'] ) && is_array( $result['data']['referral'] ) ) {
				$node = $result['data']['referral'];
			}
			if ( ! is_array( $node ) && isset( $result['data']['client']['referral'] ) && is_array( $result['data']['client']['referral'] ) ) {
				$node = $result['data']['client']['referral'];
			}
			if ( ! is_array( $node ) && ! empty( $result['data']['referrals']['edges'] ) && is_array( $result['data']['referrals']['edges'] ) ) {
				foreach ( $result['data']['referrals']['edges'] as $edge ) {
					if ( ! empty( $edge['node'] ) && is_array( $edge['node'] ) ) {
						$label = self::format_referral_node_label( $edge['node'] );
						if ( '' !== $label ) {
							return $label;
						}
					}
				}
				continue;
			}
			if ( ! is_array( $node ) ) {
				continue;
			}

			$label = self::format_referral_node_label( $node );
			if ( '' !== $label ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Normalize Boulevard client ids to urn:blvd:Client:{uuid}.
	 * REFERRAL_* webhooks sometimes send bare UUIDs in data.node.clientId.
	 *
	 * @param mixed $client_id Raw client id.
	 * @return string
	 */
	public static function normalize_blvd_client_id( $client_id ) {
		$client_id = sanitize_text_field( (string) $client_id );
		if ( '' === $client_id ) {
			return '';
		}
		if ( 0 === strpos( $client_id, 'urn:blvd:Client:' ) ) {
			return $client_id;
		}
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $client_id ) ) {
			return 'urn:blvd:Client:' . strtolower( $client_id );
		}
		return $client_id;
	}

	/**
	 * URN + bare UUID forms for SQL lookups against mixed historical rows.
	 *
	 * @param string $client_id Client id (URN or UUID).
	 * @return array
	 */
	public static function blvd_client_id_lookup_values( $client_id ) {
		$normalized = self::normalize_blvd_client_id( $client_id );
		if ( '' === $normalized ) {
			return array();
		}

		$values = array( $normalized );
		if ( 0 === strpos( $normalized, 'urn:blvd:Client:' ) ) {
			$uuid = substr( $normalized, strlen( 'urn:blvd:Client:' ) );
			if ( '' !== $uuid ) {
				$values[] = $uuid;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Merge tag sets by id (fallback: lowercased name). Later sets win on conflict.
	 *
	 * @param mixed ...$tag_sets Raw or normalized tag arrays.
	 * @return array
	 */
	public static function merge_client_tags( ...$tag_sets ) {
		$merged = array();
		foreach ( $tag_sets as $set ) {
			foreach ( self::normalize_client_tags( $set ) as $tag ) {
				$key            = '' !== (string) ( $tag['id'] ?? '' ) ? (string) $tag['id'] : strtolower( (string) $tag['name'] );
				$merged[ $key ] = $tag;
			}
		}
		return array_values( $merged );
	}

	/**
	 * @param array $node Referral GraphQL/webhook node.
	 * @return string
	 */
	public static function format_referral_node_label( array $node ) {
		$source = self::normalize_referral_source( $node['referralSource'] ?? ( $node['referral_source'] ?? '' ) );
		if ( '' !== $source ) {
			return $source;
		}

		$referrer = $node['referrerClient'] ?? ( $node['referrer_client'] ?? null );
		if ( is_array( $referrer ) ) {
			$name = trim( (string) ( $referrer['name'] ?? '' ) );
			if ( '' === $name ) {
				$name = trim( ( (string) ( $referrer['firstName'] ?? '' ) ) . ' ' . ( (string) ( $referrer['lastName'] ?? '' ) ) );
			}
			if ( '' !== $name ) {
				return 'Client referral · ' . $name;
			}
		}

		return '';
	}

	private static function build_custom_field_keys_gql() {
		$parts = array();
		foreach ( self::attribution_custom_field_keys() as $key ) {
			$parts[] = '"' . self::escape_gql_string( $key ) . '"';
		}

		return implode( ', ', $parts );
	}

	private static function normalize_client_node( array $node ) {
		$custom_fields = self::normalize_custom_fields( $node['customFields'] ?? array() );

		$external_id = trim( (string) ( $node['externalId'] ?? ( $node['external_id'] ?? '' ) ) );
		// Bridge stamps avia att_id as Boulevard externalId for concurrent-safe matching.
		if ( '' === $external_id && ! empty( $custom_fields['avia_att_id'] ) ) {
			$external_id = trim( (string) $custom_fields['avia_att_id'] );
		}

		return array(
			'id'              => $node['id'] ?? '',
			'external_id'     => $external_id,
			'first_name'      => $node['firstName'] ?? '',
			'last_name'       => $node['lastName'] ?? '',
			'email'           => $node['email'] ?? '',
			'mobile_phone'    => $node['mobilePhone'] ?? '',
			'created_at'      => $node['createdAt'] ?? '',
			'tags'            => self::normalize_client_tags( $node['tags'] ?? array() ),
			'referral_source' => '',
			'custom_fields'   => $custom_fields,
		);
	}

	/**
	 * Normalize Boulevard Tag objects (or webhook tag payloads) to [{id,name,symbol}, …].
	 *
	 * @param mixed $tags Raw tags from GraphQL or webhook JSON.
	 * @return array
	 */
	public static function normalize_client_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $tags as $tag ) {
			if ( is_string( $tag ) ) {
				$name = trim( $tag );
				if ( '' === $name ) {
					continue;
				}
				$normalized[] = array(
					'id'     => '',
					'name'   => $name,
					'symbol' => '',
				);
				continue;
			}

			if ( ! is_array( $tag ) ) {
				continue;
			}

			$name = trim( (string) ( $tag['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$normalized[] = array(
				'id'     => (string) ( $tag['id'] ?? '' ),
				'name'   => $name,
				'symbol' => (string) ( $tag['symbol'] ?? '' ),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize referral source from GraphQL / webhook (string or {id,name}).
	 *
	 * @param mixed $raw Referral source value.
	 * @return string Display name or empty.
	 */
	public static function normalize_referral_source( $raw ) {
		if ( is_string( $raw ) ) {
			return trim( $raw );
		}
		if ( is_array( $raw ) ) {
			$name = trim( (string) ( $raw['name'] ?? '' ) );
			if ( '' !== $name ) {
				return $name;
			}
			return trim( (string) ( $raw['id'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Pull tags + referral source from a webhook/API payload array.
	 *
	 * @param array $payload Decoded Boulevard webhook JSON.
	 * @return array{tags:array,referral_source:string}
	 */
	public static function extract_crm_meta_from_payload( array $payload ) {
		$has_tags = false;
		$tags_raw = array();
		$paths    = array(
			array( 'data', 'node', 'tags' ),
			array( 'data', 'node', 'client', 'tags' ),
			array( 'data', 'client', 'tags' ),
			array( 'data', 'tags' ),
		);
		foreach ( $paths as $parts ) {
			$cursor = $payload;
			$ok     = true;
			foreach ( $parts as $part ) {
				if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) {
					$ok = false;
					break;
				}
				$cursor = $cursor[ $part ];
			}
			if ( $ok ) {
				$has_tags = true;
				$tags_raw = is_array( $cursor ) ? $cursor : array();
				break;
			}
		}

		$referral = '';
		// REFERRAL_* webhooks: data.node is a Referral with referralSource / referrerClient.
		$typename = strtolower( (string) ( $payload['data']['node']['__typename'] ?? ( $payload['resource'] ?? '' ) ) );
		if ( 'referral' === $typename && isset( $payload['data']['node'] ) && is_array( $payload['data']['node'] ) ) {
			$referral = self::format_referral_node_label( $payload['data']['node'] );
		}

		if ( '' === $referral ) {
			$ref_paths = array(
				array( 'data', 'node', 'referralSource' ),
				array( 'data', 'node', 'referral_source' ),
				array( 'data', 'node', 'referral', 'referralSource' ),
				array( 'data', 'node', 'client', 'referralSource' ),
				array( 'data', 'node', 'client', 'referral_source' ),
				array( 'data', 'client', 'referralSource' ),
				array( 'data', 'referralSource' ),
				array( 'data', 'referral_source' ),
			);
			foreach ( $ref_paths as $parts ) {
				$cursor = $payload;
				$ok     = true;
				foreach ( $parts as $part ) {
					if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) {
						$ok = false;
						break;
					}
					$cursor = $cursor[ $part ];
				}
				if ( $ok && ( is_string( $cursor ) || is_array( $cursor ) ) ) {
					$referral = self::normalize_referral_source( $cursor );
					if ( '' !== $referral ) {
						break;
					}
				}
			}
		}

		return array(
			'has_tags'        => $has_tags,
			'tags'            => self::normalize_client_tags( $tags_raw ),
			'referral_source' => $referral,
		);
	}

	/**
	 * Latest CRM tags / referral source from CLIENT_* / REFERRAL_* ledger rows for a Boulevard client.
	 * Used when GraphQL scopes are missing but webhooks already delivered CRM fields in payload_json.
	 *
	 * Referral source always comes from the newest REFERRAL_CREATED/UPDATED row (staff can change source).
	 *
	 * @param string $blvd_client_id Client URN.
	 * @return array{tags:array,referral_source:string}
	 */
	public static function get_client_crm_meta_from_ledger( $blvd_client_id ) {
		global $wpdb;

		$ids = self::blvd_client_id_lookup_values( $blvd_client_id );
		$out = array(
			'has_tags'        => false,
			'tags'            => array(),
			'referral_source' => '',
		);
		if ( empty( $ids ) ) {
			return $out;
		}

		$table        = Avia_Attribution_Database::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );

		// Newest staff referral change wins (Boulevard may create a new Referral row per change).
		$referral_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload_json
				FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND event_type IN ('REFERRAL_CREATED','REFERRAL_UPDATED')
				ORDER BY created_at DESC, id DESC
				LIMIT 1",
				...$ids
			),
			ARRAY_A
		);
		if ( is_array( $referral_row ) ) {
			$payload = json_decode( (string) ( $referral_row['payload_json'] ?? '' ), true );
			if ( is_array( $payload ) ) {
				$out['referral_source'] = self::extract_crm_meta_from_payload( $payload )['referral_source'] ?? '';
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT payload_json, event_type, created_at
				FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND event_type IN ('CLIENT_CREATED','CLIENT_UPDATED','REFERRAL_CREATED','REFERRAL_UPDATED','APPOINTMENT_CREATED','APPOINTMENT_COMPLETED','ORDER_COMPLETED')
				ORDER BY created_at DESC, id DESC
				LIMIT 40",
				...$ids
			),
			ARRAY_A
		);

		$tags_resolved = false;
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$meta = self::extract_crm_meta_from_payload( $payload );
			if ( ! $tags_resolved && ! empty( $meta['has_tags'] ) ) {
				$out['tags']     = $meta['tags'];
				$out['has_tags'] = true;
				$tags_resolved   = true;
			}
			if ( '' === $out['referral_source'] && '' !== $meta['referral_source'] ) {
				$out['referral_source'] = $meta['referral_source'];
			}
		}

		return $out;
	}

	/**
	 * Batch-load tags + newest referral for many clients (one/two SQL queries total).
	 * Used by dashboard list to avoid N× ledger + N× Boulevard GraphQL.
	 *
	 * @param array $client_ids Client URNs / bare UUIDs.
	 * @return array<string,array{tags:array,referral_source:string}> keyed by normalized Client URN.
	 */
	public static function prefetch_clients_crm_meta( array $client_ids ) {
		global $wpdb;

		$canonical_by_lookup = array();
		$lookup_ids          = array();
		foreach ( $client_ids as $raw_id ) {
			$normalized = self::normalize_blvd_client_id( $raw_id );
			if ( '' === $normalized ) {
				continue;
			}
			foreach ( self::blvd_client_id_lookup_values( $normalized ) as $lookup ) {
				$canonical_by_lookup[ $lookup ] = $normalized;
				$lookup_ids[]                     = $lookup;
			}
		}
		$lookup_ids = array_values( array_unique( $lookup_ids ) );
		$map        = array();
		foreach ( array_unique( array_values( $canonical_by_lookup ) ) as $urn ) {
			$map[ $urn ] = array(
				'has_tags'        => false,
				'tags'            => array(),
				'referral_source' => '',
			);
		}
		if ( empty( $lookup_ids ) ) {
			return $map;
		}

		$table        = Avia_Attribution_Database::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $lookup_ids ), '%s' ) );

		$referral_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blvd_client_id, payload_json, created_at, id
				FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND event_type IN ('REFERRAL_CREATED','REFERRAL_UPDATED')
				ORDER BY created_at DESC, id DESC",
				...$lookup_ids
			),
			ARRAY_A
		);
		foreach ( (array) $referral_rows as $row ) {
			$urn = $canonical_by_lookup[ (string) ( $row['blvd_client_id'] ?? '' ) ] ?? '';
			if ( '' === $urn || '' !== $map[ $urn ]['referral_source'] ) {
				continue;
			}
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$source = self::extract_crm_meta_from_payload( $payload )['referral_source'] ?? '';
			if ( '' !== $source ) {
				$map[ $urn ]['referral_source'] = $source;
			}
		}

		$tag_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT blvd_client_id, payload_json
				FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND event_type IN ('CLIENT_CREATED','CLIENT_UPDATED','APPOINTMENT_CREATED','APPOINTMENT_COMPLETED','ORDER_COMPLETED')
				ORDER BY created_at DESC, id DESC
				LIMIT 200",
				...$lookup_ids
			),
			ARRAY_A
		);
		$tags_resolved = array();
		foreach ( (array) $tag_rows as $row ) {
			$urn = $canonical_by_lookup[ (string) ( $row['blvd_client_id'] ?? '' ) ] ?? '';
			if ( '' === $urn || ! empty( $tags_resolved[ $urn ] ) ) {
				continue;
			}
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$meta = self::extract_crm_meta_from_payload( $payload );
			if ( ! empty( $meta['has_tags'] ) ) {
				$map[ $urn ]['tags']     = $meta['tags'];
				$map[ $urn ]['has_tags'] = true;
				$tags_resolved[ $urn ]   = true;
			}
		}

		return $map;
	}

	/**
	 * Normalize bare-UUID blvd_client_id rows (common on REFERRAL_* webhooks) to Client URNs.
	 *
	 * @return int Rows updated.
	 */
	public static function backfill_bare_uuid_client_ids() {
		global $wpdb;

		$table = Avia_Attribution_Database::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$updated = $wpdb->query(
			"UPDATE {$table}
			SET blvd_client_id = CONCAT('urn:blvd:Client:', LOWER(blvd_client_id))
			WHERE blvd_client_id REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'"
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	private static function normalize_custom_fields( array $fields ) {
		$normalized = array();
		foreach ( self::attribution_custom_field_keys() as $key ) {
			$normalized[ $key ] = '';
		}

		foreach ( $fields as $field ) {
			$key = $field['key'] ?? '';
			if ( '' === $key || ! array_key_exists( $key, $normalized ) ) {
				continue;
			}
			$normalized[ $key ] = (string) ( $field['textValue'] ?? '' );
		}

		return $normalized;
	}

	private static function find_local_sessions( array $args ) {
		global $wpdb;

		$table = Avia_Attribution_Database::sessions_table();
		if ( ! empty( $args['gclid'] ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE gclid = %s ORDER BY id DESC LIMIT 5",
					$args['gclid']
				),
				ARRAY_A
			);
		}

		if ( ! empty( $args['att_id'] ) ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE att_id = %s ORDER BY id DESC LIMIT 5",
					$args['att_id']
				),
				ARRAY_A
			);
		}

		return array();
	}

	private static function dedupe_sessions( array $sessions ) {
		$seen   = array();
		$unique = array();

		foreach ( $sessions as $session ) {
			$key = $session['att_id'] ?? md5( wp_json_encode( $session ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $session;
		}

		return $unique;
	}

	private static function find_event_client_links( array $att_ids ) {
		$att_ids = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $att_id ) {
							return sanitize_text_field( (string) $att_id );
						},
						$att_ids
					)
				)
			)
		);

		if ( empty( $att_ids ) ) {
			return array();
		}

		global $wpdb;

		$table        = Avia_Attribution_Database::events_table();
		$placeholders = implode( ', ', array_fill( 0, count( $att_ids ), '%s' ) );
		$query        = "SELECT att_id, blvd_client_id, event_type, created_at
			FROM {$table}
			WHERE att_id IN ({$placeholders})
				AND blvd_client_id IS NOT NULL
				AND blvd_client_id != ''
			ORDER BY created_at DESC
			LIMIT 10";

		return $wpdb->get_results( $wpdb->prepare( $query, ...$att_ids ), ARRAY_A );
	}

	private static function format_local_session( array $session ) {
		return array(
			'att_id'              => $session['att_id'] ?? '',
			'gclid'               => $session['gclid'] ?? '',
			'gbraid'              => $session['gbraid'] ?? '',
			'wbraid'              => $session['wbraid'] ?? '',
			'utm_source'          => $session['utm_source'] ?? '',
			'utm_medium'          => $session['utm_medium'] ?? '',
			'utm_campaign'        => $session['utm_campaign'] ?? '',
			'blvd_client_id'      => $session['blvd_client_id'] ?? '',
			'blvd_appointment_id' => $session['blvd_appointment_id'] ?? '',
			'landing_page'        => $session['landing_page'] ?? '',
			'created_at'          => $session['created_at'] ?? '',
			'updated_at'          => $session['updated_at'] ?? '',
		);
	}

	private static function attach_local_context( array $client, array $local_sessions ) {
		$matched_sessions = array();
		$client_att_id    = $client['custom_fields']['avia_att_id'] ?? '';

		foreach ( $local_sessions as $session ) {
			$session_att_id = $session['att_id'] ?? '';
			$session_client = $session['blvd_client_id'] ?? '';
			if (
				( $client_att_id && $client_att_id === $session_att_id )
				|| ( $session_client && $session_client === $client['id'] )
			) {
				$matched_sessions[] = self::format_local_session( $session );
			}
		}

		$client['matched_local_sessions'] = $matched_sessions;
		$client['writeback_status']       = self::assess_client_writeback( $client['custom_fields'] );

		return $client;
	}

	private static function assess_writeback_status( array $clients ) {
		if ( empty( $clients ) ) {
			return array(
				'ok'      => false,
				'message' => 'No Boulevard client found for this lookup.',
			);
		}

		foreach ( $clients as $client ) {
			$status = $client['writeback_status'] ?? self::assess_client_writeback( $client['custom_fields'] ?? array() );
			if ( ! empty( $status['ok'] ) ) {
				return $status;
			}
		}

		return self::assess_client_writeback( $clients[0]['custom_fields'] ?? array() );
	}

	private static function assess_client_writeback( array $custom_fields ) {
		$required = array( 'avia_att_id', 'avia_gclid' );
		$missing  = array();

		foreach ( $required as $key ) {
			if ( empty( $custom_fields[ $key ] ) ) {
				$missing[] = $key;
			}
		}

		if ( empty( $missing ) ) {
			return array(
				'ok'      => true,
				'message' => 'Write-back looks good: avia_att_id and avia_gclid are populated.',
			);
		}

		return array(
			'ok'      => false,
			'message' => 'Missing required custom fields: ' . implode( ', ', $missing ) . '.',
		);
	}

	public static function write_client_attribution( $client_id, $att_id ) {
		if ( empty( $client_id ) || empty( $att_id ) ) {
			return new WP_Error( 'avia_writeback_missing_ids', 'Client ID and att_id are required.' );
		}

		global $wpdb;
		$table   = Avia_Attribution_Database::sessions_table();
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE att_id = %s LIMIT 1",
				$att_id
			),
			ARRAY_A
		);

		if ( empty( $session ) ) {
			return new WP_Error( 'avia_writeback_no_session', 'No attribution session found for att_id.' );
		}

		$clicks = Avia_Attribution_Ads_Integrity::effective_click_ids( $session );

		// Only write attribution fields for genuine fresh ads clicks — never stamp organic/stale sessions onto CRM.
		if ( empty( $clicks['is_ads_attributed'] ) ) {
			return new WP_Error( 'avia_writeback_not_ads', 'Session is not a fresh Google Ads click; skipping CRM attribution write-back.' );
		}

		$fields = array(
			'avia_att_id'       => $att_id,
			'avia_utm_source'   => $session['utm_source'] ?? '',
			'avia_utm_medium'   => $session['utm_medium'] ?? '',
			'avia_utm_campaign' => $session['utm_campaign'] ?? '',
			'avia_gclid'        => $clicks['gclid'],
			'avia_gbraid'       => $clicks['gbraid'],
			'avia_wbraid'       => $clicks['wbraid'],
		);

		$result = self::update_client_custom_fields( $client_id, $fields );
		if ( is_wp_error( $result ) ) {
			if ( Avia_Attribution_Settings::is_debug_enabled() ) {
				error_log( '[AVIA Attribution] Client write-back failed: ' . $result->get_error_message() );
			}
			return $result;
		}

		// Persist externalId so later webhooks (out of order) can resolve att_id without the intent queue.
		self::maybe_set_client_external_id( $client_id, $att_id );

		$tag_result = self::maybe_assign_avia_leads_tag( $client_id );
		if ( is_wp_error( $tag_result ) && Avia_Attribution_Settings::is_debug_enabled() ) {
			error_log( '[AVIA Attribution] Avia Leads tag: ' . $tag_result->get_error_message() );
		}

		if ( Avia_Attribution_Settings::is_debug_enabled() ) {
			error_log( '[AVIA Attribution] Client write-back OK client=' . $client_id . ' att_id=' . $att_id );
		}

		return true;
	}

	public static function schedule_client_writeback( $client_id, $att_id ) {
		if ( empty( $client_id ) || empty( $att_id ) ) {
			return null;
		}

		$result = self::write_client_attribution( $client_id, $att_id );

		// Fallback for hosts that terminate the REST request before shutdown handlers run.
		add_action(
			'shutdown',
			static function () use ( $client_id, $att_id, $result ) {
				if ( ! is_wp_error( $result ) ) {
					return;
				}
				self::write_client_attribution( $client_id, $att_id );
			}
		);

		return $result;
	}

	public static function update_client_custom_fields( $client_id, array $fields ) {
		$field_parts = array();
		foreach ( $fields as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$field_parts[] = '{ key: "' . self::escape_gql_string( (string) $key ) . '", textValue: "' . self::escape_gql_string( (string) $value ) . '" }';
		}

		if ( empty( $field_parts ) ) {
			return new WP_Error( 'avia_writeback_empty_fields', 'No custom fields to write.' );
		}

		$id    = self::escape_gql_string( $client_id );
		$query = 'mutation { updateClient(input: { id: "' . $id . '", customFields: [ ' . implode( ', ', $field_parts ) . ' ] }) { client { id } } }';
		$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_update_client_failed', wp_json_encode( $result['errors'] ) );
		}

		return $result['data']['updateClient']['client'] ?? true;
	}

	/**
	 * Read Book Now attribution proof blob from an appointment (referral_source / notes / message).
	 * Bridge stamps avia:UUID|gclid:… into CreateCartInput.referralSource → appointment field.
	 *
	 * @param string $appointment_id Appointment URN.
	 * @return string Raw referral-like string containing avia:UUID, or empty.
	 */
	public static function fetch_appointment_attribution_blob( $appointment_id ) {
		$appointment_id = trim( (string) $appointment_id );
		if ( '' === $appointment_id ) {
			return '';
		}

		$safe = self::escape_gql_string( $appointment_id );
		$keys = '"referral_source", "referralSource", "clientMessage", "client_message", "avia_att_id"';
		$queries = array(
			'query { appointment(id: "' . $safe . '") { id notes clientMessage customFields(keys: [' . $keys . ']) { key ... on NativeFieldTextValue { textValue } } } }',
			'query { node(id: "' . $safe . '") { __typename ... on Appointment { id notes clientMessage customFields(keys: [' . $keys . ']) { key ... on NativeFieldTextValue { textValue } } } } }',
			'query { appointment(id: "' . $safe . '") { id notes } }',
		);

		$blob = '';
		foreach ( $queries as $query ) {
			$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );
			if ( is_wp_error( $result ) || ! empty( $result['errors'] ) ) {
				continue;
			}
			$node = $result['data']['appointment'] ?? ( $result['data']['node'] ?? null );
			if ( ! is_array( $node ) ) {
				continue;
			}
			$parts = array();
			foreach ( array( 'notes', 'clientMessage', 'client_message' ) as $k ) {
				if ( ! empty( $node[ $k ] ) ) {
					$parts[] = (string) $node[ $k ];
				}
			}
			$fields = self::normalize_custom_fields( $node['customFields'] ?? array() );
			foreach ( $fields as $val ) {
				if ( '' !== trim( (string) $val ) ) {
					$parts[] = (string) $val;
				}
			}
			$blob = implode( "\n", $parts );
			if ( '' !== $blob ) {
				break;
			}
		}

		if ( '' === $blob ) {
			return '';
		}

		if ( preg_match( '/avia:[0-9a-f-]{36}(?:\|[^\s]*)?/i', $blob, $full ) ) {
			return $full[0];
		}

		return '';
	}

	/**
	 * Set client.externalId = att_id when empty or already our UUID (Book Now / concurrent match).
	 * Never overwrite a different third-party externalId.
	 *
	 * @param string $client_id Client URN.
	 * @param string $att_id    Session UUID.
	 * @return true|WP_Error|null Null when skipped.
	 */
	public static function maybe_set_client_external_id( $client_id, $att_id ) {
		$client_id = trim( (string) $client_id );
		$att_id    = strtolower( trim( (string) $att_id ) );
		if ( '' === $client_id || '' === $att_id ) {
			return null;
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $att_id ) ) {
			return null;
		}

		$client = self::get_client_by_id( $client_id );
		if ( is_wp_error( $client ) || ! is_array( $client ) ) {
			return is_wp_error( $client ) ? $client : null;
		}

		$existing = strtolower( trim( (string) ( $client['external_id'] ?? '' ) ) );
		if ( '' !== $existing && $existing !== $att_id ) {
			// Already set by another system — leave alone.
			return null;
		}
		if ( $existing === $att_id ) {
			return true;
		}

		$id    = self::escape_gql_string( $client_id );
		$ext   = self::escape_gql_string( $att_id );
		$query = 'mutation { updateClient(input: { id: "' . $id . '", externalId: "' . $ext . '" }) { client { id externalId } } }';
		$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['errors'] ) ) {
			if ( Avia_Attribution_Settings::is_debug_enabled() ) {
				error_log( '[AVIA Attribution] externalId write-back skipped: ' . wp_json_encode( $result['errors'] ) );
			}
			return new WP_Error( 'avia_external_id_failed', wp_json_encode( $result['errors'] ) );
		}

		return true;
	}

	/**
	 * Clear AVIA attribution custom fields on a Boulevard client (empty text values).
	 *
	 * @param string $client_id Client URN.
	 * @return true|WP_Error
	 */
	public static function clear_client_attribution_fields( $client_id ) {
		$client_id = self::normalize_blvd_client_id( $client_id );
		if ( '' === $client_id ) {
			return new WP_Error( 'avia_clear_missing_client', 'Client ID required.' );
		}

		$keys = array(
			'avia_att_id',
			'avia_gclid',
			'avia_gbraid',
			'avia_wbraid',
			'avia_utm_source',
			'avia_utm_medium',
			'avia_utm_campaign',
		);

		$field_parts = array();
		foreach ( $keys as $key ) {
			$field_parts[] = '{ key: "' . self::escape_gql_string( $key ) . '", textValue: "" }';
		}

		$id    = self::escape_gql_string( $client_id );
		$query = 'mutation { updateClient(input: { id: "' . $id . '", customFields: [ ' . implode( ', ', $field_parts ) . ' ] }) { client { id } } }';
		$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_clear_client_failed', wp_json_encode( $result['errors'] ) );
		}

		return true;
	}

	/**
	 * Assign CRM tag "Avia Leads" after a successful AVIA website-matched write-back.
	 *
	 * @param string $client_id Client URN.
	 * @return true|WP_Error|null Null when skipped.
	 */
	public static function maybe_assign_avia_leads_tag( $client_id ) {
		$client_id = self::normalize_blvd_client_id( $client_id );
		if ( '' === $client_id ) {
			return null;
		}

		$tag_id = self::resolve_avia_leads_tag_id();
		if ( is_wp_error( $tag_id ) ) {
			return $tag_id;
		}
		if ( '' === $tag_id ) {
			return new WP_Error( 'avia_leads_tag_missing', 'Boulevard tag "Avia Leads" not found. Create it in Boulevard CRM, then retry.' );
		}

		$safe_client = self::escape_gql_string( $client_id );
		$safe_tag    = self::escape_gql_string( $tag_id );
		$query       = 'mutation { addTag(input: { entityId: "' . $safe_client . '", tagId: "' . $safe_tag . '" }) { tags { id name } } }';
		$result      = self::graphql( wp_json_encode( array( 'query' => $query ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			$blob = strtolower( wp_json_encode( $result['errors'] ) );
			// Already tagged / duplicate — treat as success.
			if ( false !== strpos( $blob, 'already' ) || false !== strpos( $blob, 'duplicate' ) || false !== strpos( $blob, 'exists' ) ) {
				return true;
			}
			if ( false !== strpos( $blob, 'tag:write' ) || false !== strpos( $blob, 'permission' ) || false !== strpos( $blob, 'unauthorized' ) ) {
				update_option( 'avia_attribution_blvd_tag_write_unavailable', 1, false );
			}
			return new WP_Error( 'avia_add_tag_failed', wp_json_encode( $result['errors'] ) );
		}

		return true;
	}

	/**
	 * Resolve (and cache) the Boulevard tag ID for "Avia Leads".
	 *
	 * @return string|WP_Error Tag URN or empty string if not found.
	 */
	public static function resolve_avia_leads_tag_id() {
		$cached = get_option( 'avia_attribution_avia_leads_tag_id', '' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$result = self::graphql(
			wp_json_encode(
				array(
					'query' => 'query { tags(first: 100) { edges { node { id name } } } }',
				)
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_tags_query_failed', wp_json_encode( $result['errors'] ) );
		}

		$edges = $result['data']['tags']['edges'] ?? array();
		foreach ( (array) $edges as $edge ) {
			$name = trim( (string) ( $edge['node']['name'] ?? '' ) );
			if ( 0 === strcasecmp( $name, 'Avia Leads' ) ) {
				$id = (string) ( $edge['node']['id'] ?? '' );
				if ( '' !== $id ) {
					update_option( 'avia_attribution_avia_leads_tag_id', $id, false );
					return $id;
				}
			}
		}

		return '';
	}

	public static function enrich_order_revenue_context( array $payload, $event_type = '', $order_id_override = '' ) {
		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			return array();
		}

		$order_id = sanitize_text_field( (string) $order_id_override );
		if ( '' === $order_id ) {
			$order_id = self::resolve_order_id_from_payload( $payload, $event_type );
		}
		if ( ! $order_id ) {
			return array();
		}

		$order = self::get_order_by_id( $order_id );
		if ( is_wp_error( $order ) ) {
			return array(
				'order_id'    => $order_id,
				'fetch_error' => $order->get_error_code(),
			);
		}

		if ( empty( $order ) ) {
			return array(
				'order_id'    => $order_id,
				'fetch_error' => 'order_missing',
			);
		}

		$context = array(
			'order_id'      => $order_id,
			'client_id'     => $order['clientId'] ?? '',
			'location_id'   => $order['locationId'] ?? '',
			'revenue_cents' => self::money_to_cents( $order['summary']['currentTotal'] ?? null ),
		);

		if ( ! empty( $order['clientId'] ) ) {
			$client = self::get_client_by_id( $order['clientId'] );
			if ( ! is_wp_error( $client ) && ! empty( $client['custom_fields']['avia_att_id'] ) ) {
				$candidate = sanitize_text_field( (string) $client['custom_fields']['avia_att_id'] );
				// Only reuse CRM att_id when that session still passes ads integrity (fresh click + campaign).
				if ( '' !== $candidate && self::session_passes_ads_upload_gate( $candidate ) ) {
					$context['att_id'] = $candidate;
				}
			}
		}

		return $context;
	}

	/**
	 * Whether a session att_id is eligible for offline Ads upload right now.
	 *
	 * @param string $att_id Session UUID.
	 * @return bool
	 */
	public static function session_passes_ads_upload_gate( $att_id ) {
		global $wpdb;

		$att_id = sanitize_text_field( (string) $att_id );
		if ( '' === $att_id ) {
			return false;
		}

		$table   = Avia_Attribution_Database::sessions_table();
		$session = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE att_id = %s LIMIT 1", $att_id ),
			ARRAY_A
		);
		if ( empty( $session ) ) {
			return false;
		}

		$eval = Avia_Attribution_Ads_Integrity::evaluate_for_upload( $session );
		return ! empty( $eval['ok'] );
	}

	public static function resolve_order_id_from_payload( array $payload, $event_type = '' ) {
		$typename = strtoupper( (string) ( $payload['data']['node']['__typename'] ?? '' ) );
		if ( 'ORDER' === $typename && ! empty( $payload['data']['node']['id'] ) ) {
			return sanitize_text_field( (string) $payload['data']['node']['id'] );
		}

		if ( ! empty( $payload['data']['node']['orderId'] ) ) {
			return sanitize_text_field( (string) $payload['data']['node']['orderId'] );
		}

		if ( 'ORDER_COMPLETED' === $event_type && ! empty( $payload['data']['node']['id'] ) ) {
			return sanitize_text_field( (string) $payload['data']['node']['id'] );
		}

		return '';
	}

	/**
	 * Boulevard Sales Summary–style total collected:
	 * closed orders in range (minus account-credit adjustments) plus refunds closed in range
	 * against orders that closed outside the range (e.g. #6070 −$51.50).
	 */
	public static function get_net_sales( $range_key, $start_date = '', $end_date = '' ) {
		$range     = Avia_Attribution_Dashboard::resolve_range( $range_key, $start_date, $end_date );
		$cache_key = 'avia_blvd_collected_v9_' . md5( Avia_Attribution_Settings::get( 'blvd_business_id' ) . '|' . $range['start'] . '|' . $range['end'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$locations = self::get_locations( true );
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}
		if ( empty( $locations ) ) {
			return new WP_Error( 'avia_net_locations', 'No Boulevard locations available. Check location:read access.' );
		}
		if ( count( $locations ) >= 50 ) {
			return new WP_Error( 'avia_net_locations_limit', 'Location list may be incomplete. Revenue is unavailable until all locations can be fetched.' );
		}
		$timezones       = array();
		$total           = 0;
		$components      = array( 'currentTotal' => 0, 'currentTaxAmount' => 0, 'currentGratuityAmount' => 0, 'currentFeeAmount' => 0 );
		$count           = 0;
		$excluded_count  = 0;
		$excluded_cents  = 0;
		$refund_count    = 0;
		$refund_cents    = 0;
		$seen            = array();
		$seen_refunds    = array();
		$order_audit     = array();
		$deadline        = microtime( true ) + 55;
		$orders_query    = 'query($location: ID!, $after: String, $filter: QueryString) { orders(locationId: $location, first: 100, after: $after, query: $filter) { edges { node { id number note closedAt lineGroups { lines { __typename id currentSubtotal currentDiscountAmount } } summary { currentTotal currentTaxAmount currentGratuityAmount currentFeeAmount } } } pageInfo { hasNextPage endCursor } } }';
		$refunds_query   = 'query($location: ID!, $after: String, $filter: QueryString) { orders(locationId: $location, first: 100, after: $after, query: $filter) { edges { node { id number note closedAt refunds { id number closedAt status summary { refundTotal refundTax } } } } pageInfo { hasNextPage endCursor } } }';

		foreach ( $locations as $location ) {
			try {
				if ( empty( $location['tz'] ) ) {
					throw new Exception( 'Missing location timezone' );
				}
				$timezone = new DateTimeZone( $location['tz'] );
				$start    = new DateTimeImmutable( $range['start_date'] . ' 00:00:00', $timezone );
				$end      = ( new DateTimeImmutable( $range['end_date'] . ' 00:00:00', $timezone ) )->modify( '+1 day' );
			} catch ( Exception $e ) {
				return new WP_Error( 'avia_location_timezone', 'Boulevard location timezone unavailable. No estimated total is shown.' );
			}
			$timezones[] = $timezone->getName();

			$after = null;
			do {
				if ( microtime( true ) > $deadline ) {
					return new WP_Error( 'avia_net_timeout', 'Boulevard revenue fetch exceeded its time limit. No partial total is shown.' );
				}
				$filter = "closedAt >= '" . $start->format( DATE_ATOM ) . "' AND closedAt < '" . $end->format( DATE_ATOM ) . "'";
				$result = self::graphql( wp_json_encode( array( 'query' => $orders_query, 'variables' => array( 'location' => $location['id'], 'after' => $after, 'filter' => $filter ) ) ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! empty( $result['errors'] ) || ! isset( $result['data']['orders']['pageInfo'] ) ) {
					return new WP_Error( 'avia_net_fetch', 'Could not fetch complete Boulevard orders. Check order:read access and API date-query support.' );
				}
				$connection = $result['data']['orders'];
				foreach ( $connection['edges'] ?? array() as $edge ) {
					$order = $edge['node'] ?? array();
					if ( empty( $order['id'] ) || empty( $order['closedAt'] ) || isset( $seen[ $order['id'] ] ) ) {
						continue;
					}
					try {
						$closed = new DateTimeImmutable( $order['closedAt'] );
					} catch ( Exception $e ) {
						return new WP_Error( 'avia_net_date', 'Invalid Boulevard order date.' );
					}
					if ( $closed < $start || $closed >= $end ) {
						continue;
					}
					$net = self::net_order_sales_cents( $order['summary'] ?? array() );
					if ( is_wp_error( $net ) ) {
						return $net;
					}
					$seen[ $order['id'] ] = true;
					$is_adjustment        = self::is_account_credit_adjustment( $order );
					$order_audit[]        = array(
						'included'         => ! $is_adjustment,
						'exclusion_reason' => $is_adjustment ? 'Account credit adjustment' : '',
						'order_id'         => $order['id'],
						'order_number'     => $order['number'] ?? '',
						'order_note'       => $order['note'] ?? '',
						'line_items'       => wp_json_encode( $order['lineGroups'] ?? array() ),
						'location'         => $location['name'],
						'order_date'       => $closed->setTimezone( $timezone )->format( 'Y-m-d' ),
						'closed_at'        => $order['closedAt'],
						'timezone'         => $timezone->getName(),
						'total'            => (int) $order['summary']['currentTotal'] / 100,
						'tax'              => (int) $order['summary']['currentTaxAmount'] / 100,
						'tips'             => (int) $order['summary']['currentGratuityAmount'] / 100,
						'fees'             => (int) $order['summary']['currentFeeAmount'] / 100,
					);
					if ( $is_adjustment ) {
						$excluded_count++;
						$excluded_cents += (int) $order['summary']['currentTotal'];
						continue;
					}
					$total += $net;
					foreach ( $components as $key => $amount ) {
						$components[ $key ] += (int) $order['summary'][ $key ];
					}
					$count++;
				}
				$has_next = ! empty( $connection['pageInfo']['hasNextPage'] );
				$cursor   = $connection['pageInfo']['endCursor'] ?? null;
				if ( $has_next && ( ! $cursor || $cursor === $after ) ) {
					return new WP_Error( 'avia_net_pagination', 'Incomplete Boulevard order pagination. No partial total is shown.' );
				}
				$after = $cursor;
			} while ( $has_next );

			// Prior-period orders refunded in this range (Sales Summary payment activity).
			$after = null;
			do {
				if ( microtime( true ) > $deadline ) {
					return new WP_Error( 'avia_net_timeout', 'Boulevard revenue fetch exceeded its time limit. No partial total is shown.' );
				}
				$filter = "updatedAt >= '" . $start->format( DATE_ATOM ) . "' AND updatedAt < '" . $end->format( DATE_ATOM ) . "'";
				$result = self::graphql( wp_json_encode( array( 'query' => $refunds_query, 'variables' => array( 'location' => $location['id'], 'after' => $after, 'filter' => $filter ) ) ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! empty( $result['errors'] ) || ! isset( $result['data']['orders']['pageInfo'] ) ) {
					return new WP_Error( 'avia_net_refund_fetch', 'Could not fetch Boulevard order refunds. Check order:read access.' );
				}
				$connection = $result['data']['orders'];
				foreach ( $connection['edges'] ?? array() as $edge ) {
					$order = $edge['node'] ?? array();
					if ( empty( $order['id'] ) ) {
						continue;
					}
					$order_closed_in_range = false;
					if ( ! empty( $order['closedAt'] ) ) {
						try {
							$order_closed = new DateTimeImmutable( $order['closedAt'] );
							$order_closed_in_range = ( $order_closed >= $start && $order_closed < $end );
						} catch ( Exception $e ) {
							return new WP_Error( 'avia_net_date', 'Invalid Boulevard order date.' );
						}
					}
					// In-range closes already use currentTotal (includes later refunds) — do not subtract again.
					if ( $order_closed_in_range || isset( $seen[ $order['id'] ] ) ) {
						continue;
					}
					foreach ( $order['refunds'] ?? array() as $refund ) {
						$refund_id = (string) ( $refund['id'] ?? '' );
						if ( '' === $refund_id || isset( $seen_refunds[ $refund_id ] ) ) {
							continue;
						}
						if ( empty( $refund['closedAt'] ) ) {
							continue;
						}
						$status = strtoupper( (string) ( $refund['status'] ?? '' ) );
						if ( 'OPEN' === $status ) {
							continue;
						}
						try {
							$refund_closed = new DateTimeImmutable( $refund['closedAt'] );
						} catch ( Exception $e ) {
							return new WP_Error( 'avia_net_refund_date', 'Invalid Boulevard refund date.' );
						}
						if ( $refund_closed < $start || $refund_closed >= $end ) {
							continue;
						}
						$refund_total = isset( $refund['summary']['refundTotal'] ) ? (int) $refund['summary']['refundTotal'] : 0;
						$refund_tax   = isset( $refund['summary']['refundTax'] ) ? (int) $refund['summary']['refundTax'] : 0;
						if ( $refund_total <= 0 ) {
							continue;
						}
						$seen_refunds[ $refund_id ] = true;
						$refund_count++;
						$refund_cents += $refund_total;
						$components['currentTotal']    -= $refund_total;
						$components['currentTaxAmount'] -= $refund_tax;
						$total                         -= ( $refund_total - $refund_tax );
						$order_audit[]                  = array(
							'included'         => true,
							'exclusion_reason' => 'Refund (prior-period order)',
							'order_id'         => $order['id'],
							'order_number'     => $order['number'] ?? '',
							'order_note'       => 'Refund ' . ( $refund['number'] ?? $refund_id ),
							'line_items'       => '',
							'location'         => $location['name'],
							'order_date'       => $refund_closed->setTimezone( $timezone )->format( 'Y-m-d' ),
							'closed_at'        => $refund['closedAt'],
							'timezone'         => $timezone->getName(),
							'total'            => -1 * ( $refund_total / 100 ),
							'tax'              => -1 * ( $refund_tax / 100 ),
							'tips'             => 0,
							'fees'             => 0,
						);
					}
				}
				$has_next = ! empty( $connection['pageInfo']['hasNextPage'] );
				$cursor   = $connection['pageInfo']['endCursor'] ?? null;
				if ( $has_next && ( ! $cursor || $cursor === $after ) ) {
					return new WP_Error( 'avia_net_refund_pagination', 'Incomplete Boulevard refund pagination. No partial total is shown.' );
				}
				$after = $cursor;
			} while ( $has_next );
		}

		$result = array(
			'excluded_adjustments'       => $excluded_count,
			'excluded_adjustment_value'  => $excluded_cents / 100,
			'prior_period_refunds'       => $refund_count,
			'prior_period_refund_value'  => $refund_cents / 100,
			'net_sales'                  => $total / 100,
			'collected'                  => $components['currentTotal'] / 100,
			'order_total'                => $components['currentTotal'] / 100,
			'tax'                        => $components['currentTaxAmount'] / 100,
			'tips'                       => $components['currentGratuityAmount'] / 100,
			'fees'                       => $components['currentFeeAmount'] / 100,
			'timezone'                   => implode( ', ', array_unique( $timezones ) ),
			'location_names'             => array_column( $locations, 'name' ),
			'order_audit'                => $order_audit,
			'orders'                     => $count,
			'range'                      => $range,
			'fetched_at'                 => current_time( 'mysql' ),
		);
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Match Boulevard Sales Summary: exclude orders noted "Account Credit Adjustment"
	 * (any line type). Ordinary account-credit / balance sales without that note stay included.
	 */
	public static function is_account_credit_adjustment( array $order ) {
		return 0 === strcasecmp( trim( (string) ( $order['note'] ?? '' ) ), 'Account Credit Adjustment' );
	}

	/**
	 * Completed appointments from Boulevard Admin API (state FINAL), by appointment startAt.
	 * Same date/location scope pattern as get_net_sales for Sales Summary parity checks.
	 */
	public static function get_completed_appointments( $range_key, $start_date = '', $end_date = '' ) {
		$range     = Avia_Attribution_Dashboard::resolve_range( $range_key, $start_date, $end_date );
		$cache_key = 'avia_blvd_appts_completed_v1_' . md5( Avia_Attribution_Settings::get( 'blvd_business_id' ) . '|' . $range['start'] . '|' . $range['end'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$locations = self::get_locations( true );
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}
		if ( empty( $locations ) ) {
			return new WP_Error( 'avia_appt_locations', 'No Boulevard locations available. Check location:read access.' );
		}
		if ( count( $locations ) >= 50 ) {
			return new WP_Error( 'avia_appt_locations_limit', 'Location list may be incomplete. Appointment totals are unavailable until all locations can be fetched.' );
		}

		$timezones   = array();
		$count       = 0;
		$audit       = array();
		$seen        = array();
		$deadline    = microtime( true ) + 45;
		$query       = 'query($location: ID!, $after: String, $filter: QueryString) { appointments(locationId: $location, first: 100, after: $after, query: $filter) { edges { node { id state cancelled startAt endAt client { firstName lastName } } } pageInfo { hasNextPage endCursor } } }';

		foreach ( $locations as $location ) {
			try {
				if ( empty( $location['tz'] ) ) {
					throw new Exception( 'Missing location timezone' );
				}
				$timezone = new DateTimeZone( $location['tz'] );
				$start    = new DateTimeImmutable( $range['start_date'] . ' 00:00:00', $timezone );
				$end      = ( new DateTimeImmutable( $range['end_date'] . ' 00:00:00', $timezone ) )->modify( '+1 day' );
			} catch ( Exception $e ) {
				return new WP_Error( 'avia_location_timezone', 'Boulevard location timezone unavailable. No estimated appointment total is shown.' );
			}
			$timezones[] = $timezone->getName();
			$after       = null;
			$filter      = "cancelled = false AND startAt >= '" . $start->format( DATE_ATOM ) . "' AND startAt < '" . $end->format( DATE_ATOM ) . "'";

			do {
				if ( microtime( true ) > $deadline ) {
					return new WP_Error( 'avia_appt_timeout', 'Boulevard appointment fetch exceeded its time limit. No partial total is shown.' );
				}
				$result = self::graphql(
					wp_json_encode(
						array(
							'query'     => $query,
							'variables' => array(
								'location' => $location['id'],
								'after'    => $after,
								'filter'   => $filter,
							),
						)
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( ! empty( $result['errors'] ) || ! isset( $result['data']['appointments']['pageInfo'] ) ) {
					return new WP_Error( 'avia_appt_fetch', 'Could not fetch complete Boulevard appointments. Check appointment:read access and API date-query support.' );
				}
				$connection = $result['data']['appointments'];
				foreach ( $connection['edges'] ?? array() as $edge ) {
					$node = $edge['node'] ?? array();
					if ( empty( $node['id'] ) || empty( $node['startAt'] ) || isset( $seen[ $node['id'] ] ) ) {
						continue;
					}
					try {
						$start_at = new DateTimeImmutable( $node['startAt'] );
					} catch ( Exception $e ) {
						return new WP_Error( 'avia_appt_date', 'Invalid Boulevard appointment date.' );
					}
					if ( $start_at < $start || $start_at >= $end ) {
						continue;
					}
					$seen[ $node['id'] ] = true;
					$state               = (string) ( $node['state'] ?? '' );
					$is_completed        = ( 'FINAL' === $state );
					$client              = $node['client'] ?? array();
					$client_name         = trim( (string) ( $client['firstName'] ?? '' ) . ' ' . (string) ( $client['lastName'] ?? '' ) );
					$audit[]             = array(
						'included'         => $is_completed,
						'exclusion_reason' => $is_completed ? '' : ( $state ? 'State: ' . $state : 'Not completed' ),
						'appointment_id'   => $node['id'],
						'client_name'      => $client_name,
						'location'         => $location['name'],
						'appointment_date' => $start_at->setTimezone( $timezone )->format( 'Y-m-d' ),
						'start_at'         => $node['startAt'],
						'state'            => $state,
						'timezone'         => $timezone->getName(),
					);
					if ( $is_completed ) {
						$count++;
					}
				}
				$has_next = ! empty( $connection['pageInfo']['hasNextPage'] );
				$cursor   = $connection['pageInfo']['endCursor'] ?? null;
				if ( $has_next && ( ! $cursor || $cursor === $after ) ) {
					return new WP_Error( 'avia_appt_pagination', 'Incomplete Boulevard appointment pagination. No partial total is shown.' );
				}
				$after = $cursor;
			} while ( $has_next );
		}

		$result = array(
			'completed'      => $count,
			'timezone'       => implode( ', ', array_unique( $timezones ) ),
			'location_names' => array_column( $locations, 'name' ),
			'appointment_audit' => $audit,
			'range'          => $range,
			'fetched_at'     => current_time( 'mysql' ),
		);
		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	public static function net_order_sales_cents( array $summary ) {
		$values = array();
		foreach ( array( 'currentTotal', 'currentTaxAmount', 'currentGratuityAmount', 'currentFeeAmount' ) as $key ) {
			if ( ! isset( $summary[ $key ] ) || ! is_numeric( $summary[ $key ] ) ) { return new WP_Error( 'avia_net_missing', 'Boulevard order has incomplete financial amounts. No estimated revenue is shown.' ); }
			$values[ $key ] = (int) $summary[ $key ];
		}
		return $values['currentTotal'] - $values['currentTaxAmount'] - $values['currentGratuityAmount'] - $values['currentFeeAmount'];
	}

	public static function get_order_by_id( $order_id ) {
		$order_id = sanitize_text_field( (string) $order_id );
		if ( '' === $order_id ) {
			return new WP_Error( 'avia_order_invalid', 'Order ID is required.' );
		}

		$id    = self::escape_gql_string( $order_id );
		// Admin API Money is a scalar (cents), not { amount, currencyCode }.
		$query = 'query { order(id: "' . $id . '") { id clientId locationId closedAt summary { currentTotal } } }';
		$result = self::graphql( wp_json_encode( array( 'query' => $query ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['errors'] ) ) {
			return new WP_Error( 'avia_order_fetch_failed', wp_json_encode( $result['errors'] ) );
		}

		return $result['data']['order'] ?? array();
	}

	public static function money_to_cents( $money ) {
		if ( is_array( $money ) ) {
			if ( ! array_key_exists( 'amount', $money ) || ! is_numeric( $money['amount'] ) ) {
				return null;
			}
			return self::numeric_amount_to_cents( (float) $money['amount'] );
		}

		if ( is_numeric( $money ) ) {
			return self::numeric_amount_to_cents( (float) $money );
		}

		return null;
	}

	private static function numeric_amount_to_cents( $amount ) {
		if ( $amount < 0 ) {
			return null;
		}

		// Admin API order summary currentTotal is integer cents; webhook payloads may use decimal dollars.
		if ( abs( $amount - round( $amount ) ) < 0.001 ) {
			return (int) round( $amount );
		}

		return (int) round( $amount * 100 );
	}

	public static function backfill_missing_order_revenue( $limit = 25 ) {
		global $wpdb;

		if ( ! Avia_Attribution_Settings::has_credentials() || ! Avia_Attribution_Database::tables_exist() ) {
			return 0;
		}

		$table = Avia_Attribution_Database::events_table();
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, payload_json, blvd_client_id, blvd_appointment_id, att_id, revenue_cents
				FROM {$table}
				WHERE event_type IN ('ORDER_COMPLETED', 'APPOINTMENT_COMPLETED')
					AND (revenue_cents IS NULL OR revenue_cents = 0)
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$updated = 0;
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( $row['payload_json'] ?? '', true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}

			$order_id_override = '';
			if ( ! empty( $row['blvd_appointment_id'] ) && 0 === strpos( (string) $row['blvd_appointment_id'], 'urn:blvd:Order:' ) ) {
				$order_id_override = (string) $row['blvd_appointment_id'];
			}

			$context = self::enrich_order_revenue_context( $payload, $row['event_type'] ?? '', $order_id_override );
			if ( ! isset( $context['revenue_cents'] ) || null === $context['revenue_cents'] ) {
				continue;
			}

			$update = array(
				'revenue_cents' => (int) $context['revenue_cents'],
			);
			if ( empty( $row['blvd_client_id'] ) && ! empty( $context['client_id'] ) ) {
				$update['blvd_client_id'] = $context['client_id'];
			}
			if ( empty( $row['att_id'] ) && ! empty( $context['att_id'] ) ) {
				$update['att_id'] = $context['att_id'];
			}

			$result = $wpdb->update(
				$table,
				$update,
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $result ) {
				++$updated;
			}
		}

		return $updated;
	}

	public static function ping_registered_webhooks() {
		$webhooks = Avia_Attribution_Database::get_webhooks();
		if ( empty( $webhooks ) ) {
			return new WP_Error( 'avia_no_webhooks', 'No registered webhooks found. Register webhooks first.' );
		}

		$results = array();
		foreach ( $webhooks as $webhook ) {
			if ( empty( $webhook['blvd_webhook_id'] ) ) {
				continue;
			}

			$id      = self::escape_gql_string( $webhook['blvd_webhook_id'] );
			$body    = wp_json_encode(
				array(
					'query' => 'mutation { pingWebhook(input: { id: "' . $id . '" }) { webhook { id } } }',
				)
			);
			$result  = self::graphql( $body );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'webhook_id' => $webhook['blvd_webhook_id'],
					'success'    => false,
					'message'    => $result->get_error_message(),
				);
				continue;
			}

			if ( ! empty( $result['errors'] ) ) {
				$results[] = array(
					'webhook_id' => $webhook['blvd_webhook_id'],
					'success'    => false,
					'message'    => wp_json_encode( $result['errors'] ),
				);
				continue;
			}

			$results[] = array(
				'webhook_id' => $webhook['blvd_webhook_id'],
				'success'    => true,
				'message'    => 'Ping sent',
			);
		}

		return $results;
	}
}
