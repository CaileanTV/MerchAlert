<?php
/**
 * Admin-curated approved font library.
 *
 * @package CCMerchAlert\Fonts
 */

namespace CCMerchAlert\Fonts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the subset of Google Fonts that administrators have approved
 * for use by creators.
 *
 * Thin façade over Google_Fonts_Provider that exposes only the vocabulary
 * creators need: a flat list of approved family name strings and a check
 * for whether a specific family is still approved.
 *
 * Must never: render HTML.
 * Must never: be instantiated more than once.
 */
final class Font_Library {

	/** @var Google_Fonts_Provider */
	private Google_Fonts_Provider $provider;

	public function __construct( Google_Fonts_Provider $provider ) {
		$this->provider = $provider;
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Returns a flat sorted array of approved font family name strings.
	 *
	 * Used to populate `ccmerchApprovedFonts` in both the admin (via
	 * wp_localize_script) and the Creator Portal (via inline script tag).
	 *
	 * @return string[]
	 */
	public function get_approved(): array {
		$fonts = $this->provider->get_all( approved_only: true );

		return \array_values(
			\array_map( static fn( array $f ) => $f['family'], $fonts )
		);
	}

	/**
	 * Returns an array of approved fonts with family and category metadata.
	 *
	 * Intended for injection into the `ccmerchApprovedFonts` JS global so
	 * FontSelector and layout-editor.js can display category labels alongside
	 * the font name.
	 *
	 * @return array<int, array{family: string, category: string}>
	 */
	public function get_approved_with_meta(): array {
		$fonts = $this->provider->get_all( approved_only: true );

		return \array_values(
			\array_map(
				static fn( array $f ) => [
					'family'   => $f['family'],
					'category' => $f['category'],
				],
				$fonts
			)
		);
	}

	/**
	 * Returns true when a given font family is in the approved list.
	 *
	 * Used by the Creator Portal renderer to decide whether to show a
	 * "this font is no longer approved" notice for a saved overlay config.
	 *
	 * @param  string $family Font family name, e.g. 'Roboto'.
	 * @return bool
	 */
	public function is_approved( string $family ): bool {
		if ( '' === \trim( $family ) || 'inherit' === $family ) {
			return true;
		}

		$approved = $this->get_approved();
		return \in_array( $family, $approved, true );
	}

	/**
	 * Returns the total number of approved fonts.
	 *
	 * @return int
	 */
	public function get_count(): int {
		return $this->provider->get_count( approved_only: true );
	}

	// -------------------------------------------------------------------------
	// Write (delegated to provider)
	// -------------------------------------------------------------------------

	/**
	 * Approves or removes approval for a font family.
	 *
	 * @param  string $family   Font family name.
	 * @param  bool   $approved New approved state.
	 * @return bool
	 */
	public function set_approved( string $family, bool $approved ): bool {
		return $this->provider->set_approved( $family, $approved );
	}
}
