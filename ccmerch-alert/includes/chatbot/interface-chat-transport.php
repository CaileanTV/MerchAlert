<?php
/**
 * Chat transport interface.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for all Twitch chat message transports.
 *
 * Chatbot_Message_Service depends only on this interface.
 * Concrete implementations (HelixChatTransport) wire the actual API.
 */
interface ChatTransportInterface {

	/**
	 * Sends a message to the given Twitch channel.
	 *
	 * @param string $channel Twitch channel login name (without leading #).
	 * @param string $message Message text.
	 * @return bool True when the message was accepted by the transport; false on failure.
	 */
	public function send( string $channel, string $message ): bool;

	/**
	 * Returns a human-readable label identifying the transport implementation.
	 *
	 * Used exclusively for admin diagnostics display — never for routing logic.
	 *
	 * @return string
	 */
	public function get_transport_label(): string;
}
