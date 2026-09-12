<?php
/**
 * Filter contract.
 *
 * @package CCMerchAlert\Engine\Contracts
 */

namespace CCMerchAlert\Engine\Contracts;

use CCMerchAlert\Engine\Alert_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scopes an alert to a subset of entities within an event.
 *
 * Filters are entity-based constraints applied before conditions. They narrow
 * which products, customers, or order types an alert applies to, keeping
 * conditions focused on business logic rather than entity selection.
 *
 * Built-in examples (future): Product_Category_Filter, Customer_Role_Filter.
 */
interface Filter_Interface {

	/**
	 * Machine-readable identifier, unique across all registered filters.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable label shown in the alert filter builder.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Returns true when the event passes through this filter.
	 *
	 * @param Alert_Context $context Event context produced by a trigger.
	 * @return bool
	 */
	public function passes( Alert_Context $context ): bool;
}
