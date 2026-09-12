<?php
/**
 * Chatbot settings service.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business-logic layer for chatbot configuration.
 *
 * Provides get/save operations for per-creator chatbot settings and exposes
 * the connection status check used by both the admin and the Creator Portal.
 *
 * Depends on Chatbot_Repository for all DB access and Twitch_Auth_Service
 * for the bot connection state. Must never contain routing or rendering logic.
 */
final class Chatbot_Settings_Service {

	/** @var string Default message template for new creator rows. */
	public const DEFAULT_TEMPLATE = '🔔 $Name just bought $Product!';

	/**
	 * @param Chatbot_Repository  $repository   Data access layer.
	 * @param Twitch_Auth_Service $auth_service Token and credential store.
	 */
	public function __construct(
		private readonly Chatbot_Repository  $repository,
		private readonly Twitch_Auth_Service $auth_service,
	) {}

	// -------------------------------------------------------------------------
	// Connection state
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the bot account has valid stored tokens.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->auth_service->is_connected();
	}

	/**
	 * Returns the Twitch login name of the bot account, or empty string if not yet connected.
	 *
	 * @return string
	 */
	public function get_bot_login(): string {
		return $this->auth_service->get_bot_login();
	}

	// -------------------------------------------------------------------------
	// Per-creator settings
	// -------------------------------------------------------------------------

	/**
	 * Returns the chatbot settings for a creator, with defaults when no row exists.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return Chatbot_Creator_Settings
	 */
	public function get_settings( int $creator_id ): Chatbot_Creator_Settings {
		$row = $this->repository->find_by_creator_id( $creator_id );

		if ( null !== $row ) {
			// Backfill empty template with default.
			if ( '' === $row->template ) {
				return new Chatbot_Creator_Settings(
					$row->id,
					$row->creator_id,
					$row->twitch_channel,
					$row->enabled,
					self::DEFAULT_TEMPLATE,
				);
			}
			return $row;
		}

		return new Chatbot_Creator_Settings(
			id:             0,
			creator_id:     $creator_id,
			twitch_channel: '',
			enabled:        false,
			template:       self::DEFAULT_TEMPLATE,
		);
	}

	/**
	 * Saves the creator's chatbot enabled state and message template.
	 *
	 * @param int    $creator_id Creator profile ID.
	 * @param bool   $enabled    Whether the chatbot is active for this creator.
	 * @param string $template   Message template string.
	 * @return bool True on success.
	 */
	public function save_creator_settings( int $creator_id, bool $enabled, string $template ): bool {
		$template = '' !== $template ? $template : self::DEFAULT_TEMPLATE;
		return $this->repository->save( $creator_id, $enabled, $template );
	}

	/**
	 * Sets the Twitch channel for a creator.
	 *
	 * Called by the admin when mapping creators to channels on the Chatbot page.
	 *
	 * @param int    $creator_id Creator profile ID.
	 * @param string $channel    Twitch channel login name (without #).
	 * @return bool True on success.
	 */
	public function set_creator_channel( int $creator_id, string $channel ): bool {
		return $this->repository->set_channel( $creator_id, $channel );
	}

	/**
	 * Returns the Twitch channel for a creator, or empty string if not configured.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return string
	 */
	public function get_channel( int $creator_id ): string {
		$row = $this->repository->find_by_creator_id( $creator_id );
		return null !== $row ? $row->twitch_channel : '';
	}

	/**
	 * Returns true when the creator has the chatbot enabled and a channel configured.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return bool
	 */
	public function is_enabled( int $creator_id ): bool {
		$row = $this->repository->find_by_creator_id( $creator_id );
		return null !== $row && $row->enabled && '' !== $row->twitch_channel;
	}

	/**
	 * Returns all chatbot settings rows indexed by creator_id.
	 *
	 * @return array<int, Chatbot_Creator_Settings>
	 */
	public function get_all_settings(): array {
		$rows   = $this->repository->get_all();
		$result = [];
		foreach ( $rows as $row ) {
			$result[ $row->creator_id ] = $row;
		}
		return $result;
	}

	/**
	 * Returns the message template for a creator, falling back to the default.
	 *
	 * @param int $creator_id Creator profile ID.
	 * @return string
	 */
	public function get_template( int $creator_id ): string {
		return $this->get_settings( $creator_id )->template;
	}
}
