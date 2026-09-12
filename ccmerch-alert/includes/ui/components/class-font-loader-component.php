<?php
/**
 * Google Fonts URL builder and stylesheet injector.
 *
 * @package CCMerchAlert\UI\Components
 */

namespace CCMerchAlert\UI\Components;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for constructing Google Fonts CSS2 URLs and the
 * corresponding <link> tag injected into the Browser Source page.
 *
 * Must never: be instantiated.
 * Must never: make HTTP requests. URL construction only.
 */
final class Font_Loader_Component {

	/** @var string[] Weight variants requested for every font. */
	private const WEIGHTS = [ '300', '400', '500', '600', '700', '800' ];

	/** @var string Google Fonts CSS2 base URL. */
	private const BASE_URL = 'https://fonts.googleapis.com/css2';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Builds a Google Fonts CSS2 URL for a given font family.
	 *
	 * Returns an empty string when $family is blank or 'inherit' — these
	 * values mean "use the browser default" and require no font loading.
	 *
	 * @param  string $family Font family name, e.g. 'Roboto'.
	 * @return string Full Google Fonts CSS2 URL, or '' if no font needed.
	 */
	public static function build_url( string $family ): string {
		$family = \trim( $family );

		if ( '' === $family || 'inherit' === $family ) {
			return '';
		}

		$encoded_family = \str_replace( ' ', '+', $family );
		$weights        = \implode( ';', self::WEIGHTS );

		return self::BASE_URL . '?family=' . rawurlencode( $family ) . ':wght@' . $weights . '&display=swap';
	}

	/**
	 * Builds a <link> tag for injection into a standalone HTML document.
	 *
	 * Returns an empty string when $family requires no external font.
	 *
	 * Output is safe for direct echo inside a <head> element — the URL is
	 * constructed from a controlled base and an esc_url-sanitized family param.
	 *
	 * @param  string $family Font family name.
	 * @return string HTML <link> tag, or '' if no font needed.
	 */
	public static function build_link_tag( string $family ): string {
		$url = self::build_url( $family );

		if ( '' === $url ) {
			return '';
		}

		return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
			. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
			. '<link href="' . esc_attr( $url ) . '" rel="stylesheet">';
	}
}
