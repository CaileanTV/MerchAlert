<?php
/**
 * Chatbot creator settings value object.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object representing the chatbot configuration for one creator.
 *
 * Populated by Chatbot_Repository::find_by_creator_id() and returned by
 * Chatbot_Settings_Service::get_settings(). Never mutated after construction.
 */
final class Chatbot_Creator_Settings {

	/**
	 * @param int    $id             Row ID (0 for default/unsaved records).
	 * @param int    $creator_id     Creator profile ID.
	 * @param string $twitch_channel Twitch channel login name (without #).
	 * @param bool   $enabled        Whether the chatbot is active for this creator.
	 * @param string $template       Message template with $Variable placeholders.
	 */
	public function __construct(
		public readonly int    $id,
		public readonly int    $creator_id,
		public readonly string $twitch_channel,
		public readonly bool   $enabled,
		public readonly string $template,
	) {}
}
