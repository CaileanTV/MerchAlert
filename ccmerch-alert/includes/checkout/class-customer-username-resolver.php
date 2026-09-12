<?php
/**
 * Customer Username template variable resolver.
 *
 * @package CCMerchAlert\Checkout
 */

namespace CCMerchAlert\Checkout;

use CCMerchAlert\OrderLog\Order_Log_Entry;
use CCMerchAlert\Overlay\Overlay_Template_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the $UserName template variable from WooCommerce order meta.
 *
 * Reads the '_ccmerch_username' meta key written by Customer_Username_Field.
 * Returns 'Anonymous' when the meta is absent — this covers orders placed when
 * the feature was disabled, orders that predate the feature, and customers who
 * left the field empty (the field handler already stored 'Anonymous' there, but
 * the fallback here ensures correctness if the meta is ever missing).
 *
 * The $UserName variable is independent of $FirstName. Creators choose which to
 * display in their template; both can appear in the same template simultaneously.
 *
 * Must never: be instantiated.
 * Must never: register WordPress hooks.
 * Must never: be duplicated — this is the single implementation for username resolution.
 */
final class Customer_Username_Resolver {

	/**
	 * Registers the $UserName variable resolver on the given template engine.
	 *
	 * Call this on every Overlay_Template_Engine instance that renders alerts so
	 * the variable is available in global overlays, creator overlays, replay alerts,
	 * and test alerts alike.
	 *
	 * @param  Overlay_Template_Engine $engine Engine instance to extend.
	 * @return void
	 */
	public static function register_on_engine( Overlay_Template_Engine $engine ): void {
		$engine->register(
			'$UserName',
			static function ( Order_Log_Entry $entry ): string {
				$value = (string) get_post_meta( $entry->order_id, Customer_Username_Field::META_KEY, true );
				return '' !== $value ? $value : 'Anonymous';
			}
		);
	}
}
