<?php
/**
 * Chatbot health monitor.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks and persists runtime health metrics for the Twitch chatbot.
 *
 * Stores a single option (`ccmerch_chatbot_health`) with connection status,
 * token state, per-channel message counters, and failure tracking. All writes
 * go through this class — no other class may update the health option directly.
 *
 * Must never be instantiated more than once.
 * Must never contain delivery or OAuth logic.
 */
final class Chatbot_Health_Monitor {

	/** @var string WordPress option key (autoload=no). */
	public const OPTION_KEY = 'ccmerch_chatbot_health';

	/** @var string Bot status: bot account connected and token valid. */
	public const STATUS_CONNECTED = 'connected';

	/** @var string Bot status: no token stored / OAuth not completed. */
	public const STATUS_DISCONNECTED = 'disconnected';

	/** @var string Bot status: token exists but last refresh failed. */
	public const STATUS_RECONNECTING = 'reconnecting';

	/** @var string Bot status: unknown / never checked. */
	public const STATUS_UNKNOWN = 'unknown';

	/** @var array<string, mixed>|null In-process cache of the health data. */
	private ?array $data = null;

	// -------------------------------------------------------------------------
	// Status accessors
	// -------------------------------------------------------------------------

	/**
	 * Returns the current bot connection status string.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function get_bot_status(): string {
		return (string) ( $this->load()['bot_status'] ?? self::STATUS_UNKNOWN );
	}

	/**
	 * Returns the Unix timestamp of the last token refresh attempt, or 0 if never.
	 *
	 * @return int
	 */
	public function get_last_refresh_at(): int {
		return (int) ( $this->load()['last_token_refresh_at'] ?? 0 );
	}

	/**
	 * Returns true if the last token refresh attempt succeeded.
	 *
	 * @return bool
	 */
	public function last_refresh_ok(): bool {
		return (bool) ( $this->load()['last_token_refresh_ok'] ?? false );
	}

	/**
	 * Returns the total number of messages sent across all channels.
	 *
	 * @return int
	 */
	public function get_total_sent(): int {
		return (int) ( $this->load()['total_messages_sent'] ?? 0 );
	}

	/**
	 * Returns the total number of message delivery failures.
	 *
	 * @return int
	 */
	public function get_total_failed(): int {
		return (int) ( $this->load()['total_messages_failed'] ?? 0 );
	}

	/**
	 * Returns per-channel stats array (may be empty).
	 *
	 * Shape: [ 'channel_login' => [ 'messages_sent', 'messages_failed', 'consecutive_failures', 'last_sent_at' ] ]
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_channel_stats(): array {
		$data = $this->load();
		return \is_array( $data['channels'] ?? null ) ? $data['channels'] : [];
	}

	/**
	 * Returns the stats array for a specific channel (defaults to zeroed entry).
	 *
	 * @param string $channel_login Twitch login name.
	 * @return array<string, mixed>
	 */
	public function get_channel_stat( string $channel_login ): array {
		$stats = $this->get_channel_stats();
		return $stats[ $channel_login ] ?? $this->default_channel_stat();
	}

	// -------------------------------------------------------------------------
	// Mutators
	// -------------------------------------------------------------------------

	/**
	 * Updates the bot connection status.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @return void
	 */
	public function set_bot_status( string $status ): void {
		$data               = $this->load();
		$data['bot_status'] = $status;
		$this->persist( $data );
	}

	/**
	 * Records the result of a token refresh attempt.
	 *
	 * @param bool $success Whether the refresh succeeded.
	 * @return void
	 */
	public function record_token_refresh( bool $success ): void {
		$data                           = $this->load();
		$data['last_token_refresh_at']  = \time();
		$data['last_token_refresh_ok']  = $success;
		$data['bot_status']             = $success ? self::STATUS_CONNECTED : self::STATUS_RECONNECTING;
		$this->persist( $data );
	}

	/**
	 * Records a successful message delivery to a Twitch channel.
	 *
	 * @param string $channel_login Twitch channel the message was delivered to.
	 * @return void
	 */
	public function record_message_sent( string $channel_login ): void {
		$data = $this->load();

		$data['total_messages_sent'] = ( (int) ( $data['total_messages_sent'] ?? 0 ) ) + 1;

		$ch                          = $this->get_channel_stat( $channel_login );
		$ch['messages_sent']         = ( (int) $ch['messages_sent'] ) + 1;
		$ch['consecutive_failures']  = 0;
		$ch['last_sent_at']          = \gmdate( 'Y-m-d H:i:s' );

		$data['channels'][ $channel_login ] = $ch;
		$this->persist( $data );
	}

	/**
	 * Records a failed message delivery attempt to a Twitch channel.
	 *
	 * @param string $channel_login Twitch channel the send failed for.
	 * @return void
	 */
	public function record_message_failed( string $channel_login ): void {
		$data = $this->load();

		$data['total_messages_failed'] = ( (int) ( $data['total_messages_failed'] ?? 0 ) ) + 1;

		$ch                         = $this->get_channel_stat( $channel_login );
		$ch['messages_failed']      = ( (int) $ch['messages_failed'] ) + 1;
		$ch['consecutive_failures'] = ( (int) $ch['consecutive_failures'] ) + 1;

		$data['channels'][ $channel_login ] = $ch;
		$this->persist( $data );
	}

	/**
	 * Resets the in-process cache so the next read re-fetches from DB.
	 *
	 * @return void
	 */
	public function invalidate(): void {
		$this->data = null;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the full health data array, loading from the option if needed.
	 *
	 * @return array<string, mixed>
	 */
	private function load(): array {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION_KEY, [] );
			$this->data = \is_array( $stored ) ? $stored : [];
		}
		return $this->data;
	}

	/**
	 * Persists the health data array and updates the in-process cache.
	 *
	 * @param array<string, mixed> $data Updated data.
	 * @return void
	 */
	private function persist( array $data ): void {
		$this->data = $data;
		update_option( self::OPTION_KEY, $data, false );
	}

	/**
	 * Returns a zeroed channel stats entry.
	 *
	 * @return array<string, mixed>
	 */
	private function default_channel_stat(): array {
		return [
			'messages_sent'        => 0,
			'messages_failed'      => 0,
			'consecutive_failures' => 0,
			'last_sent_at'         => '',
		];
	}
}
