<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Capture {

	const COOKIE_NAME         = 'avia_att';
	const COOKIE_DAYS         = 90;
	const QUERY_KEYS          = array( 'gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
	const BOOK_INTENT_OPTION     = 'avia_attribution_book_intents';
	const PENDING_CLIENTS_OPTION = 'avia_attribution_pending_blvd_clients';
	const BOOK_INTENT_TTL        = 7200; // 2 hours — queue retention.
	const BOOK_INTENT_CLAIM_WINDOW = 900; // 15 min — claim/pair only (blocks abandoned-ads → organic false match).
	const PAIR_COHORT_WINDOW     = 900; // 15 min — ignore older leftover intents/clients from prior tests.

	public static function init_rest() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function init_frontend() {
		add_action( 'init', array( __CLASS__, 'maybe_capture_from_request' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	public static function enqueue_scripts() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'avia-attribution-capture',
			AVIA_ATTRIBUTION_URL . 'assets/capture.js',
			array(),
			AVIA_ATTRIBUTION_VERSION,
			true
		);

		wp_enqueue_script(
			'avia-attribution-blvd-bridge',
			AVIA_ATTRIBUTION_URL . 'assets/blvd-bridge.js',
			array( 'avia-attribution-capture' ),
			AVIA_ATTRIBUTION_VERSION,
			true
		);

		wp_localize_script(
			'avia-attribution-capture',
			'aviaAttribution',
			array(
				'cookieName'         => self::COOKIE_NAME,
				'cookieDays'         => self::COOKIE_DAYS,
				'clickLookbackDays'  => Avia_Attribution_Ads_Integrity::LOOKBACK_DAYS,
				'restUrl'            => rest_url( 'avia/v1/capture' ),
				'bookIntentUrl'      => rest_url( 'avia/v1/book-intent' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function register_routes() {
		register_rest_route(
			'avia/v1',
			'/capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_capture' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'avia/v1',
			'/book-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_book_intent' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function maybe_capture_from_request() {
		if ( ! Avia_Attribution_Settings::is_capture_enabled() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$incoming = self::extract_click_params( $_GET );
		if ( empty( $incoming ) ) {
			return;
		}

		$existing = self::read_cookie();
		$session  = self::merge_session( $existing, $incoming, self::current_landing_page(), self::current_referrer() );
		self::write_cookie( $session );
		self::persist_session( $session );
	}

	public static function rest_capture( WP_REST_Request $request ) {
		if ( ! Avia_Attribution_Settings::is_capture_enabled() ) {
			return new WP_REST_Response( array( 'stored' => false, 'enabled' => false ), 200 );
		}
		if ( ! self::verify_public_rest_request( $request, 'capture' ) ) {
			return new WP_REST_Response( array( 'stored' => false, 'error' => 'forbidden' ), 403 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$incoming = self::extract_click_params( $params );
		$existing = self::read_cookie();
		$session  = self::merge_session(
			$existing,
			$incoming,
			isset( $params['landing_page'] ) ? sanitize_text_field( wp_unslash( $params['landing_page'] ) ) : self::current_landing_page(),
			isset( $params['referrer'] ) ? esc_url_raw( wp_unslash( $params['referrer'] ) ) : self::current_referrer()
		);

		if ( ! empty( $params['ga4_client_id'] ) ) {
			$session['ga4_client_id'] = sanitize_text_field( wp_unslash( $params['ga4_client_id'] ) );
		}
		if ( ! empty( $params['ga4_session_id'] ) ) {
			$session['ga4_session_id'] = sanitize_text_field( wp_unslash( $params['ga4_session_id'] ) );
		}

		self::write_cookie( $session );
		self::persist_session( $session );

		return new WP_REST_Response(
			array(
				'att_id' => $session['att_id'],
				'stored' => true,
			),
			200
		);
	}

	public static function merge_session( $existing, $incoming, $landing_page, $referrer ) {
		$existing = is_array( $existing ) ? $existing : array();
		$existing = Avia_Attribution_Ads_Integrity::sanitize_cookie_session( $existing );
		$incoming = is_array( $incoming ) ? Avia_Attribution_Ads_Integrity::sanitize_click_id_fields( $incoming ) : array();

		$has_ad_click  = ! empty( $incoming['gclid'] ) || ! empty( $incoming['gbraid'] ) || ! empty( $incoming['wbraid'] );
		$has_new_click = $has_ad_click
			|| ! empty( $incoming['utm_source'] ) || ! empty( $incoming['utm_medium'] ) || ! empty( $incoming['utm_campaign'] );

		if (
			! empty( $existing['att_id'] )
			&& ! empty( $incoming['gclid'] )
			&& ! empty( $existing['gclid'] )
			&& $incoming['gclid'] === $existing['gclid']
		) {
			return Avia_Attribution_Ads_Integrity::sanitize_cookie_session(
				array_merge(
					$existing,
					$incoming,
					array(
						'landing_page' => $landing_page,
						'referrer'     => $referrer,
						'click_at'     => ! empty( $existing['click_at'] ) ? $existing['click_at'] : gmdate( 'c' ),
					)
				)
			);
		}

		if ( $has_new_click || empty( $existing['att_id'] ) ) {
			$session = array_merge(
				$incoming,
				array(
					'att_id'         => wp_generate_uuid4(),
					'landing_page'   => $landing_page,
					'referrer'       => $referrer,
					'ga4_client_id'  => $existing['ga4_client_id'] ?? null,
					'ga4_session_id' => $existing['ga4_session_id'] ?? null,
				)
			);
			if ( $has_ad_click ) {
				$session['click_at'] = gmdate( 'c' );
			} else {
				unset( $session['gclid'], $session['gbraid'], $session['wbraid'], $session['click_at'] );
			}
			return Avia_Attribution_Ads_Integrity::sanitize_cookie_session( $session );
		}

		return Avia_Attribution_Ads_Integrity::sanitize_cookie_session( array_merge( $existing, $incoming ) );
	}

	/**
	 * Ensure a session row exists with click + campaign from Boulevard referral_source proof.
	 * Fixes: cookie/bridge OK but REST persist missed → webhook ads gate wiped att_id (Dummy PROS).
	 *
	 * @param string $att_id   Session UUID.
	 * @param array  $parts    Keys: gclid, gbraid, wbraid, utm_campaign, utm_source, utm_medium.
	 * @return bool True when session now passes ads upload gate.
	 */
	public static function hydrate_session_from_referral( $att_id, array $parts ) {
		$att_id = sanitize_text_field( (string) $att_id );
		if ( '' === $att_id ) {
			return false;
		}

		global $wpdb;
		$table   = Avia_Attribution_Database::sessions_table();
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE att_id = %s LIMIT 1", $att_id ),
			ARRAY_A
		);

		$session = is_array( $existing ) ? $existing : array( 'att_id' => $att_id );
		foreach ( array( 'gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			if ( ! empty( $parts[ $key ] ) && empty( $session[ $key ] ) ) {
				$session[ $key ] = sanitize_text_field( (string) $parts[ $key ] );
			}
		}
		$session = Avia_Attribution_Ads_Integrity::sanitize_click_id_fields( $session );
		if ( empty( $session['click_at'] ) && ( ! empty( $session['gclid'] ) || ! empty( $session['gbraid'] ) || ! empty( $session['wbraid'] ) ) ) {
			$session['click_at'] = gmdate( 'c' );
		}
		if ( empty( $session['utm_source'] ) && ! empty( $session['gclid'] ) ) {
			$session['utm_source'] = 'google';
		}
		if ( empty( $session['utm_medium'] ) && ! empty( $session['gclid'] ) ) {
			$session['utm_medium'] = 'cpc';
		}

		self::persist_session( $session );
		$eval = Avia_Attribution_Ads_Integrity::evaluate_for_upload( $session );
		return ! empty( $eval['ok'] );
	}

	/**
	 * Book Now beacon: remember a fresh ads session until Boulevard CLIENT_CREATED arrives.
	 * Overlay urlParams often never echo avia_att_id / referral_source back into webhooks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_book_intent( WP_REST_Request $request ) {
		if ( ! Avia_Attribution_Settings::is_capture_enabled() ) {
			return new WP_REST_Response( array( 'recorded' => false, 'enabled' => false ), 200 );
		}
		if ( ! self::verify_public_rest_request( $request, 'book_intent' ) ) {
			return new WP_REST_Response( array( 'recorded' => false, 'error' => 'forbidden' ), 403 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$att_id = sanitize_text_field( (string) ( $params['att_id'] ?? '' ) );
		$ok     = self::record_book_intent( $att_id );

		return new WP_REST_Response( array( 'recorded' => $ok ), 200 );
	}

	/**
	 * Same-origin nonce + light per-IP rate limit for public capture endpoints.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $bucket  Rate bucket key.
	 * @return bool
	 */
	private static function verify_public_rest_request( WP_REST_Request $request, $bucket ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'avia_rl_' . md5( $bucket . '|' . $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 90 ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * @param string $att_id Session UUID from cookie / bridge.
	 * @return bool
	 */
	public static function record_book_intent( $att_id ) {
		$att_id = sanitize_text_field( (string) $att_id );
		if ( '' === $att_id || ! Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $att_id ) ) {
			return false;
		}

		$now     = time();
		$intents = self::read_book_intents();
		$next    = array();
		foreach ( $intents as $row ) {
			if ( empty( $row['att_id'] ) || empty( $row['ts'] ) ) {
				continue;
			}
			if ( (string) $row['att_id'] === $att_id ) {
				continue;
			}
			if ( ( $now - (int) $row['ts'] ) > self::BOOK_INTENT_TTL ) {
				continue;
			}
			$next[] = array(
				'att_id' => (string) $row['att_id'],
				'ts'     => (int) $row['ts'],
			);
		}
		array_unshift(
			$next,
			array(
				'att_id' => $att_id,
				'ts'     => $now,
			)
		);
		$next = array_slice( $next, 0, 40 );
		update_option( self::BOOK_INTENT_OPTION, $next, false );

		return true;
	}

	/**
	 * Claim a fresh-ads Book Now intent (consumed once).
	 *
	 * Cross-client safety:
	 * - preferred_att_id (externalId / referral / custom field proof): consume that beacon even when
	 *   many intents are pending, and even if the beacon already expired out of the queue.
	 * - else identity (email/phone) among pending intents whose sessions already store that hash.
	 * - else exactly one pending intent — never "newest of many" (wrong gclid on wrong client).
	 *
	 * @param string|null $preferred_att_id Optional att_id already resolved from webhook proof.
	 * @param string|null $email            Boulevard client email from webhook/API.
	 * @param string|null $phone            Boulevard client phone from webhook/API.
	 * @return string|null att_id
	 */
	public static function claim_book_intent( $preferred_att_id = null, $email = null, $phone = null ) {
		$now              = time();
		$preferred_att_id = sanitize_text_field( (string) $preferred_att_id );
		$intents          = self::read_book_intents();
		$valid            = array();

		foreach ( $intents as $row ) {
			if ( empty( $row['att_id'] ) || empty( $row['ts'] ) ) {
				continue;
			}
			$att_id = (string) $row['att_id'];
			$age    = $now - (int) $row['ts'];
			if ( $age > self::BOOK_INTENT_TTL ) {
				continue;
			}
			if ( ! Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $att_id ) ) {
				continue;
			}
			$valid[] = array(
				'att_id' => $att_id,
				'ts'     => (int) $row['ts'],
				'age'    => $age,
			);
		}

		$claimed = null;
		if ( '' !== $preferred_att_id ) {
			// Proof already resolved — claim that session even if not still in the TTL queue.
			if ( Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $preferred_att_id ) ) {
				$claimed = $preferred_att_id;
			}
		} else {
			$by_identity = self::match_pending_intent_by_identity( $valid, $email, $phone );
			if ( $by_identity ) {
				$claimed = $by_identity;
			} elseif ( 1 === count( $valid ) && (int) $valid[0]['age'] <= self::BOOK_INTENT_CLAIM_WINDOW ) {
				// Exactly one fresh intent — do not claim abandoned Book Now after claim window
				// (would stamp that gclid onto a later organic/staff client).
				$claimed = $valid[0]['att_id'];
			}
		}

		$kept = array();
		foreach ( $valid as $row ) {
			if ( null !== $claimed && $row['att_id'] === $claimed ) {
				continue;
			}
			$kept[] = $row;
		}
		update_option( self::BOOK_INTENT_OPTION, $kept, false );

		return $claimed;
	}

	/**
	 * Whether any ads Book Now intent is still within the claim window.
	 *
	 * @return bool
	 */
	public static function has_claimable_book_intents() {
		$now     = time();
		$intents = self::read_book_intents();
		foreach ( $intents as $row ) {
			if ( empty( $row['att_id'] ) || empty( $row['ts'] ) ) {
				continue;
			}
			$age = $now - (int) $row['ts'];
			if ( $age < 0 || $age > self::BOOK_INTENT_CLAIM_WINDOW ) {
				continue;
			}
			if ( Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( (string) $row['att_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remember an unmatched Boulevard client so concurrent Book Now intents can be paired
	 * by Boulevard createdAt order (widget overlay cannot stamp CreateCart externalId/referral).
	 *
	 * @param string      $client_id  Client URN.
	 * @param string|null $email      Email.
	 * @param string|null $phone      Phone.
	 * @param string|null $created_at Boulevard createdAt (any parseable datetime).
	 * @return void
	 */
	public static function record_pending_blvd_client( $client_id, $email = null, $phone = null, $created_at = null ) {
		$client_id = sanitize_text_field( (string) $client_id );
		if ( '' === $client_id ) {
			return;
		}

		$created_ts = time();
		if ( ! empty( $created_at ) ) {
			$parsed = strtotime( (string) $created_at );
			if ( $parsed ) {
				$created_ts = (int) $parsed;
			}
		}

		$now  = time();
		$rows = self::read_pending_blvd_clients();
		$next = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['client_id'] ) || empty( $row['created_ts'] ) ) {
				continue;
			}
			if ( (string) $row['client_id'] === $client_id ) {
				continue;
			}
			if ( ( $now - (int) $row['created_ts'] ) > self::BOOK_INTENT_TTL ) {
				continue;
			}
			$next[] = array(
				'client_id'  => (string) $row['client_id'],
				'created_ts' => (int) $row['created_ts'],
				'email'      => (string) ( $row['email'] ?? '' ),
				'phone'      => (string) ( $row['phone'] ?? '' ),
			);
		}
		$next[] = array(
			'client_id'  => $client_id,
			'created_ts' => $created_ts,
			'email'      => sanitize_text_field( (string) $email ),
			'phone'      => sanitize_text_field( (string) $phone ),
		);
		$next = array_slice( $next, -40 );
		update_option( self::PENDING_CLIENTS_OPTION, $next, false );
	}

	/**
	 * Pair pending Book Now intents with unmatched Boulevard clients by time order.
	 *
	 * Overlay cannot set CreateCartInput.externalId / referralSource — only iframe urlParams.
	 * When 2+ people Book Now, claim_book_intent refuses (no wrong gclid). This pairs
	 * intent.ts order ↔ client.createdAt order within the Book Now TTL window.
	 *
	 * @param string|null $for_client_id If set, return att_id paired to this client after pairing.
	 * @return string|null
	 */
	public static function pair_book_intents_with_pending_clients( $for_client_id = null ) {
		$for_client_id = sanitize_text_field( (string) $for_client_id );
		$now           = time();
		$intents       = self::read_book_intents();
		$clients       = self::read_pending_blvd_clients();

		$valid_intents = array();
		foreach ( $intents as $row ) {
			if ( empty( $row['att_id'] ) || empty( $row['ts'] ) ) {
				continue;
			}
			$att_id = (string) $row['att_id'];
			$ts     = (int) $row['ts'];
			if ( ( $now - $ts ) > self::BOOK_INTENT_TTL ) {
				continue;
			}
			if ( ! Avia_Attribution_Boulevard_Api::session_passes_ads_upload_gate( $att_id ) ) {
				continue;
			}
			// Skip intents already linked to a Boulevard client.
			if ( self::session_already_linked( $att_id ) ) {
				continue;
			}
			$valid_intents[] = array(
				'att_id' => $att_id,
				'ts'     => $ts,
			);
		}

		$valid_clients = array();
		foreach ( $clients as $row ) {
			if ( empty( $row['client_id'] ) || empty( $row['created_ts'] ) ) {
				continue;
			}
			$cid = (string) $row['client_id'];
			$cts = (int) $row['created_ts'];
			if ( ( $now - $cts ) > self::BOOK_INTENT_TTL ) {
				continue;
			}
			// Already linked in sessions — drop from pending.
			if ( self::client_already_linked( $cid ) ) {
				continue;
			}
			$valid_clients[] = array(
				'client_id'  => $cid,
				'created_ts' => $cts,
				'email'      => (string) ( $row['email'] ?? '' ),
				'phone'      => (string) ( $row['phone'] ?? '' ),
			);
		}

		// Drop leftovers from earlier failed tests (same 2h TTL queue) so 2 fresh clients
		// are not blocked by 4–6 stale intents/clients (unequal counts → pair refused → blank Click ID).
		$cohort_cut = $now - self::PAIR_COHORT_WINDOW;
		$valid_intents = array_values(
			array_filter(
				$valid_intents,
				static function ( $row ) use ( $cohort_cut ) {
					return (int) $row['ts'] >= $cohort_cut;
				}
			)
		);
		$valid_clients = array_values(
			array_filter(
				$valid_clients,
				static function ( $row ) use ( $cohort_cut ) {
					return (int) $row['created_ts'] >= $cohort_cut;
				}
			)
		);

		usort(
			$valid_intents,
			static function ( $a, $b ) {
				return $a['ts'] <=> $b['ts'];
			}
		);
		usort(
			$valid_clients,
			static function ( $a, $b ) {
				return $a['created_ts'] <=> $b['created_ts'];
			}
		);

		$pairs           = array(); // client_id => att_id
		$used_intent_ids = array();

		// 1) Unique window match: exactly one intent whose Book Now ts precedes client create within TTL.
		foreach ( $valid_clients as $client ) {
			$candidates = array();
			foreach ( $valid_intents as $intent ) {
				if ( isset( $used_intent_ids[ $intent['att_id'] ] ) ) {
					continue;
				}
				$gap = $client['created_ts'] - $intent['ts'];
				if ( $intent['ts'] <= $client['created_ts'] && $gap <= self::BOOK_INTENT_CLAIM_WINDOW ) {
					$candidates[] = $intent;
				}
			}
			if ( 1 === count( $candidates ) ) {
				$pairs[ $client['client_id'] ]               = $candidates[0]['att_id'];
				$used_intent_ids[ $candidates[0]['att_id'] ] = true;
			}
		}

		// 2) Leftover clients: zip only when each client falls within claim window of its intent.
		//    Extra stale intents → use N most recent. Miss preferred over wrong gclid on organic.
		$left_clients = array();
		foreach ( $valid_clients as $client ) {
			if ( ! isset( $pairs[ $client['client_id'] ] ) ) {
				$left_clients[] = $client;
			}
		}
		$left_intents = array();
		foreach ( $valid_intents as $intent ) {
			if ( ! isset( $used_intent_ids[ $intent['att_id'] ] ) ) {
				$left_intents[] = $intent;
			}
		}
		$n_left = count( $left_clients );
		if ( $n_left > 0 && count( $left_intents ) >= $n_left ) {
			if ( count( $left_intents ) > $n_left ) {
				$left_intents = array_slice( $left_intents, -$n_left );
			}
			$zip_ok = true;
			foreach ( $left_clients as $i => $client ) {
				$gap = $client['created_ts'] - $left_intents[ $i ]['ts'];
				if ( $gap < 0 || $gap > self::BOOK_INTENT_CLAIM_WINDOW ) {
					$zip_ok = false;
					break;
				}
			}
			if ( $zip_ok ) {
				foreach ( $left_clients as $i => $client ) {
					$pairs[ $client['client_id'] ]                    = $left_intents[ $i ]['att_id'];
					$used_intent_ids[ $left_intents[ $i ]['att_id'] ] = true;
				}
			}
		}

		if ( empty( $pairs ) ) {
			update_option( self::PENDING_CLIENTS_OPTION, $valid_clients, false );
			return null;
		}

		// Link every paired session now — earlier CLIENT_CREATED may have stored unmatched.
		$clients_by_id = array();
		foreach ( $valid_clients as $client ) {
			$clients_by_id[ $client['client_id'] ] = $client;
		}
		foreach ( $pairs as $cid => $att ) {
			$row = $clients_by_id[ $cid ] ?? array();
			self::link_session_blvd_client(
				$att,
				$cid,
				$row['email'] ?? '',
				$row['phone'] ?? ''
			);
		}

		// Consume paired intents from the book-intent queue.
		$kept_intents = array();
		foreach ( $valid_intents as $intent ) {
			if ( in_array( $intent['att_id'], $pairs, true ) ) {
				continue;
			}
			$kept_intents[] = $intent;
		}
		update_option( self::BOOK_INTENT_OPTION, $kept_intents, false );

		$kept_clients = array();
		foreach ( $valid_clients as $client ) {
			if ( isset( $pairs[ $client['client_id'] ] ) ) {
				continue;
			}
			$kept_clients[] = $client;
		}
		update_option( self::PENDING_CLIENTS_OPTION, $kept_clients, false );

		if ( '' !== $for_client_id && ! empty( $pairs[ $for_client_id ] ) ) {
			return $pairs[ $for_client_id ];
		}

		return null;
	}

	/**
	 * @param string $att_id    Session UUID.
	 * @param string $client_id Client URN.
	 * @param string $email     Email.
	 * @param string $phone     Phone.
	 * @return void
	 */
	private static function link_session_blvd_client( $att_id, $client_id, $email = '', $phone = '' ) {
		global $wpdb;
		$table = Avia_Attribution_Database::sessions_table();
		$data  = array(
			'blvd_client_id' => $client_id,
			'updated_at'     => current_time( 'mysql' ),
		);
		$email_hash = self::hash_email( $email );
		$phone_hash = self::hash_phone( $phone );
		if ( $email_hash ) {
			$data['client_email_hash'] = $email_hash;
		}
		if ( $phone_hash ) {
			$data['client_phone_hash'] = $phone_hash;
		}
		$wpdb->update( $table, $data, array( 'att_id' => $att_id ) );
	}

	/**
	 * @param string $att_id Session UUID.
	 * @return bool
	 */
	private static function session_already_linked( $att_id ) {
		global $wpdb;
		$table = Avia_Attribution_Database::sessions_table();
		$val   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blvd_client_id FROM {$table} WHERE att_id = %s LIMIT 1",
				$att_id
			)
		);
		return ! empty( $val );
	}

	/**
	 * @param string $client_id Client URN.
	 * @return bool
	 */
	private static function client_already_linked( $client_id ) {
		$ids = Avia_Attribution_Boulevard_Api::blvd_client_id_lookup_values( $client_id );
		if ( empty( $ids ) ) {
			return false;
		}
		global $wpdb;
		$table        = Avia_Attribution_Database::sessions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$val          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT att_id FROM {$table}
				WHERE blvd_client_id IN ({$placeholders})
					AND blvd_client_id IS NOT NULL
					AND blvd_client_id != ''
				LIMIT 1",
				...$ids
			)
		);
		return ! empty( $val );
	}

	/**
	 * @return array<int,array{client_id:string,created_ts:int,email?:string,phone?:string}>
	 */
	private static function read_pending_blvd_clients() {
		$raw = get_option( self::PENDING_CLIENTS_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Match webhook identity only against currently pending Book Now intents (not all sessions).
	 *
	 * @param array<int,array{att_id:string,ts:int}> $valid Pending intents.
	 * @param string|null                            $email Client email.
	 * @param string|null                            $phone Client phone.
	 * @return string|null
	 */
	private static function match_pending_intent_by_identity( array $valid, $email = null, $phone = null ) {
		if ( empty( $valid ) ) {
			return null;
		}

		$email_hash = self::hash_email( $email );
		$phone_hash = self::hash_phone( $phone );
		if ( ! $email_hash && ! $phone_hash ) {
			return null;
		}

		$att_ids = array();
		foreach ( $valid as $row ) {
			if ( ! empty( $row['att_id'] ) ) {
				$att_ids[] = (string) $row['att_id'];
			}
		}
		$att_ids = array_values( array_unique( $att_ids ) );
		if ( empty( $att_ids ) ) {
			return null;
		}

		global $wpdb;
		$table        = Avia_Attribution_Database::sessions_table();
		$placeholders = implode( ',', array_fill( 0, count( $att_ids ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT att_id, client_email_hash, client_phone_hash FROM {$table}
				WHERE att_id IN ({$placeholders})",
				...$att_ids
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return null;
		}

		$matches = array();
		foreach ( $rows as $row ) {
			$att = (string) ( $row['att_id'] ?? '' );
			if ( '' === $att ) {
				continue;
			}
			if ( $email_hash && ! empty( $row['client_email_hash'] ) && hash_equals( (string) $row['client_email_hash'], $email_hash ) ) {
				$matches[ $att ] = true;
			}
			if ( $phone_hash && ! empty( $row['client_phone_hash'] ) && hash_equals( (string) $row['client_phone_hash'], $phone_hash ) ) {
				$matches[ $att ] = true;
			}
		}

		$matched_ids = array_keys( $matches );
		return 1 === count( $matched_ids ) ? $matched_ids[0] : null;
	}

	/**
	 * @return array<int,array{att_id:string,ts:int}>
	 */
	private static function read_book_intents() {
		$raw = get_option( self::BOOK_INTENT_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	public static function persist_session( array $session ) {
		if ( empty( $session['att_id'] ) ) {
			return;
		}

		global $wpdb;
		$table = Avia_Attribution_Database::sessions_table();
		$now   = current_time( 'mysql' );

		$click_at = null;
		if ( ! empty( $session['click_at'] ) ) {
			$ts = strtotime( (string) $session['click_at'] );
			if ( $ts ) {
				$click_at = wp_date( 'Y-m-d H:i:s', $ts );
			}
		}

		$data = array(
			'att_id'          => $session['att_id'],
			'gclid'           => $session['gclid'] ?? null,
			'gbraid'          => $session['gbraid'] ?? null,
			'wbraid'          => $session['wbraid'] ?? null,
			'click_at'        => $click_at,
			'utm_source'      => $session['utm_source'] ?? null,
			'utm_medium'      => $session['utm_medium'] ?? null,
			'utm_campaign'    => $session['utm_campaign'] ?? null,
			'utm_term'        => $session['utm_term'] ?? null,
			'utm_content'     => $session['utm_content'] ?? null,
			'landing_page'    => $session['landing_page'] ?? null,
			'referrer'        => $session['referrer'] ?? null,
			'ga4_client_id'   => $session['ga4_client_id'] ?? null,
			'ga4_session_id'  => $session['ga4_session_id'] ?? null,
			'updated_at'      => $now,
		);

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE att_id = %s",
				$session['att_id']
			)
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'att_id' => $session['att_id'] ) );
			return;
		}

		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
	}

	public static function extract_click_params( $source ) {
		$params = array();
		foreach ( self::QUERY_KEYS as $key ) {
			if ( ! empty( $source[ $key ] ) ) {
				$params[ $key ] = sanitize_text_field( wp_unslash( $source[ $key ] ) );
			}
		}
		return $params;
	}

	public static function read_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$decoded = json_decode( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function write_cookie( array $session ) {
		$value = wp_json_encode( $session );
		if ( ! $value ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			$value,
			time() + ( DAY_IN_SECONDS * self::COOKIE_DAYS ),
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			false
		);
	}

	private static function current_landing_page() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}
		return home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	private static function current_referrer() {
		return ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	}

	public static function hash_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return null;
		}
		return hash( 'sha256', $email );
	}

	public static function hash_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( '' === $digits ) {
			return null;
		}
		return hash( 'sha256', $digits );
	}
}
