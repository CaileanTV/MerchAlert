<?php
/**
 * Channel contract.
 *
 * @package CCMerchAlert\Engine\Contracts
 */

namespace CCMerchAlert\Engine\Contracts;

use CCMerchAlert\Engine\Alert_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A notification delivery channel — a specialised action that sends a message.
 *
 * Channels extend Action_Interface and add the concept of a dedicated send()
 * method, making them identifiable as delivery mechanisms in the registry and
 * admin UI (e.g. for connection testing, template previews).
 *
 * Built-in examples (future): Discord_Channel, Slack_Channel, Email_Channel,
 * Webhook_Channel, Browser_Channel.
 */
interface Channel_Interface extends Action_Interface {

	/**
	 * Sends a notification to this channel using the event context.
	 *
	 * The engine calls execute() (from Action_Interface); send() is available
	 * for direct invocation when testing or previewing a channel independently.
	 *
	 * @param Alert_Context $context Event context produced by a trigger.
	 * @return void
	 */
	public function send( Alert_Context $context ): void;
}
