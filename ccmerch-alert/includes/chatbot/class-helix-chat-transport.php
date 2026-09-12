<?php
/**
 * Helix Chat API transport implementation.
 *
 * @package CCMerchAlert\Chatbot
 */

namespace CCMerchAlert\Chatbot;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivers Twitch chat messages via the Helix Chat Messages REST API
 * (POST /helix/chat/messages). This is the sole production transport.
 *
 * Delegates all API communication to Twitch_Chat_Service so token
 * management, broadcaster-ID caching, and retry logic stay in one place.
 *
 * Must never be instantiated more than once.
 */
final class HelixChatTransport implements ChatTransportInterface {

	/**
	 * @param Twitch_Chat_Service $twitch_chat_service Helix API message sender.
	 */
	public function __construct(
		private readonly Twitch_Chat_Service $twitch_chat_service,
	) {}

	/**
	 * Sends a message via the Helix Chat Messages API.
	 *
	 * @param string $channel Twitch channel login name.
	 * @param string $message Message text.
	 * @return bool True on success; false on API failure.
	 */
	public function send( string $channel, string $message ): bool {
		return $this->twitch_chat_service->send_message( $channel, $message );
	}

	/**
	 * Returns the human-readable transport label used in admin diagnostics.
	 *
	 * @return string
	 */
	public function get_transport_label(): string {
		return 'Helix Chat API';
	}
}
