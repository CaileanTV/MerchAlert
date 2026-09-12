<?php
/**
 * Creator channel authorization value object.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable record of a creator's Twitch channel:bot authorization grant.
 *
 * A row in this table means the creator (as a broadcaster) went through a
 * separate Twitch OAuth flow and explicitly authorized the CCMerch bot app to
 * send messages in their channel without moderator status.
 *
 * Must never be mutated after construction.
 * Must never contain repository or routing logic.
 */
final class Creator_Channel_Auth {

	/**
	 * @param int    $id            Primary key.
	 * @param int    $creator_id    FK to ccmerch_creator_profiles.id.
	 * @param string $channel_login Twitch login name of the broadcaster's channel (lowercase).
	 * @param string $scope         Space-separated OAuth scopes granted (at minimum 'channel:bot').
	 * @param string $authorized_at UTC datetime string when the grant was first stored.
	 * @param string $updated_at    UTC datetime string when the row was last written.
	 */
	public function __construct(
		public readonly int    $id,
		public readonly int    $creator_id,
		public readonly string $channel_login,
		public readonly string $scope,
		public readonly string $authorized_at,
		public readonly string $updated_at,
	) {}

	/**
	 * Returns true when this record represents a valid, stored authorization.
	 *
	 * A false result means the creator has never completed the channel:bot
	 * authorization flow — the bot should not attempt to send messages.
	 *
	 * @return bool
	 */
	public function is_authorized(): bool {
		return $this->creator_id > 0 && '' !== $this->channel_login;
	}
}
