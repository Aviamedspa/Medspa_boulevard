<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avia_Attribution_Admin {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_google_ads_settings_save' ) );
		add_action( 'admin_post_avia_google_ads_export_csv', array( __CLASS__, 'handle_google_ads_csv_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_avia_attribution_test_endpoint', array( __CLASS__, 'ajax_test_endpoint' ) );
		add_action( 'wp_ajax_avia_attribution_test_boulevard', array( __CLASS__, 'ajax_test_boulevard' ) );
		add_action( 'wp_ajax_avia_attribution_register_webhooks', array( __CLASS__, 'ajax_register_webhooks' ) );
		add_action( 'wp_ajax_avia_attribution_ping_webhooks', array( __CLASS__, 'ajax_ping_webhooks' ) );
		add_action( 'wp_ajax_avia_attribution_webhook_health', array( __CLASS__, 'ajax_webhook_health' ) );
		add_action( 'wp_ajax_avia_attribution_repair_webhooks', array( __CLASS__, 'ajax_repair_webhooks' ) );
		add_action( 'wp_ajax_avia_attribution_reregister_webhooks', array( __CLASS__, 'ajax_reregister_webhooks' ) );
		add_action( 'wp_ajax_avia_attribution_clean_duplicate_webhooks', array( __CLASS__, 'ajax_clean_duplicate_webhooks' ) );
		add_action( 'wp_ajax_avia_attribution_upload_google_ads', array( __CLASS__, 'ajax_upload_google_ads' ) );
		add_action( 'wp_ajax_avia_attribution_consolidate_same_click', array( __CLASS__, 'ajax_consolidate_same_click' ) );
		add_action( 'wp_ajax_avia_attribution_void_false_attribution', array( __CLASS__, 'ajax_void_false_attribution' ) );
		add_action( 'wp_ajax_avia_attribution_void_false_attribution_auto', array( __CLASS__, 'ajax_void_false_attribution_auto' ) );
		add_action( 'wp_ajax_avia_attribution_test_google_ads', array( __CLASS__, 'ajax_test_google_ads' ) );
		add_action( 'wp_ajax_avia_attribution_list_google_ads_accounts', array( __CLASS__, 'ajax_list_google_ads_accounts' ) );
		add_action( 'wp_ajax_avia_attribution_lookup_client', array( __CLASS__, 'ajax_lookup_client' ) );
		add_action( 'wp_ajax_avia_attribution_campaign_conversions', array( __CLASS__, 'ajax_campaign_conversions' ) );
		add_action( 'wp_ajax_avia_attribution_boulevard_revenue', array( __CLASS__, 'ajax_boulevard_revenue' ) );
		add_action( 'wp_ajax_avia_attribution_boulevard_appointments', array( __CLASS__, 'ajax_boulevard_appointments' ) );
		add_action( 'wp_ajax_avia_attribution_dashboard_ads', array( __CLASS__, 'ajax_dashboard_ads_performance' ) );
		add_action( 'wp_ajax_avia_attribution_conversions', array( __CLASS__, 'ajax_conversions' ) );
		add_action( 'wp_ajax_avia_attribution_conversion_details', array( __CLASS__, 'ajax_conversion_details' ) );
		add_action( 'wp_ajax_avia_attribution_save_dashboard_metrics', array( __CLASS__, 'ajax_save_dashboard_metrics' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_google_oauth_start' ) );
		add_action( 'admin_post_avia_attribution_google_oauth', array( __CLASS__, 'handle_google_oauth_callback' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'AVIA Attribution',
			'AVIA Attribution',
			'manage_options',
			'avia-attribution',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			81
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_avia-attribution' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'avia-datatables', AVIA_ATTRIBUTION_URL . 'assets/vendor/dataTables.dataTables.min.css', array(), '2.3.4' );
		wp_enqueue_script( 'avia-datatables', AVIA_ATTRIBUTION_URL . 'assets/vendor/dataTables.min.js', array( 'jquery' ), '2.3.4', true );
		wp_enqueue_style(
			'avia-attribution-admin',
			AVIA_ATTRIBUTION_URL . 'assets/admin.css',
			array(),
			(string) filemtime( AVIA_ATTRIBUTION_PATH . 'assets/admin.css' )
		);

		wp_enqueue_script(
			'avia-attribution-admin',
			AVIA_ATTRIBUTION_URL . 'assets/admin.js',
			array( 'jquery', 'avia-datatables' ),
			(string) filemtime( AVIA_ATTRIBUTION_PATH . 'assets/admin.js' ),
			true
		);

		$range_ctx = Avia_Attribution_Dashboard::resolve_range(
			isset( $_GET['range'] ) ? wp_unslash( $_GET['range'] ) : 'last_7_days',
			isset( $_GET['start_date'] ) ? wp_unslash( $_GET['start_date'] ) : '',
			isset( $_GET['end_date'] ) ? wp_unslash( $_GET['end_date'] ) : ''
		);
		wp_localize_script(
			'avia-attribution-admin',
			'aviaAttributionAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'avia_attribution_admin' ),
				'dashboardRange'     => $range_ctx['key'],
				'campaignIds' => Avia_Attribution_Google_Ads_Api::sanitize_campaign_ids( isset( $_GET['campaign_ids'] ) ? wp_unslash( $_GET['campaign_ids'] ) : array() ),
				'dashboardStartDate' => $range_ctx['start_date'],
				'dashboardEndDate'   => $range_ctx['end_date'],
				'hasAdsApi'          => Avia_Attribution_Google_Ads_Api::has_read_credentials(),
				'metricLayout'       => Avia_Attribution_Dashboard::get_metric_layout_for_js(),
				'maxMetricsPerRow'   => Avia_Attribution_Dashboard::MAX_METRICS_PER_ROW,
				'googleReconnectUrl' => Avia_Attribution_Google_Ads_Api::get_reconnect_url(),
			)
		);
	}

	public static function handle_settings_save() {
		if ( ! isset( $_POST['avia_attribution_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'avia_attribution_settings' );

		Avia_Attribution_Settings::save(
			array(
				'blvd_client_id'     => isset( $_POST['blvd_client_id'] ) ? wp_unslash( $_POST['blvd_client_id'] ) : '',
				'blvd_client_secret' => isset( $_POST['blvd_client_secret'] ) ? wp_unslash( $_POST['blvd_client_secret'] ) : '',
				'blvd_business_id'     => isset( $_POST['blvd_business_id'] ) ? wp_unslash( $_POST['blvd_business_id'] ) : '',
				'capture_enabled'      => ! empty( $_POST['capture_enabled'] ),
				'debug_enabled'        => ! empty( $_POST['debug_enabled'] ),
			)
		);

		Avia_Attribution_Boulevard_Api::clear_token_cache();
		// Allow tag queries again after credentials/app update (tag:read on manifest v1.0.2+).
		delete_option( 'avia_attribution_blvd_tags_unavailable' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'avia-attribution',
					'tab'     => 'settings',
					'updated' => 'boulevard',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_google_oauth_start() {
		if ( empty( $_GET['page'] ) || 'avia-attribution' !== $_GET['page'] || empty( $_GET['avia_google_oauth_start'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reconnect Google Ads.', 'avia-attribution' ) );
		}
		check_admin_referer( 'avia_google_oauth_start' );

		$auth_url = Avia_Attribution_Google_Ads_Api::get_authorization_url();
		if ( is_wp_error( $auth_url ) ) {
			set_transient(
				'avia_google_oauth_notice_' . get_current_user_id(),
				array(
					'ok'      => false,
					'message' => $auth_url->get_error_message(),
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=avia-attribution&tab=settings' ) );
			exit;
		}

		wp_redirect( $auth_url );
		exit;
	}

	public static function handle_google_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reconnect Google Ads.', 'avia-attribution' ) );
		}

		$notice_key = 'avia_google_oauth_notice_' . get_current_user_id();

		if ( ! empty( $_GET['error'] ) ) {
			set_transient(
				$notice_key,
				array(
					'ok'      => false,
					'message' => 'Google authorization cancelled: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ),
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=avia-attribution&tab=settings' ) );
			exit;
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'avia_google_ads_oauth_state_' . get_current_user_id() );
		delete_transient( 'avia_google_ads_oauth_state_' . get_current_user_id() );

		if ( '' === $state || ! is_string( $expected ) || ! hash_equals( $expected, $state ) ) {
			set_transient(
				$notice_key,
				array(
					'ok'      => false,
					'message' => 'OAuth state mismatch. Click Reconnect Google Ads again.',
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=avia-attribution&tab=settings' ) );
			exit;
		}

		$code   = isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';
		$result = Avia_Attribution_Google_Ads_Api::exchange_authorization_code( $code );
		if ( is_wp_error( $result ) ) {
			set_transient(
				$notice_key,
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				),
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( admin_url( 'admin.php?page=avia-attribution&tab=settings' ) );
			exit;
		}

		set_transient(
			$notice_key,
			array(
				'ok'      => true,
				'message' => 'Google Ads reconnect succeeded. New refresh token saved. Click Test API connection to verify.',
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=avia-attribution&tab=settings' ) );
		exit;
	}

	private static function consume_google_oauth_notice() {
		$key    = 'avia_google_oauth_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}
		delete_transient( $key );
		return $notice;
	}

	public static function handle_google_ads_settings_save() {
		if ( ! isset( $_POST['avia_attribution_save_google_ads'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'avia_attribution_google_ads' );

		Avia_Attribution_Settings::save(
			array(
				'google_ads_customer_id'              => isset( $_POST['google_ads_customer_id'] ) ? wp_unslash( $_POST['google_ads_customer_id'] ) : '',
				'google_ads_login_customer_id'        => isset( $_POST['google_ads_login_customer_id'] ) ? wp_unslash( $_POST['google_ads_login_customer_id'] ) : '',
				'google_ads_conversion_action_id'     => isset( $_POST['google_ads_conversion_action_id'] ) ? wp_unslash( $_POST['google_ads_conversion_action_id'] ) : '',
				'google_ads_conversion_name'          => isset( $_POST['google_ads_conversion_name'] ) ? wp_unslash( $_POST['google_ads_conversion_name'] ) : '',
				'google_ads_conversion_id'            => isset( $_POST['google_ads_conversion_id'] ) ? wp_unslash( $_POST['google_ads_conversion_id'] ) : '',
				'google_ads_conversion_label'         => isset( $_POST['google_ads_conversion_label'] ) ? wp_unslash( $_POST['google_ads_conversion_label'] ) : '',
				'google_ads_default_conversion_value' => isset( $_POST['google_ads_default_conversion_value'] ) ? wp_unslash( $_POST['google_ads_default_conversion_value'] ) : '',
				'google_ads_developer_token'          => isset( $_POST['google_ads_developer_token'] ) ? wp_unslash( $_POST['google_ads_developer_token'] ) : '',
				'google_ads_client_id'                => isset( $_POST['google_ads_client_id'] ) ? wp_unslash( $_POST['google_ads_client_id'] ) : '',
				'google_ads_client_secret'            => isset( $_POST['google_ads_client_secret'] ) ? wp_unslash( $_POST['google_ads_client_secret'] ) : '',
				'google_ads_refresh_token'            => isset( $_POST['google_ads_refresh_token'] ) ? wp_unslash( $_POST['google_ads_refresh_token'] ) : '',
				'google_ads_auto_upload_enabled'      => ! empty( $_POST['google_ads_auto_upload_enabled'] ),
			)
		);

		Avia_Attribution_Google_Ads_Api::clear_token_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'avia-attribution',
					'tab'     => 'settings',
					'updated' => 'google-ads',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_google_ads_csv_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'avia-attribution' ) );
		}
		check_admin_referer( 'avia_google_ads_export_csv' );

		$csv      = Avia_Attribution_Google_Ads_Offline::build_csv();
		$filename = 'avia-google-ads-offline-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( 'google-ads' === $tab ) {
			$tab = 'dashboard';
		}
		$tabs = array(
			'dashboard'   => 'Dashboard',
			'webhook-log' => 'Webhook Delivery Log',
			'status'      => 'Status',
			'verify'      => 'Verify & Connect',
			'settings'    => 'Settings',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		$settings = Avia_Attribution_Settings::get_all();
		$base_url = admin_url( 'admin.php?page=avia-attribution' );
		?>
		<div class="wrap avia-attribution-wrap">
			<div class="avia-admin-header">
				<div class="avia-admin-header-main">
					<h1>AVIA Attribution</h1>
					<p class="avia-admin-subtitle">Attribution dashboard, Google Ads performance, and offline conversion tracking for AVIA Medical Spa</p>
				</div>
			</div>
			<nav class="nav-tab-wrapper avia-admin-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'dashboard' === $tab ) : ?>
				<?php self::render_dashboard_tab( $settings ); ?>
			<?php elseif ( 'webhook-log' === $tab ) : ?>
				<?php self::render_webhook_log_tab(); ?>
			<?php elseif ( 'status' === $tab ) : ?>
				<?php self::render_status_tab(); ?>
			<?php elseif ( 'verify' === $tab ) : ?>
				<?php self::render_verify_tab( $settings ); ?>
			<?php else : ?>
				<?php self::render_settings_tab( $settings ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_settings_tab( array $settings ) {
		$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
		if ( 'boulevard' === $updated ) {
			echo '<div class="notice notice-success is-dismissible"><p>Boulevard settings saved.</p></div>';
		} elseif ( 'google-ads' === $updated ) {
			echo '<div class="notice notice-success is-dismissible"><p>Google Ads settings saved.</p></div>';
		}
		$oauth_notice = self::consume_google_oauth_notice();
		if ( is_array( $oauth_notice ) ) {
			$class = ! empty( $oauth_notice['ok'] ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $oauth_notice['message'] ) . '</p></div>';
		}
		?>
		<div class="avia-settings-section">
			<form method="post" action="" class="avia-settings-form">
				<?php wp_nonce_field( 'avia_attribution_settings' ); ?>
				<input type="hidden" name="avia_attribution_save_settings" value="1" />
				<div class="avia-attribution-panel">
					<h2>Boulevard</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="blvd_client_id">Client ID</label></th>
							<td><input type="text" class="regular-text" id="blvd_client_id" name="blvd_client_id" value="<?php echo esc_attr( $settings['blvd_client_id'] ); ?>" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="blvd_client_secret">Client Secret</label></th>
							<td>
								<input type="password" class="regular-text" id="blvd_client_secret" name="blvd_client_secret" value="" autocomplete="new-password" placeholder="<?php echo $settings['blvd_client_secret'] ? '••••••••••••••••' : ''; ?>" />
								<p class="description">Leave blank to keep the current secret.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="blvd_business_id">Business ID</label></th>
							<td>
								<input type="text" class="regular-text" id="blvd_business_id" name="blvd_business_id" value="<?php echo esc_attr( $settings['blvd_business_id'] ); ?>" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row">Front-end capture</th>
							<td>
								<label>
									<input type="checkbox" name="capture_enabled" value="1" <?php checked( $settings['capture_enabled'] ); ?> />
									Enable attribution cookie + dataLayer on the public site
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Debug logging</th>
							<td>
								<label>
									<input type="checkbox" name="debug_enabled" value="1" <?php checked( $settings['debug_enabled'] ); ?> />
									Log webhook receipts to PHP error log
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Save Boulevard Settings' ); ?>
				</div>
			</form>
		</div>

		<div class="avia-settings-section">
			<form method="post" action="" class="avia-settings-form">
				<?php wp_nonce_field( 'avia_attribution_google_ads' ); ?>
				<input type="hidden" name="avia_attribution_save_google_ads" value="1" />
				<div class="avia-attribution-panel">
					<h2>Google Ads</h2>
					<?php self::render_google_ads_settings_fields( $settings ); ?>
					<?php submit_button( 'Save Google Ads Settings' ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_google_ads_settings_fields( array $settings ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="google_ads_customer_id">Customer ID</label></th>
				<td>
					<input type="text" class="regular-text" id="google_ads_customer_id" name="google_ads_customer_id" value="<?php echo esc_attr( $settings['google_ads_customer_id'] ); ?>" />
					<p class="description">463-146-7546 (digits only is fine).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_login_customer_id">Login customer ID (MCC)</label></th>
				<td>
					<input type="text" class="regular-text" id="google_ads_login_customer_id" name="google_ads_login_customer_id" value="<?php echo esc_attr( $settings['google_ads_login_customer_id'] ?? '' ); ?>" />
					<p class="description">Optional manager account ID when accessing AVIA through an agency MCC.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_conversion_action_id">Conversion action ID</label></th>
				<td>
					<input type="text" class="regular-text" id="google_ads_conversion_action_id" name="google_ads_conversion_action_id" value="<?php echo esc_attr( $settings['google_ads_conversion_action_id'] ); ?>" />
					<p class="description">Appointment Completed (Primary): <code>7766637097</code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_conversion_name">Conversion name (CSV)</label></th>
				<td>
					<input type="text" class="regular-text" id="google_ads_conversion_name" name="google_ads_conversion_name" value="<?php echo esc_attr( $settings['google_ads_conversion_name'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_default_conversion_value">Default conversion value</label></th>
				<td>
					<p class="description">Disabled. Only actual Boulevard revenue is used; missing revenue waits for backfill.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_developer_token">Developer token</label></th>
				<td>
					<input type="password" class="regular-text" id="google_ads_developer_token" name="google_ads_developer_token" value="" autocomplete="new-password" placeholder="<?php echo $settings['google_ads_developer_token'] ? '••••••••••••••••' : ''; ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_client_id">OAuth client ID</label></th>
				<td><input type="text" class="regular-text" id="google_ads_client_id" name="google_ads_client_id" value="<?php echo esc_attr( $settings['google_ads_client_id'] ); ?>" autocomplete="off" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_client_secret">OAuth client secret</label></th>
				<td>
					<input type="password" class="regular-text" id="google_ads_client_secret" name="google_ads_client_secret" value="" autocomplete="new-password" placeholder="<?php echo $settings['google_ads_client_secret'] ? '••••••••••••••••' : ''; ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="google_ads_refresh_token">OAuth refresh token</label></th>
				<td>
					<input type="password" class="large-text" id="google_ads_refresh_token" name="google_ads_refresh_token" value="" autocomplete="new-password" placeholder="<?php echo $settings['google_ads_refresh_token'] ? '••••••••••••••••' : ''; ?>" />
					<p class="description">
						Google does <strong>not</strong> issue permanent tokens. The short-lived <em>access token</em> (~1 hour) is refreshed automatically.
						This field stores the long-lived <em>refresh token</em>, which can still expire if the consent screen is in Testing mode (~7 days),
						access is revoked, or the authorizing Google account password changes.
					</p>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=avia-attribution&tab=settings&avia_google_oauth_start=1' ), 'avia_google_oauth_start' ) ); ?>">
							Reconnect Google Ads
						</a>
					</p>
					<p class="description">
						Opens Google sign-in with scopes <code>adwords</code> + <code>datamanager</code> and saves a new refresh token.
						In Google Cloud → APIs &amp; Services → Credentials → your <strong>Web</strong> OAuth client →
						<strong>Authorized redirect URIs</strong>, add this URI exactly (copy/paste):
						<br><code><?php echo esc_html( Avia_Attribution_Google_Ads_Api::get_oauth_redirect_uri() ); ?></code>
						<br>Also keep <code>https://developers.google.com/oauthplayground</code> if you still use Playground.
						For long-lived refresh tokens, set OAuth consent screen Publishing status to <strong>Production</strong>.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Auto-upload</th>
				<td>
					<label>
						<input type="checkbox" name="google_ads_auto_upload_enabled" value="1" <?php checked( $settings['google_ads_auto_upload_enabled'] ); ?> />
						Upload conversions via Data Manager when <code>APPOINTMENT_COMPLETED</code> is received
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_verify_tab( array $settings ) {
		$webhook_url = rest_url( 'avia/v1/boulevard-webhook' );
		$webhooks    = Avia_Attribution_Database::get_webhooks();
		?>
		<div class="avia-attribution-panel">
			<h2>Webhook endpoint</h2>
			<p><code><?php echo esc_url( $webhook_url ); ?></code></p>
			<p>
				<button type="button" class="button button-primary" id="avia-test-endpoint">Verify endpoint is live</button>
			</p>
			<div id="avia-test-endpoint-result" class="avia-result" aria-live="polite"></div>
		</div>

		<div class="avia-attribution-panel">
			<h2>Boulevard API</h2>
			<p>Fetches a token and loads locations dynamically from your live Boulevard business.</p>
			<p>
				<button type="button" class="button button-secondary" id="avia-test-boulevard" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Test Boulevard connection
				</button>
			</p>
			<div id="avia-test-boulevard-result" class="avia-result" aria-live="polite"></div>
		</div>

		<div class="avia-attribution-panel">
			<h2>Register webhooks</h2>
			<p>Creates one webhook per location returned by Boulevard. No static location IDs are stored in code.</p>
			<p>
				<button type="button" class="button button-secondary" id="avia-register-webhooks" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Register webhooks for all locations
				</button>
			</p>
			<div id="avia-register-webhooks-result" class="avia-result" aria-live="polite"></div>

			<?php if ( ! empty( $webhooks ) ) : ?>
				<h3>Registered in WordPress</h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Location</th>
							<th>Location ID</th>
							<th>Webhook ID</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $webhooks as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['location_name'] ); ?></td>
								<td><code><?php echo esc_html( $row['blvd_location_id'] ); ?></code></td>
								<td><code><?php echo esc_html( $row['blvd_webhook_id'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="avia-attribution-panel">
			<h2>Webhook subscription health</h2>
			<p>Loads live subscription status from Boulevard API. Shows which event types (including <code>APPOINTMENT_CREATED</code>) are enabled or auto-disabled.</p>
			<p>
				<button type="button" class="button button-secondary" id="avia-webhook-health" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Check subscription health
				</button>
				<button type="button" class="button button-secondary" id="avia-repair-webhooks" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Repair disabled subscriptions
				</button>
				<button type="button" class="button" id="avia-reregister-webhooks" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Re-register all webhooks
				</button>
				<button type="button" class="button" id="avia-clean-duplicate-webhooks" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Clean duplicate webhooks
				</button>
			</p>
			<div id="avia-webhook-health-result" class="avia-result" aria-live="polite"></div>
			<p class="description">Repair recreates only disabled subscriptions. Clean duplicate webhooks removes extra registrations that point at the same endpoint (keeps one per location). Re-register deletes all Boulevard webhooks for this app and creates fresh ones per location (use if subscriptions are missing).</p>
		</div>

		<div class="avia-attribution-panel">
			<h2>Verify client write-back</h2>
			<p>Look up a Boulevard client by email and/or gclid (or <code>att_id</code>). Shows CRM tags, custom fields (write-back), and the local attribution session.</p>
			<?php if ( get_option( 'avia_attribution_blvd_tags_unavailable' ) ) : ?>
				<p class="notice notice-warning inline" style="margin: 8px 0 12px; padding: 8px 12px;">
					Boulevard <code>tag:read</code> is not available on the installed app, so CRM tags cannot load yet.
					Publish app manifest <strong>v1.0.2</strong> (adds <code>tag:read</code>), update the app on the AVIA business, then delete the
					<code>avia_attribution_blvd_tags_unavailable</code> option (or re-save Settings after support clears it) to retry.
				</p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="avia-lookup-email">Email</label></th>
					<td><input type="email" class="regular-text" id="avia-lookup-email" autocomplete="off" placeholder="client@example.com" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="avia-lookup-gclid">GCLID</label></th>
					<td><input type="text" class="regular-text" id="avia-lookup-gclid" autocomplete="off" placeholder="e2e_test_001" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="avia-lookup-att-id">Att ID</label></th>
					<td><input type="text" class="regular-text" id="avia-lookup-att-id" autocomplete="off" placeholder="Optional UUID from capture cookie" /></td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="avia-lookup-client" <?php disabled( ! Avia_Attribution_Settings::has_credentials() ); ?>>
					Look up client
				</button>
			</p>
			<div id="avia-lookup-client-result" class="avia-result" aria-live="polite"></div>
		</div>

		<div class="avia-attribution-panel">
			<h2>GTM &amp; Google Ads bridge</h2>
			<p>With GTM (<code>GTM-K75CQH9R</code>) and Google Ads (<code>AW-626150478</code>) access confirmed, follow the setup guide to add GA4/Ads conversion tags for <code>avia_attribution_ready</code> and <code>avia_book_now_click</code>.</p>
			<p>Plugin v1.5.0 loads <code>blvd-bridge.js</code> when capture is enabled — it passes <code>avia_att_id</code> + gclid into Boulevard <code>urlParams</code> on Book Now. Webhooks write the same fields to Boulevard client custom fields via Admin API.</p>
			<p><strong>Guide:</strong> <code>boulevard/GTM_BRIDGE_SETUP.md</code> in the project repo.</p>
		</div>

		<div class="avia-attribution-panel">
			<h2>Test Boulevard webhook delivery</h2>
			<p>Sends a signed <code>PING</code> from Boulevard to your live endpoint. Boulevard pings require HMAC verification; the internal endpoint test bypasses signing.</p>
			<p>
				<button type="button" class="button button-secondary" id="avia-ping-webhooks" <?php disabled( ! Avia_Attribution_Settings::has_credentials() || empty( $webhooks ) ); ?>>
					Send Boulevard ping to endpoint
				</button>
			</p>
			<div id="avia-ping-webhooks-result" class="avia-result" aria-live="polite"></div>
			<p class="description">After ping, refresh and confirm log shows <code>PING</code> with HTTP 200 and Signature <strong>OK</strong>. v2026-06 M2M apps send <code>x-blvd-oauth-hmac-salt</code> and <code>x-blvd-oauth-hmac-sha256</code> (legacy apps use <code>x-blvd-hmac-*</code>). If ping fails with <code>missing_headers</code>, allow both header sets through Wordfence/Sucuri/host WAF. If it fails with <code>signature_mismatch</code>, re-save the Client Secret from Developer Portal.</p>
		</div>

		<div class="avia-attribution-panel">
			<h2>Recent webhook delivery log</h2>
			<p>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'avia-attribution', 'tab' => 'webhook-log' ), admin_url( 'admin.php' ) ) ); ?>">
					Open full webhook delivery log
				</a>
				— grouped by date with pagination and duplicate counts.
			</p>
			<?php self::render_webhook_log_table( 10 ); ?>
		</div>
		<?php
	}

	private static function render_webhook_log_tab() {
		$page     = isset( $_GET['log_page'] ) ? max( 1, (int) wp_unslash( $_GET['log_page'] ) ) : 1;
		$per_page = 20;
		$result   = Avia_Attribution_Webhook_Log::get_grouped_paginated( $page, $per_page );
		$base_url = add_query_arg(
			array(
				'page' => 'avia-attribution',
				'tab'  => 'webhook-log',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="avia-attribution-panel avia-webhook-log-panel">
			<h2>Webhook delivery log</h2>
			<p class="description">
				Duplicate deliveries with the same date, event, HTTP status, signature result, and message are combined into one row with a count.
				Showing <?php echo esc_html( (string) count( $result['rows'] ) ); ?> of <?php echo esc_html( (string) $result['total'] ); ?> grouped entries (page <?php echo esc_html( (string) $result['page'] ); ?> of <?php echo esc_html( (string) $result['total_pages'] ); ?>).
			</p>

			<?php if ( empty( $result['rows'] ) ) : ?>
				<p>No webhook requests logged yet.</p>
			<?php else : ?>
				<?php self::render_grouped_webhook_log_table( $result['rows'] ); ?>

				<?php if ( $result['total_pages'] > 1 ) : ?>
					<div class="avia-log-pagination tablenav">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								printf(
									/* translators: %d: total grouped log rows */
									esc_html( _n( '%d item', '%d items', $result['total'], 'avia-attribution' ) ),
									(int) $result['total']
								);
								?>
							</span>
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'log_page', '%#%', $base_url ),
										'format'    => '',
										'prev_text' => '&laquo; Previous',
										'next_text' => 'Next &raquo;',
										'total'     => $result['total_pages'],
										'current'   => $result['page'],
										'type'      => 'plain',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_webhook_event_badge( $event_type ) {
		$event_type = sanitize_text_field( (string) $event_type );
		$class_map  = array(
			'APPOINTMENT_CANCELLED' => 'avia-event-cancelled',
			'APPOINTMENT_COMPLETED' => 'avia-event-completed',
			'APPOINTMENT_CREATED'   => 'avia-event-created',
			'CLIENT_CREATED'        => 'avia-event-client',
			'ORDER_COMPLETED'       => 'avia-event-order',
			'APPOINTMENT_UPDATED'   => 'avia-event-updated',
			'CLIENT_UPDATED'        => 'avia-event-updated',
			'PING'                  => 'avia-event-ping',
		);
		$class = $class_map[ $event_type ] ?? 'avia-event-default';

		echo '<span class="avia-event-badge ' . esc_attr( $class ) . '">' . esc_html( $event_type ) . '</span>';
	}

	private static function render_grouped_webhook_log_table( array $rows ) {
		$current_date = '';
		?>
		<table class="widefat striped avia-webhook-log-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Event</th>
					<th>HTTP</th>
					<th>Signature</th>
					<th>Message</th>
					<th>Count</th>
					<th>Last seen</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$log_date  = (string) ( $row['log_date'] ?? '' );
					$signature = Avia_Attribution_Webhook_Log::format_signature_label( $row['signature_ok'] ?? null );
					$is_fail   = 'FAIL' === $signature;
					$is_ok     = 'OK' === $signature;
					?>
					<?php if ( $log_date !== $current_date ) : ?>
						<?php $current_date = $log_date; ?>
						<tr class="avia-log-date-row">
							<td colspan="7"><strong><?php echo esc_html( $log_date ); ?></strong></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td><?php echo esc_html( $log_date ); ?></td>
						<td><?php self::render_webhook_event_badge( $row['event_type'] ?? '' ); ?></td>
						<td><?php echo esc_html( (string) ( $row['http_status'] ?? '' ) ); ?></td>
						<td>
							<?php if ( $is_ok ) : ?>
								<span class="avia-ok">OK</span>
							<?php elseif ( $is_fail ) : ?>
								<span class="avia-fail">FAIL</span>
							<?php else : ?>
								n/a
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						<td><strong><?php echo esc_html( (string) ( $row['row_count'] ?? '1' ) ); ?></strong></td>
						<td><?php echo esc_html( (string) ( $row['last_at'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_webhook_log_table( $limit = 20 ) {
		$rows = Avia_Attribution_Webhook_Log::get_recent( $limit );
		if ( empty( $rows ) ) {
			echo '<p>No webhook requests logged yet.</p>';
			return;
		}
		?>
		<table class="widefat striped avia-webhook-log-table">
			<thead>
				<tr>
					<th>Time</th>
					<th>Event</th>
					<th>HTTP</th>
					<th>Signature</th>
					<th>Message</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><?php self::render_webhook_event_badge( $row['event_type'] ); ?></td>
						<td><?php echo esc_html( (string) $row['http_status'] ); ?></td>
						<td>
							<?php
							$signature = Avia_Attribution_Webhook_Log::format_signature_label( $row['signature_ok'] ?? null );
							if ( 'OK' === $signature ) {
								echo '<span class="avia-ok">OK</span>';
							} elseif ( 'FAIL' === $signature ) {
								echo '<span class="avia-fail">FAIL</span>';
							} else {
								echo 'n/a';
							}
							?>
						</td>
						<td><?php echo esc_html( $row['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_status_tab() {
		global $wpdb;

		$sessions_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Avia_Attribution_Database::sessions_table() );
		$events_count   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Avia_Attribution_Database::events_table() );
		$settings       = Avia_Attribution_Settings::get_all();
		$pending_count  = Avia_Attribution_Google_Ads_Offline::get_pending_count();
		?>
		<div class="avia-attribution-panel">
			<h2>System status</h2>
			<table class="widefat striped avia-status-table">
				<tbody>
					<tr>
						<th>Plugin version</th>
						<td><?php echo esc_html( AVIA_ATTRIBUTION_VERSION ); ?></td>
					</tr>
					<tr>
						<th>Author</th>
						<td><?php echo esc_html( AVIA_ATTRIBUTION_AUTHOR ); ?></td>
					</tr>
					<tr>
						<th>Support</th>
						<td><a href="mailto:<?php echo esc_attr( AVIA_ATTRIBUTION_AUTHOR_EMAIL ); ?>"><?php echo esc_html( AVIA_ATTRIBUTION_AUTHOR_EMAIL ); ?></a></td>
					</tr>
					<tr>
						<th>Database tables</th>
						<td><?php echo Avia_Attribution_Database::tables_exist() ? '<span class="avia-ok">OK</span>' : '<span class="avia-fail">Missing</span>'; ?></td>
					</tr>
					<tr>
						<th>Boulevard credentials</th>
						<td><?php echo Avia_Attribution_Settings::has_credentials() ? '<span class="avia-ok">Configured</span>' : '<span class="avia-warn">Not configured</span>'; ?></td>
					</tr>
					<tr>
						<th>Google Ads credentials</th>
						<td><?php echo Avia_Attribution_Settings::has_google_ads_credentials() ? '<span class="avia-ok">Configured</span>' : '<span class="avia-warn">Not configured</span>'; ?></td>
					</tr>
					<tr>
						<th>Auto-upload</th>
						<td><?php echo Avia_Attribution_Settings::is_google_ads_auto_upload_enabled() ? '<span class="avia-ok">Enabled</span>' : '<span class="avia-warn">Disabled</span>'; ?></td>
					</tr>
					<tr>
						<th>Pending Google Ads uploads</th>
						<td><?php echo esc_html( (string) $pending_count ); ?></td>
					</tr>
					<tr>
						<th>Front-end capture</th>
						<td><?php echo $settings['capture_enabled'] ? '<span class="avia-ok">Enabled</span>' : '<span class="avia-warn">Disabled</span>'; ?></td>
					</tr>
					<tr>
						<th>Webhooks last synced</th>
						<td><?php echo $settings['webhooks_synced_at'] ? esc_html( $settings['webhooks_synced_at'] ) : 'Not yet'; ?></td>
					</tr>
					<tr>
						<th>Stored sessions</th>
						<td><?php echo esc_html( (string) $sessions_count ); ?></td>
					</tr>
					<tr>
						<th>Stored webhook events</th>
						<td><?php echo esc_html( (string) $events_count ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function ajax_test_endpoint() {
		self::verify_ajax();

		$response = self::dispatch_internal_webhook_test();
		$data     = $response->get_data();
		$status   = $response->get_status();

		if ( 200 === $status && ! empty( $data['received'] ) ) {
			wp_send_json_success(
				array(
					'message' => 'Webhook endpoint responded OK.',
					'url'     => rest_url( 'avia/v1/boulevard-webhook' ),
					'data'    => $data,
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => 'Webhook endpoint test failed.',
				'status'  => $status,
				'data'    => $data,
			)
		);
	}

	public static function ajax_lookup_client() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$email  = isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '';
		$gclid  = isset( $_POST['gclid'] ) ? wp_unslash( $_POST['gclid'] ) : '';
		$att_id = isset( $_POST['att_id'] ) ? wp_unslash( $_POST['att_id'] ) : '';

		$result = Avia_Attribution_Boulevard_Api::lookup_client_for_verification( $email, $gclid, $att_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => self::build_lookup_result_message( $result ),
				'lookup'  => $result,
			)
		);
	}

	private static function build_lookup_result_message( array $result ) {
		$client_count  = count( $result['clients'] ?? array() );
		$session_count = count( $result['local_sessions'] ?? array() );

		if ( 0 === $client_count && 0 === $session_count ) {
			return 'No matching Boulevard client or local attribution session found.';
		}

		if ( 0 === $client_count && $session_count > 0 ) {
			return 'Local attribution session found, but no Boulevard client is linked yet. Complete a booking to trigger write-back.';
		}

		$writeback = $result['writeback_ok'] ?? array();
		if ( ! empty( $writeback['message'] ) ) {
			return $writeback['message'];
		}

		return sprintf(
			'Found %1$d Boulevard client(s) and %2$d local session(s).',
			$client_count,
			$session_count
		);
	}

	public static function ajax_test_boulevard() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$result = Avia_Attribution_Boulevard_Api::test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'   => 'Boulevard API connection OK.',
				'locations' => $result['locations'],
			)
		);
	}

	public static function ajax_register_webhooks() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$endpoint_test = self::internal_endpoint_test();
		if ( is_wp_error( $endpoint_test ) ) {
			wp_send_json_error( array( 'message' => 'Verify endpoint first: ' . $endpoint_test->get_error_message() ) );
		}

		$result = Avia_Attribution_Boulevard_Api::register_webhooks_for_all_locations();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'Webhook registration completed.',
				'results' => $result,
			)
		);
	}

	public static function ajax_webhook_health() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$report = Avia_Attribution_Boulevard_Api::get_webhook_health_report();
		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'Webhook health loaded from Boulevard.',
				'report'  => $report,
			)
		);
	}

	public static function ajax_repair_webhooks() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$results = Avia_Attribution_Boulevard_Api::repair_disabled_subscriptions();
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'Repair completed. Run Check subscription health again to confirm.',
				'results' => $results,
			)
		);
	}

	public static function ajax_clean_duplicate_webhooks() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$payload = Avia_Attribution_Boulevard_Api::clean_duplicate_webhooks();
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
		}

		$result  = $payload['results'] ?? array();
		$summary = $payload['summary'] ?? array();

		$deleted = array_values(
			array_filter(
				$result,
				static function ( $row ) {
					return empty( $row['kept'] );
				}
			)
		);
		$failures = array_values(
			array_filter(
				$deleted,
				static function ( $row ) {
					return empty( $row['success'] );
				}
			)
		);

		$before_count   = (int) ( $summary['before_count'] ?? 0 );
		$after_count    = (int) ( $summary['after_count'] ?? $before_count );
		$expected_count = (int) ( $summary['expected_count'] ?? 2 );
		$still_stale    = $after_count > $expected_count;

		if ( empty( $deleted ) ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						'No duplicate webhooks found. Boulevard still lists %1$d webhook(s) for this endpoint (expected %2$d).',
						$after_count,
						$expected_count
					),
					'results' => $result,
					'summary' => $summary,
				)
			);
		}

		if ( ! empty( $failures ) || $still_stale ) {
			$message = sprintf(
				'Cleanup attempted %1$d delete(s), but Boulevard still lists %2$d webhook(s) (expected %3$d). %4$d delete failure(s). Stale webhooks from a previous app install may need Boulevard support to remove.',
				count( $deleted ),
				$after_count,
				$expected_count,
				count( $failures )
			);
			wp_send_json_error(
				array(
					'message' => $message,
					'results' => $result,
					'summary' => $summary,
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					'Duplicate webhook cleanup completed. Boulevard webhook count: %1$d -> %2$d.',
					$before_count,
					$after_count
				),
				'results' => $result,
				'summary' => $summary,
			)
		);
	}

	public static function ajax_reregister_webhooks() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$result = Avia_Attribution_Boulevard_Api::re_register_all_webhooks();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'All webhooks deleted and re-registered. Run Check subscription health to confirm.',
				'result'  => $result,
			)
		);
	}

	public static function ajax_ping_webhooks() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Boulevard credentials first.' ) );
		}

		$result = Avia_Attribution_Boulevard_Api::ping_registered_webhooks();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'Boulevard ping request sent. Refresh this page to see the delivery log.',
				'results' => $result,
			)
		);
	}

	private static function dispatch_internal_webhook_test() {
		Avia_Attribution_Webhook_Body::reset_capture();

		$request = new WP_REST_Request( 'POST', '/avia/v1/boulevard-webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'ping' => true, 'source' => 'avia-admin-test' ) ) );

		Avia_Attribution_Rest_Webhook::$allow_internal_ping = true;
		try {
			return rest_do_request( $request );
		} finally {
			Avia_Attribution_Rest_Webhook::$allow_internal_ping = false;
		}
	}

	private static function internal_endpoint_test() {
		$response = self::dispatch_internal_webhook_test();
		if ( 200 !== $response->get_status() ) {
			return new WP_Error( 'avia_endpoint_failed', 'Webhook endpoint is not reachable.' );
		}
		return true;
	}

	private static function render_dashboard_tab( array $settings ) {
		$requested_range = isset( $_GET['range'] ) ? wp_unslash( $_GET['range'] ) : 'last_7_days';
		$requested_start = isset( $_GET['start_date'] ) ? wp_unslash( $_GET['start_date'] ) : '';
		$requested_end   = isset( $_GET['end_date'] ) ? wp_unslash( $_GET['end_date'] ) : '';
		$range_ctx       = Avia_Attribution_Dashboard::resolve_range( $requested_range, $requested_start, $requested_end );
		$range_key       = $range_ctx['key'];
		$start_date      = $range_ctx['start_date'];
		$end_date        = $range_ctx['end_date'];
		$default_custom  = Avia_Attribution_Dashboard::get_default_custom_dates();
		$pending_count   = Avia_Attribution_Google_Ads_Offline::get_pending_count();
		$local_stats     = Avia_Attribution_Dashboard::get_local_stats( $range_key, $start_date, $end_date );

		$dashboard_args  = array(
			'page'  => 'avia-attribution',
			'tab'   => 'dashboard',
			'range' => $range_key,
		);
		if ( 'custom' === $range_key ) {
			$dashboard_args['start_date'] = $start_date;
			$dashboard_args['end_date']   = $end_date;
		}
		$dashboard_url = add_query_arg( $dashboard_args, admin_url( 'admin.php' ) );
		$can_process_queue = $pending_count > 0;
		$has_ads_api         = Avia_Attribution_Google_Ads_Api::has_read_credentials()
			&& '' !== Avia_Attribution_Google_Ads_Api::normalize_customer_id( $settings['google_ads_customer_id'] ?? '' );
		$range_labels = array(
			'today'        => 'Today',
			'yesterday'    => 'Yesterday',
			'last_week'    => 'Last week',
			'this_week'    => 'This week',
			'this_month'   => 'This month',
			'last_month'   => 'Last month',
			'last_7_days'  => 'Last 7 days',
			'last_30_days' => 'Last 30 days',
			'last_90_days' => 'Last 90 days',
			'custom'       => 'Custom range',
		);
		$is_custom_range = 'custom' === $range_key;
		?>
		<div class="avia-attribution-panel avia-dashboard-toolbar">
			<div class="avia-panel-heading-row">
				<div>
					<h2>Performance overview</h2>
					<p class="description">
						Showing data for <strong><?php echo esc_html( $range_ctx['label'] ); ?></strong>.
						Paid ads metrics from Google Ads API and attributed conversions tracked by this plugin.
					</p>
				</div>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="avia-dashboard-range-form" id="avia-dashboard-range-form">
					<input type="hidden" name="page" value="avia-attribution" />
					<input type="hidden" name="tab" value="dashboard" />
					<details class="avia-overview-campaign-filter" id="avia-overview-campaign-filter">
						<summary id="avia-overview-campaign-label">All campaigns</summary>
						<div class="avia-overview-campaign-options">
							<button type="button" class="button" id="avia-overview-campaign-all">All campaigns</button>
							<label for="avia-overview-campaign-search" class="screen-reader-text">Search campaigns</label>
							<input type="search" id="avia-overview-campaign-search" placeholder="Search campaigns..." />
							<div id="avia-overview-campaign-list">Loading campaigns...</div>
							<p id="avia-overview-campaign-empty" class="description" hidden>No campaigns match your search.</p>
							<button type="button" class="button button-primary" id="avia-overview-campaign-apply">Apply campaigns</button>
						</div>
					</details>
					<span id="avia-overview-campaign-inputs"></span>
					<label for="avia-dashboard-range" class="screen-reader-text">Date range</label>
					<select id="avia-dashboard-range" name="range">
						<?php foreach ( $range_labels as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $range_key, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="avia-custom-range-fields" <?php echo $is_custom_range ? '' : 'hidden'; ?>>
						<label for="avia-dashboard-start-date" class="screen-reader-text">Start date</label>
						<input
							type="date"
							id="avia-dashboard-start-date"
							name="start_date"
							value="<?php echo esc_attr( $is_custom_range ? $start_date : $default_custom['start_date'] ); ?>"
							max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"
						/>
						<span class="avia-range-separator">to</span>
						<label for="avia-dashboard-end-date" class="screen-reader-text">End date</label>
						<input
							type="date"
							id="avia-dashboard-end-date"
							name="end_date"
							value="<?php echo esc_attr( $is_custom_range ? $end_date : $default_custom['end_date'] ); ?>"
							max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"
						/>
						<button type="submit" class="button button-secondary">Apply</button>
					</span>
				</form>
			</div>
		</div>

		<?php self::render_dashboard_metrics_section( $local_stats, $has_ads_api ); ?>

		<?php if ( $has_ads_api ) : ?>
			<div class="avia-attribution-panel avia-campaign-panel" id="avia-campaign-performance-panel">
				<div class="avia-panel-heading-row avia-campaign-panel-heading">
					<div>
						<h2>Campaign performance</h2>
						<p class="description" id="avia-campaign-performance-meta">Loading from Google Ads…</p>
						<p class="description" id="avia-campaign-performance-count"></p>
					</div>
					<div class="avia-campaign-status-filter" role="group" aria-label="Campaign status filter">
						<label for="avia-campaign-status-filter" class="screen-reader-text">Show campaigns</label>
						<select id="avia-campaign-status-filter">
							<option value="enabled" selected>Enabled only</option>
							<option value="paused">Paused only</option>
							<option value="all">All campaigns</option>
						</select>
						<button type="button" class="button button-secondary" id="avia-campaign-download-csv" disabled>
							Download Excel (CSV)
						</button>
					</div>
				</div>
				<div id="avia-campaign-performance-body">
					<p class="description">Loading campaign data…</p>
				</div>
			</div>

			<div id="avia-lsa-value-modal" class="avia-modal" hidden aria-hidden="true">
				<div class="avia-modal-backdrop" data-close-lsa-value="1"></div>
				<div class="avia-modal-dialog avia-lsa-value-dialog" role="dialog" aria-modal="true" aria-labelledby="avia-lsa-value-modal-title">
					<div class="avia-modal-header">
						<h2 id="avia-lsa-value-modal-title">Why no conversion value?</h2>
						<button type="button" class="avia-modal-close" data-close-lsa-value="1" aria-label="Close">&times;</button>
					</div>
					<div class="avia-modal-body avia-lsa-value-body">
						<p class="avia-lsa-value-lead">
							<strong>Local Services</strong> campaigns do <strong>not</strong> get real booking / appointment revenue as conversion value — unlike normal Search ads.
						</p>
						<ul class="avia-lsa-value-points">
							<li><strong>Search ads:</strong> conversion value = real order / appointment money (e.g. $500).</li>
							<li><strong>Local Services:</strong> Google often shows about <strong>$1 per charged lead</strong> — that is <em>not</em> your spa revenue.</li>
						</ul>
						<p class="avia-lsa-value-note">
							We hide that fake $1 here so Key metrics and campaign totals stay accurate. For leads, spend, and Local Services details, use Google’s Local Services report.
						</p>
						<p class="avia-lsa-value-actions">
							<a class="button button-primary" href="https://ads.google.com/localservices/reports?cid=3785021515" target="_blank" rel="noopener noreferrer">
								Open Local Services report
							</a>
							<button type="button" class="button button-secondary" data-close-lsa-value="1">Got it</button>
						</p>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="avia-attribution-panel avia-conversions-panel">
			<div class="avia-panel-heading-row">
				<div>
					<h2>Attributed conversions</h2>
					<p class="description avia-conversions-data-note"><b style="background: #e2e7eb;padding: 2px;">Real ads conversions tracking started on OCT 26, 2026</b>. Earlier records may not match Google ADS/Boulevard reports exactly.</p>
					<p class="description">Showing data for <strong><?php echo esc_html( $range_ctx['label'] ); ?></strong>.</p>
				</div>
			</div>
			<p id="avia-conversions-error" class="avia-fail" role="alert" hidden></p>
			<div class="avia-conversions-toolbar">
				<label class="avia-conversions-status-filter" for="avia-conversions-status-filter">
					<span>Status</span>
					<select id="avia-conversions-status-filter">
						<option value="">All</option>
						<option value="uploaded">uploaded</option>
						<option value="merged">merged</option>
						<option value="skipped">skipped</option>
						<option value="pending">pending</option>
						<option value="failed">failed</option>
					</select>
				</label>
			</div>
			<table id="avia-conversions-table" class="widefat striped avia-conversions-table">
				<thead><tr><th>Client</th><th>Click ID</th><th>Campaign</th><th>Value</th><th>Status</th><th>Lead date</th><th>Completed</th><th>Details</th></tr></thead>
				<tbody></tbody>
			</table>
		</div>

		<div id="avia-conversion-modal" class="avia-modal" hidden aria-hidden="true">
			<div class="avia-modal-backdrop" data-close-modal="1"></div>
			<div class="avia-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="avia-conversion-modal-title">
				<div class="avia-modal-header">
					<h2 id="avia-conversion-modal-title">Conversion details</h2>
					<button type="button" class="avia-modal-close" data-close-modal="1" aria-label="Close">&times;</button>
				</div>
				<div class="avia-modal-body" id="avia-conversion-modal-body">
					<p class="description">Loading…</p>
				</div>
			</div>
		</div>

		<details class="avia-attribution-panel avia-collapsible-panel avia-admin-tools-panel">
			<summary>
				<h2>Admin tools</h2>
				<span class="avia-collapse-icon" aria-hidden="true"></span>
			</summary>
			<div class="avia-collapsible-body">
			<div class="avia-action-cards">
				<div class="avia-action-card">
					<div class="avia-action-buttons">
						<button type="button" class="button button-secondary" id="avia-test-google-ads">Test API connection</button>
						<button type="button" class="button button-secondary" id="avia-list-google-ads-accounts">List accessible accounts</button>
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=avia-attribution&tab=settings&avia_google_oauth_start=1' ), 'avia_google_oauth_start' ) ); ?>">
							Reconnect Google Ads
						</a>
					</div>
					<p class="description" style="margin-top:8px;">If Test fails with “expired or revoked”, the OAuth <strong>refresh token</strong> needs reconnect (Google has no permanent tokens).</p>
				</div>
				<div class="avia-action-card avia-action-card-primary<?php echo $can_process_queue ? '' : ' is-disabled'; ?>">
					<div class="avia-action-buttons">
						<button
							type="button"
							class="button button-primary"
							id="avia-upload-google-ads"
							<?php disabled( ! $can_process_queue ); ?>
						>
							Upload pending
						</button>
						<button
							type="button"
							class="button button-secondary"
							id="avia-consolidate-same-click"
							<?php disabled( ! Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ); ?>
						>
							Merge same-click uploads
						</button>
					</div>
					<p class="avia-action-meta">Re-send pending and failed conversion rows. Merge combines duplicate uploaded rows that share a gclid into 1 conversion with summed value (e.g. $50 + $51.50 → $101.50).</p>
					<?php if ( $can_process_queue ) : ?>
						<p class="description"><?php echo esc_html( (string) $pending_count ); ?> row(s) waiting</p>
					<?php endif; ?>
				</div>
				<div class="avia-action-card<?php echo $can_process_queue ? '' : ' is-disabled'; ?>">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="avia-inline-form">
						<?php wp_nonce_field( 'avia_google_ads_export_csv' ); ?>
						<input type="hidden" name="action" value="avia_google_ads_export_csv" />
						<button type="submit" class="button button-secondary" <?php disabled( ! $can_process_queue ); ?>>
							Download CSV
						</button>
					</form>
				</div>
				<div class="avia-action-card">
					<p class="description" style="margin:0 0 8px;"><strong>Void false ads attribution</strong> — staff/organic client wrongly got a Click ID (old unlinked-session bug). Google gets <code>$0</code>; table keeps real order value; Click ID/campaign cleared; CRM <code>avia_*</code> cleared. Use <strong>email</strong> or full <strong>gclid</strong> (when email is missing in the table).</p>
					<div class="avia-action-buttons" style="align-items:center;gap:8px;flex-wrap:wrap;">
						<input type="text" id="avia-void-false-email" class="regular-text" placeholder="email@… or paste gclid" style="max-width:360px;" autocomplete="off" />
						<button type="button" class="button button-secondary" id="avia-void-false-attribution">
							Void by email / gclid
						</button>
						<button type="button" class="button button-secondary" id="avia-void-false-attribution-auto" <?php disabled( ! Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ); ?>>
							Auto-void all false (pre–Oct 15)
						</button>
					</div>
				</div>
			</div>
			<div id="avia-google-ads-result" class="avia-result" aria-live="polite"></div>
			</div>
		</details>

		<details class="avia-attribution-panel avia-collapsible-panel avia-offline-guide-panel">
			<summary>
				<h2>How to upload offline conversions manually</h2>
				<span class="avia-collapse-icon" aria-hidden="true"></span>
			</summary>
			<div class="avia-collapsible-body">
			<p class="description">
				Use automatic upload when possible. Manual CSV is the backup if auto-upload is off or a row failed.
				Google may take 30 minutes to 24 hours to process uploads before they appear in conversion reports.
			</p>
			<div class="avia-guide-grid">
				<div class="avia-guide-block">
					<h3>Option A — Automatic (recommended)</h3>
					<ol>
						<li>Save Google Ads OAuth credentials in the <strong>Settings</strong> tab (both <code>adwords</code> and <code>datamanager</code> scopes).</li>
						<li>Set Customer ID, Login customer ID (MCC), and Conversion action ID.</li>
						<li>Enable <strong>Auto-upload</strong> and save settings.</li>
						<li>When Boulevard sends <code>APPOINTMENT_COMPLETED</code>, the plugin queues and uploads the conversion.</li>
						<li>Use <strong>Upload</strong> to re-send any pending or failed rows.</li>
					</ol>
					<p class="description">Plugin API uploads go to <strong>Appointment Completed (Primary)</strong> (<code>7766637097</code>).</p>
				</div>
				<div class="avia-guide-block avia-guide-block-wide">
					<h3>Option B — CSV via Data Manager (manual backup)</h3>
					<p class="description">
						Google retired the old <strong>Goals → Conversions → Uploads</strong> page — it now redirects to
						<a href="https://ads.google.com/aw/datamanager" target="_blank" rel="noopener noreferrer">Tools → Data manager</a>.
						Do not use Bulk upload templates (Campaign, Ad group, Keyword).
					</p>
					<h4>1. Prepare the file</h4>
					<ol>
						<li>Click <strong>Download CSV</strong> in this tab, or start from <code>samples/google-ads-offline-demo.csv</code>.</li>
						<li>CSV columns: <code>Conversion action</code>, <code>GCLID</code>, <code>Conversion date and time</code>, <code>Conversion value</code>, <code>Currency code</code>, <code>Order ID</code>, <code>Event source</code> (<code>web</code>).</li>
						<li>Use a real gclid from an ad click. Test/fake gclids upload but will not match in reports.</li>
						<li>Import the CSV into <strong>Google Sheets</strong> (File → Import → Upload).</li>
					</ol>
					<h4>2. Connect Google Sheets in Data Manager</h4>
					<ol>
						<li><strong>Tools → Data manager</strong> → <strong>+ Connect product</strong>.</li>
						<li><strong>Google Sheets</strong> → Direct connection → Use case: <strong>Conversions</strong> → accept customer data policy.</li>
						<li>Authorize and select your sheet → <strong>Next</strong>.</li>
					</ol>
					<h4>3. Map fields (wizard)</h4>
					<ul class="avia-guide-mapping">
						<li><strong>Event information</strong> — accept suggestions: Conversion date/time, Transaction ID (Order ID), Event source.</li>
						<li><strong>Attribution details</strong> — map <code>GCLID → GCLID</code>. Leave Email and Phone unmapped (GCLID-only upload).</li>
						<li><strong>Measurement Enhancement</strong> — clear any auto-map of GCLID to GBRAID or WBRAID. Leave IP address unmapped.</li>
						<li><strong>Conversion value</strong> — map Conversion value and Currency code.</li>
						<li><strong>Consent</strong> — leave unmapped; set account-level consent in Consent settings (step 5).</li>
						<li><strong>User agent / Session attributes</strong> — leave unmapped.</li>
					</ul>
					<h4>4. Review and finish connection</h4>
					<ol>
						<li>Selected data: <strong>All records</strong> from your sheet is fine for a dedicated conversion file.</li>
						<li>Update schedule: daily is OK for a live sheet; use manual/once for a one-time demo test.</li>
						<li>Click <strong>Finish</strong>.</li>
					</ol>
					<h4>5. Link conversion action and consent</h4>
					<ol>
						<li>On the connection row → <strong>Usage</strong> → <strong>+ Add conversion action</strong>.</li>
						<li>Conversion goal: <strong>Converted lead</strong> (for <code>APPOINTMENT_COMPLETED</code>). Do not use Book appointment.</li>
						<li>Event: <strong>All records from [your sheet]</strong> → <strong>Add</strong> → <strong>Save and continue</strong>.</li>
						<li>
							<a href="https://ads.google.com/aw/datamanager/consentsettings" target="_blank" rel="noopener noreferrer">Consent settings</a>
							→ mark imported/uploaded data as consented.
						</li>
					</ol>
					<p class="description">
						This creates a <strong>new</strong> conversion action under <strong>Converted lead</strong> (named after your sheet).
						It is separate from <strong>Appointment Completed (Primary)</strong>, which the plugin API uses for production uploads.
					</p>
				</div>
			</div>
			<p class="description avia-guide-note">
				<strong>Note:</strong> “Uploaded” in the queue means Google accepted the API request. Conversions may still show
				<code>0.00</code> until processing completes (30 minutes to 24 hours). Sheets imports run on the connection schedule until you trigger a manual run.
			</p>
			</div>
		</details>
		<?php
	}

	public static function ajax_upload_google_ads() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Google Ads OAuth credentials, customer ID, and conversion action ID first.' ) );
		}

		$results = Avia_Attribution_Google_Ads_Offline::process_pending_uploads( 25 );
		if ( empty( $results ) ) {
			wp_send_json_success(
				array(
					'message' => 'No pending uploads.',
					'results' => array(),
				)
			);
		}

		$success_count = 0;
		foreach ( $results as $row ) {
			if ( ! empty( $row['success'] ) ) {
				++$success_count;
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					'Processed %1$d pending upload(s). %2$d succeeded.',
					count( $results ),
					$success_count
				),
				'results' => $results,
			)
		);
	}

	public static function ajax_void_false_attribution() {
		self::verify_ajax();

		$raw = isset( $_POST['email'] ) ? trim( wp_unslash( $_POST['email'] ) ) : '';
		if ( '' === $raw ) {
			wp_send_json_error( array( 'message' => 'Enter a client email or gclid.' ) );
		}

		if ( ! Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			wp_send_json_error( array( 'message' => 'Google Ads upload credentials required to restate $0 on uploaded rows.' ) );
		}

		$email = sanitize_email( $raw );
		if ( $email && is_email( $email ) ) {
			$result  = Avia_Attribution_Google_Ads_Offline::void_false_attribution_by_email( $email );
			$label   = $email;
		} else {
			$result = Avia_Attribution_Google_Ads_Offline::void_false_attribution_by_gclid( $raw );
			$label  = 'gclid ' . ( strlen( $raw ) > 24 ? ( substr( $raw, 0, 12 ) . '…' . substr( $raw, -8 ) ) : $raw );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$message = sprintf(
			'Voided %1$s: %2$d row(s) skipped (order value kept), %3$d restated to $0 on Google, %4$d session(s) unlinked, %5$d CRM cleared.',
			$label,
			(int) ( $result['marked_skipped'] ?? 0 ),
			(int) ( $result['restated_zero'] ?? 0 ),
			(int) ( $result['sessions_unlinked'] ?? 0 ),
			(int) ( $result['crm_cleared'] ?? 0 )
		);
		if ( ! empty( $result['errors'] ) ) {
			$message .= ' Errors: ' . implode( ' | ', array_map( 'strval', $result['errors'] ) );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'result'  => $result,
			)
		);
	}

	public static function ajax_void_false_attribution_auto() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			wp_send_json_error( array( 'message' => 'Google Ads upload credentials required.' ) );
		}

		$result  = Avia_Attribution_Google_Ads_Offline::void_false_attribution_auto( 40 );
		$emails  = array_filter( array_map( 'strval', (array) ( $result['emails'] ?? array() ) ) );
		$message = sprintf(
			'Auto-void: %1$d candidate client(s), %2$d voided, %3$d already-voided row(s) repaired (value restored / click cleared).',
			(int) ( $result['candidates'] ?? 0 ),
			(int) ( $result['voided'] ?? 0 ),
			(int) ( $result['repaired_voided_rows'] ?? 0 )
		);
		if ( ! empty( $emails ) ) {
			$message .= ' Emails: ' . implode( ', ', $emails ) . '.';
		}
		if ( ! empty( $result['errors'] ) ) {
			$message .= ' Errors: ' . implode( ' | ', array_map( 'strval', $result['errors'] ) );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'result'  => $result,
			)
		);
	}

	public static function ajax_consolidate_same_click() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Google_Data_Manager_Api::has_upload_credentials() ) {
			wp_send_json_error( array( 'message' => 'Save Google Ads OAuth credentials, customer ID, and conversion action ID first.' ) );
		}

		$result = Avia_Attribution_Google_Ads_Offline::consolidate_same_click_uploaded_duplicates( 50 );
		$repair = Avia_Attribution_Google_Ads_Offline::repair_inflated_same_click_merged_values( 50 );
		$message = sprintf(
			'Merged %1$d same-click group(s): %2$d restated, %3$d secondary row(s) skipped.',
			(int) ( $result['groups'] ?? 0 ),
			(int) ( $result['restated'] ?? 0 ),
			(int) ( $result['skipped'] ?? 0 )
		);
		if ( ! empty( $repair['repaired'] ) ) {
			$message .= sprintf(
				' Repaired %1$d inflated merge value(s) from ledger (%2$d restated to Google).',
				(int) $repair['repaired'],
				(int) ( $repair['restated'] ?? 0 )
			);
			foreach ( (array) ( $repair['details'] ?? array() ) as $detail ) {
				$message .= sprintf(
					' #%1$d: $%2$s → $%3$s.',
					(int) ( $detail['upload_id'] ?? 0 ),
					number_format( (float) ( $detail['from'] ?? 0 ), 2 ),
					number_format( (float) ( $detail['to'] ?? 0 ), 2 )
				);
			}
		}
		if ( empty( $result['groups'] ) ) {
			$diag = $result['diagnostics'] ?? array();
			$message .= sprintf(
				' No true full-gclid duplicates to fold (plugin %1$s; uploaded-with-click: %2$d; SQL duplicate groups: %3$d).',
				(string) ( $diag['plugin_version'] ?? AVIA_ATTRIBUTION_VERSION ),
				(int) ( $diag['uploaded_with_click'] ?? 0 ),
				(int) ( $diag['duplicate_groups_found'] ?? 0 )
			);
			if ( ! empty( $diag['truncation_lookalike_rows'] ) ) {
				$message .= sprintf(
					' Note: %1$d row(s) in %2$d bucket(s) only look the same because Click ID was truncated in the table — full IDs differ (hard-refresh / View details).',
					(int) $diag['truncation_lookalike_rows'],
					(int) ( $diag['truncation_lookalike_buckets'] ?? 0 )
				);
			} else {
				$message .= ' If the table still shows identical Click IDs, hard-refresh the admin page (cached JS) or open View details and compare the full Click ID.';
			}
		}
		if ( ! empty( $result['errors'] ) ) {
			$message .= ' Errors: ' . implode( ' | ', array_map( 'strval', $result['errors'] ) );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'result'  => $result,
				'repair'  => $repair,
			)
		);
	}

	public static function ajax_test_google_ads() {
		self::verify_ajax();

		$result = Avia_Attribution_Google_Ads_Api::test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$customer_label = Avia_Attribution_Google_Ads_Api::format_customer_id_display( $result['customer_id'] );
		if ( ! empty( $result['customer_name'] ) ) {
			$customer_label = $result['customer_name'] . ' (' . $customer_label . ')';
		}

		$message = 'Google Ads API connection succeeded for ' . $customer_label . '.';
		if ( ! empty( $result['data_manager'] ) ) {
			if ( ! empty( $result['data_manager']['ok'] ) ) {
				$message .= ' ' . $result['data_manager']['message'];
			} else {
				$message .= ' Data Manager check failed: ' . $result['data_manager']['message'];
			}
		}

		wp_send_json_success(
			array(
				'message'       => $message,
				'customer_id'   => $result['customer_id'],
				'customer_name' => $result['customer_name'] ?? '',
				'data_manager'  => $result['data_manager'] ?? null,
			)
		);
	}

	public static function ajax_campaign_conversions() {
		self::verify_ajax();
		if ( ! Avia_Attribution_Google_Ads_Api::has_read_credentials() ) {
			wp_send_json_error( array( 'message' => 'Google Ads API credentials are not configured.' ) );
		}
		$result = Avia_Attribution_Google_Ads_Api::get_campaign_conversion_details(
			isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '',
			isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'last_7_days',
			isset( $_POST['start_date'] ) ? wp_unslash( $_POST['start_date'] ) : '',
			isset( $_POST['end_date'] ) ? wp_unslash( $_POST['end_date'] ) : ''
		);
		if ( is_wp_error( $result ) ) {
			$reconnect = Avia_Attribution_Google_Ads_Api::error_needs_reconnect( $result );
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'needs_reconnect' => $reconnect, 'reconnect_url' => $reconnect ? Avia_Attribution_Google_Ads_Api::get_reconnect_url() : '' ) );
		}
		wp_send_json_success( array( 'details' => $result ) );
	}

	public static function ajax_boulevard_revenue() {
		self::verify_ajax();
		$result = Avia_Attribution_Boulevard_Api::get_net_sales(
			isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'last_7_days',
			isset( $_POST['start_date'] ) ? wp_unslash( $_POST['start_date'] ) : '',
			isset( $_POST['end_date'] ) ? wp_unslash( $_POST['end_date'] ) : ''
		);
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( $result );
	}

	public static function ajax_boulevard_appointments() {
		self::verify_ajax();
		$range_key  = isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'last_7_days';
		$start_date = isset( $_POST['start_date'] ) ? wp_unslash( $_POST['start_date'] ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? wp_unslash( $_POST['end_date'] ) : '';
		$result = Avia_Attribution_Boulevard_Api::get_completed_appointments( $range_key, $start_date, $end_date );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_dashboard_ads_performance() {
		self::verify_ajax();

		if ( ! Avia_Attribution_Google_Ads_Api::has_read_credentials() ) {
			wp_send_json_error(
				array(
					'message'         => 'Google Ads API credentials are not configured.',
					'needs_reconnect' => true,
					'reconnect_url'   => Avia_Attribution_Google_Ads_Api::get_reconnect_url(),
				)
			);
		}

		$range      = isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'last_7_days';
		$start_date = isset( $_POST['start_date'] ) ? wp_unslash( $_POST['start_date'] ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? wp_unslash( $_POST['end_date'] ) : '';

		$campaign_ids = Avia_Attribution_Google_Ads_Api::sanitize_campaign_ids( isset( $_POST['campaign_ids'] ) ? wp_unslash( $_POST['campaign_ids'] ) : array() );
		$account = Avia_Attribution_Google_Ads_Api::get_account_performance( $range, $start_date, $end_date, $campaign_ids );
		if ( is_wp_error( $account ) ) {
			$needs_reconnect = Avia_Attribution_Google_Ads_Api::error_needs_reconnect( $account );
			wp_send_json_error(
				array(
					'message'         => $account->get_error_message(),
					'needs_reconnect' => $needs_reconnect,
					'reconnect_url'   => $needs_reconnect ? Avia_Attribution_Google_Ads_Api::get_reconnect_url() : '',
				)
			);
		}

		$campaigns = Avia_Attribution_Google_Ads_Api::get_campaign_performance( $range, $start_date, $end_date, $campaign_ids );
		if ( is_wp_error( $campaigns ) ) {
			$needs_reconnect = Avia_Attribution_Google_Ads_Api::error_needs_reconnect( $campaigns );
			wp_send_json_error(
				array(
					'message'         => $campaigns->get_error_message(),
					'needs_reconnect' => $needs_reconnect,
					'reconnect_url'   => $needs_reconnect ? Avia_Attribution_Google_Ads_Api::get_reconnect_url() : '',
				)
			);
		}

		wp_send_json_success(
			array(
				'account'   => $account,
				'campaigns' => $campaigns,
				'campaign_options' => ( $options = Avia_Attribution_Google_Ads_Api::get_campaign_filter_options() ) && ! is_wp_error( $options ) ? $options : array(),
			)
		);
	}

	public static function ajax_conversions() {
		self::verify_ajax();
		$range = Avia_Attribution_Dashboard::resolve_range(
			isset( $_POST['range'] ) ? wp_unslash( $_POST['range'] ) : 'last_7_days',
			isset( $_POST['start_date'] ) ? wp_unslash( $_POST['start_date'] ) : '',
			isset( $_POST['end_date'] ) ? wp_unslash( $_POST['end_date'] ) : ''
		);
		$status = isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : '';
		$result = Avia_Attribution_Dashboard::get_uploads_enriched(
			1,
			-1,
			$range['key'],
			$range['start_date'],
			$range['end_date'],
			'',
			$status
		);
		wp_send_json_success( array( 'rows' => $result['rows'], 'range' => $range, 'status' => $result['status'] ?? '' ) );
	}

	public static function ajax_conversion_details() {
		self::verify_ajax();

		$upload_id = isset( $_POST['upload_id'] ) ? (int) wp_unslash( $_POST['upload_id'] ) : 0;
		$details   = Avia_Attribution_Dashboard::get_conversion_details( $upload_id );
		if ( is_wp_error( $details ) ) {
			wp_send_json_error( array( 'message' => $details->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'details' => $details,
			)
		);
	}

	public static function ajax_save_dashboard_metrics() {
		self::verify_ajax();

		if ( ! empty( $_POST['reset'] ) ) {
			$layout = Avia_Attribution_Dashboard::save_user_metric_layout(
				Avia_Attribution_Dashboard::get_default_metric_layout()
			);
			wp_send_json_success(
				array(
					'message' => 'Metric layout reset to defaults.',
					'layout'  => Avia_Attribution_Dashboard::get_metric_layout_for_js(),
				)
			);
		}

		$raw = isset( $_POST['layout'] ) ? wp_unslash( $_POST['layout'] ) : '';
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		$layout = Avia_Attribution_Dashboard::save_user_metric_layout( is_array( $raw ) ? $raw : array() );
		wp_send_json_success(
			array(
				'message' => 'Metric preferences saved.',
				'layout'  => Avia_Attribution_Dashboard::get_metric_layout_for_js(),
			)
		);
	}

	private static function render_dashboard_metrics_section( array $local_stats, $has_ads_api ) {
		$definitions  = Avia_Attribution_Dashboard::get_metric_definitions();
		$layout       = Avia_Attribution_Dashboard::get_user_metric_layout();
		$local_values = Avia_Attribution_Dashboard::get_local_metric_values( $local_stats );
		?>
		<div class="avia-dashboard-metrics-section">
			<div class="avia-metrics-section-header">
				<h3 class="avia-metrics-title">
					<span>Key metrics</span>
					<button
						type="button"
						class="avia-metrics-help-trigger"
						id="avia-metrics-help-trigger"
						aria-label="What do these metrics mean?"
						title="What do these metrics mean?"
					>
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					</button>
				</h3>
				<div class="avia-customize-metrics-wrap">
					<button type="button" class="button avia-customize-metrics-toggle" id="avia-customize-metrics-toggle" aria-expanded="false" aria-controls="avia-customize-metrics-panel">
						<span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
						Customize metrics
					</button>
					<div id="avia-customize-metrics-panel" class="avia-customize-metrics-panel" hidden>
						<div class="avia-customize-metrics-panel-header">
							<strong>Visible metrics &amp; order</strong>
							<button type="button" class="button-link avia-customize-metrics-reset" id="avia-customize-metrics-reset">
								<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
								Reset
							</button>
						</div>
						<p class="description" style="margin:0 0 8px;">Layout auto-balances cards — max <?php echo (int) Avia_Attribution_Dashboard::MAX_METRICS_PER_ROW; ?> per row.</p>
						<ul class="avia-customize-metrics-list" id="avia-customize-metrics-list" aria-label="Dashboard metrics"></ul>
					</div>
				</div>
			</div>

			<?php
			$sync_through = wp_date( 'M j, Y g:i A', current_time( 'timestamp' ) - ( 12 * HOUR_IN_SECONDS ) );
			?>
			<p class="description avia-metrics-sync-note" id="avia-metrics-sync-note">
				Google Ads conversion data below is synced through <?php echo esc_html( $sync_through ); ?>. Updates can take several hours to appear.
			</p>

			<?php if ( ! $has_ads_api ) : ?>
				<div class="avia-dashboard-notice avia-dashboard-notice-muted">
					<p><strong>Google Ads performance unavailable.</strong> Save OAuth credentials and Customer ID in Settings to load spend, ROAS, and campaign metrics.</p>
				</div>
			<?php endif; ?>

			<div class="avia-dashboard-cards" id="avia-dashboard-metrics">
				<?php foreach ( $layout as $item ) : ?>
					<?php
					$metric_id = $item['id'] ?? '';
					if ( ! isset( $definitions[ $metric_id ] ) ) {
						continue;
					}

					$definition = $definitions[ $metric_id ];
					$is_visible = ! empty( $item['visible'] );
					$is_ads     = 'ads' === $definition['group'];
					$card_class = 'avia-dashboard-card avia-metric-card';
					if ( ! empty( $definition['highlight'] ) ) {
						$card_class .= ' avia-dashboard-card-highlight';
					}
					if ( $is_ads && $has_ads_api ) {
						$card_class .= ' avia-dashboard-card-loading';
					}
					if ( ! $is_visible ) {
						$card_class .= ' is-metric-hidden';
					}

					$value = '—';
					if ( ! $is_ads && isset( $local_values[ $metric_id ] ) ) {
						$value = $local_values[ $metric_id ];
					}
					?>
					<div
						class="<?php echo esc_attr( $card_class ); ?>"
						data-metric-id="<?php echo esc_attr( $metric_id ); ?>"
						data-metric-group="<?php echo esc_attr( $definition['group'] ); ?>"
						data-metric-visible="<?php echo $is_visible ? '1' : '0'; ?>"
						style="<?php echo esc_attr( 'order:' . (int) ( $item['order'] ?? 0 ) ); ?>"
						<?php echo $is_visible ? '' : 'hidden'; ?>
					>
						<span class="avia-card-label"><?php echo esc_html( $definition['label'] ); ?></span>
						<span class="avia-card-value" data-metric="<?php echo esc_attr( $metric_id ); ?>"><?php echo esc_html( $value ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div id="avia-metrics-help-modal" class="avia-modal" hidden aria-hidden="true">
				<div class="avia-modal-backdrop" data-close-metrics-help="1"></div>
				<div class="avia-modal-dialog avia-metrics-help-dialog" role="dialog" aria-modal="true" aria-labelledby="avia-metrics-help-modal-title">
					<div class="avia-modal-header">
						<h2 id="avia-metrics-help-modal-title">Key metrics guide</h2>
						<button type="button" class="avia-modal-close" data-close-metrics-help="1" aria-label="Close">&times;</button>
					</div>
					<div class="avia-modal-body avia-metrics-help-body">
						<p class="description avia-metrics-help-intro">
							Numbers respect the dashboard date range. <strong>Google Ads</strong> cards come from your Ads account;
							<strong>local</strong> cards come from the AVIA attribution plugin and Boulevard webhooks.
						</p>
						<div class="avia-metrics-help-groups">
							<section class="avia-modal-section">
								<h3>Google Ads (account)</h3>
								<dl class="avia-metrics-help-list">
									<?php foreach ( $definitions as $metric_id => $definition ) : ?>
										<?php if ( 'ads' !== ( $definition['group'] ?? '' ) ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<div class="avia-metrics-help-item">
											<dt><?php echo esc_html( $definition['label'] ); ?></dt>
											<dd><?php echo esc_html( $definition['help'] ?? '' ); ?></dd>
										</div>
									<?php endforeach; ?>
								</dl>
							</section>
							<section class="avia-modal-section">
								<h3>Boulevard and attribution plugin</h3>
								<dl class="avia-metrics-help-list">
									<?php foreach ( $definitions as $metric_id => $definition ) : ?>
										<?php if ( 'ads' === ( $definition['group'] ?? '' ) ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<div class="avia-metrics-help-item">
											<dt><?php echo esc_html( $definition['label'] ); ?></dt>
											<dd><?php echo esc_html( $definition['help'] ?? '' ); ?></dd>
										</div>
									<?php endforeach; ?>
								</dl>
							</section>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function ajax_list_google_ads_accounts() {
		self::verify_ajax();

		$accounts = Avia_Attribution_Google_Ads_Api::list_accessible_customers_with_details();
		if ( is_wp_error( $accounts ) ) {
			wp_send_json_error( array( 'message' => $accounts->get_error_message() ) );
		}

		if ( empty( $accounts ) ) {
			wp_send_json_error(
				array(
					'message' => 'No accessible Google Ads accounts returned for this OAuth user.',
				)
			);
		}

		wp_send_json_success(
			array(
				'message'   => 'OAuth user can access these accounts. Use your manager/MCC ID as Login customer ID when uploading to a client account.',
				'accounts'  => $accounts,
				'customers' => wp_list_pluck( $accounts, 'id' ),
			)
		);
	}

	private static function truncate_middle( $value, $max = 18 ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $max ) {
			return $value;
		}

		$keep = max( 4, (int) floor( ( $max - 1 ) / 2 ) );

		return substr( $value, 0, $keep ) . '…' . substr( $value, -$keep );
	}

	/**
	 * Render Boulevard CRM tags as compact pills (Approach A — display only).
	 *
	 * @param array $tags Normalized tags [{id,name,symbol}, …].
	 * @return string Safe HTML.
	 */
	private static function render_client_tags_html( $tags ) {
		$tags = Avia_Attribution_Boulevard_Api::normalize_client_tags( $tags );
		if ( empty( $tags ) ) {
			return '';
		}

		$html = '<div class="avia-client-tags">';
		foreach ( $tags as $tag ) {
			$label = $tag['name'];
			if ( ! empty( $tag['symbol'] ) ) {
				$label = $tag['symbol'] . ' ' . $label;
			}
			$html .= sprintf(
				'<span class="avia-client-tag">%s</span>',
				esc_html( $label )
			);
		}
		$html .= '</div>';

		return $html;
	}

	private static function render_upload_status_badge( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = array(
			'uploaded' => 'Uploaded',
			'pending'  => 'Pending',
			'failed'   => 'Failed',
			'skipped'  => 'Skipped',
		);
		$label  = $labels[ $status ] ?? ucfirst( $status );

		return sprintf(
			'<span class="avia-status-badge avia-status-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	private static function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}
		check_ajax_referer( 'avia_attribution_admin', 'nonce' );
	}
}
