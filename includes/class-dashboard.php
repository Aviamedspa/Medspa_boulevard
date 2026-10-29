<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Dashboard {

	const DATE_RANGES = array(
		'today' => 'TODAY',
		'yesterday' => 'YESTERDAY',
		'last_week' => 'LAST_WEEK',
		'this_week' => 'THIS_WEEK',
		'this_month' => 'THIS_MONTH',
		'last_month' => 'LAST_MONTH',
		'last_7_days'  => 'LAST_7_DAYS',
		'last_30_days' => 'LAST_30_DAYS',
		'last_90_days' => 'LAST_90_DAYS',
		'custom'       => 'CUSTOM',
	);

	const MAX_CUSTOM_RANGE_DAYS = 730;

	public static function sanitize_date_range( $range ) {
		$range = sanitize_key( (string) $range );
		return isset( self::DATE_RANGES[ $range ] ) ? $range : 'last_7_days';
	}

	public static function sanitize_date_input( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		$timezone = wp_timezone();
		$dt       = DateTime::createFromFormat( 'Y-m-d', $value, $timezone );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $value ) {
			return '';
		}

		return $value;
	}

	public static function resolve_range( $range_key = 'last_7_days', $start_date = '', $end_date = '' ) {
		$range_key = self::sanitize_date_range( $range_key );

		if ( 'custom' !== $range_key ) {
			$bounds = self::get_preset_date_bounds( $range_key );

			return array(
				'key'        => $range_key,
				'start'      => $bounds['start'],
				'end'        => $bounds['end'],
				'start_date' => substr( $bounds['start'], 0, 10 ),
				'end_date'   => substr( $bounds['end'], 0, 10 ),
				'cache_key'  => $range_key,
				'label'      => self::get_range_label( $range_key ),
			);
		}

		$start_date = self::sanitize_date_input( $start_date );
		$end_date   = self::sanitize_date_input( $end_date );

		if ( '' === $start_date || '' === $end_date || $start_date > $end_date ) {
			return self::resolve_range( 'last_7_days' );
		}

		$timezone  = wp_timezone();
		$start_dt  = DateTime::createFromFormat( 'Y-m-d', $start_date, $timezone );
		$end_dt    = DateTime::createFromFormat( 'Y-m-d', $end_date, $timezone );
		$interval  = $start_dt->diff( $end_dt );
		$day_count = (int) $interval->days + 1;

		if ( $day_count > self::MAX_CUSTOM_RANGE_DAYS ) {
			$end_dt = clone $start_dt;
			$end_dt->modify( '+' . ( self::MAX_CUSTOM_RANGE_DAYS - 1 ) . ' days' );
			$end_date = $end_dt->format( 'Y-m-d' );
		}

		return array(
			'key'        => 'custom',
			'start'      => $start_date . ' 00:00:00',
			'end'        => $end_date . ' 23:59:59',
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'cache_key'  => 'custom_' . $start_date . '_' . $end_date,
			'label'      => $start_date . ' to ' . $end_date,
		);
	}

	public static function get_range_label( $range_key ) {
		$labels = array(
			'today' => 'Today',
			'yesterday' => 'Yesterday',
			'last_week' => 'Last week',
			'this_week' => 'This week',
			'this_month' => 'This month',
			'last_month' => 'Last month',
			'last_7_days'  => 'Last 7 days',
			'last_30_days' => 'Last 30 days',
			'last_90_days' => 'Last 90 days',
			'custom'       => 'Custom range',
		);

		return $labels[ $range_key ] ?? 'Last 7 days';
	}

	public static function get_default_custom_dates() {
		$bounds = self::get_preset_date_bounds( 'last_30_days' );

		return array(
			'start_date' => substr( $bounds['start'], 0, 10 ),
			'end_date'   => substr( $bounds['end'], 0, 10 ),
		);
	}

	private static function get_preset_date_bounds( $range_key ) {
		$today = new DateTime( 'today', wp_timezone() );
		$start = clone $today;
		$end = clone $today;
		switch ( $range_key ) {
			case 'today': break;
			case 'yesterday': $start->modify( '-1 day' ); $end->modify( '-1 day' ); break;
			case 'this_week': $start->modify( '-' . ( ( (int) $today->format( 'w' ) - (int) get_option( 'start_of_week', 1 ) + 7 ) % 7 ) . ' days' ); break;
			case 'last_week':
				$start->modify( '-' . ( ( ( (int) $today->format( 'w' ) - (int) get_option( 'start_of_week', 1 ) + 7 ) % 7 ) + 7 ) . ' days' );
				$end = clone $start; $end->modify( '+6 days' ); break;
			case 'this_month': $start->modify( 'first day of this month' ); break;
			case 'last_month': $start->modify( 'first day of last month' ); $end->modify( 'last day of last month' ); break;
			default: $start = null;
		}
		if ( $start ) {
			return array( 'start' => $start->format( 'Y-m-d 00:00:00' ), 'end' => $end->format( 'Y-m-d 23:59:59' ) );
		}
		$days = array(
			'last_7_days'  => 7,
			'last_30_days' => 30,
			'last_90_days' => 90,
		);

		if ( ! isset( $days[ $range_key ] ) ) {
			$range_key = 'last_7_days';
		}

		$timezone = wp_timezone();
		$end      = new DateTime( 'now', $timezone );
		$start    = clone $end;
		$start->modify( '-' . (int) $days[ $range_key ] . ' days' );

		return array(
			'start' => $start->format( 'Y-m-d 00:00:00' ),
			'end'   => $end->format( 'Y-m-d 23:59:59' ),
		);
	}

	public static function get_local_stats( $range_key = 'last_7_days', $start_date = '', $end_date = '' ) {
		global $wpdb;

		$range     = self::resolve_range( $range_key, $start_date, $end_date );
		$range_key = $range['key'];
		$bounds    = array(
			'start' => $range['start'],
			'end'   => $range['end'],
		);
		$uploads   = Avia_Attribution_Database::google_ads_uploads_table();
		$sessions  = Avia_Attribution_Database::sessions_table();
		$events    = Avia_Attribution_Database::events_table();

		// Same Performance overview clock as Attributed conversions table (conversion/completed time).
		$upload_date_clause = '';
		$upload_date_args   = array();
		if ( '' !== $bounds['start'] ) {
			$upload_date_clause = ' AND COALESCE(created_at, uploaded_at) >= %s AND COALESCE(created_at, uploaded_at) <= %s';
			$upload_date_args   = array( $bounds['start'], $bounds['end'] );
		}

		$upload_counts = $wpdb->get_results(
			empty( $upload_date_args )
				? "SELECT status, COUNT(*) AS total FROM {$uploads} WHERE 1=1 GROUP BY status"
				: $wpdb->prepare(
					"SELECT status, COUNT(*) AS total FROM {$uploads} WHERE 1=1{$upload_date_clause} GROUP BY status",
					...$upload_date_args
				),
			ARRAY_A
		);

		$by_status = array(
			'uploaded' => 0,
			'pending'  => 0,
			'failed'   => 0,
			'skipped'  => 0,
		);
		foreach ( (array) $upload_counts as $row ) {
			$status = $row['status'] ?? '';
			if ( isset( $by_status[ $status ] ) ) {
				$by_status[ $status ] = (int) $row['total'];
			}
		}

		// Match "Uploaded to Google" / Status: uploaded table (conversion/completed time, not upload clock).
		$value_sql = "SELECT COALESCE(SUM(conversion_value), 0) FROM {$uploads} WHERE status = 'uploaded'";
		if ( '' !== $bounds['start'] ) {
			$value_sql .= ' AND COALESCE(created_at, uploaded_at) >= %s AND COALESCE(created_at, uploaded_at) <= %s';
			$uploaded_value = (float) $wpdb->get_var(
				$wpdb->prepare( $value_sql, $bounds['start'], $bounds['end'] )
			);
		} else {
			$uploaded_value = (float) $wpdb->get_var( $value_sql );
		}

		$queue_value_sql = "SELECT COALESCE(SUM(conversion_value), 0) FROM {$uploads} WHERE status IN ('uploaded', 'skipped', 'pending', 'failed')";
		if ( '' !== $bounds['start'] ) {
			$queue_value_sql .= ' AND created_at >= %s AND created_at <= %s';
			$queue_value_incl_skipped = (float) $wpdb->get_var(
				$wpdb->prepare( $queue_value_sql, $bounds['start'], $bounds['end'] )
			);
		} else {
			$queue_value_incl_skipped = (float) $wpdb->get_var( $queue_value_sql );
		}

		$sessions_sql = "SELECT COUNT(*) FROM {$sessions} WHERE gclid IS NOT NULL AND gclid != ''";
		if ( '' !== $bounds['start'] ) {
			$sessions_sql .= ' AND created_at >= %s AND created_at <= %s';
			$attributed_sessions = (int) $wpdb->get_var(
				$wpdb->prepare( $sessions_sql, $bounds['start'], $bounds['end'] )
			);
		} else {
			$attributed_sessions = (int) $wpdb->get_var( $sessions_sql );
		}

		$completed_sql = "SELECT COUNT(*) FROM {$events} WHERE event_type = 'APPOINTMENT_COMPLETED'";
		if ( '' !== $bounds['start'] ) {
			$completed_sql .= ' AND created_at >= %s AND created_at <= %s';
			$completed_events = (int) $wpdb->get_var(
				$wpdb->prepare( $completed_sql, $bounds['start'], $bounds['end'] )
			);
		} else {
			$completed_events = (int) $wpdb->get_var( $completed_sql );
		}

		return array(
			'range'               => $range_key,
			'range_label'         => $range['label'],
			'start_date'          => $range['start_date'],
			'end_date'            => $range['end_date'],
			'uploaded'            => $by_status['uploaded'],
			'pending'             => $by_status['pending'],
			'failed'              => $by_status['failed'],
			'skipped'             => $by_status['skipped'],
			'total_rows'          => array_sum( $by_status ),
			'uploaded_value'             => round( $uploaded_value, 2 ),
			'queue_value_incl_skipped' => round( $queue_value_incl_skipped, 2 ),
			'attributed_sessions'        => $attributed_sessions,
			'completed_events'         => $completed_events,
		);
	}

	public static function get_uploads_enriched( $page = 1, $per_page = 10, $range_key = 'last_7_days', $start_date = '', $end_date = '', $search = '', $status = '' ) {
		global $wpdb;

		$range     = self::resolve_range( $range_key, $start_date, $end_date );
		$range_key = $range['key'];
		$bounds    = array(
			'start' => $range['start'],
			'end'   => $range['end'],
		);
		$uploads   = Avia_Attribution_Database::google_ads_uploads_table();
		$events    = Avia_Attribution_Database::events_table();
		$sessions  = Avia_Attribution_Database::sessions_table();
		$page      = max( 1, (int) $page );
		$all_rows  = -1 === (int) $per_page;
		$per_page  = $all_rows ? 1 : max( 1, min( 100, (int) $per_page ) );
		$offset    = ( $page - 1 ) * $per_page;
		$search    = trim( sanitize_text_field( (string) $search ) );
		$status    = strtolower( trim( sanitize_text_field( (string) $status ) ) );
		$allowed_statuses = array( 'uploaded', 'skipped', 'merged', 'pending', 'failed' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = '';
		}
		// Text search is all-time — do not limit by Performance overview date range.
		$search_all_time = ( '' !== $search );

		$where  = '1=1';
		$args   = array();

		if ( 'merged' === $status ) {
			$where .= ' AND u.status = %s AND u.error_message LIKE %s';
			$args[] = 'skipped';
			$args[] = '%Merged into upload #%';
		} elseif ( 'skipped' === $status ) {
			$where .= ' AND u.status = %s AND (u.error_message IS NULL OR u.error_message NOT LIKE %s)';
			$args[] = 'skipped';
			$args[] = '%Merged into upload #%';
		} elseif ( '' !== $status ) {
			$where .= ' AND u.status = %s';
			$args[] = $status;
		}

		if ( ! $search_all_time && '' !== $bounds['start'] ) {
			// One clock for All + any Status filter: conversion/completed time (ledger event),
			// same Performance overview range. Status only filters u.status.
			$where .= ' AND COALESCE(e.created_at, u.created_at, u.uploaded_at) >= %s AND COALESCE(e.created_at, u.created_at, u.uploaded_at) <= %s';
			$args[] = $bounds['start'];
			$args[] = $bounds['end'];
		}

		if ( '' !== $search ) {
			$search_clause = self::build_uploads_search_sql( $search, $args );
			if ( '' !== $search_clause ) {
				$where .= $search_clause;
			}
		}

		$count_sql = "SELECT COUNT(*) FROM {$uploads} u
			LEFT JOIN {$events} e ON e.id = u.ledger_event_id
			LEFT JOIN {$sessions} s ON s.att_id = u.att_id
			WHERE {$where}";
		$total     = empty( $args )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );

		$query = "SELECT u.*, e.blvd_client_id, e.blvd_appointment_id, e.status AS event_status,
				e.revenue_cents, e.payload_json, e.created_at AS event_created_at,
				s.utm_campaign, s.utm_source, s.utm_medium, s.created_at AS session_created_at,
				s.click_at AS session_click_at,
				s.gclid AS session_gclid, s.gbraid AS session_gbraid, s.wbraid AS session_wbraid
			FROM {$uploads} u
			LEFT JOIN {$events} e ON e.id = u.ledger_event_id
			LEFT JOIN {$sessions} s ON s.att_id = u.att_id
			WHERE {$where}
			ORDER BY u.id DESC
			LIMIT %d OFFSET %d";

		if ( $all_rows ) {
			$query = str_replace( 'LIMIT %d OFFSET %d', '', $query );
			$query_args = $args;
		} else {
			$query_args = array_merge( $args, array( $per_page, $offset ) );
		}
		$rows = $wpdb->get_results( empty( $query_args ) ? $query : $wpdb->prepare( $query, ...$query_args ), ARRAY_A );

		$client_ids_for_crm = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['blvd_client_id'] ) ) {
				$client_ids_for_crm[] = (string) $row['blvd_client_id'];
			}
		}
		$crm_batch = Avia_Attribution_Boulevard_Api::prefetch_clients_crm_meta( $client_ids_for_crm );

		$enriched     = array();
		$client_cache = array();
		foreach ( (array) $rows as $row ) {
			$client = self::resolve_client_for_ledger_row(
				$row,
				$client_cache,
				array( 'use_boulevard_api' => 'fill_missing' )
			);

			$session_for_click = array(
				'gclid'        => $row['session_gclid'] ?: ( $row['gclid'] ?? '' ),
				'gbraid'       => $row['session_gbraid'] ?: ( $row['gbraid'] ?? '' ),
				'wbraid'       => $row['session_wbraid'] ?: ( $row['wbraid'] ?? '' ),
				'click_at'     => $row['session_click_at'] ?? '',
				'created_at'   => $row['session_created_at'] ?? '',
				'utm_campaign' => $row['utm_campaign'] ?? '',
			);
			$effective = Avia_Attribution_Ads_Integrity::effective_click_ids(
				$session_for_click,
				$row['conversion_datetime'] ?? ( $row['event_created_at'] ?? '' )
			);
			$click      = $effective['gclid'] ?: ( $effective['gbraid'] ?: $effective['wbraid'] );
			$click_type = $effective['gclid'] ? 'gclid' : ( $effective['gbraid'] ? 'gbraid' : ( $effective['wbraid'] ? 'wbraid' : '' ) );
			$click_at   = (string) ( $row['session_created_at'] ?? '' );

			$is_voided_false = class_exists( 'Avia_Attribution_Google_Ads_Offline' )
				&& Avia_Attribution_Google_Ads_Offline::is_voided_false_attribution( $row['error_message'] ?? '' );
			$row_has_stored_click = ! empty( $row['gclid'] ) || ! empty( $row['gbraid'] ) || ! empty( $row['wbraid'] );
			// Already sent to Google with a click ID: keep showing what Ads received even if session UTM is gone.
			if ( 'uploaded' === ( $row['status'] ?? '' ) && $row_has_stored_click && empty( $click ) ) {
				$click      = (string) ( $row['gclid'] ?: ( $row['gbraid'] ?: $row['wbraid'] ) );
				$click_type = ! empty( $row['gclid'] ) ? 'gclid' : ( ! empty( $row['gbraid'] ) ? 'gbraid' : 'wbraid' );
				$click_at   = (string) ( $row['session_created_at'] ?? '' );
				$effective['is_ads_attributed'] = true;
			} elseif ( $is_voided_false || empty( $effective['is_ads_attributed'] ) ) {
				$click                          = '';
				$click_type                     = '';
				$click_at                       = '';
				$effective['is_ads_attributed'] = false;
				if ( $is_voided_false ) {
					$effective['reason'] = 'voided_false_attribution';
				}
				$row['utm_campaign'] = '';
				$row['utm_source']   = '';
				$row['utm_medium']   = '';
			}

			$conversion_value = null === $row['conversion_value'] ? null : (float) $row['conversion_value'];
			if ( $is_voided_false && ( null === $conversion_value || abs( $conversion_value ) < 0.009 ) ) {
				if ( isset( $row['revenue_cents'] ) && '' !== $row['revenue_cents'] && null !== $row['revenue_cents'] ) {
					$conversion_value = round( ( (int) $row['revenue_cents'] ) / 100, 2 );
				}
			}

			$blvd_client_id = ! empty( $row['blvd_client_id'] )
				? (string) $row['blvd_client_id']
				: (string) ( $client['blvd_client_id'] ?? '' );
			$crm_key  = Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $blvd_client_id );
			$crm_meta = self::resolve_client_crm_meta(
				$blvd_client_id,
				$client_cache,
				$row['payload_json'] ?? '',
				null,
				array(
					'live_api'    => false,
					'ledger_meta' => ( '' !== $crm_key && isset( $crm_batch[ $crm_key ] ) ) ? $crm_batch[ $crm_key ] : null,
				)
			);

			$enriched[] = array(
				'id'                     => (int) $row['id'],
				'ledger_event_id'        => (int) $row['ledger_event_id'],
				'event_type'             => $row['event_type'],
				'att_id'                 => $is_voided_false ? '' : ( $row['att_id'] ?? '' ),
				'click_id'               => $click,
				'click_type'             => $click_type,
				'click_at'               => $click_at,
				'is_ads_attributed'      => $effective['is_ads_attributed'],
				'ads_attribution_reason' => $effective['reason'],
				'transaction_id'         => 'avia-ledger-' . (int) $row['ledger_event_id'],
				'conversion_value'       => $conversion_value,
				'currency_code'          => $row['currency_code'] ?? 'USD',
				'status'                 => $row['status'],
				'error_message'          => $row['error_message'] ?? '',
				'created_at'             => $row['created_at'],
				'uploaded_at'            => $row['uploaded_at'],
				'event_created_at'       => $row['event_created_at'] ?? '',
				'blvd_client_id'         => $row['blvd_client_id'] ?? '',
				'blvd_appointment_id'    => $row['blvd_appointment_id'] ?? '',
				'event_status'           => $row['event_status'] ?? '',
				'client_name'            => $client['name'],
				'client_email'           => $client['email'],
				'client_phone'           => $client['phone'],
				'client_tags'            => $crm_meta['tags'],
				'client_referral_source' => $crm_meta['referral_source'],
				'utm_campaign'           => $row['utm_campaign'] ?? '',
				'utm_source'             => $row['utm_source'] ?? '',
				'utm_medium'             => $row['utm_medium'] ?? '',
			);
		}

		return array(
			'rows'             => $enriched,
			'total'            => $total,
			'page'             => $page,
			'per_page'         => $per_page,
			'total_pages'      => max( 1, (int) ceil( $total / max( 1, $per_page ) ) ),
			'range'            => $range_key,
			'range_label'      => $range['label'],
			'start_date'       => $range['start_date'],
			'end_date'         => $range['end_date'],
			'search'           => $search,
			'search_all_time'  => $search_all_time,
			'status'           => $status,
		);
	}

	/**
	 * Search uploads by click ID / campaign / status, and by client name/email.
	 * Names are often split across firstName/lastName in appointment payloads (not contiguous),
	 * and ORDER_COMPLETED rows omit client — so we resolve matching blvd_client_id / order IDs first.
	 *
	 * @param string $search Search text.
	 * @param array  $args   Prepared-statement args (by reference).
	 * @return string SQL AND (…) clause or empty string.
	 */
	private static function build_uploads_search_sql( $search, array &$args ) {
		global $wpdb;

		$search = trim( (string) $search );
		if ( '' === $search ) {
			return '';
		}

		$like_full = '%' . $wpdb->esc_like( $search ) . '%';
		$or_parts  = array(
			'u.gclid LIKE %s',
			'u.gbraid LIKE %s',
			'u.wbraid LIKE %s',
			'u.att_id LIKE %s',
			'u.status LIKE %s',
			'u.error_message LIKE %s',
			'e.payload_json LIKE %s',
			'e.blvd_client_id LIKE %s',
			'e.blvd_appointment_id LIKE %s',
			's.utm_campaign LIKE %s',
			's.utm_source LIKE %s',
			's.utm_medium LIKE %s',
		);
		$args      = array_merge(
			$args,
			array( $like_full, $like_full, $like_full, $like_full, $like_full, $like_full, $like_full, $like_full, $like_full, $like_full, $like_full, $like_full )
		);

		$tokens = preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array_values(
			array_filter(
				(array) $tokens,
				static function ( $token ) {
					return strlen( (string) $token ) >= 2;
				}
			)
		);

		// "Criselda De Guia" → require each token inside payload (firstName/lastName are separate JSON fields).
		if ( count( $tokens ) > 1 ) {
			$token_ands = array();
			foreach ( $tokens as $token ) {
				$token_ands[] = 'e.payload_json LIKE %s';
				$args[]       = '%' . $wpdb->esc_like( $token ) . '%';
			}
			$or_parts[] = '(' . implode( ' AND ', $token_ands ) . ')';
		}

		$matched = self::find_client_and_order_ids_for_name_search( $search, $tokens );
		if ( ! empty( $matched['client_ids'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $matched['client_ids'] ), '%s' ) );
			$or_parts[]   = "e.blvd_client_id IN ({$placeholders})";
			$args         = array_merge( $args, $matched['client_ids'] );
		}
		if ( ! empty( $matched['order_ids'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $matched['order_ids'] ), '%s' ) );
			$or_parts[]   = "e.blvd_appointment_id IN ({$placeholders})";
			$args         = array_merge( $args, $matched['order_ids'] );
		}

		return ' AND (' . implode( ' OR ', $or_parts ) . ')';
	}

	/**
	 * Find Boulevard client / order IDs from ledger events whose payloads match a person name.
	 *
	 * @param string $search Full search string.
	 * @param array  $tokens Name tokens (min length 2).
	 * @return array{client_ids: string[], order_ids: string[]}
	 */
	private static function find_client_and_order_ids_for_name_search( $search, array $tokens ) {
		global $wpdb;

		$table = Avia_Attribution_Database::events_table();
		$where = array( "event_type IN ('APPOINTMENT_CREATED','APPOINTMENT_COMPLETED','APPOINTMENT_UPDATED','CLIENT_CREATED','CLIENT_UPDATED','ORDER_COMPLETED')" );
		$args  = array();

		if ( count( $tokens ) > 1 ) {
			foreach ( $tokens as $token ) {
				$where[] = 'payload_json LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $token ) . '%';
			}
		} else {
			$where[] = 'payload_json LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql  = "SELECT blvd_client_id, blvd_appointment_id, payload_json, event_type
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY id DESC
			LIMIT 100';
		$rows = empty( $args )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		$client_ids = array();
		$order_ids  = array();
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row['blvd_client_id'] ) ) {
				$client_ids[ (string) $row['blvd_client_id'] ] = true;
			}
			$resource = (string) ( $row['blvd_appointment_id'] ?? '' );
			if ( 0 === strpos( $resource, 'urn:blvd:Order:' ) ) {
				$order_ids[ $resource ] = true;
			}
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			if ( is_array( $payload ) ) {
				$order_id = Avia_Attribution_Boulevard_Api::resolve_order_id_from_payload( $payload, $row['event_type'] ?? '' );
				if ( '' !== $order_id ) {
					$order_ids[ $order_id ] = true;
				}
			}
		}

		return array(
			'client_ids' => array_keys( $client_ids ),
			'order_ids'  => array_keys( $order_ids ),
		);
	}

	public static function get_conversion_details( $upload_id ) {
		global $wpdb;

		$upload_id = (int) $upload_id;
		if ( $upload_id <= 0 ) {
			return new WP_Error( 'avia_upload_invalid', 'Invalid upload ID.' );
		}

		$uploads  = Avia_Attribution_Database::google_ads_uploads_table();
		$events   = Avia_Attribution_Database::events_table();
		$sessions = Avia_Attribution_Database::sessions_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT u.*, e.blvd_client_id, e.blvd_appointment_id, e.status AS event_status,
					e.revenue_cents, e.payload_json, e.created_at AS event_created_at, e.event_type AS ledger_event_type
				FROM {$uploads} u
				LEFT JOIN {$events} e ON e.id = u.ledger_event_id
				WHERE u.id = %d LIMIT 1",
				$upload_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return new WP_Error( 'avia_upload_missing', 'Upload row not found.' );
		}

		$client_cache   = array();
		$payload_client = self::resolve_client_for_ledger_row(
			$row,
			$client_cache,
			array( 'use_boulevard_api' => true )
		);
		$session = array();
		if ( ! empty( $row['att_id'] ) ) {
			$session = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$sessions} WHERE att_id = %s ORDER BY id DESC LIMIT 1",
					$row['att_id']
				),
				ARRAY_A
			);
		}
		if ( empty( $session ) ) {
			$stored_click = $row['gclid'] ?: ( $row['gbraid'] ?: $row['wbraid'] );
			if ( $stored_click ) {
				$session = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$sessions} WHERE gclid = %s OR gbraid = %s OR wbraid = %s ORDER BY id DESC LIMIT 1",
						$stored_click,
						$stored_click,
						$stored_click
					),
					ARRAY_A
				);
			}
		}
		if ( empty( $session ) ) {
			$session = array(
				'gclid'        => $row['gclid'] ?? '',
				'gbraid'       => $row['gbraid'] ?? '',
				'wbraid'       => $row['wbraid'] ?? '',
				'utm_campaign' => '',
				'created_at'   => '',
			);
		}
		// Same basis as the Attributed conversions table: session click, else upload-row click (what Ads got).
		$session_for_click = array(
			'gclid'        => ! empty( $session['gclid'] ) ? $session['gclid'] : ( $row['gclid'] ?? '' ),
			'gbraid'       => ! empty( $session['gbraid'] ) ? $session['gbraid'] : ( $row['gbraid'] ?? '' ),
			'wbraid'       => ! empty( $session['wbraid'] ) ? $session['wbraid'] : ( $row['wbraid'] ?? '' ),
			'click_at'     => $session['click_at'] ?? '',
			'created_at'   => $session['created_at'] ?? '',
			'utm_campaign' => $session['utm_campaign'] ?? '',
		);
		$effective = Avia_Attribution_Ads_Integrity::effective_click_ids(
			$session_for_click,
			$row['conversion_datetime'] ?? ( $row['event_created_at'] ?? '' )
		);
		$click_id   = $effective['gclid'] ?: ( $effective['gbraid'] ?: $effective['wbraid'] );
		$click_type = $effective['gclid'] ? 'gclid' : ( $effective['gbraid'] ? 'gbraid' : ( $effective['wbraid'] ? 'wbraid' : '' ) );
		// Never show a stored click ID when integrity says this is not ads-attributed.
		if ( empty( $effective['is_ads_attributed'] ) ) {
			$click_id   = '';
			$click_type = '';
		}

		$related_events   = array();
		$timeline_client  = $row['blvd_client_id'] ?? '';
		if ( empty( $timeline_client ) && ! empty( $payload_client['blvd_client_id'] ) ) {
			$timeline_client = $payload_client['blvd_client_id'];
		}
		if ( ! empty( $timeline_client ) ) {
			$related_events = Avia_Attribution_Boulevard_Api::get_client_event_timeline( $timeline_client, 30 );
		} elseif ( ! empty( $row['att_id'] ) ) {
			$related_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_type, blvd_client_id, blvd_appointment_id, att_id, status, revenue_cents, payload_json, created_at
					FROM {$events}
					WHERE att_id = %s
					ORDER BY created_at ASC, id ASC
					LIMIT 30",
					$row['att_id']
				),
				ARRAY_A
			);
			$related_events = array();
			foreach ( (array) $related_rows as $related_row ) {
				$related_events[] = Avia_Attribution_Boulevard_Api::summarize_ledger_event( $related_row );
			}
		}

		$boulevard_client = null;
		if ( Avia_Attribution_Settings::has_credentials() && ! empty( $row['blvd_client_id'] ) ) {
			$boulevard_client = self::get_cached_boulevard_client( $row['blvd_client_id'], $client_cache );
		}

		if ( empty( $row['blvd_client_id'] ) && ! empty( $payload_client['blvd_client_id'] ) ) {
			$row['blvd_client_id'] = $payload_client['blvd_client_id'];
		}

		$crm_meta = self::resolve_client_crm_meta(
			$row['blvd_client_id'] ?? '',
			$client_cache,
			$row['payload_json'] ?? '',
			is_array( $boulevard_client ) ? $boulevard_client : null,
			array( 'live_api' => true )
		);

		return array(
			'upload' => array(
				'id'                     => (int) $row['id'],
				'status'                 => $row['status'],
				'event_type'             => $row['event_type'],
				'click_id'               => $click_id,
				'click_type'             => $click_type,
				'is_ads_attributed'      => $effective['is_ads_attributed'],
				'ads_attribution_reason' => $effective['reason'],
				'transaction_id'         => 'avia-ledger-' . (int) $row['ledger_event_id'],
				'conversion_value'       => null === $row['conversion_value'] ? null : (float) $row['conversion_value'],
				'currency_code'          => $row['currency_code'] ?? 'USD',
				'conversion_datetime'    => $row['conversion_datetime'] ?? '',
				'created_at'             => $row['created_at'],
				'uploaded_at'            => $row['uploaded_at'],
				'error_message'          => $row['error_message'] ?? '',
				'att_id'                 => $row['att_id'] ?? '',
			),
			'client' => array(
				'name'              => $payload_client['name'] ?? '',
				'email'             => $payload_client['email'] ?? '',
				'phone'             => $payload_client['phone'] ?? '',
				'blvd_client_id'    => ! empty( $row['blvd_client_id'] ) ? $row['blvd_client_id'] : ( $payload_client['blvd_client_id'] ?? '' ),
				'blvd_created_at'   => $boulevard_client['created_at'] ?? '',
				'tags'              => $crm_meta['tags'],
				'referral_source'   => $crm_meta['referral_source'],
				'custom_fields'     => $boulevard_client['custom_fields'] ?? array(),
			),
			'appointment' => array(
				'blvd_appointment_id' => $row['blvd_appointment_id'] ?? '',
				'event_status'        => $row['event_status'] ?? '',
				'completed_at'        => $row['event_created_at'] ?? '',
				'revenue_cents'       => isset( $row['revenue_cents'] ) ? (int) $row['revenue_cents'] : null,
			),
			'attribution' => array(
				'utm_source'   => ! empty( $effective['is_ads_attributed'] ) ? ( $session['utm_source'] ?? '' ) : '',
				'utm_medium'   => ! empty( $effective['is_ads_attributed'] ) ? ( $session['utm_medium'] ?? '' ) : '',
				'utm_campaign' => ! empty( $effective['is_ads_attributed'] ) ? ( $session['utm_campaign'] ?? '' ) : '',
				'utm_term'     => ! empty( $effective['is_ads_attributed'] ) ? ( $session['utm_term'] ?? '' ) : '',
				'utm_content'  => ! empty( $effective['is_ads_attributed'] ) ? ( $session['utm_content'] ?? '' ) : '',
				'landing_page' => $session['landing_page'] ?? '',
				'referrer'     => $session['referrer'] ?? '',
				'session_created_at' => $session['created_at'] ?? '',
				'session_updated_at' => $session['updated_at'] ?? '',
				'ga4_client_id'      => $session['ga4_client_id'] ?? '',
			),
			'timeline' => $related_events,
		);
	}

	public static function parse_client_from_payload( $payload_json ) {
		$payload = json_decode( (string) $payload_json, true );
		if ( ! is_array( $payload ) ) {
			return array(
				'name'  => '',
				'email' => '',
				'phone' => '',
			);
		}

		$first = self::resolve_payload_path(
			$payload,
			array(
				'data.node.client.firstName',
				'data.node.firstName',
				'data.client.firstName',
				'data.appointment.client.firstName',
			)
		);
		$last = self::resolve_payload_path(
			$payload,
			array(
				'data.node.client.lastName',
				'data.node.lastName',
				'data.client.lastName',
				'data.appointment.client.lastName',
			)
		);
		$email = self::resolve_payload_path(
			$payload,
			array(
				'data.node.email',
				'data.node.client.email',
				'data.client.email',
				'data.email',
				'data.appointment.client.email',
			)
		);
		$phone = self::resolve_payload_path(
			$payload,
			array(
				'data.node.mobilePhone',
				'data.node.phoneNumber',
				'data.node.client.mobilePhone',
				'data.node.client.phoneNumber',
				'data.client.mobilePhone',
				'data.client.phoneNumber',
				'data.phone',
			)
		);
		$name = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = self::resolve_payload_path(
				$payload,
				array(
					'data.node.client.name',
					'data.node.name',
					'data.client.name',
				)
			);
		}

		return array(
			'name'  => $name,
			'email' => $email,
			'phone' => $phone,
		);
	}

	/**
	 * Client name/email for dashboard rows (ORDER webhooks often omit client in payload).
	 *
	 * @param array $row Ledger/upload join row with payload_json, blvd_client_id, blvd_appointment_id.
	 * @param array $cache Optional request cache for Boulevard API lookups.
	 */
	public static function resolve_client_for_ledger_row( array $row, array &$cache = array(), array $options = array() ) {
		$api_mode = $options['use_boulevard_api'] ?? false;
		// true = order + client API; fill_missing = local first, then client API only if name/email still empty.
		$use_order_api  = ( true === $api_mode );
		$use_client_api = ( true === $api_mode || 'fill_missing' === $api_mode );

		$client = self::parse_client_from_payload( $row['payload_json'] ?? '' );
		if ( self::client_identity_is_complete( $client ) ) {
			return $client;
		}

		$blvd_client_id = sanitize_text_field( (string) ( $row['blvd_client_id'] ?? '' ) );
		$resource_id    = sanitize_text_field( (string) ( $row['blvd_appointment_id'] ?? '' ) );

		if ( $use_order_api && '' === $blvd_client_id && '' !== $resource_id && 0 === strpos( $resource_id, 'urn:blvd:Order:' ) ) {
			$order = self::get_cached_order( $resource_id, $cache );
			if ( ! empty( $order['clientId'] ) ) {
				$blvd_client_id = (string) $order['clientId'];
			}
		}

		if ( ! self::client_identity_is_complete( $client ) ) {
			$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
			$event_type = (string) ( $row['ledger_event_type'] ?? $row['event_type'] ?? '' );
			$order_id   = is_array( $payload )
				? Avia_Attribution_Boulevard_Api::resolve_order_id_from_payload( $payload, $event_type )
				: '';
			if ( '' === $order_id && 0 === strpos( $resource_id, 'urn:blvd:Order:' ) ) {
				$order_id = $resource_id;
			}
			if ( '' !== $order_id ) {
				$sibling = self::lookup_client_from_appointment_ledger_by_order_id( $order_id, $cache );
				if ( self::client_identity_is_complete( $sibling ) ) {
					$client = self::merge_client_identity( $client, $sibling );
				}
				if ( '' === $blvd_client_id && ! empty( $sibling['blvd_client_id'] ) ) {
					$blvd_client_id = (string) $sibling['blvd_client_id'];
				}
			}
		}

		// List view: ORDER payloads often omit client; View details already had API — fill table the same way.
		if ( $use_client_api && ! self::client_identity_is_complete( $client ) && '' === $blvd_client_id && '' !== $resource_id && 0 === strpos( $resource_id, 'urn:blvd:Order:' ) ) {
			$order = self::get_cached_order( $resource_id, $cache );
			if ( ! empty( $order['clientId'] ) ) {
				$blvd_client_id = (string) $order['clientId'];
			}
		}

		if ( $use_client_api && ! self::client_identity_is_complete( $client ) && '' !== $blvd_client_id && Avia_Attribution_Settings::has_credentials() ) {
			$api_client = self::get_cached_boulevard_client( $blvd_client_id, $cache );
			if ( is_array( $api_client ) && ! empty( $api_client ) ) {
				$client = self::merge_client_identity(
					$client,
					array(
						'name'           => trim( ( $api_client['first_name'] ?? '' ) . ' ' . ( $api_client['last_name'] ?? '' ) ),
						'email'          => $api_client['email'] ?? '',
						'phone'          => $api_client['mobile_phone'] ?? '',
						'blvd_client_id' => $blvd_client_id,
					)
				);
			}
		}

		if ( ! empty( $blvd_client_id ) && empty( $client['blvd_client_id'] ) ) {
			$client['blvd_client_id'] = $blvd_client_id;
		}

		return $client;
	}

	private static function client_identity_is_complete( array $client ) {
		return ! empty( $client['email'] ) || ! empty( $client['name'] );
	}

	private static function merge_client_identity( array $base, array $extra ) {
		foreach ( array( 'name', 'email', 'phone', 'blvd_client_id' ) as $key ) {
			if ( empty( $base[ $key ] ) && ! empty( $extra[ $key ] ) ) {
				$base[ $key ] = $extra[ $key ];
			}
		}

		return $base;
	}

	/**
	 * Resolve CRM tags + referral source.
	 *
	 * List views: payload + ledger only (pass live_api=false + optional ledger_meta batch).
	 * Details modal: live_api=true may hit Boulevard once for missing fields.
	 *
	 * @param string     $blvd_client_id Client URN.
	 * @param array      $cache          Request cache.
	 * @param string     $payload_json   Optional current row payload.
	 * @param array|null $api_client     Optional already-loaded API client.
	 * @param array      $options        live_api (bool), ledger_meta (array|null).
	 * @return array{tags:array,referral_source:string}
	 */
	private static function resolve_client_crm_meta( $blvd_client_id, array &$cache, $payload_json = '', $api_client = null, array $options = array() ) {
		$live_api    = ! empty( $options['live_api'] );
		$ledger_meta = array_key_exists( 'ledger_meta', $options ) ? $options['ledger_meta'] : null;
		$out         = array(
			'tags'            => array(),
			'referral_source' => '',
		);

		if ( $live_api && null === $api_client ) {
			$api_client = self::get_cached_boulevard_client( $blvd_client_id, $cache );
		}
		if ( is_array( $api_client ) ) {
			$out['tags']            = Avia_Attribution_Boulevard_Api::normalize_client_tags( $api_client['tags'] ?? array() );
			$out['referral_source'] = Avia_Attribution_Boulevard_Api::normalize_referral_source( $api_client['referral_source'] ?? '' );
		}

		$payload      = json_decode( (string) $payload_json, true );
		$from_payload = is_array( $payload )
			? Avia_Attribution_Boulevard_Api::extract_crm_meta_from_payload( $payload )
			: array( 'has_tags' => false, 'tags' => array(), 'referral_source' => '' );

		$client_key = Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $blvd_client_id );

		if ( is_array( $ledger_meta ) ) {
			$from_ledger = $ledger_meta;
		} elseif ( '' !== $client_key && ! ( $live_api && is_array( $api_client ) ) ) {
			// Skip ledger tag merge when live CRM already answered — avoids stale "Avia Leads".
			$from_ledger = Avia_Attribution_Boulevard_Api::get_client_crm_meta_from_ledger( $client_key );
		} else {
			$from_ledger = array(
				'has_tags'        => false,
				'tags'            => array(),
				'referral_source' => '',
			);
		}

		// Source of truth priority for tags (Latest Event Wins — never merge historical tags if staff removed them):
		// 1. Live CRM API (if live_api requested and API responded)
		// 2. Latest ledger event with tags (from prefetch / ledger query)
		// 3. Fallback: conversion row payload (only if neither live API nor ledger had tag info)
		if ( $live_api && is_array( $api_client ) ) {
			// Already set from $api_client['tags'].
		} elseif ( ! empty( $from_ledger['has_tags'] ) ) {
			$out['tags'] = $from_ledger['tags'] ?? array();
		} elseif ( ! empty( $from_payload['has_tags'] ) ) {
			$out['tags'] = $from_payload['tags'] ?? array();
		}

		if ( '' === $out['referral_source'] && '' !== ( $from_ledger['referral_source'] ?? '' ) ) {
			$out['referral_source'] = $from_ledger['referral_source'];
		}
		if ( '' === $out['referral_source'] && '' !== ( $from_payload['referral_source'] ?? '' ) ) {
			$out['referral_source'] = $from_payload['referral_source'];
		}

		// Live Referral GraphQL only on demand (details) when ledger has nothing.
		if ( $live_api && '' === $out['referral_source'] && '' !== $client_key ) {
			$from_referral = Avia_Attribution_Boulevard_Api::fetch_client_referral_source( $client_key );
			if ( '' !== $from_referral ) {
				$out['referral_source'] = $from_referral;
			}
		}

		if ( $live_api && is_array( $api_client ) && '' !== $client_key ) {
			// Cache live CRM tags only — never write ledger-merged tags back into the transient.
			$api_client['referral_source'] = $out['referral_source'];
			$api_client['tags']            = Avia_Attribution_Boulevard_Api::normalize_client_tags( $api_client['tags'] ?? array() );
			$out['tags']                   = $api_client['tags'];
			$cache[ $client_key ]          = $api_client;
			set_transient( 'avia_blvd_client_v8_' . md5( $client_key ), $api_client, 15 * MINUTE_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * Boulevard CRM tags for a client (cached with the client record).
	 *
	 * @param string $blvd_client_id Client URN.
	 * @param array  $cache          Request cache.
	 * @return array
	 */
	private static function resolve_client_tags_for_id( $blvd_client_id, array &$cache ) {
		$meta = self::resolve_client_crm_meta( $blvd_client_id, $cache );
		return $meta['tags'];
	}

	private static function get_cached_boulevard_client( $blvd_client_id, array &$cache ) {
		$blvd_client_id = sanitize_text_field( (string) $blvd_client_id );
		if ( '' === $blvd_client_id || ! Avia_Attribution_Settings::has_credentials() ) {
			return null;
		}

		if ( array_key_exists( $blvd_client_id, $cache ) ) {
			return $cache[ $blvd_client_id ];
		}

		// v8: get_client_by_id no longer merges ledger tags — invalidate v7 caches that still had Avia Leads.
		$transient_key = 'avia_blvd_client_v8_' . md5( $blvd_client_id );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			$cache[ $blvd_client_id ] = $cached;
			return $cached;
		}

		$client = Avia_Attribution_Boulevard_Api::get_client_by_id( $blvd_client_id );
		if ( is_wp_error( $client ) || empty( $client ) ) {
			$cache[ $blvd_client_id ] = null;
			return null;
		}

		set_transient( $transient_key, $client, 15 * MINUTE_IN_SECONDS );
		$cache[ $blvd_client_id ] = $client;
		return $client;
	}

	private static function get_cached_order( $order_id, array &$cache ) {
		$order_id = sanitize_text_field( (string) $order_id );
		if ( '' === $order_id || ! Avia_Attribution_Settings::has_credentials() ) {
			return array();
		}

		if ( ! isset( $cache['orders'] ) ) {
			$cache['orders'] = array();
		}

		if ( array_key_exists( $order_id, $cache['orders'] ) ) {
			return $cache['orders'][ $order_id ];
		}

		$order = Avia_Attribution_Boulevard_Api::get_order_by_id( $order_id );
		if ( is_wp_error( $order ) || empty( $order ) ) {
			$cache['orders'][ $order_id ] = array();
			return array();
		}

		$cache['orders'][ $order_id ] = $order;
		return $order;
	}

	private static function lookup_client_from_appointment_ledger_by_order_id( $order_id, array &$cache = array() ) {
		global $wpdb;

		$order_id = sanitize_text_field( (string) $order_id );
		if ( '' === $order_id ) {
			return array(
				'name'  => '',
				'email' => '',
				'phone' => '',
			);
		}

		if ( ! isset( $cache['sibling_orders'] ) ) {
			$cache['sibling_orders'] = array();
		}
		if ( array_key_exists( $order_id, $cache['sibling_orders'] ) ) {
			return $cache['sibling_orders'][ $order_id ];
		}

		$table = Avia_Attribution_Database::events_table();
		$like  = '%' . $wpdb->esc_like( $order_id ) . '%';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload_json, blvd_client_id FROM {$table}
				WHERE event_type = 'APPOINTMENT_COMPLETED'
					AND payload_json LIKE %s
				ORDER BY id DESC
				LIMIT 1",
				$like
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			$empty = array(
				'name'  => '',
				'email' => '',
				'phone' => '',
			);
			$cache['sibling_orders'][ $order_id ] = $empty;
			return $empty;
		}

		$client = self::parse_client_from_payload( $row['payload_json'] ?? '' );
		if ( ! empty( $row['blvd_client_id'] ) ) {
			$client['blvd_client_id'] = (string) $row['blvd_client_id'];
		}

		$cache['sibling_orders'][ $order_id ] = $client;
		return $client;
	}

	private static function resolve_payload_path( array $payload, array $paths ) {
		foreach ( $paths as $path ) {
			$value = self::get_nested_value( $payload, $path );
			if ( null !== $value && '' !== $value ) {
				return is_scalar( $value ) ? (string) $value : '';
			}
		}

		return '';
	}

	private static function get_nested_value( array $payload, $path ) {
		$parts = explode( '.', (string) $path );
		$node  = $payload;

		foreach ( $parts as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return null;
			}
			$node = $node[ $part ];
		}

		return $node;
	}

	public static function format_money( $amount, $currency = 'USD' ) {
		$amount = null === $amount ? 0.0 : (float) $amount;
		return '$' . number_format( $amount, 2 );
	}

	public static function format_roas( $value, $cost ) {
		$cost = (float) $cost;
		if ( $cost <= 0 ) {
			return '—';
		}

		return number_format( (float) $value / $cost, 2 ) . 'x';
	}

	const METRIC_LAYOUT_META_KEY = 'avia_dashboard_metric_layout';
	/** Max Key metrics cards in one row (desktop). Overall visible count is uncapped. */
	const MAX_METRICS_PER_ROW    = 6;

	public static function get_metric_definitions() {
		return array(
			'spend'                   => array(
				'label'     => 'Ad spend',
				'group'     => 'ads',
				'highlight' => false,
				'help'      => 'Total Google Ads cost for the selected date range (account level, from the Google Ads API).',
			),
			'clicks'                  => array(
				'label'     => 'Clicks',
				'group'     => 'ads',
				'highlight' => false,
				'help'      => 'Paid clicks recorded in Google Ads for the selected range.',
			),
			'impressions'             => array(
				'label'     => 'Impressions',
				'group'     => 'ads',
				'highlight' => false,
				'help'      => 'How often your ads were shown on Google for the selected range.',
			),
			'ads_conversions'         => array(
				'label'     => 'Ads conversions',
				'group'     => 'ads',
				'highlight' => false,
				'help'      => 'Conversion count Google Ads attributes to your campaigns (includes online + imported offline, per Google reporting).',
			),
			'conv_value'              => array(
				'label'     => 'Conv. value',
				'group'     => 'ads',
				'highlight' => false,
				'help'      => 'Total conversion value in Google Ads for the range. May differ from plugin totals until offline uploads are processed.',
			),
			'roas'                    => array(
				'label'     => 'ROAS',
				'group'     => 'ads',
				'highlight' => true,
				'help'      => 'Return on ad spend from Google Ads: conversion value divided by spend for the selected range.',
			),
			'cpc'                     => array(
				'label'     => 'Avg. CPC',
				'group'     => 'ads',
				'highlight' => false,
				'help'      => 'Average cost per click in Google Ads for the selected range.',
			),
			'uploaded'                => array(
				'label'     => 'Uploaded to Google',
				'group'     => 'local',
				'highlight' => false,
				'help'      => 'Count of plugin rows with status uploaded in the selected range (by conversion/completed time — same Performance overview date filter as the table).',
			),
			'upload_value'            => array(
				'label'     => 'Upload value',
				'group'     => 'local',
				'highlight' => false,
				'help'      => 'Sum of conversion_value on status=uploaded rows in range (same date clock + rows as Attributed conversions → Status: uploaded). Merged sibling rows are not included.',
			),
			'pending_failed'          => array(
				'label'     => 'Pending / failed',
				'group'     => 'local',
				'highlight' => false,
				'help'      => 'Upload queue rows still waiting or that failed API validation. Use Process queue on the Uploads tab if needed.',
			),
			'upload_value_incl_skips' => array(
				'label'     => 'Boulevard total collected',
				'group'     => 'boulevard',
				'highlight' => true,
				'help'      => 'Sum of conversion value for all attributed conversion rows in the range, including Skipped (no gclid), Pending, and Failed — matches the Value column in the table below.',
			),
			'appointments_completed'  => array(
				'label'     => 'Appointments completed',
				'group'     => 'boulevard',
				'highlight' => false,
				'help'      => 'Live Boulevard count of appointments with state FINAL in the selected range (by appointment start date). Info opens details and the Boulevard appointments report.',
			),
		);
	}

	public static function get_default_metric_layout() {
		$layout = array();
		foreach ( array_keys( self::get_metric_definitions() ) as $index => $metric_id ) {
			$layout[] = array(
				'id'      => $metric_id,
				'visible' => true,
				'order'   => $index,
			);
		}

		return $layout;
	}

	public static function sanitize_metric_layout( $layout ) {
		if ( ! is_array( $layout ) ) {
			return self::get_default_metric_layout();
		}

		$definitions = self::get_metric_definitions();
		$sanitized   = array();
		$seen        = array();

		foreach ( $layout as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$metric_id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $metric_id || ! isset( $definitions[ $metric_id ] ) || isset( $seen[ $metric_id ] ) ) {
				continue;
			}

			$seen[ $metric_id ] = true;
			$sanitized[]        = array(
				'id'      => $metric_id,
				'visible' => ! empty( $item['visible'] ),
				'order'   => count( $sanitized ),
			);
		}

		foreach ( array_keys( $definitions ) as $metric_id ) {
			if ( isset( $seen[ $metric_id ] ) ) {
				continue;
			}

			$sanitized[] = array(
				'id'      => $metric_id,
				'visible' => true,
				'order'   => count( $sanitized ),
			);
		}

		if ( empty( $sanitized ) ) {
			return self::get_default_metric_layout();
		}

		return self::normalize_upload_metric_order( $sanitized );
	}

	/** Keep Uploaded to Google → Upload value → Pending / failed as a contiguous block. */
	private static function normalize_upload_metric_order( array $layout ) {
		$preferred = array( 'uploaded', 'upload_value', 'pending_failed' );
		$by_id     = array();
		foreach ( $layout as $item ) {
			$by_id[ $item['id'] ] = $item;
		}
		$present = array();
		foreach ( $preferred as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$present[] = $id;
			}
		}
		if ( count( $present ) < 2 ) {
			return $layout;
		}
		$first_index = null;
		$rest        = array();
		foreach ( $layout as $index => $item ) {
			if ( in_array( $item['id'], $present, true ) ) {
				if ( null === $first_index ) {
					$first_index = count( $rest );
				}
				continue;
			}
			$rest[] = $item;
		}
		$block = array();
		foreach ( $present as $id ) {
			$block[] = $by_id[ $id ];
		}
		array_splice( $rest, (int) $first_index, 0, $block );
		foreach ( $rest as $index => $item ) {
			$rest[ $index ]['order'] = $index;
		}
		return $rest;
	}

	public static function get_user_metric_layout( $user_id = 0 ) {
		$user_id = $user_id > 0 ? (int) $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return self::get_default_metric_layout();
		}

		$stored = get_user_meta( $user_id, self::METRIC_LAYOUT_META_KEY, true );
		if ( empty( $stored ) ) {
			return self::get_default_metric_layout();
		}

		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}

		return self::sanitize_metric_layout( $stored );
	}

	public static function save_user_metric_layout( $layout, $user_id = 0 ) {
		$user_id  = $user_id > 0 ? (int) $user_id : get_current_user_id();
		$layout   = self::sanitize_metric_layout( $layout );
		$encoded  = wp_json_encode( $layout );

		if ( $user_id <= 0 ) {
			return $layout;
		}

		update_user_meta( $user_id, self::METRIC_LAYOUT_META_KEY, $encoded );

		return $layout;
	}

	public static function get_local_metric_values( array $local_stats ) {
		return array(
			'uploaded'               => (string) ( $local_stats['uploaded'] ?? 0 ),
			'pending_failed'         => (string) ( (int) ( $local_stats['pending'] ?? 0 ) + (int) ( $local_stats['failed'] ?? 0 ) ),
			'upload_value'            => self::format_money( $local_stats['uploaded_value'] ?? 0 ),
			'upload_value_incl_skips' => 'Loading...',
			'appointments_completed'  => 'Loading...',
		);
	}

	public static function get_metric_layout_for_js( $user_id = 0 ) {
		$definitions = self::get_metric_definitions();
		$layout      = self::get_user_metric_layout( $user_id );
		$items       = array();

		foreach ( $layout as $item ) {
			$metric_id = $item['id'];
			if ( ! isset( $definitions[ $metric_id ] ) ) {
				continue;
			}

			$items[] = array(
				'id'      => $metric_id,
				'label'   => $definitions[ $metric_id ]['label'],
				'group'   => $definitions[ $metric_id ]['group'],
				'visible' => ! empty( $item['visible'] ),
			);
		}

		return $items;
	}
}
