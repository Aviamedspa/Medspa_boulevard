<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Webhook_Log {

	public static function log( $event_type, $http_status, $message, $signature_ok = null, $payload_preview = '' ) {
		global $wpdb;
		$table = Avia_Attribution_Database::webhook_log_table();

		$wpdb->insert(
			$table,
			array(
				'event_type'       => sanitize_text_field( (string) $event_type ),
				'http_status'      => (int) $http_status,
				'signature_ok'     => null === $signature_ok ? null : (int) (bool) $signature_ok,
				'message'          => sanitize_text_field( (string) $message ),
				'payload_preview'  => substr( sanitize_textarea_field( (string) $payload_preview ), 0, 500 ),
				'request_ip'       => self::request_ip(),
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Ledger rows that were stored but omitted from the delivery log (writeback_skipped on lifecycle events).
	 */
	public static function backfill_missing_delivery_log() {
		global $wpdb;

		$events_table = Avia_Attribution_Database::events_table();
		$log_table    = Avia_Attribution_Database::webhook_log_table();

		if ( ! Avia_Attribution_Database::tables_exist() ) {
			return 0;
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days', current_time( 'timestamp', true ) ) );

		$missing_cancelled = $wpdb->get_results(
			"SELECT e.event_type, e.created_at
			FROM {$events_table} e
			WHERE e.event_source = 'boulevard'
				AND e.event_type = 'APPOINTMENT_CANCELLED'
				AND NOT EXISTS (
					SELECT 1 FROM {$log_table} l
					WHERE l.event_type = e.event_type
						AND l.created_at = e.created_at
				)
			ORDER BY e.id ASC",
			ARRAY_A
		);

		$missing_client_updated = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_type, e.created_at
				FROM {$events_table} e
				WHERE e.event_source = 'boulevard'
					AND e.event_type = 'CLIENT_UPDATED'
					AND e.created_at >= %s
					AND NOT EXISTS (
						SELECT 1 FROM {$log_table} l
						WHERE l.event_type = e.event_type
							AND l.created_at = e.created_at
					)
				ORDER BY e.id DESC
				LIMIT 100",
				$since
			),
			ARRAY_A
		);

		$missing = array_merge( $missing_cancelled, $missing_client_updated );

		$inserted = 0;
		foreach ( $missing as $row ) {
			$result = $wpdb->insert(
				$log_table,
				array(
					'event_type'      => sanitize_text_field( (string) $row['event_type'] ),
					'http_status'     => 200,
					'signature_ok'    => 1,
					'message'         => 'writeback_skipped',
					'payload_preview' => 'backfilled from ledger',
					'request_ip'      => '',
					'created_at'      => $row['created_at'],
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( false !== $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete webhook delivery log rows older than N days.
	 *
	 * @param int $days Retention days.
	 * @return int Rows deleted.
	 */
	public static function prune_older_than( $days = 60 ) {
		global $wpdb;
		$days  = max( 7, min( 365, (int) $days ) );
		$table = Avia_Attribution_Database::webhook_log_table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from plugin helper.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cut
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	public static function get_recent( $limit = 20 ) {
		global $wpdb;
		$table = Avia_Attribution_Database::webhook_log_table();
		$limit = max( 1, min( 50, (int) $limit ) );

		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}",
			ARRAY_A
		);
	}

	public static function get_grouped_paginated( $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table    = Avia_Attribution_Database::webhook_log_table();
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT 1 FROM {$table}
				GROUP BY DATE(created_at), event_type, http_status, signature_ok, message
			) grouped"
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(created_at) AS log_date,
					event_type,
					http_status,
					signature_ok,
					message,
					COUNT(*) AS row_count,
					MAX(created_at) AS last_at
				FROM {$table}
				GROUP BY DATE(created_at), event_type, http_status, signature_ok, message
				ORDER BY log_date DESC, last_at DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'rows'        => is_array( $rows ) ? $rows : array(),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 1,
		);
	}

	public static function format_signature_label( $signature_ok ) {
		if ( null === $signature_ok || '' === $signature_ok ) {
			return 'n/a';
		}

		return (int) $signature_ok ? 'OK' : 'FAIL';
	}

	private static function request_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}
}
