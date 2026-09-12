<?php
/**
 * Dashboard Recent Orders section.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Integrations\WooCommerce;
use CCMerchAlert\OrderLog\Order_Log_Entry;
use CCMerchAlert\OrderLog\Order_Log_Item;
use CCMerchAlert\OrderLog\Order_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the "Recent Orders" section on the plugin dashboard.
 *
 * This class is pure presentation. It reads from Order_Repository and resolves
 * all dynamic display data (thumbnails, category names, variation attributes)
 * at render time — nothing from the order log is treated as final display text.
 *
 * Resolution rules:
 *  - Thumbnails      → wp_get_attachment_image() from stored attachment ID.
 *  - Category names  → get_term() from stored category term IDs.
 *  - Variation attrs → wc_get_product() + get_variation_attributes() from stored variation ID.
 *
 * Instantiated admin-side only by {@see \CCMerchAlert\Plugin::load_components()}.
 */
final class Dashboard_Orders {

	/**
	 * @var Order_Repository
	 */
	private Order_Repository $repository;

	/**
	 * @var WooCommerce
	 */
	private WooCommerce $woocommerce;

	/**
	 * @param Order_Repository $repository  Order log repository.
	 * @param WooCommerce      $woocommerce WooCommerce integration module (guards WC API calls).
	 */
	public function __construct( Order_Repository $repository, WooCommerce $woocommerce ) {
		$this->repository  = $repository;
		$this->woocommerce = $woocommerce;
	}

	// -------------------------------------------------------------------------
	// Data access
	// -------------------------------------------------------------------------

	/**
	 * Returns the number of orders logged today.
	 *
	 * Delegates to Order_Repository::count_today(). Called by Admin to populate
	 * the "Orders Today" dashboard card without requiring a separate repository
	 * injection into the Admin constructor.
	 *
	 * @return int
	 */
	public function count_today(): int {
		return $this->repository->count_today();
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Outputs the Recent Orders section HTML including filter tabs.
	 *
	 * Defaults to the "today" filter on initial page load; JS takes over after that.
	 * The empty-state paragraph and orders list are both always present in the DOM
	 * so JS can toggle visibility without creating or destroying elements.
	 *
	 * @return void
	 */
	public function render(): void {
		$entries  = $this->repository->get_filtered( Order_Repository::FILTER_TODAY, 10 );
		$is_empty = empty( $entries );
		?>
		<div class="ccmerch-section">

			<div class="ccmerch-section__header">
				<h2 class="ccmerch-section__title">
					<?php esc_html_e( 'Recent Orders', 'ccmerch-alert' ); ?>
				</h2>
				<span class="ccmerch-refresh-timer" id="ccmerch-refresh-timer" aria-live="polite">
					<?php esc_html_e( 'Next refresh in:', 'ccmerch-alert' ); ?>
					<span id="ccmerch-countdown">--</span>
				</span>
				<div class="ccmerch-filters" role="group" aria-label="<?php esc_attr_e( 'Filter orders by time range', 'ccmerch-alert' ); ?>">
					<button class="ccmerch-filter ccmerch-filter--active" data-filter="<?php echo esc_attr( Order_Repository::FILTER_TODAY ); ?>" type="button">
						<?php esc_html_e( 'Today', 'ccmerch-alert' ); ?>
					</button>
					<button class="ccmerch-filter" data-filter="<?php echo esc_attr( Order_Repository::FILTER_LAST_24 ); ?>" type="button">
						<?php esc_html_e( 'Last 24h', 'ccmerch-alert' ); ?>
					</button>
					<button class="ccmerch-filter" data-filter="<?php echo esc_attr( Order_Repository::FILTER_ALL ); ?>" type="button">
						<?php esc_html_e( 'All-time', 'ccmerch-alert' ); ?>
					</button>
				</div>
			</div>

			<p class="ccmerch-empty <?php echo $is_empty ? '' : 'ccmerch-hidden'; ?>" id="ccmerch-orders-empty">
				<?php esc_html_e( 'No orders have been logged yet. Orders are captured when they reach Processing or Completed status.', 'ccmerch-alert' ); ?>
			</p>

			<div class="ccmerch-orders <?php echo $is_empty ? 'ccmerch-hidden' : ''; ?>" id="ccmerch-orders-list">
				<?php foreach ( $entries as $entry ) : ?>
					<?php $this->render_order( $entry ); ?>
				<?php endforeach; ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Renders an array of order entries to an HTML string without outputting it.
	 *
	 * Used by Dashboard_Ajax to build the response payload without echoing to the
	 * page. Returns an empty string when $entries is empty.
	 *
	 * @param Order_Log_Entry[] $entries Logged order entries.
	 * @return string HTML markup for the given entries.
	 */
	public function render_orders_html( array $entries ): string {
		if ( empty( $entries ) ) {
			return '';
		}

		\ob_start();

		foreach ( $entries as $entry ) {
			$this->render_order( $entry );
		}

		return \ob_get_clean() ?: '';
	}

	// -------------------------------------------------------------------------
	// Private renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders a single order card.
	 *
	 * @param Order_Log_Entry $entry Logged order.
	 * @return void
	 */
	private function render_order( Order_Log_Entry $entry ): void {
		$display_date = wp_date( get_option( 'date_format' ), $entry->order_date->getTimestamp() );
		?>
		<div class="ccmerch-order">

			<div class="ccmerch-order__header">
				<span class="ccmerch-order__customer">
					<?php echo esc_html( $entry->first_name ); ?>
				</span>
				<span class="ccmerch-order__number">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: WooCommerce order number */
							__( '#%s', 'ccmerch-alert' ),
							$entry->order_number
						)
					);
					?>
				</span>
				<span class="ccmerch-order__date">
					<?php echo esc_html( (string) $display_date ); ?>
				</span>
			</div>

			<?php if ( ! empty( $entry->items ) ) : ?>
				<ul class="ccmerch-order__items">
					<?php foreach ( $entry->items as $item ) : ?>
						<?php $this->render_item( $item ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Renders a single order line item.
	 *
	 * @param Order_Log_Item $item Logged line item.
	 * @return void
	 */
	private function render_item( Order_Log_Item $item ): void {
		$thumbnail      = $this->resolve_thumbnail( $item->image_attachment_id );
		$category_names = $this->resolve_category_names( $item->category_ids );
		$variation_text = ( null !== $item->variation_id && $this->woocommerce->is_active() )
			? $this->resolve_variation_attributes( $item->variation_id )
			: '';
		?>
		<li class="ccmerch-order-item">

			<div class="ccmerch-order-item__image" aria-hidden="true">
				<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_get_attachment_image escapes internally ?>
			</div>

			<div class="ccmerch-order-item__details">

				<span class="ccmerch-order-item__name">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: product name, 2: quantity */
							__( '%1$s &times; %2$d', 'ccmerch-alert' ),
							$item->product_name,
							$item->quantity
						)
					);
					?>
				</span>

				<?php if ( ! empty( $category_names ) ) : ?>
					<span class="ccmerch-order-item__categories">
						<?php echo esc_html( \implode( ', ', $category_names ) ); ?>
					</span>
				<?php endif; ?>

				<?php if ( '' !== $variation_text ) : ?>
					<span class="ccmerch-order-item__variation">
						<?php echo esc_html( $variation_text ); ?>
					</span>
				<?php endif; ?>

			</div>

		</li>
		<?php
	}

	// -------------------------------------------------------------------------
	// Dynamic resolution helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the thumbnail HTML for a given attachment ID, or a placeholder.
	 *
	 * @param int|null $attachment_id WordPress media library attachment ID.
	 * @return string HTML img tag or placeholder div.
	 */
	private function resolve_thumbnail( ?int $attachment_id ): string {
		if ( null === $attachment_id || $attachment_id <= 0 ) {
			return '<div class="ccmerch-order-item__no-image"></div>';
		}

		$img = wp_get_attachment_image(
			$attachment_id,
			[ 48, 48 ],
			false,
			[ 'class' => 'ccmerch-order-item__thumb' ]
		);

		return $img ?: '<div class="ccmerch-order-item__no-image"></div>';
	}

	/**
	 * Resolves an array of category term IDs to their current display names.
	 *
	 * Terms that no longer exist are silently dropped from the result.
	 *
	 * @param int[] $category_ids WordPress term IDs.
	 * @return string[]
	 */
	private function resolve_category_names( array $category_ids ): array {
		$names = [];

		foreach ( $category_ids as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * Returns a formatted string of variation attribute labels for a variation ID.
	 *
	 * Resolves attribute names via wc_attribute_label() and term slugs via
	 * get_term_by() so the output reflects current category/attribute state.
	 * Returns an empty string if the variation product no longer exists.
	 *
	 * Example output: "Color: Red, Size: Large"
	 *
	 * @param int $variation_id WooCommerce variation product ID.
	 * @return string
	 */
	private function resolve_variation_attributes( int $variation_id ): string {
		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return '';
		}

		$attributes = $variation->get_variation_attributes();
		$parts      = [];

		foreach ( $attributes as $attribute_name => $raw_value ) {
			$taxonomy = \str_replace( 'attribute_', '', $attribute_name );
			$label    = wc_attribute_label( $taxonomy );

			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $raw_value, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$raw_value = $term->name;
				}
			}

			if ( '' !== (string) $raw_value ) {
				$parts[] = $label . ': ' . $raw_value;
			}
		}

		return \implode( ', ', $parts );
	}
}
