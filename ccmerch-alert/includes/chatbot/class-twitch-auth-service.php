<?php
/**
 * Twitch OAuth authentication service.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Twitch OAuth 2.0 tokens and app credentials for the chatbot bot account.
 *
 * Tokens and credentials are stored encrypted in wp_options using AES-256-CBC.
 * The encryption key is derived from the WordPress auth salt so it is installation-unique.
 *
 * Token storage:
 *   - OPTION_KEY stores the bot OAuth tokens (access_token, refresh_token, bot_id).
 *   - OPTION_APP_KEY stores the Twitch app Client ID and Client Secret.
 *
 * get_tokens() returns DECRYPTED values.
 * store_tokens() accepts PLAIN values and stores ENCRYPTED.
 *
 * The OAuth callback is registered as a WordPress AJAX action so it is reachable
 * at admin-ajax.php regardless of whether the requesting user is logged in.
 * This class is always instantiated (not inside is_admin()) so the callback hook fires.
 */
final class Twitch_Auth_Service {

	/** @var string wp_options key for encrypted bot OAuth tokens. */
	public const OPTION_KEY = 'ccmerch_chatbot_twitch_tokens';

	/** @var string wp_options key for encrypted Twitch app credentials. */
	public const OPTION_APP_KEY = 'ccmerch_chatbot_twitch_app';

	/** @var string WordPress AJAX action for the OAuth callback. */
	public const AJAX_CALLBACK = 'ccmerch_twitch_oauth_callback';

	/** @var string OAuth scopes requested from Twitch. */
	public const OAUTH_SCOPES = 'user:write:chat user:bot';

	/** @var string Twitch authorization endpoint. */
	private const AUTHORIZE_URL = 'https://id.twitch.tv/oauth2/authorize';

	/** @var string Twitch token endpoint. */
	private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

	/** @var string Twitch users endpoint. */
	private const USERS_URL = 'https://api.twitch.tv/helix/users';

	/** @var string Twitch token validation endpoint. */
	private const VALIDATE_URL = 'https://id.twitch.tv/oauth2/validate';

	/** @var string Transient key prefix for OAuth state verification. */
	private const STATE_TRANSIENT_PREFIX = 'ccmerch_twitch_oauth_state_';

	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_CALLBACK, [ $this, 'handle_oauth_callback' ] );
	}

	// -------------------------------------------------------------------------
	// Connection status
	// -------------------------------------------------------------------------

	/**
	 * Returns true if we have a stored, non-empty access token.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		$tokens = $this->get_tokens();
		return ! empty( $tokens['access_token'] );
	}

	/**
	 * Removes all stored tokens, effectively disconnecting the bot account.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		delete_option( self::OPTION_KEY );
	}

	// -------------------------------------------------------------------------
	// Token access
	// -------------------------------------------------------------------------

	/**
	 * Returns the decrypted bot OAuth tokens array.
	 *
	 * Keys: access_token (string), refresh_token (string), bot_id (string).
	 * Returns an empty array when no tokens are stored.
	 *
	 * @return array<string, string>
	 */
	public function get_tokens(): array {
		$raw = get_option( self::OPTION_KEY, '' );
		if ( '' === $raw ) {
			return [];
		}
		$decrypted = $this->decrypt( (string) $raw );
		if ( '' === $decrypted ) {
			return [];
		}
		$data = \json_decode( $decrypted, true );
		return \is_array( $data ) ? $data : [];
	}

	/**
	 * Encrypts and persists the bot OAuth tokens.
	 *
	 * @param array<string, string> $tokens Keys: access_token, refresh_token, bot_id.
	 * @return void
	 */
	public function store_tokens( array $tokens ): void {
		$json      = \json_encode( $tokens );
		$encrypted = $this->encrypt( (string) $json );
		update_option( self::OPTION_KEY, $encrypted, false );
	}

	// -------------------------------------------------------------------------
	// App credentials
	// -------------------------------------------------------------------------

	/**
	 * Returns the decrypted Twitch app credentials.
	 *
	 * Keys: client_id (string), client_secret (string).
	 * Returns an empty array when no credentials are stored.
	 *
	 * @return array<string, string>
	 */
	public function get_app_credentials(): array {
		$raw = get_option( self::OPTION_APP_KEY, '' );
		if ( '' === $raw ) {
			return [];
		}
		$decrypted = $this->decrypt( (string) $raw );
		if ( '' === $decrypted ) {
			return [];
		}
		$data = \json_decode( $decrypted, true );
		return \is_array( $data ) ? $data : [];
	}

	/**
	 * Encrypts and persists the Twitch app credentials.
	 *
	 * @param string $client_id     Twitch application Client ID.
	 * @param string $client_secret Twitch application Client Secret.
	 * @return void
	 */
	public function store_app_credentials( string $client_id, string $client_secret ): void {
		$json      = \json_encode( [ 'client_id' => $client_id, 'client_secret' => $client_secret ] );
		$encrypted = $this->encrypt( (string) $json );
		update_option( self::OPTION_APP_KEY, $encrypted, false );
	}

	// -------------------------------------------------------------------------
	// OAuth flow
	// -------------------------------------------------------------------------

	/**
	 * Builds and returns the Twitch OAuth authorization URL.
	 *
	 * Generates a 16-byte random state token, stores it in a transient for 600 s,
	 * and embeds it in the authorization URL for CSRF protection.
	 *
	 * @return string Empty string if no app credentials are configured.
	 */
	public function get_auth_url(): string {
		$creds = $this->get_app_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return '';
		}

		$state = \bin2hex( \random_bytes( 16 ) );
		set_transient( self::STATE_TRANSIENT_PREFIX . $state, '1', 600 );

		$redirect_uri = $this->get_redirect_uri();

		$params = \http_build_query( [
			'client_id'     => $creds['client_id'],
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => Chatbot_Scope_Manager::get_bot_scopes(),
			'state'         => $state,
			'force_verify'  => 'true',
		] );

		return self::AUTHORIZE_URL . '?' . $params;
	}

	/**
	 * Handles the Twitch OAuth callback AJAX request.
	 *
	 * Twitch redirects the admin browser to admin-ajax.php with this action
	 * after the user authorizes the bot account. This handler verifies the
	 * state, exchanges the code for tokens, fetches the bot user ID, and
	 * redirects back to the Chatbot admin page.
	 *
	 * @return void
	 */
	public function handle_oauth_callback(): void {
		// Only admins can complete an OAuth flow.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ccmerch-alert' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		// phpcs:enable

		$failure_url = admin_url( 'admin.php?page=ccmerch-alert-chatbot&ccmerch_notice=chatbot_connect_failed' );
		$success_url = admin_url( 'admin.php?page=ccmerch-alert-chatbot&ccmerch_notice=chatbot_connected' );

		if ( '' !== $error || '' === $code || '' === $state ) {
			wp_safe_redirect( $failure_url );
			exit;
		}

		// Verify state to prevent CSRF.
		$transient_key = self::STATE_TRANSIENT_PREFIX . $state;
		$stored_state  = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( false === $stored_state || ! \hash_equals( '1', (string) $stored_state ) ) {
			wp_safe_redirect( $failure_url );
			exit;
		}

		// Exchange authorization code for tokens.
		$tokens = $this->exchange_code( $code );
		if ( empty( $tokens['access_token'] ) ) {
			wp_safe_redirect( $failure_url );
			exit;
		}

		// Fetch bot user info (ID + login). Both are required; bot_id is the Helix sender_id.
		$user_info = $this->fetch_user_info( $tokens['access_token'] );
		if ( null === $user_info ) {
			// Cannot send messages without a valid bot_id — show failure so the admin
			// knows to try reconnecting rather than seeing "Connected" with no delivery.
			wp_safe_redirect( $failure_url );
			exit;
		}

		$tokens['bot_id']    = $user_info['id'];
		$tokens['bot_login'] = $user_info['login'];

		$this->store_tokens( $tokens );

		wp_safe_redirect( $success_url );
		exit;
	}

	/**
	 * Refreshes the access token using the stored refresh token.
	 *
	 * Updates stored tokens on success; returns false on failure.
	 * Per Twitch docs: the response contains a NEW refresh token — the old one is
	 * immediately invalidated. Both tokens must be stored from the response.
	 *
	 * @return bool True if the refresh succeeded and new tokens were stored.
	 */
	public function refresh_tokens(): bool {
		$tokens = $this->get_tokens();
		if ( empty( $tokens['refresh_token'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Twitch] refresh_tokens — no refresh token stored; bot must be reconnected' );
			}
			return false;
		}

		$creds = $this->get_app_credentials();
		if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Twitch] refresh_tokens — app credentials missing; cannot refresh' );
			}
			return false;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $tokens['refresh_token'],
					'client_id'     => $creds['client_id'],
					'client_secret' => $creds['client_secret'],
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Twitch] refresh_tokens — network error: ' . $response->get_error_message() );
			}
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = \json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$msg = \is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : 'no message';
				\error_log( '[CCMerch Twitch] refresh_tokens — Twitch returned HTTP ' . $code . ': ' . $msg );
			}
			return false;
		}

		if ( ! \is_array( $body ) || empty( $body['access_token'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Twitch] refresh_tokens — 200 response but no access_token in body' );
			}
			return false;
		}

		// Twitch always issues a new refresh token on every refresh — the old one is dead.
		// Preserve bot_id and bot_login from the existing tokens since they do not change.
		$new_tokens = [
			'access_token'  => (string) $body['access_token'],
			'refresh_token' => (string) ( $body['refresh_token'] ?? $tokens['refresh_token'] ),
			'bot_id'        => (string) ( $tokens['bot_id']    ?? '' ),
			'bot_login'     => (string) ( $tokens['bot_login'] ?? '' ),
		];

		$this->store_tokens( $new_tokens );

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$expires_in = (int) ( $body['expires_in'] ?? 0 );
			\error_log( '[CCMerch Twitch] refresh_tokens — success; new token expires_in=' . $expires_in . 's' );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the OAuth redirect URI pointing at this AJAX handler.
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return admin_url( 'admin-ajax.php?action=' . self::AJAX_CALLBACK );
	}

	/**
	 * Exchanges an authorization code for access and refresh tokens.
	 *
	 * @param string $code Authorization code from Twitch.
	 * @return array<string, string> Empty array on failure.
	 */
	private function exchange_code( string $code ): array {
		$creds = $this->get_app_credentials();
		if ( empty( $creds['client_id'] ) || empty( $creds['client_secret'] ) ) {
			return [];
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 10,
				'body'    => [
					'client_id'     => $creds['client_id'],
					'client_secret' => $creds['client_secret'],
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $this->get_redirect_uri(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$body = \json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $body ) || empty( $body['access_token'] ) ) {
			return [];
		}

		return [
			'access_token'  => (string) $body['access_token'],
			'refresh_token' => (string) ( $body['refresh_token'] ?? '' ),
		];
	}

	/**
	 * Fetches the Twitch user info (id + login) for the authenticated bot account.
	 *
	 * @param string $access_token Valid Twitch access token.
	 * @return array{id: string, login: string}|null User info or null on failure.
	 */
	public function fetch_user_info( string $access_token ): ?array {
		$creds = $this->get_app_credentials();
		if ( empty( $creds['client_id'] ) ) {
			return null;
		}

		$response = wp_remote_get(
			self::USERS_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
					'Client-Id'     => $creds['client_id'],
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = \json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $body ) || empty( $body['data'][0]['id'] ) ) {
			return null;
		}

		return [
			'id'    => (string) $body['data'][0]['id'],
			'login' => (string) ( $body['data'][0]['login'] ?? '' ),
		];
	}

	/**
	 * Fetches the Twitch user ID for the authenticated bot account.
	 *
	 * @param string $access_token Valid Twitch access token.
	 * @return string|null User ID string or null on failure.
	 */
	public function fetch_user_id( string $access_token ): ?string {
		$info = $this->fetch_user_info( $access_token );
		return null !== $info ? $info['id'] : null;
	}

	/**
	 * Returns the stored bot account login name, or an empty string if not yet stored.
	 *
	 * @return string
	 */
	public function get_bot_login(): string {
		$tokens = $this->get_tokens();
		return (string) ( $tokens['bot_login'] ?? '' );
	}

	/**
	 * Validates the stored access token against the Twitch validation endpoint.
	 *
	 * Uses the `OAuth` prefix (not `Bearer`) as required by this endpoint.
	 * Returns an empty array when no tokens are stored.
	 *
	 * @return array{valid?: bool, login?: string, user_id?: string, scopes?: string[], expires_in?: int, error?: string}
	 */
	public function validate_token(): array {
		$tokens = $this->get_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			return [];
		}

		$response = wp_remote_get(
			self::VALIDATE_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'OAuth ' . $tokens['access_token'],
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'valid' => false, 'error' => $response->get_error_message() ];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = \json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$msg = \is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : 'Token invalid or expired';
			return [ 'valid' => false, 'error' => $msg ];
		}

		if ( ! \is_array( $body ) ) {
			return [ 'valid' => false, 'error' => 'Unexpected response from Twitch validation endpoint' ];
		}

		return [
			'valid'      => true,
			'login'      => (string) ( $body['login']      ?? '' ),
			'user_id'    => (string) ( $body['user_id']    ?? '' ),
			'scopes'     => (array)  ( $body['scopes']     ?? [] ),
			'expires_in' => (int)    ( $body['expires_in'] ?? 0 ),
		];
	}

	// -------------------------------------------------------------------------
	// Encryption / decryption
	// -------------------------------------------------------------------------

	/**
	 * Encrypts a string using AES-256-CBC.
	 *
	 * Key: first 32 bytes of SHA-256(wp_salt('auth'), raw binary output).
	 * IV:  16 random bytes prepended to the ciphertext before base64 encoding.
	 *
	 * @param string $plaintext Plain text to encrypt.
	 * @return string base64-encoded IV + ciphertext, or empty string on failure.
	 */
	private function encrypt( string $plaintext ): string {
		$key = \substr( \hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$iv  = \random_bytes( 16 );

		$ciphertext = \openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}

		return \base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypts a string that was encrypted with encrypt().
	 *
	 * @param string $encrypted base64-encoded IV + ciphertext.
	 * @return string Decrypted plain text, or empty string on failure.
	 */
	private function decrypt( string $encrypted ): string {
		$key  = \substr( \hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
		$raw  = \base64_decode( $encrypted, true );

		if ( false === $raw || \strlen( $raw ) < 16 ) {
			return '';
		}

		$iv         = \substr( $raw, 0, 16 );
		$ciphertext = \substr( $raw, 16 );

		$plaintext = \openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plaintext ? '' : $plaintext;
	}
}
