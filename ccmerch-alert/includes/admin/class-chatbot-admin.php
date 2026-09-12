<?php
/**
 * Chatbot admin page.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Chatbot\Chatbot_Health_Monitor;
use CCMerchAlert\Chatbot\Chatbot_Scope_Manager;
use CCMerchAlert\Chatbot\Chatbot_Settings_Service;
use CCMerchAlert\Chatbot\Chatbot_Token_Manager;
use CCMerchAlert\Chatbot\Creator_Channel_Auth_Service;
use CCMerchAlert\Chatbot\Twitch_Auth_Service;
use CCMerchAlert\Chatbot\Twitch_Chat_Service;
use CCMerchAlert\Creators\Creator_Repository;
use CCMerchAlert\UI\Asset_Loader;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the CCMerch Alert → Chatbot admin submenu page.
 *
 * Three cards:
 *  1. Bot Connection — Twitch app credentials form + "Save & Connect" button.
 *  2. Creator Channel Mapping — assign each creator to a Twitch channel.
 *  3. Chatbot Diagnostics — live token validation, per-channel last-send status,
 *     broadcaster/sender IDs, last error, and a per-creator Test Send button.
 *
 * Must never: output non-admin HTML.
 * Must never: write to the database directly.
 * Must never: contain business logic.
 */
final class Chatbot_Admin {

	/** @var string Page slug. */
	public const PAGE = 'ccmerch-alert-chatbot';

	/** @var string Nonce action shared by all admin forms and AJAX endpoints on this page. */
	public const NONCE_ACTION = 'ccmerch_chatbot_admin';

	/** @var string AJAX action for per-creator test message send. */
	public const AJAX_TEST_SEND = 'ccmerch_chatbot_test_send';

	/** @var string admin_post_ action — save Client ID + Secret and redirect to OAuth. */
	private const ACTION_SAVE_CREDENTIALS = 'ccmerch_chatbot_save_credentials';

	/** @var string admin_post_ action — disconnect the bot account. */
	private const ACTION_DISCONNECT = 'ccmerch_chatbot_disconnect';

	/** @var string admin_post_ action — save creator → channel mapping. */
	private const ACTION_SAVE_CHANNEL = 'ccmerch_chatbot_save_channels';

	/** @var string admin_post_ action — save optional OAuth scope config. */
	private const ACTION_SAVE_SCOPES = 'ccmerch_chatbot_save_scopes';

	/**
	 * @param Twitch_Auth_Service              $auth_service         Token and credential store.
	 * @param Chatbot_Settings_Service         $settings_service     Creator chatbot settings.
	 * @param Creator_Repository               $creator_repo         All creator profiles for the mapping table.
	 * @param Twitch_Chat_Service              $chat_service         Message sending and diagnostics.
	 * @param Chatbot_Health_Monitor|null      $health_monitor       Health metrics reader for Bot Runtime Status card.
	 * @param Chatbot_Token_Manager|null       $token_manager        Token validation for Bot Runtime Status card.
	 * @param Creator_Channel_Auth_Service|null $channel_auth_service Channel authorization overview.
	 */
	public function __construct(
		private readonly Twitch_Auth_Service               $auth_service,
		private readonly Chatbot_Settings_Service          $settings_service,
		private readonly Creator_Repository                $creator_repo,
		private readonly Twitch_Chat_Service               $chat_service,
		private readonly ?Chatbot_Health_Monitor           $health_monitor      = null,
		private readonly ?Chatbot_Token_Manager            $token_manager       = null,
		private readonly ?Creator_Channel_Auth_Service     $channel_auth_service = null,
	) {
		add_action( 'admin_menu',            [ $this, 'register_menu'          ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'         ] );
		add_action( 'admin_post_' . self::ACTION_SAVE_CREDENTIALS, [ $this, 'handle_save_credentials' ] );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT,       [ $this, 'handle_disconnect'       ] );
		add_action( 'admin_post_' . self::ACTION_SAVE_CHANNEL,     [ $this, 'handle_save_channels'    ] );
		add_action( 'admin_post_' . self::ACTION_SAVE_SCOPES,      [ $this, 'handle_save_scopes'      ] );
		add_action( 'wp_ajax_' . self::AJAX_TEST_SEND,             [ $this, 'handle_test_send'        ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the Chatbot submenu under the CCMerch Alert top-level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'ccmerch-alert',
			__( 'Chatbot', 'ccmerch-alert' ),
			__( 'Chatbot', 'ccmerch-alert' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues admin assets on the Chatbot page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === \strpos( $hook_suffix, self::PAGE ) ) {
			return;
		}
		Asset_Loader::enqueue_chatbot_admin();
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the full Chatbot admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_connected = $this->auth_service->is_connected();
		$app_creds    = $this->auth_service->get_app_credentials();
		$client_id    = (string) ( $app_creds['client_id'] ?? '' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( $_GET['ccmerch_notice'] ?? '' );
		// phpcs:enable

		$creators     = $this->creator_repo->find_all();
		$all_settings = $this->settings_service->get_all_settings();
		?>
		<div class="wrap">

			<h1><?php esc_html_e( 'Chatbot', 'ccmerch-alert' ); ?></h1>

			<?php $this->render_notices( $notice ); ?>

			<!-- ── Card 1: Bot Connection ─────────────────────────────────── -->
			<div class="ccmerch-card ccmerch-chatbot-card" style="max-width:860px;margin-top:20px;">
				<div class="ccmerch-card__header">
					<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Bot Connection', 'ccmerch-alert' ); ?></strong>
				</div>
				<div class="ccmerch-chatbot-card__body">

					<?php if ( $is_connected ) : ?>
					<p>
						<span class="ccmerch-chatbot-status ccmerch-chatbot-status--connected">
							&#10003; <?php esc_html_e( 'Connected', 'ccmerch-alert' ); ?>
						</span>
					</p>
					<p class="description"><?php esc_html_e( 'The bot account is connected and ready to send messages.', 'ccmerch-alert' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISCONNECT ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<button type="submit" class="button"
							onclick="return confirm( <?php echo esc_js( __( 'Disconnect the bot account? The bot will stop sending messages until reconnected.', 'ccmerch-alert' ) ); ?> );">
							<?php esc_html_e( 'Disconnect', 'ccmerch-alert' ); ?>
						</button>
					</form>
					<?php else : ?>
					<p>
						<span class="ccmerch-chatbot-status ccmerch-chatbot-status--disconnected">
							&#9679; <?php esc_html_e( 'Not connected', 'ccmerch-alert' ); ?>
						</span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your Twitch application credentials below and click "Save & Connect" to authorize the bot account.', 'ccmerch-alert' ); ?>
						<br>
						<?php
						printf(
							/* translators: %s: URL to Twitch developer console */
							esc_html__( 'Create an application at %s and set the OAuth Redirect URL to the address shown below.', 'ccmerch-alert' ),
							'<a href="https://dev.twitch.tv/console/apps" target="_blank" rel="noopener noreferrer">dev.twitch.tv/console/apps</a>'
						);
						?>
					</p>
					<p class="description" style="margin-top:6px;">
						<strong><?php esc_html_e( 'Redirect URL:', 'ccmerch-alert' ); ?></strong>
						<code><?php echo esc_html( $this->auth_service->get_redirect_uri() ); ?></code>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_CREDENTIALS ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<table class="form-table" style="max-width:600px;">
							<tr>
								<th scope="row"><label for="ccmerch-chatbot-client-id"><?php esc_html_e( 'Client ID', 'ccmerch-alert' ); ?></label></th>
								<td>
									<input type="text" id="ccmerch-chatbot-client-id" name="client_id"
										value="<?php echo esc_attr( $client_id ); ?>"
										class="regular-text" autocomplete="off">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="ccmerch-chatbot-client-secret"><?php esc_html_e( 'Client Secret', 'ccmerch-alert' ); ?></label></th>
								<td>
									<input type="password" id="ccmerch-chatbot-client-secret" name="client_secret"
										value="" class="regular-text" autocomplete="new-password"
										placeholder="<?php esc_attr_e( 'Leave blank to keep existing secret', 'ccmerch-alert' ); ?>">
								</td>
							</tr>
						</table>
						<p>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Save & Connect with Twitch', 'ccmerch-alert' ); ?>
							</button>
						</p>
					</form>
					<?php endif; ?>

				</div>
			</div>

			<!-- ── Card 2: Creator Channel Mapping ────────────────────────── -->
			<div class="ccmerch-card ccmerch-chatbot-card" style="max-width:860px;margin-top:20px;">
				<div class="ccmerch-card__header">
					<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Creator Channel Mapping', 'ccmerch-alert' ); ?></strong>
				</div>
				<div class="ccmerch-chatbot-card__body">

					<?php if ( empty( $creators ) ) : ?>
					<p class="description"><?php esc_html_e( 'No creator profiles found. Add creators first.', 'ccmerch-alert' ); ?></p>
					<?php else : ?>
					<p class="description"><?php esc_html_e( 'Assign a Twitch channel to each creator. Leave blank to disable chatbot for that creator.', 'ccmerch-alert' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_CHANNEL ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<table class="widefat striped ccmerch-chatbot-mapping-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Creator', 'ccmerch-alert' ); ?></th>
									<th><?php esc_html_e( 'Twitch Channel', 'ccmerch-alert' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $creators as $creator ) :
									$row     = $all_settings[ $creator->id ] ?? null;
									$channel = $row ? $row->twitch_channel : '';
								?>
								<tr>
									<td><?php echo esc_html( $creator->creator_name ); ?></td>
									<td>
										<input
											type="text"
											name="channels[<?php echo esc_attr( (string) $creator->id ); ?>]"
											value="<?php echo esc_attr( $channel ); ?>"
											class="regular-text"
											placeholder="<?php esc_attr_e( 'channel_name', 'ccmerch-alert' ); ?>"
										>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p style="margin-top:16px;">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Save Channel Mapping', 'ccmerch-alert' ); ?>
							</button>
						</p>
					</form>
					<?php endif; ?>

				</div>
			</div>

			<!-- ── Card 3: Chatbot Diagnostics ───────────────────────────── -->
			<?php $this->render_diagnostics_card( $creators, $all_settings ); ?>

			<!-- ── Card 4: Bot Runtime Status ────────────────────────────── -->
			<?php $this->render_runtime_status_card(); ?>

			<!-- ── Card 5: Channel Authorization Overview ───────────────── -->
			<?php $this->render_channel_auth_card( $creators ); ?>

			<!-- ── Card 6: OAuth Scopes ─────────────────────────────────── -->
			<?php $this->render_oauth_scopes_card(); ?>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// admin_post_ handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles the "Save & Connect" form submission.
	 *
	 * @return void
	 */
	public function handle_save_credentials(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ccmerch-alert' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$client_id     = sanitize_text_field( wp_unslash( $_POST['client_id']     ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		// phpcs:enable

		$existing = $this->auth_service->get_app_credentials();

		if ( '' === $client_secret && ! empty( $existing['client_secret'] ) ) {
			$client_secret = $existing['client_secret'];
		}

		if ( '' !== $client_id ) {
			$this->auth_service->store_app_credentials( $client_id, $client_secret );
		}

		$auth_url = $this->auth_service->get_auth_url();
		if ( '' === $auth_url ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ccmerch_notice=chatbot_missing_credentials' ) );
			exit;
		}

		wp_redirect( $auth_url );
		exit;
	}

	/**
	 * Handles the "Disconnect" form submission.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ccmerch-alert' ) );
		}

		$this->auth_service->disconnect();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ccmerch_notice=chatbot_disconnected' ) );
		exit;
	}

	/**
	 * Handles the "Save Channel Mapping" form submission.
	 *
	 * @return void
	 */
	public function handle_save_channels(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ccmerch-alert' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$channels = \is_array( $_POST['channels'] ?? null ) ? $_POST['channels'] : [];
		// phpcs:enable

		foreach ( $channels as $creator_id => $channel ) {
			$creator_id = (int) $creator_id;
			$channel    = sanitize_text_field( wp_unslash( (string) $channel ) );
			$channel    = \strtolower( $channel );

			if ( $creator_id > 0 ) {
				$this->settings_service->set_creator_channel( $creator_id, $channel );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ccmerch_notice=chatbot_channels_saved' ) );
		exit;
	}

	/**
	 * Handles the per-creator test message send AJAX endpoint.
	 *
	 * Admin-only: registered as wp_ajax_ (not nopriv).
	 * Returns a `steps` array so the JS can render a step-by-step diagnostic log.
	 *
	 * @return never
	 */
	public function handle_test_send(): never {
		check_ajax_referer( self::NONCE_ACTION );

		/** @var array{string, string}[] $steps [status, message] pairs. */
		$steps = [];

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => 'Access denied.',
				'reason'  => 'insufficient_permissions',
				'step'    => 'auth',
				'steps'   => $steps,
			] );
		}

		$steps[] = [ 'ok', 'Admin authentication: passed' ];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$creator_id = (int) ( $_POST['creator_id'] ?? 0 );
		if ( $creator_id <= 0 ) {
			$steps[] = [ 'fail', 'Invalid creator ID in request' ];
			wp_send_json_error( [
				'message' => 'Invalid creator ID.',
				'reason'  => 'invalid_creator_id',
				'step'    => 'validate',
				'steps'   => $steps,
			] );
		}

		$steps[] = [ 'ok', 'Creator ID: ' . $creator_id ];

		$channel = $this->settings_service->get_channel( $creator_id );
		if ( '' === $channel ) {
			$steps[] = [ 'fail', 'No Twitch channel configured for this creator' ];
			wp_send_json_error( [
				'message' => 'No Twitch channel is configured for this creator. Set the channel name in the Creator Channel Mapping section above.',
				'reason'  => 'no_channel',
				'step'    => 'validate',
				'steps'   => $steps,
			] );
		}

		$steps[] = [ 'ok', 'Target channel: #' . $channel ];

		// Pre-flight: check stored tokens before attempting the API call.
		$tokens = $this->auth_service->get_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			$steps[] = [ 'fail', 'No access token stored — bot account is not connected' ];
			wp_send_json_error( [
				'message' => 'Bot account is not connected. Use the Bot Connection card above to authorize.',
				'reason'  => 'no_token',
				'step'    => 'validate',
				'steps'   => $steps,
			] );
		}

		$steps[] = [ 'ok', 'Access token: present' ];

		if ( empty( $tokens['bot_id'] ) ) {
			$steps[] = [ 'fail', 'Bot user ID (sender_id) is empty — disconnect and reconnect the bot account to fix this' ];
			wp_send_json_error( [
				'message' => 'Bot user ID is not stored. Disconnect the bot account and reconnect — the connection flow will re-fetch and store the user ID.',
				'reason'  => 'no_bot_id',
				'step'    => 'validate',
				'steps'   => $steps,
			] );
		}

		$bot_display = ! empty( $tokens['bot_login'] ) ? $tokens['bot_login'] : $tokens['bot_id'];
		$steps[]     = [ 'ok', 'Bot account: ' . $bot_display . ' (sender_id: ' . $tokens['bot_id'] . ')' ];
		$steps[]     = [ 'info', 'Calling Twitch Helix Chat Messages API for #' . $channel . '...' ];

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch Chatbot] Test Send — creator=' . $creator_id . ' channel=' . $channel . ' sender=' . $tokens['bot_id'] );
		}

		$sent = $this->chat_service->send_message( $channel, '🔔 Test message from CCMerch Alert — if you see this the chatbot is working!' );
		$diag = $this->chat_service->get_channel_diagnostics( $channel );

		$http_status = isset( $diag['last_http_status'] ) ? (int) $diag['last_http_status'] : 0;
		$is_sent     = ! empty( $diag['last_is_sent'] );
		$drop_reason = (string) ( $diag['last_drop_reason'] ?? '' );

		if ( 0 === $http_status ) {
			$steps[] = [ 'fail', 'HTTP request to Twitch failed (no response received)' ];
		} elseif ( $is_sent ) {
			$steps[] = [ 'ok', 'Twitch API → HTTP ' . $http_status . ' · is_sent: true ✓ — Message delivered' ];
		} else {
			$drop_label = '' !== $drop_reason ? ' · drop_reason: ' . $drop_reason : '';
			$steps[]    = [ 'fail', 'Twitch API → HTTP ' . $http_status . ' · is_sent: false' . $drop_label ];
		}

		if ( $sent ) {
			wp_send_json_success( [
				'message' => 'Message delivered to #' . $channel . '!',
				'steps'   => $steps,
			] );
		}

		$error = ! empty( $diag['last_error'] ) ? (string) $diag['last_error'] : 'Send failed. Check the diagnostics table for details.';
		wp_send_json_error( [
			'message' => $error,
			'reason'  => 'send_failed',
			'step'    => 'send',
			'steps'   => $steps,
		] );
	}

	// -------------------------------------------------------------------------
	// Runtime status card
	// -------------------------------------------------------------------------

	/**
	 * Renders the Bot Runtime Status card.
	 *
	 * Shows health monitor metrics, token manager validation status,
	 * and the next WP-Cron scheduled token check time.
	 *
	 * @return void
	 */
	private function render_runtime_status_card(): void {
		if ( null === $this->health_monitor ) {
			return;
		}

		$bot_status      = $this->health_monitor->get_bot_status();
		$last_refresh    = $this->health_monitor->get_last_refresh_at();
		$last_refresh_ok = $this->health_monitor->last_refresh_ok();
		$total_sent      = $this->health_monitor->get_total_sent();
		$total_failed    = $this->health_monitor->get_total_failed();
		$channel_stats   = $this->health_monitor->get_channel_stats();

		// Token manager: validity check reads the cached transient — cheap.
		$token_valid = null !== $this->token_manager ? $this->token_manager->is_token_valid() : null;

		// WP-Cron: timestamp of the next scheduled background token check.
		$next_cron = wp_next_scheduled( 'ccmerch_chatbot_runtime_token_check' );

		$status_colors = [
			Chatbot_Health_Monitor::STATUS_CONNECTED    => '#00a32a',
			Chatbot_Health_Monitor::STATUS_RECONNECTING => '#dba617',
			Chatbot_Health_Monitor::STATUS_DISCONNECTED => '#d63638',
			Chatbot_Health_Monitor::STATUS_UNKNOWN      => '#666',
		];
		$status_color = $status_colors[ $bot_status ] ?? '#666';
		?>
		<div class="ccmerch-card ccmerch-chatbot-card" style="max-width:860px;margin-top:20px;">
			<div class="ccmerch-card__header">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Bot Runtime Status', 'ccmerch-alert' ); ?></strong>
			</div>
			<div class="ccmerch-chatbot-card__body">

				<table class="form-table" style="max-width:640px;margin-top:0;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot Connection', 'ccmerch-alert' ); ?></th>
						<td>
							<span style="color:<?php echo esc_attr( $status_color ); ?>;font-weight:600;">
								<?php echo esc_html( \ucfirst( $bot_status ) ); ?>
							</span>
						</td>
					</tr>
					<?php if ( null !== $token_valid ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Token Validity', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( $token_valid ) : ?>
							<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Valid (cached)', 'ccmerch-alert' ); ?></span>
							<?php else : ?>
							<span style="color:#d63638;">&#9675; <?php esc_html_e( 'Invalid or expired', 'ccmerch-alert' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last Token Refresh', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( 0 === $last_refresh ) : ?>
							<em style="color:#666;"><?php esc_html_e( 'Never', 'ccmerch-alert' ); ?></em>
							<?php else : ?>
							<span title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s \U\T\C', $last_refresh ) ); ?>">
								<?php
								echo esc_html( human_time_diff( $last_refresh, \time() ) );
								esc_html_e( ' ago', 'ccmerch-alert' );
								?>
							</span>
							&mdash;
							<span style="color:<?php echo esc_attr( $last_refresh_ok ? '#00a32a' : '#d63638' ); ?>;">
								<?php
								echo $last_refresh_ok
									? esc_html__( 'succeeded', 'ccmerch-alert' )
									: esc_html__( 'failed', 'ccmerch-alert' );
								?>
							</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Messages Sent (total)', 'ccmerch-alert' ); ?></th>
						<td><?php echo esc_html( (string) $total_sent ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Messages Failed (total)', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( $total_failed > 0 ) : ?>
							<span style="color:#d63638;"><?php echo esc_html( (string) $total_failed ); ?></span>
							<?php else : ?>
							<?php echo esc_html( (string) $total_failed ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Background Token Check (WP-Cron)', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( false !== $next_cron ) : ?>
							<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Scheduled', 'ccmerch-alert' ); ?></span>
							&mdash;
							<?php
							\printf(
								/* translators: %s: human-readable time until next run */
								esc_html__( 'next run in %s', 'ccmerch-alert' ),
								esc_html( human_time_diff( \time(), $next_cron ) )
							);
							?>
							<?php else : ?>
							<span style="color:#d63638;">&#9675; <?php esc_html_e( 'Not scheduled', 'ccmerch-alert' ); ?></span>
							<br><small style="color:#666;">
								<?php esc_html_e( 'The cron event will be scheduled automatically on the next page load when the chatbot runtime is active.', 'ccmerch-alert' ); ?>
							</small>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php if ( ! empty( $channel_stats ) ) : ?>
				<h3 style="margin-bottom:8px;"><?php esc_html_e( 'Per-Channel Message Stats', 'ccmerch-alert' ); ?></h3>
				<table class="widefat striped" style="max-width:480px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Channel', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Sent', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Failed', 'ccmerch-alert' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $channel_stats as $ch => $stats ) :
							$ch_sent   = (int) ( $stats['sent']   ?? 0 );
							$ch_failed = (int) ( $stats['failed'] ?? 0 );
						?>
						<tr>
							<td><code><?php echo esc_html( (string) $ch ); ?></code></td>
							<td><?php echo esc_html( (string) $ch_sent ); ?></td>
							<td>
								<?php if ( $ch_failed > 0 ) : ?>
								<span style="color:#d63638;"><?php echo esc_html( (string) $ch_failed ); ?></span>
								<?php else : ?>
								<?php echo esc_html( (string) $ch_failed ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Channel authorization overview card
	// -------------------------------------------------------------------------

	/**
	 * Renders the Channel Authorization Overview card.
	 *
	 * Lists every creator profile with their channel:bot authorization status,
	 * authorized Twitch channel, and timestamp of when authorization was granted.
	 * Read-only — creators authorize from the Creator Portal.
	 *
	 * @param array<mixed> $creators All Creator_Profile objects.
	 * @return void
	 */
	private function render_channel_auth_card( array $creators ): void {
		if ( null === $this->channel_auth_service || empty( $creators ) ) {
			return;
		}

		// Index auth records by creator_id for O(1) lookup.
		$auth_records = [];
		foreach ( $this->channel_auth_service->get_all() as $record ) {
			$auth_records[ $record->creator_id ] = $record;
		}
		?>
		<div class="ccmerch-card ccmerch-chatbot-card" style="max-width:860px;margin-top:20px;">
			<div class="ccmerch-card__header">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Channel Authorization Overview', 'ccmerch-alert' ); ?></strong>
			</div>
			<div class="ccmerch-chatbot-card__body">

				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Each creator must authorize the bot for their Twitch channel (channel:bot scope) via the Creator Portal before messages can be sent. This table shows the current authorization state for every creator profile.', 'ccmerch-alert' ); ?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Creator', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Auth Status', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Authorized Channel', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Granted Scopes', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Authorized', 'ccmerch-alert' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $creators as $creator ) :
							$auth = $auth_records[ $creator->id ] ?? null;
							$is_authorized = null !== $auth && $auth->is_authorized();
						?>
						<tr>
							<td><?php echo esc_html( $creator->creator_name ); ?></td>
							<td>
								<?php if ( $is_authorized ) : ?>
								<span style="color:#00a32a;font-weight:600;">
									&#10003; <?php esc_html_e( 'Authorized', 'ccmerch-alert' ); ?>
								</span>
								<?php else : ?>
								<span style="color:#d63638;">
									&#9675; <?php esc_html_e( 'Not authorized', 'ccmerch-alert' ); ?>
								</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null !== $auth && '' !== $auth->channel_login ) : ?>
								<code><?php echo esc_html( $auth->channel_login ); ?></code>
								<?php else : ?>
								<em style="color:#666;">&mdash;</em>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$granted_scope = null !== $auth ? (string) ( $auth->scope ?? '' ) : '';
								if ( '' !== $granted_scope ) :
									foreach ( \explode( ' ', $granted_scope ) as $scope_slug ) :
								?>
								<code style="margin-right:4px;"><?php echo esc_html( $scope_slug ); ?></code>
								<?php
									endforeach;
								else :
								?>
								<em style="color:#666;">&mdash;</em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null !== $auth && '' !== $auth->authorized_at ) :
									$ts = \strtotime( $auth->authorized_at );
								?>
								<span title="<?php echo esc_attr( $auth->authorized_at ); ?>">
									<?php
									if ( $ts > 0 ) {
										echo esc_html( human_time_diff( $ts, \time() ) );
										esc_html_e( ' ago', 'ccmerch-alert' );
									} else {
										echo esc_html( $auth->authorized_at );
									}
									?>
								</span>
								<?php else : ?>
								<em style="color:#666;">&mdash;</em>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Diagnostics card
	// -------------------------------------------------------------------------

	/**
	 * Renders the Chatbot Diagnostics card.
	 *
	 * Shows architecture explanation, live token validation, per-channel
	 * last-send diagnostic data, and a Test Send button per creator.
	 *
	 * @param array<mixed> $creators     All Creator_Profile objects.
	 * @param array<mixed> $all_settings All chatbot settings keyed by creator_id.
	 * @return void
	 */
	private function render_diagnostics_card( array $creators, array $all_settings ): void {
		$is_connected = $this->auth_service->is_connected();
		$test_nonce   = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url     = admin_url( 'admin-ajax.php' );

		// Validate token live only when connected; avoids an HTTP call when not.
		$token_info = $is_connected ? $this->auth_service->validate_token() : [];

		// Fetch stored tokens now so we can check bot_id separately from the live validate result.
		$stored_tokens = $this->auth_service->get_tokens();
		$stored_bot_id = (string) ( $stored_tokens['bot_id'] ?? '' );
		?>
		<div class="ccmerch-card ccmerch-chatbot-card" style="max-width:860px;margin-top:20px;">
			<div class="ccmerch-card__header">
				<span class="dashicons dashicons-chart-area" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Chatbot Diagnostics', 'ccmerch-alert' ); ?></strong>
			</div>
			<div class="ccmerch-chatbot-card__body">

				<!-- How it works ─────────────────────────────────────────── -->
				<details style="margin-bottom:16px;border:1px solid #ddd;border-radius:4px;padding:8px 12px;">
					<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'How the chatbot works', 'ccmerch-alert' ); ?></summary>
					<div style="margin-top:8px;font-size:13px;line-height:1.6;color:#444;">
						<p style="margin:4px 0;"><strong><?php esc_html_e( 'No persistent service required.', 'ccmerch-alert' ); ?></strong>
						<?php esc_html_e( 'The chatbot sends via the Twitch Helix Chat Messages REST API — a stateless HTTP POST. WordPress fires it automatically on every WooCommerce order via a hook registered at priority 20. There is no daemon, no cron, no persistent connection, and no channel join step required.', 'ccmerch-alert' ); ?></p>
						<p style="margin:4px 0;"><strong><?php esc_html_e( 'Bot account permission required.', 'ccmerch-alert' ); ?></strong>
						<?php esc_html_e( 'For the Helix API to accept messages from the bot, the broadcaster must run /mod botloginname in their Twitch channel chat — or use the Twitch Creator Dashboard to add the bot as a moderator. Without this permission, Twitch returns HTTP 200 with is_sent=false and drop_reason=chat_restricted. The message looks sent but is silently discarded.', 'ccmerch-alert' ); ?></p>
						<p style="margin:4px 0;"><strong><?php esc_html_e( 'Delivery checklist:', 'ccmerch-alert' ); ?></strong></p>
						<ol style="margin:4px 0 0 20px;padding:0;">
							<li><?php esc_html_e( 'Bot account connected (token status below shows Valid)', 'ccmerch-alert' ); ?></li>
							<li><?php esc_html_e( 'Bot user ID stored (not empty — see "Bot User ID" row below)', 'ccmerch-alert' ); ?></li>
							<li><?php esc_html_e( 'Twitch channel name entered in Creator Channel Mapping above', 'ccmerch-alert' ); ?></li>
							<li><?php esc_html_e( 'Creator chatbot enabled on their Creator Portal', 'ccmerch-alert' ); ?></li>
							<li><?php esc_html_e( 'Broadcaster has run /mod botloginname in their channel', 'ccmerch-alert' ); ?></li>
						</ol>
					</div>
				</details>

				<!-- Token Status ─────────────────────────────────────────── -->
				<h3 style="margin-top:0;"><?php esc_html_e( 'Bot Token Status', 'ccmerch-alert' ); ?></h3>

				<?php if ( ! $is_connected ) : ?>
				<p class="description">
					<span style="color:#d63638;">&#9679;</span>
					<?php esc_html_e( 'Bot account is not connected. Use the Bot Connection card above to authorize.', 'ccmerch-alert' ); ?>
				</p>

				<?php elseif ( ! empty( $token_info['valid'] ) ) : ?>
				<?php
					$scopes       = (array) ( $token_info['scopes'] ?? [] );
					$has_write    = \in_array( 'user:write:chat', $scopes, true );
					$expires_in   = (int) ( $token_info['expires_in'] ?? 0 );
					$bot_id       = $stored_bot_id;
					$bot_login    = (string) ( $stored_tokens['bot_login'] ?? $token_info['login'] ?? '' );
				?>
				<table class="form-table" style="max-width:600px;margin-top:0;">
					<tr>
						<th><?php esc_html_e( 'Transport', 'ccmerch-alert' ); ?></th>
						<td><code>Helix Chat API</code> <small style="color:#666;">(POST /helix/chat/messages)</small></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'ccmerch-alert' ); ?></th>
						<td><span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Valid', 'ccmerch-alert' ); ?></span></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bot Login', 'ccmerch-alert' ); ?></th>
						<td><code><?php echo esc_html( (string) ( $token_info['login'] ?? '' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bot User ID (Sender ID)', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( '' !== $bot_id ) : ?>
							<code><?php echo esc_html( $bot_id ); ?></code>
							<?php else : ?>
							<span style="color:#d63638;font-weight:600;">
								&#9888; <?php esc_html_e( 'EMPTY — this will prevent all message delivery.', 'ccmerch-alert' ); ?>
							</span>
							<br><small><?php esc_html_e( 'Disconnect and reconnect the bot account. The reconnection flow will re-fetch and store the user ID.', 'ccmerch-alert' ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Expires In', 'ccmerch-alert' ); ?></th>
						<td><?php echo esc_html( $expires_in > 0 ? human_time_diff( \time(), \time() + $expires_in ) : __( 'Unknown', 'ccmerch-alert' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active Scopes', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( empty( $scopes ) ) : ?>
							<em><?php esc_html_e( 'None returned', 'ccmerch-alert' ); ?></em>
							<?php else : ?>
							<?php foreach ( $scopes as $scope ) : ?>
							<code style="background:<?php echo esc_attr( 'user:write:chat' === $scope ? '#d7f7c2' : '#f0f0f1' ); ?>;margin-right:4px;">
								<?php echo esc_html( (string) $scope ); ?>
							</code>
							<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $bot_login ) : ?>
					<tr>
						<th><?php esc_html_e( '/mod command', 'ccmerch-alert' ); ?></th>
						<td>
							<code>/mod <?php echo esc_html( $bot_login ); ?></code>
							<br><small style="color:#666;"><?php esc_html_e( 'The broadcaster must run this in their Twitch chat to allow the bot to send messages.', 'ccmerch-alert' ); ?></small>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<?php if ( ! $has_write ) : ?>
				<div class="notice notice-error inline" style="margin:8px 0;">
					<p>
						<strong><?php esc_html_e( 'Missing required scope: user:write:chat', 'ccmerch-alert' ); ?></strong><br>
						<?php esc_html_e( 'The active token does not have the user:write:chat scope required by the Helix Chat Messages API. Disconnect and reconnect the bot account to issue a new token with the correct scopes.', 'ccmerch-alert' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<?php else : ?>
				<?php $token_error = (string) ( $token_info['error'] ?? __( 'Token validation failed.', 'ccmerch-alert' ) ); ?>
				<p class="description">
					<span style="color:#d63638;">&#9675; <?php esc_html_e( 'Token invalid or expired.', 'ccmerch-alert' ); ?></span>
					<?php echo ' ' . esc_html( $token_error ); ?>
					<br>
					<?php esc_html_e( 'Disconnect and reconnect the bot account to refresh the token.', 'ccmerch-alert' ); ?>
				</p>
				<?php endif; ?>

				<hr style="margin:20px 0;">

				<!-- Per-channel diagnostics ──────────────────────────────── -->
				<h3 style="margin-top:0;"><?php esc_html_e( 'Channel Send Diagnostics', 'ccmerch-alert' ); ?></h3>

				<?php if ( empty( $creators ) ) : ?>
				<p class="description"><?php esc_html_e( 'No creator profiles found.', 'ccmerch-alert' ); ?></p>
				<?php else : ?>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Data shown below is recorded automatically on every send attempt. Click "Test Send" to trigger a test message — a step-by-step log will appear inline.', 'ccmerch-alert' ); ?>
				</p>
				<table class="widefat striped ccmerch-chatbot-diag-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Creator', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Channel', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Last Status', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Last Error', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Last Message Sent', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Last Activity', 'ccmerch-alert' ); ?></th>
							<th><?php esc_html_e( 'Action', 'ccmerch-alert' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $creators as $creator ) :
							$row     = $all_settings[ $creator->id ] ?? null;
							$channel = $row ? $row->twitch_channel : '';
							$diag    = '' !== $channel ? $this->chat_service->get_channel_diagnostics( $channel ) : [];

							$http_status  = isset( $diag['last_http_status'] ) ? (int) $diag['last_http_status'] : null;
							$is_sent      = isset( $diag['last_is_sent'] ) ? (bool) $diag['last_is_sent'] : null;
							$last_error   = (string) ( $diag['last_error'] ?? '' );
							$last_message = (string) ( $diag['last_sent_message'] ?? '' );
							$timestamp    = isset( $diag['timestamp'] ) ? (int) $diag['timestamp'] : 0;
							$broadcaster  = (string) ( $diag['broadcaster_id'] ?? '' );
							$sender       = (string) ( $diag['sender_id'] ?? '' );
						?>
						<tr>
							<td><?php echo esc_html( $creator->creator_name ); ?></td>
							<td>
								<?php if ( '' !== $channel ) : ?>
								<code><?php echo esc_html( $channel ); ?></code>
								<?php if ( '' !== $broadcaster ) : ?>
								<br><small style="color:#666;"><?php printf( esc_html__( 'Broadcaster ID: %s', 'ccmerch-alert' ), esc_html( $broadcaster ) ); ?></small>
								<?php endif; ?>
								<?php if ( '' !== $sender ) : ?>
								<br><small style="color:#666;"><?php printf( esc_html__( 'Sender ID: %s', 'ccmerch-alert' ), esc_html( $sender ) ); ?></small>
								<?php endif; ?>
								<?php else : ?>
								<em style="color:#666;"><?php esc_html_e( 'Not set', 'ccmerch-alert' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null === $http_status ) : ?>
								<em style="color:#666;"><?php esc_html_e( 'No data', 'ccmerch-alert' ); ?></em>
								<?php elseif ( 0 === $http_status ) : ?>
								<span style="color:#d63638;"><?php esc_html_e( 'Request failed', 'ccmerch-alert' ); ?></span>
								<?php elseif ( $is_sent ) : ?>
								<span style="color:#00a32a;">&#10003; <?php echo esc_html( (string) $http_status ); ?> <?php esc_html_e( '— Delivered', 'ccmerch-alert' ); ?></span>
								<?php else : ?>
								<span style="color:#d63638;">&#9675; <?php echo esc_html( (string) $http_status ); ?> <?php esc_html_e( '— Not sent', 'ccmerch-alert' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $last_error ) : ?>
								<span style="color:#d63638;" title="<?php echo esc_attr( $last_error ); ?>">
									<?php echo esc_html( \strlen( $last_error ) > 80 ? \substr( $last_error, 0, 80 ) . '…' : $last_error ); ?>
								</span>
								<?php else : ?>
								<span style="color:#666;">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $last_message ) : ?>
								<em><?php echo esc_html( \strlen( $last_message ) > 60 ? \substr( $last_message, 0, 60 ) . '…' : $last_message ); ?></em>
								<?php else : ?>
								<span style="color:#666;">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $timestamp > 0 ) : ?>
								<span title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $timestamp ) ); ?>">
									<?php echo esc_html( human_time_diff( $timestamp, \time() ) ); ?> <?php esc_html_e( 'ago', 'ccmerch-alert' ); ?>
								</span>
								<?php else : ?>
								<span style="color:#666;">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $channel ) : ?>
								<button
									type="button"
									class="button"
									data-ccmerch-chatbot-test="1"
									data-ccmerch-creator-id="<?php echo esc_attr( (string) $creator->id ); ?>"
									data-ccmerch-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
									data-ccmerch-nonce="<?php echo esc_attr( $test_nonce ); ?>"
								>
									<?php esc_html_e( 'Test Send', 'ccmerch-alert' ); ?>
								</button>
								<span class="ccmerch-chatbot-test-result"></span>
								<?php else : ?>
								<span style="color:#666;"><?php esc_html_e( 'Set channel first', 'ccmerch-alert' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// OAuth scopes card
	// -------------------------------------------------------------------------

	/**
	 * Renders the OAuth Scopes card.
	 *
	 * Shows the required bot scopes (locked, always included) and any optional
	 * bot scopes (configurable toggle per scope). Currently OPTIONAL_BOT_SCOPES
	 * is empty; the card structure is in place for future expansion.
	 *
	 * @return void
	 */
	private function render_oauth_scopes_card(): void {
		$required_bot      = Chatbot_Scope_Manager::REQUIRED_BOT_SCOPES;
		$required_creator  = Chatbot_Scope_Manager::REQUIRED_CREATOR_SCOPES;
		$optional          = Chatbot_Scope_Manager::OPTIONAL_BOT_SCOPES;
		$saved_config      = (array) get_option( Chatbot_Scope_Manager::OPTION_KEY, [] );
		?>
		<div class="ccmerch-card ccmerch-chatbot-card" style="max-width:860px;margin-top:20px;">
			<div class="ccmerch-card__header">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'OAuth Scopes', 'ccmerch-alert' ); ?></strong>
			</div>
			<div class="ccmerch-chatbot-card__body">

				<p class="description" style="margin-bottom:16px;">
					<?php esc_html_e( 'Scopes are Twitch permissions requested during the OAuth authorization flow. Required scopes are always included and cannot be disabled. Changes take effect the next time the bot account is reconnected.', 'ccmerch-alert' ); ?>
				</p>

				<!-- Required bot scopes ─────────────────────────────────────── -->
				<h3 style="margin-top:0;"><?php esc_html_e( 'Required Bot Scopes', 'ccmerch-alert' ); ?></h3>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'These scopes are always requested and cannot be removed.', 'ccmerch-alert' ); ?>
				</p>
				<p>
					<?php foreach ( $required_bot as $scope ) : ?>
					<code style="background:#d7f7c2;margin-right:6px;">
						&#10003; <?php echo esc_html( $scope ); ?>
					</code>
					<?php endforeach; ?>
				</p>

				<!-- Required creator scopes ─────────────────────────────────── -->
				<h3><?php esc_html_e( 'Required Creator Channel Scopes', 'ccmerch-alert' ); ?></h3>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'Requested during the creator\'s own channel authorization flow. Always required.', 'ccmerch-alert' ); ?>
				</p>
				<p>
					<?php foreach ( $required_creator as $scope ) : ?>
					<code style="background:#d7f7c2;margin-right:6px;">
						&#10003; <?php echo esc_html( $scope ); ?>
					</code>
					<?php endforeach; ?>
				</p>

				<!-- Optional bot scopes ─────────────────────────────────────── -->
				<?php if ( ! empty( $optional ) ) : ?>
				<h3><?php esc_html_e( 'Optional Bot Scopes', 'ccmerch-alert' ); ?></h3>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Enable additional permissions. Reconnect the bot account after saving for changes to take effect.', 'ccmerch-alert' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_SCOPES ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<table class="form-table" style="max-width:700px;">
						<?php foreach ( $optional as $slug => $label ) :
							$enabled = ! empty( $saved_config[ $slug ] );
						?>
						<tr>
							<th scope="row">
								<label for="ccmerch-scope-<?php echo esc_attr( $slug ); ?>">
									<code><?php echo esc_html( $slug ); ?></code>
								</label>
							</th>
							<td>
								<label>
									<input
										type="checkbox"
										id="ccmerch-scope-<?php echo esc_attr( $slug ); ?>"
										name="scopes[<?php echo esc_attr( $slug ); ?>]"
										value="1"
										<?php checked( $enabled ); ?>
									>
									<?php echo esc_html( $label ); ?>
								</label>
							</td>
						</tr>
						<?php endforeach; ?>
					</table>
					<p>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Scope Config', 'ccmerch-alert' ); ?>
						</button>
					</p>
				</form>
				<?php else : ?>
				<h3><?php esc_html_e( 'Optional Bot Scopes', 'ccmerch-alert' ); ?></h3>
				<p class="description" style="color:#666;">
					<?php esc_html_e( 'No optional scopes are available in this version. Future updates may add additional optional permissions here.', 'ccmerch-alert' ); ?>
				</p>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// admin_post_ handler — scope config save
	// -------------------------------------------------------------------------

	/**
	 * Handles the "Save Scope Config" form submission.
	 *
	 * Persists the optional-scope enable/disable state via Chatbot_Scope_Manager.
	 * Only called when OPTIONAL_BOT_SCOPES is non-empty; safe to invoke otherwise
	 * (saves an empty array, which is a no-op given the current constant).
	 *
	 * @return void
	 */
	public function handle_save_scopes(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ccmerch-alert' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = \is_array( $_POST['scopes'] ?? null ) ? $_POST['scopes'] : [];
		Chatbot_Scope_Manager::save_scope_config( $raw );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ccmerch_notice=chatbot_scopes_saved' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	/**
	 * Renders an admin notice based on the ?ccmerch_notice query parameter.
	 *
	 * @param string $notice Sanitized notice slug.
	 * @return void
	 */
	private function render_notices( string $notice ): void {
		if ( '' === $notice ) {
			return;
		}

		$messages = [
			'chatbot_connected'           => [ 'success', __( 'Bot account connected successfully.', 'ccmerch-alert' ) ],
			'chatbot_connect_failed'      => [ 'error',   __( 'Failed to connect bot account. Please check your credentials and try again.', 'ccmerch-alert' ) ],
			'chatbot_disconnected'        => [ 'success', __( 'Bot account disconnected.', 'ccmerch-alert' ) ],
			'chatbot_channels_saved'      => [ 'success', __( 'Channel mapping saved.', 'ccmerch-alert' ) ],
			'chatbot_missing_credentials' => [ 'error',   __( 'Please enter Client ID and Client Secret before connecting.', 'ccmerch-alert' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $type, $message ] = $messages[ $notice ];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}
}
