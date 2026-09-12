<?php
/**
 * Trigger contract.
 *
 * @package CCMerchAlert\Engine\Contracts
 */

namespace CCMerchAlert\Engine\Contracts;

use CCMerchAlert\Engine\Alert_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a single event source that can fire an alert.
 *
 * A trigger knows which WordPress/WooCommerce hook to listen on. When that
 * hook fires, the trigger builds an Alert_Context from the event data and
 * calls $dispatch to hand it to the Alert_Engine.
 *
 * Built-in examples (future): New_Order_Trigger, Low_Stock_Trigger.
 * Third-party code registers custom triggers via the 'ccmerch_alert_init' hook.
 */
interface Trigger_Interface {

	/**
	 * Machine-readable identifier, unique across all registered triggers.
	 *
	 * Used to match alert configurations to their trigger type in storage.
	 * Example: 'new_order', 'low_stock', 'high_value_order'.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable label shown in the admin UI trigger selector.
	 *
	 * Should be translated with __() in the implementation.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Attaches the WordPress/WooCommerce hooks that activate this trigger.
	 *
	 * Called by {@see \CCMerchAlert\Engine\Alert_Engine::init()} once during
	 * plugin boot. When the underlying event fires, the implementation must
	 * build an Alert_Context and invoke $dispatch to forward it to the engine.
	 *
	 * @param callable(Alert_Context):void $dispatch Dispatch callback from Alert_Engine.
	 * @return void
	 */
	public function register( callable $dispatch ): void;
}
