<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared rules: when a session/click counts as genuine ads attribution.
 */
class Avia_Attribution_Ads_Integrity {

	const LOOKBACK_DAYS = 90;

	/** Minimum length for a plausible Google click ID (rejects values like 792398333). */
	const MIN_CLICK_ID_LENGTH = 20;

	/** Hard upper bound to reject garbage payloads. */
	const MAX_CLICK_ID_LENGTH = 200;

	/**
	 * Whether a click ID looks like a real Google gclid / gbraid / wbraid.
	 *
	 * Rejects empty, too-short, digits-only, and non-URL-safe values.
	 *
	 * @param string $value Raw click ID.
	 * @param string $type  gclid|gbraid|wbraid (reserved for future type-specific rules).
	 * @return bool
	 */
	public static function is_valid_click_id( $value, $type = 'gclid' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}

		$len = strlen( $value );
		if ( $len < self::MIN_CLICK_ID_LENGTH || $len > self::MAX_CLICK_ID_LENGTH ) {
			return false;
		}

		// Google click IDs are URL-safe alphanumeric (often with _ / -). Digits-only is never valid.
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		if ( ctype_digit( $value ) ) {
			return false;
		}
		if ( ! preg_match( '/[A-Za-z]/', $value ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Keep only format-valid click IDs on a session/cookie payload.
	 *
	 * @param array $session Session or cookie.
	 * @return array
	 */
	public static function sanitize_click_id_fields( array $session ) {
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $key ) {
			if ( empty( $session[ $key ] ) ) {
				continue;
			}
			if ( ! self::is_valid_click_id( $session[ $key ], $key ) ) {
				unset( $session[ $key ] );
			} else {
				$session[ $key ] = trim( (string) $session[ $key ] );
			}
		}
		return $session;
	}

	/**
	 * @param array $session Session row or cookie payload.
	 * @return bool True if any valid Google click identifier is present.
	 */
	public static function session_has_ad_click( array $session ) {
		$session = self::sanitize_click_id_fields( $session );
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $key ) {
			if ( ! empty( $session[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Timestamp when the ad click was captured (cookie click_at or session created_at).
	 *
	 * @param array $session Session/cookie.
	 * @return int Unix timestamp or 0.
	 */
	public static function click_captured_unix( array $session ) {
		if ( ! empty( $session['click_at'] ) ) {
			$ts = strtotime( (string) $session['click_at'] );
			return $ts ? (int) $ts : 0;
		}
		if ( ! empty( $session['created_at'] ) ) {
			$ts = strtotime( (string) $session['created_at'] );
			return $ts ? (int) $ts : 0;
		}
		return 0;
	}

	/**
	 * @param array  $session             Session/cookie.
	 * @param string $conversion_datetime MySQL/ISO datetime or empty (use now).
	 * @return bool
	 */
	public static function is_click_fresh( array $session, $conversion_datetime = '' ) {
		$click_ts = self::click_captured_unix( $session );
		if ( $click_ts <= 0 ) {
			return false;
		}

		$ref_ts = time();
		if ( '' !== trim( (string) $conversion_datetime ) ) {
			$parsed = strtotime( (string) $conversion_datetime );
			if ( $parsed ) {
				$ref_ts = (int) $parsed;
			}
		}

		return ( $ref_ts - $click_ts ) <= ( DAY_IN_SECONDS * self::LOOKBACK_DAYS )
			&& ( $click_ts <= ( $ref_ts + HOUR_IN_SECONDS ) );
	}

	/**
	 * Genuine ads attribution: has click ID + known capture time within lookback of conversion.
	 *
	 * @param array  $session             Session/cookie.
	 * @param string $conversion_datetime Optional conversion time.
	 * @return bool
	 */
	public static function is_ads_attributed( array $session, $conversion_datetime = '' ) {
		if ( ! self::session_has_ad_click( $session ) ) {
			return false;
		}
		return self::is_click_fresh( $session, $conversion_datetime );
	}

	/**
	 * @param array  $session             Session/cookie.
	 * @param string $conversion_datetime Optional.
	 * @return array{ok:bool,reason:string,gclid:string,gbraid:string,wbraid:string}
	 */
	public static function evaluate_for_upload( array $session, $conversion_datetime = '' ) {
		$raw_has_click = false;
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $key ) {
			if ( ! empty( $session[ $key ] ) ) {
				$raw_has_click = true;
				break;
			}
		}

		$session = self::sanitize_click_id_fields( $session );
		$out     = array(
			'ok'     => false,
			'reason' => '',
			'gclid'  => isset( $session['gclid'] ) ? (string) $session['gclid'] : '',
			'gbraid' => isset( $session['gbraid'] ) ? (string) $session['gbraid'] : '',
			'wbraid' => isset( $session['wbraid'] ) ? (string) $session['wbraid'] : '',
		);

		if ( ! self::session_has_ad_click( $session ) ) {
			$out['reason'] = $raw_has_click ? 'invalid_click_id' : 'no_ad_click';
			$out['gclid']  = '';
			$out['gbraid'] = '';
			$out['wbraid'] = '';
			return $out;
		}

		// utm_campaign is optional: Google auto-tagging often sends gclid without UTMs.
		// Organic / no-click sessions already fail session_has_ad_click above.

		if ( ! self::is_click_fresh( $session, $conversion_datetime ) ) {
			$out['reason'] = 'stale_click';
			$out['gclid']  = '';
			$out['gbraid'] = '';
			$out['wbraid'] = '';
			return $out;
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Click IDs safe to show in plugin UI / inject into Boulevard bridge.
	 *
	 * @param array  $session             Session/cookie.
	 * @param string $conversion_datetime Optional.
	 * @return array{gclid:string,gbraid:string,wbraid:string,is_ads_attributed:bool,reason:string}
	 */
	public static function effective_click_ids( array $session, $conversion_datetime = '' ) {
		$eval = self::evaluate_for_upload( $session, $conversion_datetime );
		return array(
			'gclid'              => $eval['ok'] ? $eval['gclid'] : '',
			'gbraid'             => $eval['ok'] ? $eval['gbraid'] : '',
			'wbraid'             => $eval['ok'] ? $eval['wbraid'] : '',
			'is_ads_attributed'  => $eval['ok'],
			'reason'             => $eval['reason'],
		);
	}

	/**
	 * Strip stale/unknown click IDs from a cookie session payload.
	 *
	 * @param array $session Cookie session.
	 * @return array
	 */
	public static function sanitize_cookie_session( array $session ) {
		if ( empty( $session ) ) {
			return $session;
		}

		$session = self::sanitize_click_id_fields( $session );

		if ( ! self::session_has_ad_click( $session ) ) {
			unset( $session['gclid'], $session['gbraid'], $session['wbraid'], $session['click_at'] );
			return $session;
		}

		// Require explicit click_at for cookie freshness; missing => strip (stops legacy false inject).
		if ( empty( $session['click_at'] ) || ! self::is_click_fresh( $session ) ) {
			unset( $session['gclid'], $session['gbraid'], $session['wbraid'], $session['click_at'] );
		}

		return $session;
	}

	/**
	 * SQL cutoff for session matching lookback.
	 *
	 * @return string MySQL datetime (site local via current_time semantics using gmdate offset-aware helper).
	 */
	public static function match_cutoff_mysql() {
		return gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * self::LOOKBACK_DAYS ) );
	}

	/**
	 * Human-readable skip reason for upload rows.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	public static function skip_message( $code ) {
		$map = array(
			'no_ad_click'          => 'No gclid, gbraid, or wbraid on matched session.',
			'invalid_click_id'     => 'Click ID is not a valid Google gclid/gbraid/wbraid (too short, digits-only, or malformed).',
			'missing_utm_campaign' => 'utm_campaign missing on session — not uploaded to Google Ads.',
			'stale_click'          => 'Click ID older than ' . self::LOOKBACK_DAYS . '-day ads lookback (not ads-attributed).',
		);
		$code = (string) $code;
		return isset( $map[ $code ] ) ? $map[ $code ] : ( $code ? $code : 'Not ads-attributed.' );
	}

	/**
	 * Mark pending/failed upload rows as skipped when they fail the ads gate. Never touches uploaded.
	 *
	 * @param int $limit Max rows.
	 * @return int Rows updated.
	 */
	public static function skip_non_ads_pending( $limit = 200 ) {
		global $wpdb;

		$uploads  = Avia_Attribution_Database::google_ads_uploads_table();
		$sessions = Avia_Attribution_Database::sessions_table();
		$limit    = max( 1, min( 500, (int) $limit ) );

		$rows = $wpdb->get_results(
			"SELECT u.id, u.att_id, u.gclid, u.gbraid, u.wbraid, u.conversion_datetime, u.status,
				s.gclid AS s_gclid, s.gbraid AS s_gbraid, s.wbraid AS s_wbraid, s.created_at AS s_created_at,
				s.utm_campaign AS s_utm_campaign
			FROM {$uploads} u
			LEFT JOIN {$sessions} s ON s.att_id = u.att_id
			WHERE u.status IN ('pending', 'failed')
			ORDER BY u.id DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		$updated = 0;
		foreach ( (array) $rows as $row ) {
			$session = array(
				'gclid'        => $row['s_gclid'] ?: ( $row['gclid'] ?? '' ),
				'gbraid'       => $row['s_gbraid'] ?: ( $row['gbraid'] ?? '' ),
				'wbraid'       => $row['s_wbraid'] ?: ( $row['wbraid'] ?? '' ),
				'created_at'   => $row['s_created_at'] ?? '',
				'utm_campaign' => $row['s_utm_campaign'] ?? '',
			);
			$eval = self::evaluate_for_upload( $session, $row['conversion_datetime'] ?? '' );
			if ( $eval['ok'] ) {
				continue;
			}
			$wpdb->update(
				$uploads,
				array(
					'status'        => 'skipped',
					'error_message' => self::skip_message( $eval['reason'] ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$updated++;
		}

		return $updated;
	}
}
