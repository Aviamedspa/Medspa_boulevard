<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Google_Ads_Offline {

	const UPLOADABLE_EVENT_TYPES = array(
		'APPOINTMENT_COMPLETED',
		'ORDER_COMPLETED',
	);

	public static function maybe_queue_from_stored_event( array $stored, array $payload ) {
		if ( empty( $stored['ledger_event_id'] ) || empty( $stored['event_type'] ) ) {
			return null;
		}

		if ( ! in_array( $stored['event_type'], self::UPLOADABLE_EVENT_TYPES, true ) ) {
			return null;
		}

		return self::queue_from_ledger_event( (int) $stored['ledger_event_id'] );
	}

	public static function queue_from_ledger_event( $ledger_event_id ) {
		global $wpdb;

		$events_table = Avia_Attribution_Database::events_table();

		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$events_table} WHERE id = %d LIMIT 1",
				$ledger_event_id
			),
			ARRAY_A
		);

		if ( empty( $event ) ) {
			return new WP_Error( 'avia_offline_event_missing', 'Ledger event not found.' );
		}

		if ( ! in_array( $event['event_type'], self::UPLOADABLE_EVENT_TYPES, true ) ) {
			return new WP_Error( 'avia_offline_event_type', 'Event type is not eligible for Google Ads upload.' );
		}

		$payload  = json_decode( $event['payload_json'] ?? '', true );
		$payload  = is_array( $payload ) ? $payload : array();
		$decision = self::evaluate_upload_queue( $event, $payload );

		if ( 'defer' === $decision['action'] ) {
			return new WP_Error( 'avia_upload_deferred', 'Upload deferred to ORDER_COMPLETED for revenue accuracy.' );
		}

		if ( 'duplicate' === $decision['action'] ) {
			return new WP_Error(
				'avia_upload_duplicate',
				'Duplicate upload skipped.',
				array( 'upload_id' => (int) ( $decision['upload_id'] ?? 0 ) )
			);
		}

		$uploads_table = Avia_Attribution_Database::google_ads_uploads_table();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$uploads_table} WHERE ledger_event_id = %d LIMIT 1",
				$ledger_event_id
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$session     = self::get_session_for_event( $event );
		$conv_dt     = Avia_Attribution_Google_Ads_Api::format_conversion_datetime( $event['created_at'] );
		$eligibility = Avia_Attribution_Ads_Integrity::evaluate_for_upload( $session, $event['created_at'] ?? '' );

		$conversion_value = self::resolve_conversion_value( $event );

		if ( ! $eligibility['ok'] ) {
			return self::insert_upload_row(
				array(
					'ledger_event_id'     => $ledger_event_id,
					'event_type'          => $event['event_type'],
					'att_id'              => $event['att_id'] ?? '',
					'gclid'               => '',
					'gbraid'              => '',
					'wbraid'              => '',
					'conversion_value'    => $conversion_value,
					'currency_code'       => 'USD',
					'conversion_datetime' => $conv_dt,
					'status'              => 'skipped',
					'error_message'       => Avia_Attribution_Ads_Integrity::skip_message( $eligibility['reason'] ),
				)
			);
		}

		// Same ad click already uploaded (Google often counts One per click) — fold revenue into that row and restate.
		$existing_click_upload = self::find_uploaded_row_by_click_ids(
			$eligibility['gclid'],
			$eligibility['gbraid'],
			$eligibility['wbraid']
		);
		if ( ! empty( $existing_click_upload['id'] ) && null !== $conversion_value && '' !== $conversion_value ) {
			$merged_id = self::merge_value_into_uploaded_row(
				(int) $existing_click_upload['id'],
				$conversion_value,
				$ledger_event_id
			);
			if ( ! is_wp_error( $merged_id ) ) {
				return self::insert_upload_row(
					array(
						'ledger_event_id'     => $ledger_event_id,
						'event_type'          => $event['event_type'],
						'att_id'              => $event['att_id'] ?? '',
						'gclid'               => $eligibility['gclid'],
						'gbraid'              => $eligibility['gbraid'],
						'wbraid'              => $eligibility['wbraid'],
						'conversion_value'    => $conversion_value,
						'currency_code'       => 'USD',
						'conversion_datetime' => $conv_dt,
						'status'              => 'skipped',
						'error_message'       => sprintf(
							'Merged into upload #%d (own order $%s; combined conversion value).',
							(int) $merged_id,
							number_format( (float) $conversion_value, 2, '.', '' )
						),
					)
				);
			}
		}

		$upload_id = self::insert_upload_row(
			array(
				'ledger_event_id'     => $ledger_event_id,
				'event_type'          => $event['event_type'],
				'att_id'              => $event['att_id'] ?? '',
				'gclid'               => $eligibility['gclid'],
				'gbraid'              => $eligibility['gbraid'],
				'wbraid'              => $eligibility['wbraid'],
				'conversion_value'    => $conversion_value,
				'currency_code'       => 'USD',
				'conversion_datetime' => $conv_dt,
				'status'              => 'pending',
				'error_message'       => '',
			)
		);

		if ( is_wp_error( $upload_id ) ) {
			return $upload_id;
		}

		if ( Avia_Attribution_Settings::is_google_ads_auto_upload_enabled() ) {
			return self::upload_row( $upload_id );
		}

		return $upload_id;
	}

	public static function upload_row( $upload_id ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$upload_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return new WP_Error( 'avia_offline_upload_missing', 'Upload row not found.' );
		}

		if ( 'uploaded' === $row['status'] ) {
			return true;
		}

		if ( 'skipped' === $row['status'] ) {
			return new WP_Error( 'avia_offline_upload_skipped', $row['error_message'] ?: 'Upload skipped.' );
		}

		// Safety net: never upload a pending/failed row that fails ads-integrity (do not change uploaded rows).
		$event_for_session = array(
			'att_id'     => $row['att_id'] ?? '',
			'created_at' => $row['conversion_datetime'] ?? '',
		);
		$session_check = self::get_session_for_event( $event_for_session );
		if ( empty( $session_check ) ) {
			$session_check = array(
				'gclid'  => $row['gclid'] ?? '',
				'gbraid' => $row['gbraid'] ?? '',
				'wbraid' => $row['wbraid'] ?? '',
			);
		}
		$elig = Avia_Attribution_Ads_Integrity::evaluate_for_upload( $session_check, $row['conversion_datetime'] ?? '' );
		if ( ! $elig['ok'] ) {
			self::update_upload_row(
				$upload_id,
				array(
					'status'        => 'skipped',
					'error_message' => Avia_Attribution_Ads_Integrity::skip_message( $elig['reason'] ),
				)
			);
			return new WP_Error( 'avia_offline_upload_skipped', Avia_Attribution_Ads_Integrity::skip_message( $elig['reason'] ) );
		}

		if ( null === $row['conversion_value'] || '' === $row['conversion_value'] ) {
			self::update_upload_row( $upload_id, array( 'status' => 'pending', 'error_message' => 'Waiting for actual Boulevard revenue. No fallback value is used.' ) );
			return new WP_Error( 'avia_revenue_pending', 'Waiting for actual Boulevard revenue.' );
		}

		$result = Avia_Attribution_Google_Data_Manager_Api::ingest_offline_conversion(
			array(
				'gclid'               => $row['gclid'],
				'gbraid'              => $row['gbraid'],
				'wbraid'              => $row['wbraid'],
				'conversion_datetime' => $row['conversion_datetime'],
				'conversion_value'    => null === $row['conversion_value'] ? null : (float) $row['conversion_value'],
				'currency_code'       => $row['currency_code'] ?: 'USD',
				'transaction_id'      => self::csv_transaction_id( $row ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::update_upload_row(
				$upload_id,
				array(
					'status'        => 'failed',
					'error_message' => $result->get_error_message(),
				)
			);
			return $result;
		}

		self::update_upload_row(
			$upload_id,
			array(
				'status'        => 'uploaded',
				'error_message' => '',
				'uploaded_at'   => current_time( 'mysql' ),
			)
		);

		return true;
	}

	/**
	 * Copy revenue from ledger into existing upload rows (same row — no new queue entry).
	 * Never overwrites same-click merged primaries (their value is a sum of multiple ledgers).
	 */
	public static function sync_upload_values_from_ledger( $limit = 50 ) {
		global $wpdb;

		$uploads = Avia_Attribution_Database::google_ads_uploads_table();
		$events  = Avia_Attribution_Database::events_table();
		$limit   = max( 1, min( 200, (int) $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.id, u.conversion_value, u.status, u.error_message, u.gclid, u.gbraid, u.wbraid, e.revenue_cents
				FROM {$uploads} u
				INNER JOIN {$events} e ON e.id = u.ledger_event_id
				WHERE e.revenue_cents IS NOT NULL AND e.revenue_cents > 0
				ORDER BY u.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$updated = 0;
		foreach ( (array) $rows as $row ) {
			$msg = (string) ( $row['error_message'] ?? '' );
			if ( false !== stripos( $msg, 'Combined same-click' ) || false !== stripos( $msg, 'Merged ledger' ) ) {
				continue;
			}

			$new_value = round( ( (int) $row['revenue_cents'] ) / 100, 2 );
			$old_value = null === $row['conversion_value'] ? null : (float) $row['conversion_value'];
			if ( null !== $old_value && $new_value <= $old_value + 0.001 ) {
				continue;
			}

			$result = $wpdb->update(
				$uploads,
				array( 'conversion_value' => $new_value ),
				array( 'id' => (int) $row['id'] ),
				array( '%f' ),
				array( '%d' )
			);
			if ( false !== $result ) {
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Ledger revenue for an upload row (dollars), or null if unknown.
	 *
	 * @param int $upload_id Upload row id.
	 * @return float|null
	 */
	public static function ledger_revenue_for_upload_id( $upload_id ) {
		global $wpdb;

		$uploads = Avia_Attribution_Database::google_ads_uploads_table();
		$events  = Avia_Attribution_Database::events_table();
		$upload_id = (int) $upload_id;
		if ( $upload_id <= 0 ) {
			return null;
		}

		$cents = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT e.revenue_cents
				FROM {$uploads} u
				INNER JOIN {$events} e ON e.id = u.ledger_event_id
				WHERE u.id = %d
				LIMIT 1",
				$upload_id
			)
		);
		if ( null === $cents || '' === $cents ) {
			return null;
		}
		return round( ( (int) $cents ) / 100, 2 );
	}

	/**
	 * Restate conversion value using this upload row's transaction ID.
	 * Works for uploaded or skipped rows (needed when Google counted an older member, not the merge primary).
	 * Does not change status / error_message.
	 *
	 * @param int   $upload_id         Upload row id.
	 * @param float $conversion_value  Value to send.
	 * @return true|WP_Error
	 */
	public static function restate_value_on_transaction_id( $upload_id, $conversion_value ) {
		global $wpdb;

		$upload_id = (int) $upload_id;
		if ( $upload_id <= 0 ) {
			return new WP_Error( 'avia_restate_invalid', 'Invalid upload ID.' );
		}
		if ( null === $conversion_value || '' === $conversion_value ) {
			return new WP_Error( 'avia_revenue_pending', 'Cannot restate without a conversion value.' );
		}

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $upload_id ),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return new WP_Error( 'avia_restate_missing', 'Upload row not found.' );
		}
		if ( empty( $row['gclid'] ) && empty( $row['gbraid'] ) && empty( $row['wbraid'] ) ) {
			return new WP_Error( 'avia_restate_no_click', 'No click ID on upload row.' );
		}

		return Avia_Attribution_Google_Data_Manager_Api::ingest_offline_conversion(
			array(
				'gclid'               => $row['gclid'],
				'gbraid'              => $row['gbraid'],
				'wbraid'              => $row['wbraid'],
				'conversion_datetime' => $row['conversion_datetime'],
				'conversion_value'    => (float) $conversion_value,
				'currency_code'       => $row['currency_code'] ?: 'USD',
				'transaction_id'      => self::csv_transaction_id( $row ),
			)
		);
	}

	/**
	 * Push combined same-click value to Google on member transaction IDs that were already uploaded once.
	 * Google "One" may have counted an older upload (e.g. $654) while the plugin primary is newer ($150+$654).
	 * Only rows with uploaded_at are restated — never invents a brand-new Google conversion from a never-sent row.
	 *
	 * @param int[] $member_ids       Upload ids in the merge group.
	 * @param float $combined_value   Ledger sum.
	 * @return array{ok:int,failed:int,skipped:int,errors:array}
	 */
	public static function restate_combined_value_on_all_members( array $member_ids, $combined_value ) {
		global $wpdb;

		$out = array(
			'ok'      => 0,
			'failed'  => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		$combined_value = round( (float) $combined_value, 2 );
		$table          = Avia_Attribution_Database::google_ads_uploads_table();
		$ids            = array_values( array_unique( array_map( 'intval', $member_ids ) ) );

		// Oldest uploaded_at first — update the conversion Google most likely counted before newer ones.
		$ordered = array();
		foreach ( $ids as $mid ) {
			if ( $mid <= 0 ) {
				continue;
			}
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, uploaded_at, status, gclid, gbraid, wbraid FROM {$table} WHERE id = %d LIMIT 1",
					$mid
				),
				ARRAY_A
			);
			if ( empty( $row ) ) {
				++$out['skipped'];
				continue;
			}
			// Only touch rows Google already received (avoids creating extra conversions).
			if ( empty( $row['uploaded_at'] ) ) {
				++$out['skipped'];
				continue;
			}
			if ( empty( $row['gclid'] ) && empty( $row['gbraid'] ) && empty( $row['wbraid'] ) ) {
				++$out['skipped'];
				continue;
			}
			$ordered[] = $row;
		}

		usort(
			$ordered,
			static function ( $a, $b ) {
				$ta = (string) ( $a['uploaded_at'] ?? '' );
				$tb = (string) ( $b['uploaded_at'] ?? '' );
				if ( $ta === $tb ) {
					return (int) $a['id'] - (int) $b['id'];
				}
				return strcmp( $ta, $tb );
			}
		);

		foreach ( $ordered as $row ) {
			$result = self::restate_value_on_transaction_id( (int) $row['id'], $combined_value );
			if ( is_wp_error( $result ) ) {
				++$out['failed'];
				$out['errors'][] = '#' . (int) $row['id'] . ': ' . $result->get_error_message();
			} else {
				++$out['ok'];
			}
		}
		return $out;
	}

	/**
	 * Pick the best primary upload for a same-click group: oldest successful upload
	 * (usually what Google "One" counted), falling back to lowest id.
	 *
	 * @param int[]                $member_ids Upload ids.
	 * @param array<int,array>     $rows_by_id Rows keyed by id.
	 * @return int Primary upload id.
	 */
	private static function pick_oldest_uploaded_primary( array $member_ids, array $rows_by_id ) {
		$candidates = array();
		foreach ( $member_ids as $mid ) {
			$row = $rows_by_id[ (int) $mid ] ?? null;
			if ( empty( $row ) ) {
				continue;
			}
			$candidates[] = $row;
		}
		if ( empty( $candidates ) ) {
			return (int) ( $member_ids[0] ?? 0 );
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				$ta = (string) ( $a['uploaded_at'] ?? '' );
				$tb = (string) ( $b['uploaded_at'] ?? '' );
				$a_has = '' !== $ta;
				$b_has = '' !== $tb;
				if ( $a_has && ! $b_has ) {
					return -1;
				}
				if ( $b_has && ! $a_has ) {
					return 1;
				}
				if ( $ta !== $tb ) {
					return strcmp( $ta, $tb );
				}
				return (int) $a['id'] - (int) $b['id'];
			}
		);

		return (int) $candidates[0]['id'];
	}

	/**
	 * Normalize a same-click group for honest dashboard display + Google value.
	 * Primary (oldest upload) = combined ledger sum, status uploaded.
	 * Display = each row's own ledger (e.g. $654 uploaded + $150 merged).
	 * Google still gets one restated conversion at the combined sum ($804).
	 *
	 * @param int[] $member_ids Upload ids sharing a click.
	 * @param bool  $restate    Whether to push combined value to Google on prior uploads.
	 * @return array{primary_id:int,sum:float,changed:bool,restated:int,errors:array}|null
	 */
	public static function normalize_same_click_group( array $member_ids, $restate = true ) {
		global $wpdb;

		$table      = Avia_Attribution_Database::google_ads_uploads_table();
		$member_ids = array_values( array_unique( array_filter( array_map( 'intval', $member_ids ) ) ) );
		if ( count( $member_ids ) < 2 ) {
			return null;
		}

		$rows_by_id = array();
		foreach ( $member_ids as $mid ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $mid ),
				ARRAY_A
			);
			if ( ! empty( $row ) ) {
				$rows_by_id[ $mid ] = $row;
			}
		}
		if ( count( $rows_by_id ) < 2 ) {
			return null;
		}

		$primary_id = self::pick_oldest_uploaded_primary( array_keys( $rows_by_id ), $rows_by_id );
		if ( $primary_id <= 0 || empty( $rows_by_id[ $primary_id ] ) ) {
			return null;
		}

		$sum = 0.0;
		$ledger_by_id = array();
		foreach ( array_keys( $rows_by_id ) as $mid ) {
			$rev = self::ledger_revenue_for_upload_id( $mid );
			if ( null === $rev ) {
				// Prefer own-order fallback: never treat an already-combined primary value as this row's ledger.
				$msg = (string) ( $rows_by_id[ $mid ]['error_message'] ?? '' );
				if ( preg_match( '/own order \$([0-9]+(?:\.[0-9]+)?)/i', $msg, $m ) ) {
					$rev = (float) $m[1];
				} else {
					$fallback = $rows_by_id[ $mid ]['conversion_value'] ?? null;
					$rev      = null === $fallback || '' === $fallback ? 0.0 : (float) $fallback;
				}
			}
			$ledger_by_id[ $mid ] = round( (float) $rev, 2 );
			$sum                 += $ledger_by_id[ $mid ];
		}
		$sum = round( $sum, 2 );

		$primary_own = $ledger_by_id[ $primary_id ];
		$note        = sprintf(
			'Combined same-click rows #%s into 1 Ads conversion $%s (table shows own orders; Google restated combined).',
			implode( ', #', array_keys( $rows_by_id ) ),
			number_format( $sum, 2, '.', '' )
		);

		$changed = false;
		$primary = $rows_by_id[ $primary_id ];
		$primary_val = null === $primary['conversion_value'] || '' === $primary['conversion_value']
			? null
			: (float) $primary['conversion_value'];
		if ( 'uploaded' !== ( $primary['status'] ?? '' ) || null === $primary_val || abs( $primary_val - $primary_own ) > 0.009 ) {
			$changed = true;
		}

		self::update_upload_row(
			$primary_id,
			array(
				'status'           => 'uploaded',
				'conversion_value' => $primary_own,
				'error_message'    => $note,
			)
		);

		foreach ( $rows_by_id as $mid => $row ) {
			if ( (int) $mid === $primary_id ) {
				continue;
			}
			$own = $ledger_by_id[ $mid ];
			$msg = sprintf(
				'Merged into upload #%d (own order $%s; combined conversion value $%s).',
				$primary_id,
				number_format( $own, 2, '.', '' ),
				number_format( $sum, 2, '.', '' )
			);
			$cur_status = (string) ( $row['status'] ?? '' );
			$cur_val    = null === $row['conversion_value'] || '' === $row['conversion_value']
				? null
				: (float) $row['conversion_value'];
			$cur_msg = (string) ( $row['error_message'] ?? '' );
			if ( 'skipped' !== $cur_status || null === $cur_val || abs( $cur_val - $own ) > 0.009 || $cur_msg !== $msg ) {
				$changed = true;
			}
			self::update_upload_row(
				(int) $mid,
				array(
					'status'           => 'skipped',
					'conversion_value' => $own,
					'error_message'    => $msg,
				)
			);
		}

		$restated = 0;
		$errors   = array();
		if ( $restate ) {
			$multi = self::restate_combined_value_on_all_members( array_keys( $rows_by_id ), $sum );
			$restated = (int) $multi['ok'];
			$errors   = $multi['errors'];
			if ( $restated > 0 ) {
				self::update_upload_row( $primary_id, array( 'error_message' => $note ) );
			} elseif ( ! empty( $errors ) ) {
				self::update_upload_row(
					$primary_id,
					array(
						'error_message' => $note . ' Google restatement failed: ' . implode( ' | ', $errors ),
					)
				);
			}
		}

		return array(
			'primary_id' => $primary_id,
			'sum'        => $sum,
			'changed'    => $changed,
			'restated'   => $restated,
			'errors'     => $errors,
		);
	}

	/**
	 * Parse member upload ids from a same-click merge note / sibling rows.
	 *
	 * @param int    $primary_id Primary upload id.
	 * @param string $msg        error_message.
	 * @return int[]
	 */
	private static function same_click_member_ids_from_row( $primary_id, $msg ) {
		global $wpdb;

		$primary_id = (int) $primary_id;
		$member_ids = array();
		if ( preg_match_all( '/#(\d+)/', (string) $msg, $matches ) ) {
			$member_ids = array_values( array_unique( array_map( 'intval', $matches[1] ) ) );
		}
		if ( count( $member_ids ) < 2 ) {
			$member_ids = array( $primary_id );
			$table      = Avia_Attribution_Database::google_ads_uploads_table();
			$siblings   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE error_message LIKE %s ORDER BY id DESC LIMIT 20",
					'%merged into upload #' . $primary_id . '%'
				)
			);
			foreach ( (array) $siblings as $sid ) {
				$member_ids[] = (int) $sid;
			}
			$member_ids = array_values( array_unique( $member_ids ) );
		}
		return $member_ids;
	}

	/**
	 * Recompute same-click merged primary values from ledger + restate ALL member transaction IDs.
	 * Fixes inflation AND cases where Google still shows an older member value (e.g. $654 vs plugin $804).
	 *
	 * @param int $limit Max merged primaries to repair.
	 * @return array{repaired:int,restated:int,errors:array,details:array}
	 */
	public static function repair_inflated_same_click_merged_values( $limit = 50 ) {
		global $wpdb;

		$table  = Avia_Attribution_Database::google_ads_uploads_table();
		$limit  = max( 1, min( 100, (int) $limit ) );
		$result = array(
			'repaired' => 0,
			'restated' => 0,
			'errors'   => array(),
			'details'  => array(),
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, conversion_value, error_message, status
				FROM {$table}
				WHERE status = 'uploaded'
					AND (
						error_message LIKE %s
						OR error_message LIKE %s
					)
				ORDER BY id DESC
				LIMIT %d",
				'%Combined same-click%',
				'%Merged ledger%',
				$limit
			),
			ARRAY_A
		);

		$seen = array();
		foreach ( (array) $rows as $row ) {
			$primary_id = (int) $row['id'];
			$msg        = (string) ( $row['error_message'] ?? '' );
			$member_ids = self::same_click_member_ids_from_row( $primary_id, $msg );
			if ( count( $member_ids ) < 2 ) {
				continue;
			}
			sort( $member_ids );
			$key = implode( '-', $member_ids );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$current = null === $row['conversion_value'] || '' === $row['conversion_value']
				? 0.0
				: (float) $row['conversion_value'];

			$normalized = self::normalize_same_click_group( $member_ids, true );
			if ( empty( $normalized ) ) {
				continue;
			}

			if ( ! empty( $normalized['changed'] ) ) {
				++$result['repaired'];
			}
			$result['restated'] += (int) ( $normalized['restated'] ?? 0 );
			foreach ( (array) ( $normalized['errors'] ?? array() ) as $err ) {
				$result['errors'][] = 'Primary #' . (int) $normalized['primary_id'] . ' ' . $err;
			}

			$result['details'][] = array(
				'upload_id'   => (int) $normalized['primary_id'],
				'from'        => $current,
				'to'          => (float) $normalized['sum'],
				'members'     => $member_ids,
				'restated_ok' => (int) ( $normalized['restated'] ?? 0 ),
			);
		}

		return $result;
	}

	/**
	 * Find an already-uploaded row for the same Google click ID.
	 *
	 * @param string $gclid  GCLID.
	 * @param string $gbraid GBRAID.
	 * @param string $wbraid WBRAID.
	 * @return array<string,mixed> Upload row or empty.
	 */
	public static function find_uploaded_row_by_click_ids( $gclid, $gbraid, $wbraid ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$gclid  = trim( (string) $gclid );
		$gbraid = trim( (string) $gbraid );
		$wbraid = trim( (string) $wbraid );

		if ( '' !== $gclid ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'uploaded' AND gclid = %s ORDER BY uploaded_at DESC, id DESC LIMIT 1",
					$gclid
				),
				ARRAY_A
			);
			if ( ! empty( $row ) ) {
				return $row;
			}
		}
		if ( '' !== $gbraid ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'uploaded' AND gbraid = %s ORDER BY uploaded_at DESC, id DESC LIMIT 1",
					$gbraid
				),
				ARRAY_A
			);
			if ( ! empty( $row ) ) {
				return $row;
			}
		}
		if ( '' !== $wbraid ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'uploaded' AND wbraid = %s ORDER BY uploaded_at DESC, id DESC LIMIT 1",
					$wbraid
				),
				ARRAY_A
			);
			if ( ! empty( $row ) ) {
				return $row;
			}
		}

		return array();
	}

	/**
	 * Add order revenue onto an existing uploaded click conversion and restate in Google (count stays 1).
	 *
	 * @param int        $upload_id       Primary uploaded row.
	 * @param float|null $additional_value Revenue to add (null = skip merge).
	 * @param int        $ledger_event_id  Merged ledger event id (for audit message).
	 * @return int|WP_Error Upload id.
	 */
	public static function merge_value_into_uploaded_row( $upload_id, $additional_value, $ledger_event_id = 0 ) {
		global $wpdb;

		$upload_id = (int) $upload_id;
		if ( $upload_id <= 0 ) {
			return new WP_Error( 'avia_merge_invalid', 'Invalid upload ID.' );
		}
		if ( null === $additional_value || '' === $additional_value ) {
			return new WP_Error( 'avia_revenue_pending', 'Cannot merge without actual Boulevard revenue.' );
		}

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $upload_id ),
			ARRAY_A
		);
		if ( empty( $row ) || 'uploaded' !== ( $row['status'] ?? '' ) ) {
			return new WP_Error( 'avia_merge_missing', 'Uploaded row not found.' );
		}

		$own = self::ledger_revenue_for_upload_id( $upload_id );
		if ( null === $own ) {
			$own = null === $row['conversion_value'] || '' === $row['conversion_value']
				? 0.0
				: (float) $row['conversion_value'];
		}
		$own     = round( (float) $own, 2 );
		$merged  = round( $own + (float) $additional_value, 2 );

		$note = sprintf(
			'Merged ledger #%d into this click (table own order $%s; 1 Ads conversion restated $%s).',
			(int) $ledger_event_id,
			number_format( $own, 2, '.', '' ),
			number_format( $merged, 2, '.', '' )
		);

		// Keep own-order value in the table; send combined value only to Google.
		self::update_upload_row(
			$upload_id,
			array(
				'conversion_value' => $own,
				'error_message'    => $note,
			)
		);

		$restate = self::restate_value_on_transaction_id( $upload_id, $merged );
		if ( is_wp_error( $restate ) ) {
			return $restate;
		}

		self::update_upload_row(
			$upload_id,
			array(
				'error_message' => $note,
			)
		);

		return $upload_id;
	}

	/**
	 * Whether a skipped row was blocked by ads-integrity (must not fold into Google value).
	 *
	 * @param string $error_message Upload error_message.
	 * @return bool
	 */
	private static function is_integrity_skip_message( $error_message ) {
		$msg = (string) $error_message;
		if ( '' === $msg ) {
			return false;
		}
		$needles = array(
			'No gclid, gbraid, or wbraid',
			'not a valid Google gclid',
			'utm_campaign missing',
			'not ads-attributed',
			'-day ads lookback',
		);
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $msg, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collapse same-click duplicates into 1 uploaded conversion (sum values + restate).
	 * Includes uploaded rows and non-integrity skipped siblings that share the same full click ID.
	 * Truncated UI labels can look identical — this keys on the full gclid only.
	 *
	 * @param int $limit_groups Max click-ID groups to process.
	 * @return array{groups:int,merged:int,skipped:int,restated:int,errors:array,diagnostics:array}
	 */
	public static function consolidate_same_click_uploaded_duplicates( $limit_groups = 25 ) {
		global $wpdb;

		$table    = Avia_Attribution_Database::google_ads_uploads_table();
		$sessions = Avia_Attribution_Database::sessions_table();
		$limit    = max( 1, min( 50, (int) $limit_groups ) );
		$result   = array(
			'groups'      => 0,
			'merged'      => 0,
			'skipped'     => 0,
			'restated'    => 0,
			'errors'      => array(),
			'diagnostics' => array(),
		);

		// Prefer upload.gclid; fall back to session (dashboard often shows session click).
		$click_expr = "NULLIF(TRIM(COALESCE(NULLIF(u.gclid, ''), NULLIF(s.gclid, ''))), '')";

		$uploaded_click_rows = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} u
			LEFT JOIN {$sessions} s ON s.att_id = u.att_id
			WHERE u.status = 'uploaded' AND {$click_expr} IS NOT NULL"
		);
		$result['diagnostics']['uploaded_with_click'] = $uploaded_click_rows;
		$result['diagnostics']['plugin_version']      = defined( 'AVIA_ATTRIBUTION_VERSION' ) ? AVIA_ATTRIBUTION_VERSION : '';

		// Subquery avoids flaky GROUP BY on expression across MySQL modes.
		$groups = $wpdb->get_results(
			"SELECT click_id, COUNT(*) AS cnt,
				SUM(CASE WHEN status = 'uploaded' THEN 1 ELSE 0 END) AS uploaded_cnt,
				GROUP_CONCAT(id ORDER BY CASE WHEN status = 'uploaded' THEN 0 ELSE 1 END, id DESC) AS ids
			FROM (
				SELECT u.id, u.status, {$click_expr} AS click_id
				FROM {$table} u
				LEFT JOIN {$sessions} s ON s.att_id = u.att_id
				WHERE u.status IN ('uploaded', 'skipped')
			) AS click_rows
			WHERE click_id IS NOT NULL AND click_id != ''
			GROUP BY click_id
			HAVING COUNT(*) > 1 AND SUM(CASE WHEN status = 'uploaded' THEN 1 ELSE 0 END) >= 1
			ORDER BY cnt DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		$result['diagnostics']['duplicate_groups_found'] = count( (array) $groups );

		// How many rows only LOOK the same because of old prefix|suffix truncation.
		$lookalike = $wpdb->get_results(
			"SELECT CONCAT(LEFT(click_id, 9), '|', RIGHT(click_id, 8)) AS trunc_key,
				COUNT(*) AS row_cnt,
				COUNT(DISTINCT click_id) AS distinct_full
			FROM (
				SELECT {$click_expr} AS click_id
				FROM {$table} u
				LEFT JOIN {$sessions} s ON s.att_id = u.att_id
				WHERE u.status IN ('uploaded', 'skipped')
			) AS t
			WHERE click_id IS NOT NULL AND click_id != '' AND CHAR_LENGTH(click_id) > 18
			GROUP BY trunc_key
			HAVING COUNT(*) > 1 AND COUNT(DISTINCT click_id) > 1
			ORDER BY row_cnt DESC
			LIMIT 20",
			ARRAY_A
		);
		$lookalike_rows = 0;
		foreach ( (array) $lookalike as $bucket ) {
			$lookalike_rows += (int) ( $bucket['row_cnt'] ?? 0 );
		}
		$result['diagnostics']['truncation_lookalike_buckets'] = count( (array) $lookalike );
		$result['diagnostics']['truncation_lookalike_rows']    = $lookalike_rows;

		foreach ( (array) $groups as $group ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', explode( ',', (string) ( $group['ids'] ?? '' ) ) )
				)
			);
			if ( count( $ids ) < 2 ) {
				continue;
			}

			// Drop integrity-skipped rows from the fold set.
			$fold_ids = array();
			foreach ( $ids as $id ) {
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT id, status, error_message FROM {$table} WHERE id = %d LIMIT 1", $id ),
					ARRAY_A
				);
				if ( empty( $row ) ) {
					continue;
				}
				if ( 'skipped' === ( $row['status'] ?? '' ) && self::is_integrity_skip_message( $row['error_message'] ?? '' ) ) {
					continue;
				}
				$fold_ids[] = (int) $id;
			}
			if ( count( $fold_ids ) < 2 ) {
				continue;
			}

			$normalized = self::normalize_same_click_group( $fold_ids, true );
			if ( empty( $normalized ) ) {
				continue;
			}
			if ( empty( $normalized['changed'] ) && (int) ( $normalized['restated'] ?? 0 ) <= 0 ) {
				continue;
			}

			++$result['groups'];
			++$result['merged'];
			$result['restated'] += (int) ( $normalized['restated'] ?? 0 );
			$result['skipped']  += max( 0, count( $fold_ids ) - 1 );
			foreach ( (array) ( $normalized['errors'] ?? array() ) as $err ) {
				$result['errors'][] = 'Primary #' . (int) $normalized['primary_id'] . ' ' . $err;
			}
		}

		return $result;
	}

	/**
	 * Re-send an already-uploaded conversion with the same transaction ID (value restatement — not a new row).
	 */
	public static function restate_upload_row( $upload_id ) {
		global $wpdb;

		$upload_id = (int) $upload_id;
		if ( $upload_id <= 0 ) {
			return new WP_Error( 'avia_restate_invalid', 'Invalid upload ID.' );
		}

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $upload_id ),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return new WP_Error( 'avia_restate_missing', 'Upload row not found.' );
		}

		if ( empty( $row['gclid'] ) && empty( $row['gbraid'] ) && empty( $row['wbraid'] ) ) {
			return new WP_Error( 'avia_restate_no_click', 'No click ID — dashboard value updated only.' );
		}

		if ( 'uploaded' !== $row['status'] ) {
			return self::upload_row( $upload_id );
		}

		if ( null === $row['conversion_value'] || '' === $row['conversion_value'] ) {
			return new WP_Error( 'avia_revenue_pending', 'Cannot restate without actual Boulevard revenue.' );
		}

		$result = Avia_Attribution_Google_Data_Manager_Api::ingest_offline_conversion(
			array(
				'gclid'               => $row['gclid'],
				'gbraid'              => $row['gbraid'],
				'wbraid'              => $row['wbraid'],
				'conversion_datetime' => $row['conversion_datetime'],
				'conversion_value'    => null === $row['conversion_value'] ? null : (float) $row['conversion_value'],
				'currency_code'       => $row['currency_code'] ?: 'USD',
				'transaction_id'      => self::csv_transaction_id( $row ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::update_upload_row(
				$upload_id,
				array(
					'status'        => 'failed',
					'error_message' => 'Restate failed: ' . $result->get_error_message(),
				)
			);
			return $result;
		}

		self::update_upload_row(
			$upload_id,
			array(
				'status'        => 'uploaded',
				'error_message' => '',
				'uploaded_at'   => current_time( 'mysql' ),
			)
		);

		return true;
	}

	/**
	 * After ledger revenue backfill: fix dashboard values, then restate Google for uploaded rows with click IDs.
	 */
	public static function reconcile_stale_upload_values( $sync_limit = 100, $restate_limit = 15 ) {
		global $wpdb;

		$uploads = Avia_Attribution_Database::google_ads_uploads_table();
		$events  = Avia_Attribution_Database::events_table();
		$limit   = max( 0, min( 50, (int) $restate_limit ) );

		$restate_ids = array();
		if ( $limit > 0 ) {
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.id
					FROM {$uploads} u
					INNER JOIN {$events} e ON e.id = u.ledger_event_id
					WHERE u.status = 'uploaded'
						AND e.revenue_cents IS NOT NULL AND e.revenue_cents > 0
						AND ( u.gclid <> '' OR u.gbraid <> '' OR u.wbraid <> '' )
						AND (
							u.conversion_value IS NULL
							OR u.conversion_value < ROUND( e.revenue_cents / 100, 2 ) - 0.01
						)
					ORDER BY u.id DESC
					LIMIT %d",
					$limit
				),
				ARRAY_A
			);
			foreach ( (array) $candidates as $row ) {
				$restate_ids[] = (int) $row['id'];
			}
		}

		self::sync_upload_values_from_ledger( $sync_limit );

		$restated = 0;
		foreach ( $restate_ids as $upload_id ) {
			$result = self::restate_upload_row( $upload_id );
			if ( ! is_wp_error( $result ) ) {
				++$restated;
			}
		}

		return array(
			'restated' => $restated,
		);
	}

	public static function process_pending_uploads( $limit = 25 ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status IN ('pending', 'failed') ORDER BY id ASC LIMIT %d",
				$limit
			)
		);

		$results = array();
		foreach ( $ids as $upload_id ) {
			$result    = self::upload_row( (int) $upload_id );
			$results[] = array(
				'upload_id' => (int) $upload_id,
				'success'   => ! is_wp_error( $result ),
				'message'   => is_wp_error( $result ) ? $result->get_error_message() : 'Uploaded',
			);
		}

		return $results;
	}

	public static function get_recent_uploads( $limit = 50 ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$limit = max( 1, (int) $limit );

		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}",
			ARRAY_A
		);
	}

	public static function get_uploads_paginated( $page = 1, $per_page = 20 ) {
		global $wpdb;

		$table    = Avia_Attribution_Database::google_ads_uploads_table();
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
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
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	public static function build_csv( array $statuses = array( 'pending', 'failed' ) ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status IN ('pending','failed') ORDER BY id ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$rows = $wpdb->get_results(
				"SELECT * FROM {$table} WHERE status IN ('pending','failed','skipped') ORDER BY id DESC LIMIT 500",
				ARRAY_A
			);
		}

		$conversion_name = Avia_Attribution_Settings::get( 'google_ads_conversion_name', 'Appointment Completed (Primary)' );
		$lines           = array(
			'Conversion action,GCLID,Conversion date and time,Conversion value,Currency code,Order ID,Event source',
		);

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['status'], $statuses, true ) && ! in_array( $row['status'], array( 'pending', 'failed' ), true ) ) {
				continue;
			}

			$click_id = $row['gclid'] ?: ( $row['gbraid'] ?: $row['wbraid'] );
			if ( '' === $click_id ) {
				continue;
			}

			if ( null === $row['conversion_value'] || '' === $row['conversion_value'] ) {
				continue; // Await actual revenue; do not export an estimated amount.
			}
			$value = (string) $row['conversion_value'];
			$lines[] = self::csv_line(
				array(
					$conversion_name,
					$click_id,
					self::csv_datetime_for_import( $row['conversion_datetime'] ),
					$value,
					$row['currency_code'] ?: 'USD',
					self::csv_transaction_id( $row ),
					'web',
				)
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	public static function get_pending_count() {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'failed')"
		);
	}

	private static function get_session_for_event( array $event ) {
		if ( empty( $event['att_id'] ) ) {
			return array();
		}

		global $wpdb;
		$table = Avia_Attribution_Database::sessions_table();

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE att_id = %s LIMIT 1",
				$event['att_id']
			),
			ARRAY_A
		);

		return is_array( $session ) ? $session : array();
	}

	private static function resolve_click_ids( array $session ) {
		return array(
			'gclid'  => isset( $session['gclid'] ) ? (string) $session['gclid'] : '',
			'gbraid' => isset( $session['gbraid'] ) ? (string) $session['gbraid'] : '',
			'wbraid' => isset( $session['wbraid'] ) ? (string) $session['wbraid'] : '',
		);
	}

	/**
	 * One conversion upload per order (preferred) or per appointment when no order exists.
	 */
	private static function evaluate_upload_queue( array $event, array $payload ) {
		$event_type = (string) ( $event['event_type'] ?? '' );
		$order_id   = Avia_Attribution_Boulevard_Api::resolve_order_id_from_payload( $payload, $event_type );
		$dedupe_key = self::resolve_conversion_dedupe_key( $event, $payload, $order_id );

		if ( '' !== $dedupe_key ) {
			$existing = self::find_existing_upload_for_key( $dedupe_key );
			if ( ! empty( $existing['id'] ) ) {
				self::maybe_refresh_upload_value( (int) $existing['id'], $event );
				return array(
					'action'    => 'duplicate',
					'upload_id' => (int) $existing['id'],
				);
			}
		}

		if ( 'APPOINTMENT_COMPLETED' === $event_type && '' !== $order_id ) {
			return array( 'action' => 'defer' );
		}

		return array( 'action' => 'queue' );
	}

	private static function resolve_conversion_dedupe_key( array $event, array $payload, $order_id = '' ) {
		if ( '' === $order_id ) {
			$order_id = Avia_Attribution_Boulevard_Api::resolve_order_id_from_payload( $payload, $event['event_type'] ?? '' );
		}

		if ( '' !== $order_id ) {
			return 'order:' . $order_id;
		}

		$appointment_id = (string) ( $event['blvd_appointment_id'] ?? '' );
		if ( '' !== $appointment_id && false === strpos( $appointment_id, 'urn:blvd:Order:' ) ) {
			return 'appointment:' . $appointment_id;
		}

		return '';
	}

	private static function find_existing_upload_for_key( $dedupe_key ) {
		global $wpdb;

		$events_table  = Avia_Attribution_Database::events_table();
		$uploads_table = Avia_Attribution_Database::google_ads_uploads_table();
		$resource_id   = substr( (string) $dedupe_key, strpos( (string) $dedupe_key, ':' ) + 1 );

		if ( '' === $resource_id ) {
			return array();
		}

		if ( 0 === strpos( (string) $dedupe_key, 'order:' ) ) {
			$like = '%' . $wpdb->esc_like( $resource_id ) . '%';
			return (array) $wpdb->get_row(
				$wpdb->prepare(
					"SELECT u.id, u.conversion_value, u.status
					FROM {$uploads_table} u
					INNER JOIN {$events_table} e ON e.id = u.ledger_event_id
					WHERE u.status IN ('pending', 'uploaded', 'failed')
						AND (
							(e.event_type = 'ORDER_COMPLETED' AND e.blvd_appointment_id = %s)
							OR (e.event_type = 'APPOINTMENT_COMPLETED' AND e.payload_json LIKE %s)
						)
					ORDER BY u.id DESC
					LIMIT 1",
					$resource_id,
					$like
				),
				ARRAY_A
			);
		}

		return (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT u.id, u.conversion_value, u.status
				FROM {$uploads_table} u
				INNER JOIN {$events_table} e ON e.id = u.ledger_event_id
				WHERE u.status IN ('pending', 'uploaded', 'failed')
					AND e.event_type = 'APPOINTMENT_COMPLETED'
					AND e.blvd_appointment_id = %s
				ORDER BY u.id DESC
				LIMIT 1",
				$resource_id
			),
			ARRAY_A
		);
	}

	private static function maybe_refresh_upload_value( $upload_id, array $event ) {
		global $wpdb;

		$upload_id = (int) $upload_id;
		if ( $upload_id <= 0 ) {
			return;
		}

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, conversion_value, status FROM {$table} WHERE id = %d LIMIT 1",
				$upload_id
			),
			ARRAY_A
		);

		if ( empty( $row ) || ! in_array( $row['status'], array( 'pending', 'failed' ), true ) ) {
			return;
		}

		$new_value = self::resolve_conversion_value( $event );
		if ( null === $new_value ) {
			return;
		}

		$old_value = null === $row['conversion_value'] ? null : (float) $row['conversion_value'];
		if ( null === $old_value || $new_value > $old_value ) {
			$wpdb->update(
				$table,
				array( 'conversion_value' => $new_value ),
				array( 'id' => $upload_id ),
				array( '%f' ),
				array( '%d' )
			);
		}
	}

	private static function resolve_conversion_value( array $event ) {
		if ( null !== $event['revenue_cents'] && '' !== $event['revenue_cents'] ) {
			return round( ( (int) $event['revenue_cents'] ) / 100, 2 );
		}

		// Unknown revenue must remain unknown; never fabricate a conversion value.
		return null;
	}

	private static function insert_upload_row( array $data ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'ledger_event_id'     => (int) $data['ledger_event_id'],
				'event_type'          => $data['event_type'],
				'att_id'              => $data['att_id'],
				'gclid'               => $data['gclid'],
				'gbraid'              => $data['gbraid'],
				'wbraid'              => $data['wbraid'],
				'conversion_value'    => $data['conversion_value'],
				'currency_code'       => $data['currency_code'],
				'conversion_datetime' => $data['conversion_datetime'],
				'status'              => $data['status'],
				'error_message'       => $data['error_message'],
				'created_at'          => $now,
				'uploaded_at'         => null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'avia_offline_insert_failed', 'Could not queue Google Ads upload row.' );
		}

		return (int) $wpdb->insert_id;
	}

	private static function update_upload_row( $upload_id, array $data ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$wpdb->update( $table, $data, array( 'id' => (int) $upload_id ) );
	}

	private static function csv_line( array $fields ) {
		$escaped = array();
		foreach ( $fields as $field ) {
			$value     = (string) $field;
			$escaped[] = '"' . str_replace( '"', '""', $value ) . '"';
		}

		return implode( ',', $escaped );
	}

	private static function csv_transaction_id( array $row ) {
		$ledger_event_id = isset( $row['ledger_event_id'] ) ? (int) $row['ledger_event_id'] : 0;
		$upload_id       = isset( $row['id'] ) ? (int) $row['id'] : 0;

		if ( $ledger_event_id > 0 ) {
			return 'avia-ledger-' . $ledger_event_id;
		}

		if ( $upload_id > 0 ) {
			return 'avia-upload-' . $upload_id;
		}

		return 'avia-' . wp_generate_uuid4();
	}

	private static function csv_datetime_for_import( $datetime ) {
		try {
			$dt = new DateTime( (string) $datetime, wp_timezone() );
		} catch ( Exception $exception ) {
			$dt = new DateTime( 'now', wp_timezone() );
		}

		return $dt->format( 'c' );
	}

	const VOID_FALSE_NOTE = 'Voided: false ads attribution (staff/organic client — not a real Google click for this person).';

	/**
	 * Whether an upload row was voided as false ads attribution.
	 *
	 * @param string $error_message Upload error_message.
	 * @return bool
	 */
	public static function is_voided_false_attribution( $error_message ) {
		return false !== stripos( (string) $error_message, 'Voided: false ads attribution' );
	}

	/**
	 * Void false ads attribution for a staff/organic client (e.g. Baylee matched via old unlinked-session bug).
	 * Google gets $0 restate; table keeps actual Boulevard order value; Click ID/campaign cleared on the row.
	 *
	 * @param string $email Client email.
	 * @return array|WP_Error
	 */
	public static function void_false_attribution_by_email( $email ) {
		$email = sanitize_email( trim( (string) $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'avia_void_email', 'Enter a valid client email.' );
		}

		$clients = Avia_Attribution_Boulevard_Api::search_clients_by_email( $email );
		if ( is_wp_error( $clients ) ) {
			return $clients;
		}
		if ( empty( $clients ) ) {
			return new WP_Error( 'avia_void_no_client', 'No Boulevard client found for that email.' );
		}

		return self::void_false_attribution_for_clients( $clients, $email );
	}

	/**
	 * Void false ads attribution when email is missing — resolve Boulevard client(s) via uploaded gclid.
	 *
	 * @param string $gclid Google click id (full or unique prefix).
	 * @return array|WP_Error
	 */
	public static function void_false_attribution_by_gclid( $gclid ) {
		global $wpdb;

		$gclid = sanitize_text_field( trim( (string) $gclid ) );
		if ( strlen( $gclid ) < 12 ) {
			return new WP_Error( 'avia_void_gclid', 'Enter a full gclid (or at least 12 characters).' );
		}

		$uploads = Avia_Attribution_Database::google_ads_uploads_table();
		$events  = Avia_Attribution_Database::events_table();
		$like    = $wpdb->esc_like( $gclid ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.id, u.gclid, e.blvd_client_id, e.payload_json
				FROM {$uploads} u
				INNER JOIN {$events} e ON e.id = u.ledger_event_id
				WHERE u.gclid = %s OR u.gclid LIKE %s
				ORDER BY u.id ASC
				LIMIT 20",
				$gclid,
				$like
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return new WP_Error( 'avia_void_gclid_none', 'No upload row found for that gclid.' );
		}

		$client_ids = array();
		$email      = '';
		foreach ( $rows as $row ) {
			$cid = Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $row['blvd_client_id'] ?? '' );
			if ( '' !== $cid ) {
				$client_ids[] = $cid;
			}
			if ( '' === $email && ! empty( $row['payload_json'] ) ) {
				$payload = json_decode( (string) $row['payload_json'], true );
				if ( is_array( $payload ) ) {
					$candidate = $payload['data']['node']['client']['email']
						?? ( $payload['data']['node']['email'] ?? ( $payload['data']['client']['email'] ?? '' ) );
					$candidate = sanitize_email( (string) $candidate );
					if ( $candidate && is_email( $candidate ) ) {
						$email = $candidate;
					}
				}
			}
		}
		$client_ids = array_values( array_unique( $client_ids ) );
		if ( empty( $client_ids ) ) {
			return new WP_Error( 'avia_void_gclid_no_client', 'gclid found but no Boulevard client id on the ledger event.' );
		}

		$clients = array();
		foreach ( $client_ids as $cid ) {
			$client = Avia_Attribution_Boulevard_Api::get_client_by_id( $cid );
			if ( is_wp_error( $client ) || empty( $client['id'] ) ) {
				$clients[] = array( 'id' => $cid, 'email' => $email );
				continue;
			}
			if ( '' === $email && ! empty( $client['email'] ) ) {
				$email = sanitize_email( (string) $client['email'] );
			}
			$clients[] = $client;
		}

		return self::void_false_attribution_for_clients( $clients, $email );
	}

	/**
	 * Auto-void likely false matches from the old match_latest_unlinked_session bug (removed Oct 15, 2026).
	 * Candidates: gclid upload linked to a client created within 6 hours after the orphan ad session,
	 * with no genuine avia: referral / avia_att_id in early webhook payloads.
	 *
	 * @param int $limit Max distinct clients to void.
	 * @return array
	 */
	public static function void_false_attribution_auto( $limit = 40 ) {
		global $wpdb;

		$limit          = max( 1, min( 100, (int) $limit ) );
		$uploads_table  = Avia_Attribution_Database::google_ads_uploads_table();
		$events_table   = Avia_Attribution_Database::events_table();
		$sessions_table = Avia_Attribution_Database::sessions_table();
		// match_latest removed in commit on 2026-10-15 ~12:01 IST.
		$fix_cutoff = '2026-10-15 12:00:00';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.id AS upload_id, u.status, u.error_message, u.att_id, u.gclid,
					e.blvd_client_id, e.payload_json AS event_payload,
					s.created_at AS session_created_at, s.gclid AS session_gclid
				FROM {$uploads_table} u
				INNER JOIN {$events_table} e ON e.id = u.ledger_event_id
				LEFT JOIN {$sessions_table} s ON s.att_id = u.att_id
				WHERE e.blvd_client_id IS NOT NULL AND e.blvd_client_id <> ''
					AND (
						(u.gclid IS NOT NULL AND u.gclid <> '')
						OR (s.gclid IS NOT NULL AND s.gclid <> '')
					)
					AND s.created_at IS NOT NULL
					AND s.created_at < %s
					AND (u.error_message IS NULL OR u.error_message NOT LIKE %s)
				ORDER BY u.id ASC
				LIMIT 500",
				$fix_cutoff,
				'%Voided: false ads attribution%'
			),
			ARRAY_A
		);

		$by_client = array();
		foreach ( (array) $rows as $row ) {
			$cid = Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $row['blvd_client_id'] ?? '' );
			if ( '' === $cid ) {
				continue;
			}
			if ( self::client_has_genuine_avia_link( $cid ) ) {
				continue;
			}
			$session_ts = strtotime( (string) ( $row['session_created_at'] ?? '' ) );
			if ( ! $session_ts ) {
				continue;
			}
			$client_created = self::earliest_client_created_at( $cid );
			if ( '' === $client_created ) {
				continue;
			}
			$client_ts = strtotime( $client_created );
			if ( ! $client_ts ) {
				continue;
			}
			$delta = $client_ts - $session_ts;
			// match_latest window was 6 hours: session first, then staff CLIENT_CREATED.
			if ( $delta < 0 || $delta > ( 6 * HOUR_IN_SECONDS ) ) {
				continue;
			}
			$by_client[ $cid ] = true;
			if ( count( $by_client ) >= $limit ) {
				break;
			}
		}

		$out = array(
			'candidates' => count( $by_client ),
			'voided'     => 0,
			'emails'     => array(),
			'errors'     => array(),
			'results'    => array(),
		);

		foreach ( array_keys( $by_client ) as $cid ) {
			$client = Avia_Attribution_Boulevard_Api::get_client_by_id( $cid );
			if ( is_wp_error( $client ) || empty( $client ) ) {
				$out['errors'][] = $cid . ': client lookup failed';
				continue;
			}
			$email = sanitize_email( (string) ( $client['email'] ?? '' ) );
			$batch = self::void_false_attribution_for_clients( array( $client ), $email );
			if ( is_wp_error( $batch ) ) {
				$out['errors'][] = ( $email ?: $cid ) . ': ' . $batch->get_error_message();
				continue;
			}
			++$out['voided'];
			if ( $email ) {
				$out['emails'][] = $email;
			}
			$out['results'][] = $batch;
		}

		// Repair already-voided rows that still show $0 / click IDs (e.g. Baylee first void).
		$repair = self::repair_voided_false_attribution_rows( 100 );
		$out['repaired_voided_rows'] = (int) ( $repair['repaired'] ?? 0 );

		return $out;
	}

	/**
	 * Restore ledger order values + clear click IDs on rows already marked voided.
	 *
	 * @param int $limit Max rows.
	 * @return array{repaired:int}
	 */
	public static function repair_voided_false_attribution_rows( $limit = 100 ) {
		global $wpdb;

		$table = Avia_Attribution_Database::google_ads_uploads_table();
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE error_message LIKE %s
				ORDER BY id DESC
				LIMIT %d",
				'%Voided: false ads attribution%',
				$limit
			),
			ARRAY_A
		);

		$repaired = 0;
		foreach ( (array) $rows as $row ) {
			$full = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $row['id'] ),
				ARRAY_A
			);
			if ( empty( $full ) ) {
				continue;
			}
			self::apply_void_to_upload_row( $full, false );
			++$repaired;
		}

		return array( 'repaired' => $repaired );
	}

	/**
	 * @param array  $clients Boulevard client rows.
	 * @param string $email   Primary email for session unlink.
	 * @return array|WP_Error
	 */
	private static function void_false_attribution_for_clients( array $clients, $email = '' ) {
		global $wpdb;

		$client_ids = array();
		foreach ( $clients as $client ) {
			$id = Avia_Attribution_Boulevard_Api::normalize_blvd_client_id( $client['id'] ?? '' );
			if ( '' !== $id ) {
				$client_ids[] = $id;
			}
			foreach ( Avia_Attribution_Boulevard_Api::blvd_client_id_lookup_values( $client['id'] ?? '' ) as $variant ) {
				if ( '' !== $variant ) {
					$client_ids[] = $variant;
				}
			}
			if ( '' === $email && ! empty( $client['email'] ) ) {
				$email = sanitize_email( (string) $client['email'] );
			}
		}
		$client_ids = array_values( array_unique( $client_ids ) );
		if ( empty( $client_ids ) ) {
			return new WP_Error( 'avia_void_no_client_id', 'Boulevard client has no usable ID.' );
		}

		$uploads_table  = Avia_Attribution_Database::google_ads_uploads_table();
		$events_table   = Avia_Attribution_Database::events_table();
		$sessions_table = Avia_Attribution_Database::sessions_table();

		$placeholders = implode( ',', array_fill( 0, count( $client_ids ), '%s' ) );
		$upload_rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.*
				FROM {$uploads_table} u
				INNER JOIN {$events_table} e ON e.id = u.ledger_event_id
				WHERE e.blvd_client_id IN ({$placeholders})
				ORDER BY u.id ASC",
				...$client_ids
			),
			ARRAY_A
		);

		$session_att_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT att_id FROM {$sessions_table}
				WHERE blvd_client_id IN ({$placeholders}) AND att_id IS NOT NULL AND att_id <> ''",
				...$client_ids
			)
		);
		if ( ! empty( $session_att_ids ) ) {
			$att_ph = implode( ',', array_fill( 0, count( $session_att_ids ), '%s' ) );
			$extra  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$uploads_table}
					WHERE att_id IN ({$att_ph})
					ORDER BY id ASC",
					...$session_att_ids
				),
				ARRAY_A
			);
			$by_id = array();
			foreach ( array_merge( (array) $upload_rows, (array) $extra ) as $row ) {
				$by_id[ (int) $row['id'] ] = $row;
			}
			$upload_rows = array_values( $by_id );
		}

		$result = array(
			'email'             => $email,
			'client_ids'        => $client_ids,
			'uploads_found'     => count( $upload_rows ),
			'restated_zero'     => 0,
			'marked_skipped'    => 0,
			'sessions_unlinked' => 0,
			'crm_cleared'       => 0,
			'errors'            => array(),
			'details'           => array(),
		);

		foreach ( (array) $upload_rows as $row ) {
			$detail = self::apply_void_to_upload_row( $row, true );
			if ( ! empty( $detail['restated'] ) ) {
				++$result['restated_zero'];
			}
			if ( ! empty( $detail['error'] ) ) {
				$result['errors'][] = $detail['error'];
			}
			if ( ! empty( $detail['skipped'] ) ) {
				++$result['marked_skipped'];
			}
			$result['details'][] = $detail;
		}

		$email_hash = Avia_Attribution_Capture::hash_email( $email );
		$unlink_sql = "UPDATE {$sessions_table} SET
			blvd_client_id = NULL,
			client_email_hash = NULL,
			client_phone_hash = NULL,
			updated_at = %s
			WHERE blvd_client_id IN ({$placeholders})";
		$unlink_args = array_merge( array( current_time( 'mysql' ) ), $client_ids );
		$wpdb->query( $wpdb->prepare( $unlink_sql, ...$unlink_args ) );
		$result['sessions_unlinked'] = (int) $wpdb->rows_affected;

		if ( $email_hash ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$sessions_table} SET
						client_email_hash = NULL,
						updated_at = %s
					WHERE client_email_hash = %s",
					current_time( 'mysql' ),
					$email_hash
				)
			);
			$result['sessions_unlinked'] += (int) $wpdb->rows_affected;
		}

		foreach ( $clients as $client ) {
			$cid = $client['id'] ?? '';
			if ( '' === $cid ) {
				continue;
			}
			$clear = Avia_Attribution_Boulevard_Api::clear_client_attribution_fields( $cid );
			if ( is_wp_error( $clear ) ) {
				$result['errors'][] = 'CRM clear ' . $cid . ': ' . $clear->get_error_message();
			} else {
				++$result['crm_cleared'];
			}
		}

		return $result;
	}

	/**
	 * Apply void to one upload row. Keeps Boulevard order value; clears click IDs; optionally restates $0 to Google.
	 *
	 * @param array $row           Upload row.
	 * @param bool  $restate_google Whether to push $0 when previously uploaded.
	 * @return array
	 */
	private static function apply_void_to_upload_row( array $row, $restate_google = true ) {
		$upload_id = (int) ( $row['id'] ?? 0 );
		$detail    = array(
			'upload_id' => $upload_id,
			'restated'  => false,
			'skipped'   => false,
			'error'     => '',
			'was'       => $row['status'] ?? '',
			'value'     => null,
		);
		if ( $upload_id <= 0 ) {
			return $detail;
		}

		$own = self::ledger_revenue_for_upload_id( $upload_id );
		if ( null === $own ) {
			$own = null === $row['conversion_value'] || '' === $row['conversion_value']
				? null
				: (float) $row['conversion_value'];
			// First void mistakenly stored 0 — treat as unknown rather than keep $0 when ledger missing.
			if ( null !== $own && abs( (float) $own ) < 0.009 && self::is_voided_false_attribution( $row['error_message'] ?? '' ) ) {
				$own = null;
			}
		}
		if ( null !== $own ) {
			$own = round( (float) $own, 2 );
		}

		$already_voided = self::is_voided_false_attribution( $row['error_message'] ?? '' );
		if ( $restate_google && ! $already_voided ) {
			$was_sent = ! empty( $row['uploaded_at'] ) || 'uploaded' === ( $row['status'] ?? '' );
			$has_click = ! empty( $row['gclid'] ) || ! empty( $row['gbraid'] ) || ! empty( $row['wbraid'] );
			if ( $was_sent && $has_click ) {
				$re = self::restate_value_on_transaction_id( $upload_id, 0.0 );
				if ( is_wp_error( $re ) ) {
					$detail['error'] = '#' . $upload_id . ' restate $0: ' . $re->get_error_message();
				} else {
					$detail['restated'] = true;
				}
			}
		}

		$update = array(
			'status'        => 'skipped',
			'error_message' => self::VOID_FALSE_NOTE,
			'gclid'         => '',
			'gbraid'        => '',
			'wbraid'        => '',
			'att_id'        => '',
		);
		if ( null !== $own ) {
			$update['conversion_value'] = $own;
			$detail['value']            = $own;
		}

		self::update_upload_row( $upload_id, $update );
		$detail['skipped'] = true;

		// Genuine ads siblings folded into this false primary must re-upload under their own click.
		$released = self::release_merge_siblings_of_primary( $upload_id, true );
		$detail['siblings_released'] = (int) ( $released['released'] ?? 0 );
		$detail['siblings_uploaded'] = (int) ( $released['uploaded'] ?? 0 );

		return $detail;
	}

	/**
	 * Un-merge siblings of a voided/invalid primary and re-queue them for their own Google upload.
	 *
	 * @param int  $primary_id     Voided (or otherwise invalid) primary upload id.
	 * @param bool $attempt_upload Try Data Manager upload when ads gate still passes.
	 * @return array{released:int,uploaded:int,failed:int,skipped:int,ids:array,errors:array}
	 */
	public static function release_merge_siblings_of_primary( $primary_id, $attempt_upload = true ) {
		global $wpdb;

		$primary_id = (int) $primary_id;
		$out        = array(
			'released' => 0,
			'uploaded' => 0,
			'failed'   => 0,
			'skipped'  => 0,
			'ids'      => array(),
			'errors'   => array(),
		);
		if ( $primary_id <= 0 ) {
			return $out;
		}

		$table    = Avia_Attribution_Database::google_ads_uploads_table();
		$siblings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE error_message LIKE %s
				ORDER BY id ASC
				LIMIT 50",
				'%Merged into upload #' . $primary_id . '%'
			),
			ARRAY_A
		);

		foreach ( (array) $siblings as $row ) {
			$sid = (int) ( $row['id'] ?? 0 );
			if ( $sid <= 0 ) {
				continue;
			}

			$has_click = ! empty( $row['gclid'] ) || ! empty( $row['gbraid'] ) || ! empty( $row['wbraid'] );
			$has_value = null !== $row['conversion_value'] && '' !== $row['conversion_value'];
			if ( ! $has_click || ! $has_value ) {
				self::update_upload_row(
					$sid,
					array(
						'status'        => 'skipped',
						'error_message' => sprintf(
							'Skipped: was merged into voided/false upload #%d; no usable click ID or value to re-upload.',
							$primary_id
						),
					)
				);
				++$out['skipped'];
				$out['ids'][] = $sid;
				continue;
			}

			// Clear merge state; allow a fresh upload under this row's own transaction id.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = 'pending',
						error_message = '',
						uploaded_at = NULL
					WHERE id = %d",
					$sid
				)
			);
			++$out['released'];
			$out['ids'][] = $sid;

			if ( ! $attempt_upload ) {
				continue;
			}

			$result = self::upload_row( $sid );
			if ( is_wp_error( $result ) ) {
				++$out['failed'];
				$out['errors'][] = '#' . $sid . ': ' . $result->get_error_message();
			} elseif ( true === $result ) {
				++$out['uploaded'];
			}
		}

		return $out;
	}

	/**
	 * Find voided false-attribution primaries and release any still-merged siblings (e.g. #35 → #38).
	 *
	 * @param int $limit Max voided primaries to scan.
	 * @return array{primaries:int,released:int,uploaded:int,failed:int,skipped:int,errors:array,details:array}
	 */
	public static function repair_orphaned_merges_into_voided_primaries( $limit = 50 ) {
		global $wpdb;

		$limit  = max( 1, min( 200, (int) $limit ) );
		$table  = Avia_Attribution_Database::google_ads_uploads_table();
		$result = array(
			'primaries' => 0,
			'released'  => 0,
			'uploaded'  => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'errors'    => array(),
			'details'   => array(),
		);

		$primaries = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE error_message LIKE %s
				ORDER BY id ASC
				LIMIT %d",
				'%Voided: false ads attribution%',
				$limit
			)
		);

		foreach ( (array) $primaries as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			$has_sibling = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE error_message LIKE %s",
					'%Merged into upload #' . $pid . '%'
				)
			);
			if ( $has_sibling < 1 ) {
				continue;
			}
			++$result['primaries'];
			$batch = self::release_merge_siblings_of_primary( $pid, true );
			$result['released'] += (int) ( $batch['released'] ?? 0 );
			$result['uploaded'] += (int) ( $batch['uploaded'] ?? 0 );
			$result['failed']   += (int) ( $batch['failed'] ?? 0 );
			$result['skipped']  += (int) ( $batch['skipped'] ?? 0 );
			if ( ! empty( $batch['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], $batch['errors'] );
			}
			$result['details'][] = array(
				'primary_id' => $pid,
				'batch'      => $batch,
			);
		}

		return $result;
	}

	/**
	 * Earliest CLIENT_CREATED (or any) ledger timestamp for a Boulevard client.
	 *
	 * @param string $blvd_client_id Client id.
	 * @return string MySQL datetime or empty.
	 */
	private static function earliest_client_created_at( $blvd_client_id ) {
		global $wpdb;

		$ids = Avia_Attribution_Boulevard_Api::blvd_client_id_lookup_values( $blvd_client_id );
		if ( empty( $ids ) ) {
			return '';
		}
		$table        = Avia_Attribution_Database::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );

		$created = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(created_at) FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND event_type = 'CLIENT_CREATED'",
				...$ids
			)
		);
		if ( $created ) {
			return (string) $created;
		}

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(created_at) FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})",
				...$ids
			)
		);
	}

	/**
	 * True when early webhooks show a real website Book Now / write-back link (not match_latest).
	 *
	 * @param string $blvd_client_id Client id.
	 * @return bool
	 */
	private static function client_has_genuine_avia_link( $blvd_client_id ) {
		global $wpdb;

		$ids = Avia_Attribution_Boulevard_Api::blvd_client_id_lookup_values( $blvd_client_id );
		if ( empty( $ids ) ) {
			return false;
		}
		$table        = Avia_Attribution_Database::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$payloads     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload_json FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND event_type IN ('CLIENT_CREATED','APPOINTMENT_CREATED','ORDER_COMPLETED')
				ORDER BY id ASC
				LIMIT 20",
				...$ids
			)
		);

		foreach ( (array) $payloads as $json ) {
			$blob = strtolower( (string) $json );
			if ( false !== strpos( $blob, 'avia_att_id' ) && preg_match( '/avia_att_id["\']?\s*[:=]\s*["\']?[0-9a-f-]{8}/i', (string) $json ) ) {
				return true;
			}
			if ( preg_match( '/avia:[0-9a-f-]{8}/i', (string) $json ) ) {
				return true;
			}
			if ( false !== strpos( $blob, 'referral' ) && false !== strpos( $blob, 'avia:' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Demote legacy status=uploaded rows that have no click ID (cannot be real Ads attributions).
	 * Also demotes "merged into upload #X" siblings of those primaries (e.g. Maria Saldana).
	 *
	 * @param int $limit Max primary rows.
	 * @return array{demoted:int,siblings:int,ids:array}
	 */
	public static function demote_uploaded_without_click_ids( $limit = 100 ) {
		global $wpdb;

		$table  = Avia_Attribution_Database::google_ads_uploads_table();
		$limit  = max( 1, min( 200, (int) $limit ) );
		$note   = 'Skipped: uploaded row had no Google click ID (not a valid ads attribution).';
		$result = array(
			'demoted'  => 0,
			'siblings' => 0,
			'ids'      => array(),
		);

		$primaries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE status = 'uploaded'
					AND (gclid IS NULL OR gclid = '')
					AND (gbraid IS NULL OR gbraid = '')
					AND (wbraid IS NULL OR wbraid = '')
					AND (error_message IS NULL OR error_message NOT LIKE %s)
				ORDER BY id ASC
				LIMIT %d",
				'%Voided: false ads%',
				$limit
			),
			ARRAY_A
		);

		foreach ( (array) $primaries as $row ) {
			$pid = (int) $row['id'];
			if ( $pid <= 0 ) {
				continue;
			}
			self::update_upload_row(
				$pid,
				array(
					'status'        => 'skipped',
					'error_message' => $note,
				)
			);
			++$result['demoted'];
			$result['ids'][] = $pid;

			$siblings = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					WHERE error_message LIKE %s
					ORDER BY id ASC
					LIMIT 50",
					'%Merged into upload #' . $pid . '%'
				)
			);
			foreach ( (array) $siblings as $sid ) {
				$sid = (int) $sid;
				if ( $sid <= 0 ) {
					continue;
				}
				self::update_upload_row(
					$sid,
					array(
						'status'        => 'skipped',
						'error_message' => $note . ' (was merged into #' . $pid . ')',
					)
				);
				++$result['siblings'];
			}
		}

		return $result;
	}
}
