<?php
/**
 * Creator channel authorization service.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

use CCMerchAlert\Auth\Auth_Context;
use CCMerchAlert\Creators\Creator_Service;
use CCMerchAlert\Chatbot\Chatbot_Scope_Manager;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the per-creator Twitch channel:bot authorization OAuth flow.
 *
 * A creator must independently authorize the CCMerch bot app with the
 * `channel:bot` scope so the bot can send messages in their channel without
 * being a moderator. This is a separate OAuth flow from the admin bot-account
 * connection managed by Twitch_Auth_Service.
 *
 * AJAX endpoints use admin-ajax.php with both nopriv + priv hooks.
 * Auth: creator portal access token (Auth_Context::resolve_creator()).
 *
 * Must never be instantiated more than once.
 * Must never expose tokens to the browser.
 */
final class Creator_Channel_Auth_Service {

	/** @var string AJAX action: returns the Twitch OAuth URL for the creator to visit. */
	public const AJAX_GET_AUTH_URL = 'ccmerch_creator_get_channel_auth_url';

	/** @var string AJAX action: OAuth callback from Twitch (nopriv — no WP login). */
	public const AJAX_CALLBACK = 'ccmerch_creator_channel_auth_callback';

	/** @var string AJAX action: revokes/deletes the creator's channel authorization. */
	public const AJAX_REVOKE = 'ccmerch_creator_revoke_channel_auth';

	/** @var string Twitch OAuth scope required from the broadcaster. */
	public const CHANNEL_SCOPE = 'channel:bot';

	/** @var string Twitch authorization endpoint. */
	private const AUTHORIZE_URL = 'https://id.twitch.tv/oauth2/authorize';

	/** @var string Twitch token endpoint. */
	private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

	/** @var string Transient key prefix for creator OAuth state. */
	private const STATE_PREFIX = 'ccmerch_cca_state_';

	/** @var int State transient TTL in seconds (1 hour). */
	private const STATE_TTL = 3600;

	/** @var Creator_Channel_Auth_Repository */
	private Creator_Channel_Auth_Repository $repository;

	/** @var Twitch_Auth_Service */
	private Twitch_Auth_Service $bot_auth;

	/** @var Creator_Service */
	private Creator_Service $creator_service;

	/**
	 * @param Creator_Channel_Auth_Repository $repository      DB layer for creator auth records.
	 * @param Twitch_Auth_Service              $bot_auth        Bot account auth (provides Client ID / Secret).
	 * @param Creator_Service                  $creator_service Creator profile lookup + token auth.
	 */
	public function __construct(
		Creator_Channel_Auth_Repository $repository,
		Twitch_Auth_Service $bot_auth,
		Creator_Service $creator_service
	) {
		$this->repository      = $repository;
		$this->bot_auth        = $bot_auth;
		$this->creator_service = $creator_service;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Registers all AJAX hooks.
	 *
	 * Called unconditionally from Plugin::load_components().
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_nopriv_' . self::AJAX_GET_AUTH_URL, [ $this, 'handle_get_auth_url' ] );
		add_action( 'wp_ajax_'        . self::AJAX_GET_AUTH_URL, [ $this, 'handle_get_auth_url' ] );

		// Callback from Twitch — no WP login; state carries creator_id.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_CALLBACK, [ $this, 'handle_callback' ] );
		add_action( 'wp_ajax_'        . self::AJAX_CALLBACK, [ $this, 'handle_callback' ] );

		// Intercept on the shared admin bot callback URL (registered in the Twitch app).
		// Priority 1 so this runs before Twitch_Auth_Service::handle_oauth_callback() at priority 10.
		// If the state belongs to a creator flow we process it and exit; otherwise we return
		// without exiting so the bot account handler can continue normally.
		add_action( 'wp_ajax_nopriv_' . Twitch_Auth_Service::AJAX_CALLBACK, [ $this, 'intercept_shared_callback' ], 1 );
		add_action( 'wp_ajax_'        . Twitch_Auth_Service::AJAX_CALLBACK, [ $this, 'intercept_shared_callback' ], 1 );

		add_action( 'wp_ajax_nopriv_' . self::AJAX_REVOKE, [ $this, 'handle_revoke' ] );
		add_action( 'wp_ajax_'        . self::AJAX_REVOKE, [ $this, 'handle_revoke' ] );
	}

	// -------------------------------------------------------------------------
	// Business logic
	// -------------------------------------------------------------------------

	/**
	 * Returns the authorization record for a creator, or null if not authorized.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return Creator_Channel_Auth|null
	 */
	public function get_authorization( int $creator_id ): ?Creator_Channel_Auth {
		return $this->repository->find_by_creator_id( $creator_id );
	}

	/**
	 * Returns true when a valid channel authorization exists for the creator.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return bool
	 */
	public function is_authorized( int $creator_id ): bool {
		$auth = $this->repository->find_by_creator_id( $creator_id );
		return null !== $auth && $auth->is_authorized();
	}

	/**
	 * Returns all authorization records (used by admin overview table).
	 *
	 * @return Creator_Channel_Auth[]
	 */
	public function get_all(): array {
		return $this->repository->get_all();
	}

	/**
	 * Deletes the channel authorization for a creator.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return bool
	 */
	public function revoke( int $creator_id ): bool {
		return $this->repository->delete_by_creator_id( $creator_id );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Returns the Twitch OAuth URL for the creator to visit.
	 *
	 * Auth: creator portal access token.
	 * Response: { url: string } on success; wp_send_json_error on failure.
	 *
	 * @return never
	 */
	public function handle_get_auth_url(): never {
		$ctx = Auth_Context::resolve_creator( $this->creator_service );

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_get_auth_url — creator_id=' . $ctx->creator_id );
		}

		$url = $this->build_auth_url( $ctx->creator_id );
		if ( '' === $url ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_get_auth_url — failed: no app credentials configured' );
			}
			wp_send_json_error( [
				'message' => 'Twitch app not configured. Ask your admin to set up the Twitch app credentials first.',
				'reason'  => 'no_app_credentials',
				'step'    => 'get_auth_url',
			] );
		}

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_get_auth_url — success: returning OAuth URL for creator_id=' . $ctx->creator_id );
		}

		wp_send_json_success( [ 'url' => $url ] );
	}

	/**
	 * AJAX: Handles the Twitch OAuth callback for a creator.
	 *
	 * Twitch redirects the creator's browser here after authorization.
	 * The state transient carries the creator_id and portal_url for redirect.
	 * On success, saves the authorization record and redirects back to the portal.
	 *
	 * @return never
	 */
	public function handle_callback(): never {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		// phpcs:enable

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log(
				'[CCMerch CCA] handle_callback — received: code=' . ( '' !== $code ? '(set)' : '(empty)' )
				. ' state=' . ( '' !== $state ? \substr( $state, 0, 8 ) . '…' : '(empty)' )
				. ' error=' . ( '' !== $error ? $error : '(none)' )
			);
		}

		// Parse the state transient.
		$state_data = '' !== $state ? get_transient( self::STATE_PREFIX . $state ) : false;
		$creator_id = (int) ( \is_array( $state_data ) ? ( $state_data['creator_id'] ?? 0 ) : 0 );
		$portal_url = \is_array( $state_data ) ? (string) ( $state_data['portal_url'] ?? '' ) : '';

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			if ( false === $state_data ) {
				\error_log( '[CCMerch CCA] handle_callback — state transient not found (expired, already consumed, or invalid state value)' );
			} else {
				\error_log( '[CCMerch CCA] handle_callback — state resolved: creator_id=' . $creator_id . ' portal_url=' . $portal_url );
			}
		}

		if ( '' !== $state ) {
			delete_transient( self::STATE_PREFIX . $state );
		}

		$failure_url = '' !== $portal_url
			? add_query_arg( 'ccmerch_notice', 'channel_auth_failed', $portal_url )
			: home_url();

		if ( '' !== $error ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_callback — Twitch returned error: ' . $error );
			}
			wp_safe_redirect( $failure_url );
			exit;
		}

		if ( '' === $code ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_callback — no authorization code received' );
			}
			wp_safe_redirect( $failure_url );
			exit;
		}

		if ( $creator_id <= 0 ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_callback — state transient missing or invalid (creator_id=' . $creator_id . ')' );
			}
			wp_safe_redirect( $failure_url );
			exit;
		}

		// Exchange code for tokens.
		$tokens = $this->exchange_code( $code );
		if ( empty( $tokens['access_token'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_callback — token exchange failed for creator_id=' . $creator_id );
			}
			wp_safe_redirect( $failure_url );
			exit;
		}

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_callback — token exchange succeeded for creator_id=' . $creator_id );
		}

		// Fetch the broadcaster channel login.
		$user_info = $this->bot_auth->fetch_user_info( $tokens['access_token'] );
		if ( null === $user_info ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_callback — fetch_user_info failed for creator_id=' . $creator_id );
			}
			wp_safe_redirect( $failure_url );
			exit;
		}

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_callback — broadcaster identified: login=' . $user_info['login'] . ' for creator_id=' . $creator_id );
		}

		$saved = $this->repository->save(
			$creator_id,
			$user_info['login'],
			$tokens['access_token'],
			$tokens['refresh_token'],
			Chatbot_Scope_Manager::get_creator_scopes()
		);

		if ( ! $saved ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] handle_callback — repository->save() failed for creator_id=' . $creator_id );
			}
			wp_safe_redirect( $failure_url );
			exit;
		}

		$success_url = '' !== $portal_url
			? add_query_arg( 'ccmerch_notice', 'channel_auth_success', $portal_url )
			: home_url();

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_callback — authorization saved for creator_id=' . $creator_id . ' login=' . $user_info['login'] . '; redirecting to success URL' );
		}

		wp_safe_redirect( $success_url );
		exit;
	}

	/**
	 * AJAX: Revokes the creator's channel authorization.
	 *
	 * Auth: creator portal access token.
	 * Response: wp_send_json_success on success; wp_send_json_error on failure.
	 *
	 * @return never
	 */
	public function handle_revoke(): never {
		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_revoke — step: ajax_received' );
		}

		$ctx = Auth_Context::resolve_creator( $this->creator_service );

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_revoke — step: creator_resolved creator_id=' . $ctx->creator_id );
		}

		$deleted = $this->repository->delete_by_creator_id( $ctx->creator_id );

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_revoke — step: deletion_result result=' . ( $deleted ? 'true' : 'false' ) . ' creator_id=' . $ctx->creator_id );
		}

		if ( ! $deleted ) {
			wp_send_json_error( [
				'message' => 'Failed to revoke authorization.',
				'reason'  => 'db_fail',
				'step'    => 'revoke',
			] );
		}

		// Runtime synchronization note:
		// Chatbot_Message_Service::send_for_creator() calls Creator_Channel_Auth_Service::is_authorized()
		// on every order, which calls Creator_Channel_Auth_Repository::find_by_creator_id() fresh from the
		// DB. After deletion here, the next order's is_authorized() returns false and skips delivery.
		// No in-memory state or transient cache needs to be cleared.

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] handle_revoke — step: success_response creator_id=' . $ctx->creator_id );
		}

		wp_send_json_success( [ 'message' => 'Channel authorization revoked.' ] );
	}

	// -------------------------------------------------------------------------
	// Shared-callback interceptor
	// -------------------------------------------------------------------------

	/**
	 * Intercepts the shared Twitch OAuth callback URL to detect creator flows.
	 *
	 * Both the bot account OAuth and the creator channel OAuth use the same
	 * redirect URI registered in the Twitch Developer Console. This method runs
	 * at priority 1 (before Twitch_Auth_Service at priority 10) and inspects
	 * the `state` parameter.
	 *
	 * - If the state transient is a creator CCA transient (STATE_PREFIX), we own
	 *   this request: delegate to handle_callback() which always exits.
	 * - Otherwise we return without doing anything so Twitch_Auth_Service can
	 *   handle the bot account flow normally.
	 *
	 * @return void  May exit via handle_callback() when the state belongs to us.
	 */
	public function intercept_shared_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable

		if ( '' === $state ) {
			return;
		}

		// Peek at the transient without deleting it — handle_callback() will consume it.
		$state_data = get_transient( self::STATE_PREFIX . $state );

		if ( ! \is_array( $state_data ) ) {
			// Not a creator state transient — yield to Twitch_Auth_Service.
			return;
		}

		// This is a creator channel auth callback — process it and exit.
		$this->handle_callback();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds the Twitch OAuth authorization URL for a creator.
	 *
	 * Stores a state transient carrying creator_id and portal_url so the callback
	 * handler can redirect back to the correct portal page after authorization.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return string Empty string if no app credentials are configured.
	 */
	private function build_auth_url( int $creator_id ): string {
		$creds = $this->bot_auth->get_app_credentials();
		if ( empty( $creds['client_id'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] build_auth_url — no app credentials; cannot build OAuth URL' );
			}
			return '';
		}

		$creator    = $this->creator_service->get_creator( $creator_id );
		$portal_url = null !== $creator ? $this->creator_service->get_portal_url( $creator ) : '';

		$state = \bin2hex( \random_bytes( 16 ) );
		$transient_key = self::STATE_PREFIX . $state;
		set_transient(
			$transient_key,
			[ 'creator_id' => $creator_id, 'portal_url' => $portal_url ],
			self::STATE_TTL
		);

		$redirect_uri = admin_url( 'admin-ajax.php?action=' . Twitch_Auth_Service::AJAX_CALLBACK );
		$scope        = Chatbot_Scope_Manager::get_creator_scopes();

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log(
				'[CCMerch CCA] build_auth_url — state transient set: key=' . $transient_key
				. ' creator_id=' . $creator_id . ' redirect_uri=' . $redirect_uri . ' scope=' . $scope
			);
		}

		$params = \http_build_query( [
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => $scope,
			'state'         => $state,
			'force_verify'  => 'true',
		] );

		return self::AUTHORIZE_URL . '?' . $params;
	}

	/**
	 * Returns the pre-generated Twitch OAuth URL for a creator's channel authorization.
	 *
	 * Pre-generates the state transient at page render time so the creator can be
	 * sent directly to Twitch via a plain HTML anchor — no JavaScript AJAX step required.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return string Empty string if no app credentials are configured.
	 */
	public function get_auth_url( int $creator_id ): string {
		return $this->build_auth_url( $creator_id );
	}

	/**
	 * Exchanges an authorization code for access and refresh tokens.
	 *
	 * @param string $code Authorization code from Twitch.
	 * @return array<string, string> Empty array on failure.
	 */
	private function exchange_code( string $code ): array {
		$creds = $this->bot_auth->get_app_credentials();
		if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] exchange_code — no app credentials; cannot exchange code' );
			}
			return [];
		}

		$redirect_uri = admin_url( 'admin-ajax.php?action=' . Twitch_Auth_Service::AJAX_CALLBACK );

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 10,
				'body'    => [
					'client_id'     => $creds['client_id'],
					'client_secret' => $creds['client_secret'],
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $redirect_uri,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch CCA] exchange_code — HTTP error: ' . $response->get_error_message() );
			}
			return [];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = \json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! \is_array( $body ) || empty( $body['access_token'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$error_msg = \is_array( $body ) ? ( $body['message'] ?? $body['error'] ?? '(no message)' ) : '(invalid JSON)';
				\error_log( '[CCMerch CCA] exchange_code — token exchange failed: HTTP ' . $http_code . ' error=' . $error_msg );
			}
			return [];
		}

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch CCA] exchange_code — success: HTTP ' . $http_code . ' access_token=(set) refresh_token=' . ( ! empty( $body['refresh_token'] ) ? '(set)' : '(empty)' ) );
		}

		return [
			'access_token'  => (string) $body['access_token'],
			'refresh_token' => (string) ( $body['refresh_token'] ?? '' ),
		];
	}
}
