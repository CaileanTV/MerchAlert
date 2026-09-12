<?php
/**
 * Twitch chat message delivery service.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends messages to a Twitch channel via the Helix chat messages endpoint.
 *
 * Broadcaster IDs are cached in transients (TTL 3600 s). On 401, the service
 * attempts one token refresh then retries. HTTP 2xx responses are only treated
 * as success when Twitch confirms is_sent === true in the response body — a
 * 2xx with is_sent === false means the message was silently dropped.
 *
 * All failures are silent to the checkout flow but recorded in DIAGNOSTICS_OPTION
 * so the admin Chatbot Diagnostics card can surface the root cause.
 *
 * The chatbot must never throw an exception that propagates to WooCommerce.
 */
final class Twitch_Chat_Service {

	/** @var string Twitch Helix chat messages endpoint. */
	private const CHAT_URL = 'https://api.twitch.tv/helix/chat/messages';

	/** @var string Twitch Helix users endpoint (broadcaster ID lookup). */
	private const USERS_URL = 'https://api.twitch.tv/helix/users';

	/** @var string Transient key prefix for cached broadcaster IDs. */
	private const BROADCASTER_TRANSIENT_PREFIX = 'ccmerch_twitch_uid_';

	/** @var string wp_options key for per-channel diagnostic data. */
	public const DIAGNOSTICS_OPTION = 'ccmerch_chatbot_diagnostics';

	/**
	 * @param Twitch_Auth_Service    $auth_service    Token and credential store.
	 * @param Chatbot_Health_Monitor $health_monitor  Health metrics recorder (optional for backwards compat).
	 */
	public function __construct(
		private readonly Twitch_Auth_Service $auth_service,
		private readonly ?Chatbot_Health_Monitor $health_monitor = null,
	) {}

	/**
	 * Sends a message to a Twitch channel.
	 *
	 * Returns true only when Twitch confirms is_sent === true.
	 * Records diagnostic data on every attempt regardless of outcome.
	 *
	 * @param string $channel Twitch channel login name (without #).
	 * @param string $message Message text to send.
	 * @param bool   $retry   Attempt one token refresh on 401 before failing.
	 * @return bool True if Twitch confirmed the message was delivered.
	 */
	public function send_message( string $channel, string $message, bool $retry = true ): bool {
		$tokens = $this->auth_service->get_tokens();

		if ( empty( $tokens['access_token'] ) || empty( $tokens['bot_id'] ) ) {
			$missing = empty( $tokens['access_token'] ) ? 'access_token' : 'bot_id';
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Chatbot] send_message aborted — ' . $missing . ' is empty. Reconnect bot account.' );
			}
			$this->record_diagnostic( $channel, [
				'last_error'       => 'No access token or bot user ID stored. Disconnect and reconnect the bot account on the Chatbot page.',
				'last_http_status' => 0,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => '',
				'broadcaster_id'   => '',
			] );
			return false;
		}

		$creds = $this->auth_service->get_app_credentials();
		if ( empty( $creds['client_id'] ) ) {
			$this->record_diagnostic( $channel, [
				'last_error'       => 'No Client ID configured. Enter credentials on the Chatbot page.',
				'last_http_status' => 0,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => '',
			] );
			return false;
		}

		$broadcaster_id = $this->get_broadcaster_id( $channel, $tokens['access_token'], $creds['client_id'] );
		if ( null === $broadcaster_id ) {
			$this->record_diagnostic( $channel, [
				'last_error'       => 'Could not resolve broadcaster ID for channel "' . $channel . '". Verify the channel name is correct.',
				'last_http_status' => 0,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => '',
			] );
			return false;
		}

		$response = wp_remote_post(
			self::CHAT_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Bearer ' . $tokens['access_token'],
					'Client-Id'     => $creds['client_id'],
					'Content-Type'  => 'application/json',
				],
				'body' => (string) \json_encode( [
					'broadcaster_id' => $broadcaster_id,
					'sender_id'      => $tokens['bot_id'],
					'message'        => $message,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->record_diagnostic( $channel, [
				'last_error'       => 'HTTP request failed: ' . $response->get_error_message(),
				'last_http_status' => 0,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => $broadcaster_id,
			] );
			$this->health_monitor?->record_message_failed( $channel );
			return false;
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$parsed_body = \json_decode( $body_raw, true );

		// On 401, attempt one token refresh then retry once.
		if ( 401 === $status && $retry ) {
			$refreshed = $this->auth_service->refresh_tokens();
			if ( $refreshed ) {
				return $this->send_message( $channel, $message, false );
			}
			$this->record_diagnostic( $channel, [
				'last_error'       => 'HTTP 401 Unauthorized — token refresh failed. Disconnect and reconnect the bot account.',
				'last_http_status' => $status,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => $broadcaster_id,
			] );
			return false;
		}

		if ( 401 === $status ) {
			$this->record_diagnostic( $channel, [
				'last_error'       => 'HTTP 401 Unauthorized — access token is invalid and refresh failed. Disconnect and reconnect the bot account.',
				'last_http_status' => $status,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => $broadcaster_id,
			] );
			return false;
		}

		if ( 403 === $status ) {
			// Use stored bot_login (set during OAuth) for a human-readable /mod instruction.
			$bot_login = (string) ( $tokens['bot_login'] ?? $tokens['bot_id'] ?? 'the_bot_account' );
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Chatbot] 403 Forbidden — channel=' . $channel . ' bot_login=' . $bot_login . '. Bot needs /mod in broadcaster channel.' );
			}
			$this->record_diagnostic( $channel, [
				'last_error'       => 'HTTP 403 Forbidden — the bot is not a moderator in #' . $channel . '. Ask the broadcaster to run: /mod ' . $bot_login,
				'last_http_status' => $status,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => $broadcaster_id,
			] );
			return false;
		}

		if ( $status < 200 || $status >= 300 ) {
			$api_error = \is_array( $parsed_body ) && ! empty( $parsed_body['message'] )
				? 'Twitch API error: ' . (string) $parsed_body['message']
				: 'HTTP ' . $status;
			$this->record_diagnostic( $channel, [
				'last_error'       => $api_error,
				'last_http_status' => $status,
				'last_is_sent'     => false,
				'last_message'     => $message,
				'sender_id'        => (string) ( $tokens['bot_id'] ?? '' ),
				'broadcaster_id'   => $broadcaster_id,
			] );
			return false;
		}

		// 2xx response — verify is_sent; Twitch can return 200 with is_sent=false.
		$is_sent = \is_array( $parsed_body )
			&& isset( $parsed_body['data'][0]['is_sent'] )
			&& (bool) $parsed_body['data'][0]['is_sent'];

		$drop_reason = '';
		if ( ! $is_sent && \is_array( $parsed_body ) && ! empty( $parsed_body['data'][0]['drop_reason'] ) ) {
			$dr          = (array) $parsed_body['data'][0]['drop_reason'];
			$drop_reason = (string) ( $dr['code'] ?? '' );
			if ( ! empty( $dr['message'] ) ) {
				$drop_reason .= ': ' . (string) $dr['message'];
			}
		}

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch Chatbot] send_message result — channel=' . $channel . ' http=' . $status . ' is_sent=' . ( $is_sent ? 'true' : 'false' ) . ( $drop_reason ? ' drop_reason=' . $drop_reason : '' ) );
		}

		$this->record_diagnostic( $channel, [
			'last_error'        => $is_sent ? '' : 'Message silently dropped by Twitch. Reason: ' . ( $drop_reason ?: 'unknown' ),
			'last_http_status'  => $status,
			'last_is_sent'      => $is_sent,
			'last_drop_reason'  => $drop_reason,
			'last_message'      => $message,
			'sender_id'         => (string) ( $tokens['bot_id'] ?? '' ),
			'broadcaster_id'    => $broadcaster_id,
			'last_sent_message' => $is_sent ? $message : '',
		] );

		if ( $is_sent ) {
			$this->health_monitor?->record_message_sent( $channel );
		} else {
			$this->health_monitor?->record_message_failed( $channel );
		}

		return $is_sent;
	}

	/**
	 * Resolves and returns the Twitch broadcaster ID for a channel.
	 *
	 * Public so the admin Diagnostics card can call it directly.
	 * Results are cached in transients (TTL 3600 s).
	 *
	 * @param string $channel Twitch channel login name.
	 * @return string|null Broadcaster ID or null on failure.
	 */
	public function resolve_broadcaster_id( string $channel ): ?string {
		$tokens = $this->auth_service->get_tokens();
		$creds  = $this->auth_service->get_app_credentials();

		if ( empty( $tokens['access_token'] ) || empty( $creds['client_id'] ) ) {
			return null;
		}

		return $this->get_broadcaster_id( $channel, $tokens['access_token'], $creds['client_id'] );
	}

	/**
	 * Returns all stored per-channel diagnostics.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_diagnostics(): array {
		$data = get_option( self::DIAGNOSTICS_OPTION, [] );
		return \is_array( $data ) ? $data : [];
	}

	/**
	 * Returns diagnostics for a single channel.
	 *
	 * @param string $channel Twitch channel login name.
	 * @return array<string, mixed>
	 */
	public function get_channel_diagnostics( string $channel ): array {
		$all = $this->get_diagnostics();
		return \is_array( $all[ $channel ] ?? null ) ? $all[ $channel ] : [];
	}

	/**
	 * Resolves the broadcaster ID for a channel login, with transient caching.
	 *
	 * @param string $channel   Twitch channel login name.
	 * @param string $token     Valid access token.
	 * @param string $client_id Twitch app Client ID.
	 * @return string|null Broadcaster ID or null on failure.
	 */
	private function get_broadcaster_id( string $channel, string $token, string $client_id ): ?string {
		$transient_key = self::BROADCASTER_TRANSIENT_PREFIX . \sanitize_key( $channel );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$response = wp_remote_get(
			self::USERS_URL . '?login=' . rawurlencode( $channel ),
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Client-Id'     => $client_id,
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

		$broadcaster_id = (string) $body['data'][0]['id'];
		set_transient( $transient_key, $broadcaster_id, 3600 );

		return $broadcaster_id;
	}

	/**
	 * Records a per-channel diagnostic snapshot in wp_options.
	 *
	 * @param string              $channel Twitch channel login name.
	 * @param array<string,mixed> $data    Fields to write/overwrite.
	 * @return void
	 */
	private function record_diagnostic( string $channel, array $data ): void {
		$all = $this->get_diagnostics();

		$existing        = \is_array( $all[ $channel ] ?? null ) ? $all[ $channel ] : [];
		$all[ $channel ] = \array_merge( $existing, $data, [ 'timestamp' => \time() ] );

		update_option( self::DIAGNOSTICS_OPTION, $all, false );
	}
}
