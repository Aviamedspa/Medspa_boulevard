<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Database {

	public static function activate() {
		self::create_tables();
		update_option( 'avia_attribution_db_version', Avia_Attribution_Settings::DB_VERSION, false );

		if ( false === get_option( Avia_Attribution_Settings::OPTION_KEY, false ) ) {
			update_option( Avia_Attribution_Settings::OPTION_KEY, Avia_Attribution_Settings::defaults(), false );
		}
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'avia_attribution_db_version', '' );
		if ( Avia_Attribution_Settings::DB_VERSION !== $installed ) {
			self::create_tables();
			update_option( 'avia_attribution_db_version', Avia_Attribution_Settings::DB_VERSION, false );
		}
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sessions        = self::sessions_table();
		$events          = self::events_table();
		$webhooks        = self::webhooks_table();
		$webhook_log     = self::webhook_log_table();
		$google_uploads  = self::google_ads_uploads_table();

		$sql_sessions = "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			att_id char(36) NOT NULL,
			gclid varchar(255) DEFAULT NULL,
			gbraid varchar(255) DEFAULT NULL,
			wbraid varchar(255) DEFAULT NULL,
			click_at datetime DEFAULT NULL,
			utm_source varchar(255) DEFAULT NULL,
			utm_medium varchar(255) DEFAULT NULL,
			utm_campaign varchar(255) DEFAULT NULL,
			utm_term varchar(255) DEFAULT NULL,
			utm_content varchar(255) DEFAULT NULL,
			landing_page text DEFAULT NULL,
			referrer text DEFAULT NULL,
			ga4_client_id varchar(64) DEFAULT NULL,
			ga4_session_id varchar(64) DEFAULT NULL,
			client_email_hash char(64) DEFAULT NULL,
			client_phone_hash char(64) DEFAULT NULL,
			blvd_client_id varchar(128) DEFAULT NULL,
			blvd_appointment_id varchar(128) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY att_id (att_id),
			KEY gclid (gclid),
			KEY blvd_client_id (blvd_client_id),
			KEY blvd_appointment_id (blvd_appointment_id),
			KEY client_email_hash (client_email_hash),
			KEY client_phone_hash (client_phone_hash)
		) {$charset_collate};";

		$sql_events = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_source varchar(32) NOT NULL DEFAULT 'boulevard',
			event_type varchar(64) NOT NULL,
			blvd_event_id varchar(128) DEFAULT NULL,
			blvd_location_id varchar(128) DEFAULT NULL,
			blvd_client_id varchar(128) DEFAULT NULL,
			blvd_appointment_id varchar(128) DEFAULT NULL,
			att_id char(36) DEFAULT NULL,
			status varchar(32) DEFAULT NULL,
			revenue_cents bigint(20) DEFAULT NULL,
			payload_json longtext NOT NULL,
			processed tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY blvd_event_id (blvd_event_id),
			KEY event_type (event_type),
			KEY att_id (att_id),
			KEY blvd_appointment_id (blvd_appointment_id)
		) {$charset_collate};";

		$sql_webhooks = "CREATE TABLE {$webhooks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blvd_location_id varchar(128) NOT NULL,
			location_name varchar(191) DEFAULT NULL,
			blvd_webhook_id varchar(128) DEFAULT NULL,
			webhook_url text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY blvd_location_id (blvd_location_id)
		) {$charset_collate};";

		$sql_webhook_log = "CREATE TABLE {$webhook_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(64) NOT NULL DEFAULT '',
			http_status smallint(6) NOT NULL DEFAULT 0,
			signature_ok tinyint(1) DEFAULT NULL,
			message varchar(255) NOT NULL DEFAULT '',
			payload_preview longtext DEFAULT NULL,
			request_ip varchar(45) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_google_uploads = "CREATE TABLE {$google_uploads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ledger_event_id bigint(20) unsigned NOT NULL,
			event_type varchar(64) NOT NULL,
			att_id char(36) DEFAULT NULL,
			gclid varchar(255) DEFAULT NULL,
			gbraid varchar(255) DEFAULT NULL,
			wbraid varchar(255) DEFAULT NULL,
			conversion_value decimal(12,2) DEFAULT NULL,
			currency_code varchar(8) NOT NULL DEFAULT 'USD',
			conversion_datetime varchar(32) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending',
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			uploaded_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ledger_event_id (ledger_event_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_sessions );
		dbDelta( $sql_events );
		dbDelta( $sql_webhooks );
		dbDelta( $sql_webhook_log );
		dbDelta( $sql_google_uploads );
	}

	public static function upsert_webhook( $location_id, $location_name, $webhook_id, $webhook_url ) {
		global $wpdb;
		$table = self::webhooks_table();
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE blvd_location_id = %s",
				$location_id
			)
		);

		$data = array(
			'location_name'   => $location_name,
			'blvd_webhook_id'   => $webhook_id,
			'webhook_url'       => $webhook_url,
			'updated_at'        => $now,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'blvd_location_id' => $location_id ) );
			return;
		}

		$data['blvd_location_id'] = $location_id;
		$data['created_at']       = $now;
		$wpdb->insert( $table, $data );
	}

	public static function get_webhooks() {
		global $wpdb;
		$table = self::webhooks_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY location_name ASC", ARRAY_A );
	}

	public static function tables_exist() {
		global $wpdb;
		$sessions = self::sessions_table();
		$events   = self::events_table();
		$webhooks = self::webhooks_table();

		$sessions_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) === $sessions;
		$events_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) === $events;
		$webhooks_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $webhooks ) ) === $webhooks;

		return $sessions_exists && $events_exists && $webhooks_exists;
	}

	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'avia_attribution_sessions';
	}

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'avia_attribution_events';
	}

	public static function webhooks_table() {
		global $wpdb;
		return $wpdb->prefix . 'avia_blvd_webhooks';
	}

	public static function webhook_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'avia_webhook_log';
	}

	public static function google_ads_uploads_table() {
		global $wpdb;
		return $wpdb->prefix . 'avia_google_ads_uploads';
	}
}
