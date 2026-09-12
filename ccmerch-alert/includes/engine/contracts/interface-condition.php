<?php
/**
 * Condition contract.
 *
 * @package CCMerchAlert\Engine\Contracts
 */

namespace CCMerchAlert\Engine\Contracts;

use CCMerchAlert\Engine\Alert_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates a business-logic condition against an incoming alert event.
 *
 * Conditions gate whether an alert fires. All conditions attached to an alert
 * must return true for the alert to proceed to dispatch.
 *
 * Built-in examples (future): Order_Value_Condition, Customer_Country_Condition.
 */
interface Condition_Interface {

	/**
	 * Machine-readable identifier, unique across all registered conditions.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable label shown in the alert condition builder.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Returns true when this condition is satisfied by the event context.
	 *
	 * @param Alert_Context $context Event context produced by a trigger.
	 * @return bool
	 */
	public function evaluate( Alert_Context $context ): bool;
}
