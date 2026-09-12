<?php
/**
 * Overlay template engine.
 *
 * @package CCMerchAlert\Overlay
 */

namespace CCMerchAlert\Overlay;

use CCMerchAlert\OrderLog\Order_Log_Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces $Variable placeholders in an overlay template string.
 *
 * Each variable is backed by a resolver callable that receives an
 * Order_Log_Entry and returns a plain string. New variables can be added
 * via {@see register()} without modifying any existing code.
 *
 * Built-in variables:
 *  $FirstName   — billing first name
 *  $Product     — first line-item product name
 *  $Variation   — variation attribute labels (empty for simple products)
 *  $Category    — comma-separated category names of the first line item
 *  $Quantity    — quantity of the first line item
 *  $OrderNumber — WooCommerce order number
 *
 * Must never: output HTML. Must never read from the database. Must never
 * call WooCommerce order queries — it only receives pre-loaded value objects.
 */
final class Overlay_Template_Engine {

	/**
	 * Map of placeholder string → resolver callable.
	 *
	 * @var array<string, callable(Order_Log_Entry): string>
	 */
	private array $resolvers = [];

	public function __construct() {
		$this->register_defaults();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Registers or overwrites a variable resolver.
	 *
	 * The callable receives an Order_Log_Entry and must return a plain string.
	 * The returned string is HTML-escaped by {@see render()} before insertion.
	 *
	 * @param string                            $placeholder Exact placeholder string, e.g. '$FirstName'.
	 * @param callable(Order_Log_Entry): string $resolver    Returns the replacement value.
	 * @return void
	 */
	public function register( string $placeholder, callable $resolver ): void {
		$this->resolvers[ $placeholder ] = $resolver;
	}

	/**
	 * Renders a template string against an order log entry.
	 *
	 * All registered variable values are resolved and HTML-escaped before
	 * substitution so that the caller can embed the result directly in HTML.
	 *
	 * @param  string          $template Template string containing $Variable placeholders.
	 * @param  Order_Log_Entry $entry    Logged order to resolve variables from.
	 * @return string Rendered string with all placeholders replaced by escaped values.
	 */
	public function render( string $template, Order_Log_Entry $entry ): string {
		$search  = \array_keys( $this->resolvers );
		$replace = [];

		foreach ( $this->resolvers as $resolver ) {
			$replace[] = esc_html( (string) $resolver( $entry ) );
		}

		return \str_replace( $search, $replace, $template );
	}

	// -------------------------------------------------------------------------
	// Private: default variable registrations
	// -------------------------------------------------------------------------

	/**
	 * Registers the six built-in variables.
	 *
	 * @return void
	 */
	private function register_defaults(): void {
		$this->register(
			'$FirstName',
			static fn ( Order_Log_Entry $e ): string => $e->first_name
		);

		$this->register(
			'$OrderNumber',
			static fn ( Order_Log_Entry $e ): string => $e->order_number
		);

		$this->register(
			'$Product',
			static fn ( Order_Log_Entry $e ): string => $e->items[0]->product_name ?? ''
		);

		$this->register(
			'$Quantity',
			static fn ( Order_Log_Entry $e ): string => isset( $e->items[0] ) ? (string) $e->items[0]->quantity : ''
		);

		$this->register(
			'$Category',
			fn ( Order_Log_Entry $e ): string => $this->resolve_category( $e )
		);

		$this->register(
			'$Variation',
			fn ( Order_Log_Entry $e ): string => $this->resolve_variation( $e )
		);
	}

	/**
	 * Resolves the category names for the first line item.
	 *
	 * Term IDs are stored in Order_Log_Item; names are resolved via get_term()
	 * at render time so they reflect current taxonomy state.
	 *
	 * @param  Order_Log_Entry $entry Logged order.
	 * @return string Comma-separated category names, or empty string.
	 */
	private function resolve_category( Order_Log_Entry $entry ): string {
		$item = $entry->items[0] ?? null;

		if ( null === $item || empty( $item->category_ids ) ) {
			return '';
		}

		$names = [];

		foreach ( $item->category_ids as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}

		return \implode( ', ', $names );
	}

	/**
	 * Resolves variation attribute labels for the first line item.
	 *
	 * Requires WooCommerce to be active. Returns empty string for simple
	 * products (variation_id === null) or when WooCommerce is inactive.
	 *
	 * @param  Order_Log_Entry $entry Logged order.
	 * @return string Comma-separated attribute values, e.g. "Red, Large".
	 */
	private function resolve_variation( Order_Log_Entry $entry ): string {
		$item = $entry->items[0] ?? null;

		if ( null === $item || null === $item->variation_id ) {
			return '';
		}

		if ( ! \function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $item->variation_id );

		if ( ! $product instanceof \WC_Product_Variation ) {
			return '';
		}

		$parts = [];

		foreach ( $product->get_variation_attributes() as $raw_value ) {
			if ( '' !== (string) $raw_value ) {
				$parts[] = $raw_value;
			}
		}

		return \implode( ', ', $parts );
	}
}
