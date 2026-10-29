<?php
/**
 * Plugin Name: AVIA Attribution
 * Description: First-party Google Ads attribution capture and Boulevard webhook ledger for AVIA Medical Spa. Support - freelancerjd55@gmail.com
 * Version: 1.10.96
 * Author: PROS Global Inc × Drunall.com
 * License: GPL-2.0-or-later
 * Text Domain: avia-attribution
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AVIA_ATTRIBUTION_VERSION', '1.10.96' );
define( 'AVIA_ATTRIBUTION_FILE', __FILE__ );
define( 'AVIA_ATTRIBUTION_PATH', plugin_dir_path( __FILE__ ) );
define( 'AVIA_ATTRIBUTION_URL', plugin_dir_url( __FILE__ ) );
define( 'AVIA_ATTRIBUTION_AUTHOR', 'PROS Global Inc × Drunall.com' );
define( 'AVIA_ATTRIBUTION_AUTHOR_EMAIL', 'freelancerjd55@gmail.com' );

require_once AVIA_ATTRIBUTION_PATH . 'includes/class-webhook-body.php';
Avia_Attribution_Webhook_Body::maybe_capture_early();

require_once AVIA_ATTRIBUTION_PATH . 'includes/class-settings.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-database.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-webhook-log.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-ads-integrity.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-boulevard-api.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-google-ads-api.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-google-data-manager-api.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-google-ads-offline.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-dashboard.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-capture.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-rest-webhook.php';
require_once AVIA_ATTRIBUTION_PATH . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'Avia_Attribution_Database', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		// Lightweight schema check only — heavy one-time migrations run on admin/cron.
		Avia_Attribution_Database::maybe_upgrade();

		Avia_Attribution_Capture::init_rest();
		Avia_Attribution_Rest_Webhook::init();
		Avia_Attribution_Admin::init();

		if ( Avia_Attribution_Settings::is_capture_enabled() ) {
			Avia_Attribution_Capture::init_frontend();
		}

		if ( ! wp_next_scheduled( 'avia_attribution_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'avia_attribution_daily_maintenance' );
		}
	},
	20
);

/**
 * One-time data migrations + integrity repairs (admin or WP-Cron only — not every front-end hit).
 */
add_action(
	'admin_init',
	static function () {
		avia_attribution_run_pending_migrations();
	}
);

add_action(
	'avia_attribution_daily_maintenance',
	static function () {
		avia_attribution_run_pending_migrations();
		Avia_Attribution_Webhook_Log::prune_older_than( 60 );
	}
);

/**
 * @return void
 */
function avia_attribution_run_pending_migrations() {
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;

	$backfill_version = '1.9.9';
	if ( version_compare( (string) get_option( 'avia_attribution_webhook_log_backfill', '' ), $backfill_version, '<' ) ) {
		Avia_Attribution_Webhook_Log::backfill_missing_delivery_log();
		update_option( 'avia_attribution_webhook_log_backfill', $backfill_version, false );
	}

	$revenue_backfill_version = '1.10.3';
	if ( version_compare( (string) get_option( 'avia_attribution_order_revenue_backfill', '' ), $revenue_backfill_version, '<' ) ) {
		Avia_Attribution_Boulevard_Api::backfill_missing_order_revenue( 25 );
		update_option( 'avia_attribution_order_revenue_backfill', $revenue_backfill_version, false );
	}

	$upload_reconcile_version = '1.10.6';
	if ( version_compare( (string) get_option( 'avia_attribution_upload_value_reconcile', '' ), $upload_reconcile_version, '<' ) ) {
		Avia_Attribution_Google_Ads_Offline::reconcile_stale_upload_values( 100, 15 );
		update_option( 'avia_attribution_upload_value_reconcile', $upload_reconcile_version, false );
	}

	$tags_retry_version = '1.10.20';
	if ( version_compare( (string) get_option( 'avia_attribution_tags_retry', '' ), $tags_retry_version, '<' ) ) {
		delete_option( 'avia_attribution_blvd_tags_unavailable' );
		update_option( 'avia_attribution_tags_retry', $tags_retry_version, false );
	}

	$referral_retry_version = '1.10.21';
	if ( version_compare( (string) get_option( 'avia_attribution_referral_retry', '' ), $referral_retry_version, '<' ) ) {
		delete_option( 'avia_attribution_blvd_referral_unavailable' );
		update_option( 'avia_attribution_referral_retry', $referral_retry_version, false );
	}

	$client_id_backfill_version = '1.10.22';
	if ( version_compare( (string) get_option( 'avia_attribution_client_id_urn_backfill', '' ), $client_id_backfill_version, '<' ) ) {
		Avia_Attribution_Boulevard_Api::backfill_bare_uuid_client_ids();
		update_option( 'avia_attribution_client_id_urn_backfill', $client_id_backfill_version, false );
	}

	$ads_integrity_version = '1.10.38';
	if ( version_compare( (string) get_option( 'avia_attribution_ads_integrity_skip', '' ), $ads_integrity_version, '<' ) ) {
		Avia_Attribution_Ads_Integrity::skip_non_ads_pending( 300 );
		delete_option( 'avia_attribution_blvd_tag_write_unavailable' );
		update_option( 'avia_attribution_ads_integrity_skip', $ads_integrity_version, false );
	}

	$ads_integrity_v2 = '1.10.64';
	if ( version_compare( (string) get_option( 'avia_attribution_ads_integrity_skip_v2', '' ), $ads_integrity_v2, '<' ) ) {
		Avia_Attribution_Database::maybe_upgrade();
		Avia_Attribution_Ads_Integrity::skip_non_ads_pending( 500 );
		update_option( 'avia_attribution_ads_integrity_skip_v2', $ads_integrity_v2, false );
	}

	$demote_no_click = '1.10.65';
	if ( version_compare( (string) get_option( 'avia_attribution_demote_no_click', '' ), $demote_no_click, '<' ) ) {
		Avia_Attribution_Google_Ads_Offline::demote_uploaded_without_click_ids( 100 );
		update_option( 'avia_attribution_demote_no_click', $demote_no_click, false );
	}

	$same_click_merge_version = '1.10.45';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_merge', '' ), $same_click_merge_version, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::consolidate_same_click_uploaded_duplicates( 25 );
		}
		update_option( 'avia_attribution_same_click_merge', $same_click_merge_version, false );
	}

	$same_click_merge_v2 = '1.10.46';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_merge_v2', '' ), $same_click_merge_v2, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::consolidate_same_click_uploaded_duplicates( 25 );
		}
		update_option( 'avia_attribution_same_click_merge_v2', $same_click_merge_v2, false );
	}

	$same_click_merge_v3 = '1.10.47';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_merge_v3', '' ), $same_click_merge_v3, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::consolidate_same_click_uploaded_duplicates( 50 );
		}
		update_option( 'avia_attribution_same_click_merge_v3', $same_click_merge_v3, false );
	}

	$same_click_repair_version = '1.10.49';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_repair', '' ), $same_click_repair_version, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::repair_inflated_same_click_merged_values( 50 );
		}
		update_option( 'avia_attribution_same_click_repair', $same_click_repair_version, false );
	}

	$same_click_all_txn_version = '1.10.57';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_all_txn_restate', '' ), $same_click_all_txn_version, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::repair_inflated_same_click_merged_values( 50 );
		}
		update_option( 'avia_attribution_same_click_all_txn_restate', $same_click_all_txn_version, false );
	}

	$same_click_display_version = '1.10.58';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_display', '' ), $same_click_display_version, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::repair_inflated_same_click_merged_values( 50 );
		}
		update_option( 'avia_attribution_same_click_display', $same_click_display_version, false );
	}

	$same_click_own_orders_version = '1.10.59';
	if ( version_compare( (string) get_option( 'avia_attribution_same_click_own_orders', '' ), $same_click_own_orders_version, '<' ) ) {
		if ( Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			Avia_Attribution_Google_Ads_Offline::repair_inflated_same_click_merged_values( 50 );
		}
		update_option( 'avia_attribution_same_click_own_orders', $same_click_own_orders_version, false );
	}
}
