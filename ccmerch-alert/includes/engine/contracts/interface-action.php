<?php
/**
 * Action contract.
 *
 * @package CCMerchAlert\Engine\Contracts
 */

namespace CCMerchAlert\Engine\Contracts;

use CCMerchAlert\Engine\Alert_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents anything that happens when an alert fires.
 *
 * Actions are the response side of the alert pipeline. Notification channels
 * (Discord, Slack, Email) are a specialised kind of action. Future actions
 * may include tagging orders, updating inventory, or triggering webhooks.
 *
 * All channels implement this interface via {@see Channel_Interface}.
 */
interface Action_Interface {

	/**
	 * Machine-readable identifier, unique across all registered actions.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable label shown in the alert action builder.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Executes this action using the event context.
	 *
	 * Implementations should be fault-tolerant: log errors but do not throw
	 * exceptions that would abort other actions in the same alert.
	 *
	 * @param Alert_Context $context Event context produced by a trigger.
	 * @return void
	 */
	public function execute( Alert_Context $context ): void;
}
