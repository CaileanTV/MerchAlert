<?php
/**
 * Customer Username checkout field.
 *
 * @package CCMerchAlert\Checkout
 */

namespace CCMerchAlert\Checkout;

use CCMerchAlert\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the optional customer username WooCommerce checkout field.
 *
 * When enabled in plugin settings, adds an optional text field so customers can
 * enter their streaming or community username during checkout. The value is saved
 * as WooCommerce order meta and consumed by Customer_Username_Resolver to produce
 * the $UserName template variable. Empty submissions are stored as 'Anonymous' so
 * the variable always resolves to a displayable string.
 *
 * The field is entirely opt-in: when Settings::FIELD_USERNAME_ENABLED is falsy,
 * no hooks are registered and WooCommerce checkout is unaffected.
 *
 * Must never: render admin pages.
 * Must never: query the database directly.
 * Must never: be instantiated more than once.
 */
final class Customer_Username_Field {

	/**
	 * WooCommerce order meta key under which the username is stored.
	 *
	 * Prefixed with an underscore so it is treated as a protected meta key and
	 * does not appear in WooCommerce's default order meta UI.
	 */
	public const META_KEY = '_ccmerch_username';

	/**
	 * @param Settings $settings Plugin settings module.
	 */
	public function __construct( private readonly Settings $settings ) {
		// Hooks are always registered so wp_filter holds a reference to this
		// object for the lifetime of the request. The is_enabled() check is
		// deferred to each callback so it runs at filter-fire time rather than
		// at plugins_loaded time — this avoids a stale-cache window where the
		// option has been saved as 1 in the DB but the persistent object cache
		// (Redis/Memcached) has not yet been invalidated at boot time.
		add_filter( 'woocommerce_checkout_fields',            [ $this, 'add_field'  ]        );
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_field' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Public hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Appends the username field to WooCommerce checkout order fields.
	 *
	 * @param  array<string, mixed[]> $fields WooCommerce checkout fields grouped by section.
	 * @return array<string, mixed[]>
	 */
	public function add_field( array $fields ): array {
		if ( ! $this->is_enabled() ) {
			return $fields;
		}

		$label = \trim( (string) $this->settings->get( Settings::FIELD_USERNAME_LABEL ) );
		if ( '' === $label ) {
			$label = ccmerch_translate( 'checkout.username.label' );
		}

		$fields['order'][ self::META_KEY ] = [
			'type'        => 'text',
			'label'       => $label,
			'placeholder' => ccmerch_translate( 'checkout.username.placeholder' ),
			'required'    => false,
			'class'       => [ 'form-row-wide' ],
			'priority'    => 110,
		];

		return $fields;
	}

	/**
	 * Saves the submitted username to WooCommerce order meta after checkout.
	 *
	 * 'Anonymous' is stored when the customer leaves the field blank so that
	 * the $UserName template variable always resolves to a non-empty string.
	 *
	 * @param  int                   $order_id WooCommerce order ID.
	 * @param  array<string, mixed>  $data     Full checkout POST data.
	 * @return void
	 */
	public function save_field( int $order_id, array $data ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$raw   = \trim( (string) ( $data[ self::META_KEY ] ?? '' ) );
		$value = '' !== $raw ? sanitize_text_field( $raw ) : 'Anonymous';
		update_post_meta( $order_id, self::META_KEY, $value );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the checkout field feature is enabled in settings.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return (bool) $this->settings->get( Settings::FIELD_USERNAME_ENABLED );
	}
}
