<?php
/**
 * Chatbot token manager.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages proactive token validation and refresh for the bot account.
 *
 * Runs both on-demand (called from the hot message path) and proactively (via
 * WP-Cron). Caches the validation result in a transient so the Twitch API is
 * not hit on every message delivery.
 *
 * Must never contain message delivery or OAuth flow logic.
 * Must never be instantiated more than once.
 */
final class Chatbot_Token_Manager {

	/** @var string Transient key for the cached token validation result. */
	private const TRANSIENT_STATUS = 'ccmerch_chatbot_token_status';

	/** @var int Seconds before the cached status expires (50 minutes). */
	private const STATUS_TTL = 3000;

	/** @var string Twitch token validation endpoint. */
	private const VALIDATE_URL = 'https://id.twitch.tv/oauth2/validate';

	/** @var Twitch_Auth_Service */
	private Twitch_Auth_Service $auth_service;

	/** @var Chatbot_Health_Monitor */
	private Chatbot_Health_Monitor $health_monitor;

	/**
	 * @param Twitch_Auth_Service   $auth_service   Bot account OAuth service.
	 * @param Chatbot_Health_Monitor $health_monitor Health metrics recorder.
	 */
	public function __construct(
		Twitch_Auth_Service $auth_service,
		Chatbot_Health_Monitor $health_monitor
	) {
		$this->auth_service   = $auth_service;
		$this->health_monitor = $health_monitor;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the bot token is currently valid.
	 *
	 * Checks the cached transient first to avoid unnecessary Twitch API calls.
	 * If the cache has expired, calls the Twitch validate endpoint.
	 *
	 * @return bool
	 */
	public function is_token_valid(): bool {
		$cached = get_transient( self::TRANSIENT_STATUS );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		return $this->validate_and_cache();
	}

	/**
	 * Forces a fresh token validation against the Twitch API.
	 *
	 * Refreshes the token if it will expire within the next hour or if
	 * validation returns 401. Updates the health monitor with the result.
	 *
	 * Called by the WP-Cron hourly check and the admin "Test Connection" action.
	 *
	 * @return bool True when a valid token exists after the operation.
	 */
	public function run_maintenance(): bool {
		delete_transient( self::TRANSIENT_STATUS );
		return $this->validate_and_cache();
	}

	/**
	 * Invalidates the cached token status without hitting the Twitch API.
	 *
	 * Call after a token refresh or revocation to force re-validation on the
	 * next `is_token_valid()` call.
	 *
	 * @return void
	 */
	public function invalidate_cache(): void {
		delete_transient( self::TRANSIENT_STATUS );
		$this->health_monitor->invalidate();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Validates the bot access token against the Twitch API.
	 *
	 * If validation fails with 401, attempts a single token refresh. Caches
	 * the result in a transient and updates the health monitor.
	 *
	 * @return bool True when valid.
	 */
	private function validate_and_cache(): bool {
		$tokens = $this->auth_service->get_tokens();
		if ( empty( $tokens['access_token'] ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Token] validate_and_cache — no access token stored; bot is not connected' );
			}
			$this->cache_status( false );
			$this->health_monitor->set_bot_status( Chatbot_Health_Monitor::STATUS_DISCONNECTED );
			return false;
		}

		$response = wp_remote_get(
			self::VALIDATE_URL,
			[
				'headers' => [ 'Authorization' => 'OAuth ' . $tokens['access_token'] ],
				'timeout' => 8,
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Token] validate_and_cache — Twitch validate network error: ' . $response->get_error_message() );
			}
			// Network error — treat as unknown rather than disconnected.
			$this->cache_status( false, 60 );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$body       = \json_decode( wp_remote_retrieve_body( $response ), true );
			$expires_in = (int) ( $body['expires_in'] ?? 3600 );

			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Token] validate_and_cache — token valid; expires_in=' . $expires_in . 's' );
			}

			if ( $expires_in < 7200 ) {
				// Token expires within 2 hours — refresh proactively.
				// 2-hour window gives the hourly WP-Cron 2 chances to catch an expiring token
				// before it actually expires, accounting for cron timing jitter on low-traffic sites.
				if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					\error_log( '[CCMerch Token] validate_and_cache — token near expiry (' . $expires_in . 's); attempting proactive refresh' );
				}
				$refreshed = $this->auth_service->refresh_tokens();
				if ( $refreshed ) {
					if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						\error_log( '[CCMerch Token] validate_and_cache — proactive refresh succeeded' );
					}
					$this->health_monitor->record_token_refresh( true );
					$this->health_monitor->set_bot_status( Chatbot_Health_Monitor::STATUS_CONNECTED );
					$this->cache_status( true );
					return true;
				}
				// Refresh failed but current token is still valid — use proportional TTL so
				// the cache expires before the token does rather than reporting "valid" for 50 min.
				if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					\error_log( '[CCMerch Token] validate_and_cache — proactive refresh failed; token still valid for ' . $expires_in . 's; caching with proportional TTL' );
				}
				$this->health_monitor->record_token_refresh( false );
				$cache_ttl = \min( self::STATUS_TTL, \max( 60, $expires_in - 60 ) );
				$this->cache_status( true, $cache_ttl );
				$this->health_monitor->set_bot_status( Chatbot_Health_Monitor::STATUS_CONNECTED );
				return true;
			}

			$this->cache_status( true );
			$this->health_monitor->set_bot_status( Chatbot_Health_Monitor::STATUS_CONNECTED );
			return true;
		}

		if ( 401 === $code ) {
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Token] validate_and_cache — token expired (401); attempting refresh' );
			}
			// Token expired — attempt refresh.
			$refreshed = $this->auth_service->refresh_tokens();
			if ( $refreshed ) {
				if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					\error_log( '[CCMerch Token] validate_and_cache — refresh after 401 succeeded' );
				}
				$this->health_monitor->record_token_refresh( true );
				$this->health_monitor->set_bot_status( Chatbot_Health_Monitor::STATUS_CONNECTED );
				$this->cache_status( true );
				return true;
			}
			if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( '[CCMerch Token] validate_and_cache — refresh after 401 failed; bot disconnected' );
			}
			$this->health_monitor->record_token_refresh( false );
			$this->health_monitor->set_bot_status( Chatbot_Health_Monitor::STATUS_DISCONNECTED );
			$this->cache_status( false );
			return false;
		}

		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch Token] validate_and_cache — unexpected HTTP ' . $code . ' from Twitch validate endpoint; short retry TTL' );
		}
		// Unexpected status — short cache TTL to retry soon.
		$this->cache_status( false, 120 );
		return false;
	}

	/**
	 * Stores the token validity result in a transient.
	 *
	 * @param bool $valid  Whether the token is valid.
	 * @param int  $ttl    TTL in seconds (defaults to STATUS_TTL).
	 * @return void
	 */
	private function cache_status( bool $valid, int $ttl = self::STATUS_TTL ): void {
		set_transient( self::TRANSIENT_STATUS, $valid ? '1' : '0', $ttl );
	}
}
