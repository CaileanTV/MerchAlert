<?php
/**
 * Asset loader.
 *
 * @package CCMerchAlert\UI
 */

namespace CCMerchAlert\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralised asset URL resolver and enqueueing service.
 *
 * All plugin asset paths are owned by this class so that:
 *  - Renaming or moving a file requires exactly one edit.
 *  - Admin pages and the Creator Portal use consistent, versioned URLs.
 *  - WordPress's script/style de-duplication works correctly because the
 *    same handle string is always used for the same asset.
 *
 * Two usage modes:
 *
 * Admin context (WordPress page template):
 *   Call one of the enqueue_*() static methods inside an
 *   admin_enqueue_scripts callback that has already verified the correct
 *   page hook suffix. WordPress will print the tags via wp_head()/wp_footer().
 *
 * Portal context (standalone HTML document, no wp_head/wp_footer):
 *   Use url() to get the base URL (no version string) or versioned() to wrap
 *   it, then output <link> / <script> tags manually in the HTML document.
 *   Example:
 *     <link rel="stylesheet" href="<?= esc_url( Asset_Loader::versioned( Asset_Loader::admin_css() ) ) ?>">
 *
 * Must never: output HTML directly.
 * Must never: contain business logic.
 * Must never: be instantiated — all methods are static.
 */
final class Asset_Loader {

	// -------------------------------------------------------------------------
	// Named base URLs (no version query string)
	// -------------------------------------------------------------------------

	/**
	 * Returns the base URL of the shared admin stylesheet.
	 *
	 * @return string
	 */
	public static function admin_css(): string {
		return CCMERCH_ALERT_URL . 'assets/css/admin.css';
	}

	/**
	 * Returns the base URL of the layout editor stylesheet.
	 *
	 * @return string
	 */
	public static function layout_css(): string {
		return CCMERCH_ALERT_URL . 'assets/css/layout-editor.css';
	}

	/**
	 * Returns the base URL of the overlay standalone-page stylesheet.
	 *
	 * @return string
	 */
	public static function overlay_css(): string {
		return CCMERCH_ALERT_URL . 'assets/css/overlay.css';
	}

	/**
	 * Returns the base URL of the Creator Portal standalone-page stylesheet.
	 *
	 * @return string
	 */
	public static function portal_css(): string {
		return CCMERCH_ALERT_URL . 'assets/css/creator-portal.css';
	}

	/**
	 * Returns the base URL of the shared utility script (clipboard helper,
	 * test-controls AJAX handler).
	 *
	 * @return string
	 */
	public static function utils_js(): string {
		return CCMERCH_ALERT_URL . 'assets/js/ccmerch-utils.js';
	}

	/**
	 * Returns the base URL of the layout editor script.
	 *
	 * @return string
	 */
	public static function layout_js(): string {
		return CCMERCH_ALERT_URL . 'assets/js/layout-editor.js';
	}

	/**
	 * Returns the base URL of the overlay browser-source script.
	 *
	 * @return string
	 */
	public static function overlay_js(): string {
		return CCMERCH_ALERT_URL . 'assets/js/overlay.js';
	}

	/**
	 * Returns the base URL of the shared Google Fonts system script.
	 *
	 * Provides window.CCMerchFonts = { FontPreview, FontSelector }.
	 * Must be loaded before layout-editor.js on every page that uses the
	 * Layout Editor (admin Overlay page, Creator Portal).
	 *
	 * @return string
	 */
	public static function fonts_js(): string {
		return CCMERCH_ALERT_URL . 'assets/js/ccmerch-fonts.js';
	}

	// -------------------------------------------------------------------------
	// Versioning helper
	// -------------------------------------------------------------------------

	/**
	 * Appends the plugin version as a query-string cache-buster.
	 *
	 * Use this when outputting <link>/<script> tags manually in standalone HTML
	 * pages (e.g. the Creator Portal) where wp_enqueue_*() is not available.
	 *
	 * @param string $base_url URL returned by one of the named-URL methods above.
	 * @return string Versioned URL.
	 */
	public static function versioned( string $base_url ): string {
		// Use file modification time so the cache is busted whenever the file
		// changes, regardless of whether CCMERCH_ALERT_VERSION has incremented.
		$relative = \str_replace( CCMERCH_ALERT_URL, '', $base_url );
		$abs      = CCMERCH_ALERT_DIR . $relative;
		$ver      = \file_exists( $abs ) ? (string) \filemtime( $abs ) : CCMERCH_ALERT_VERSION;
		return $base_url . '?ver=' . $ver;
	}

	// -------------------------------------------------------------------------
	// Admin context enqueue wrappers
	// -------------------------------------------------------------------------

	/**
	 * Enqueues all assets required by the Overlay Configuration page.
	 *
	 * Loads: admin CSS, layout editor CSS, ccmerch-utils.js, layout-editor.js.
	 * Callers must still call wp_localize_script() for page-specific JS data
	 * (e.g. ccmerchLayout) and wp_enqueue_media() for audio pickers.
	 *
	 * Must be called inside an admin_enqueue_scripts callback that has already
	 * verified the correct page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_overlay_config(): void {
		wp_enqueue_style(
			'ccmerch-alert-admin',
			self::admin_css(),
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_style(
			'ccmerch-layout-editor',
			self::layout_css(),
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_script(
			'ccmerch-utils',
			self::utils_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);

		wp_enqueue_script(
			'ccmerch-fonts',
			self::fonts_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);

		wp_enqueue_script(
			'ccmerch-layout-editor',
			self::layout_js(),
			[ 'ccmerch-fonts' ],
			CCMERCH_ALERT_VERSION,
			true
		);
	}

	/**
	 * Enqueues assets required by the Translation Manager admin page.
	 *
	 * Loads: admin CSS, ccmerch-utils.js (for save/reset/filter interactions).
	 *
	 * Must be called inside an admin_enqueue_scripts callback that has already
	 * verified the correct page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_translations_admin(): void {
		wp_enqueue_style(
			'ccmerch-alert-admin',
			self::admin_css(),
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_script(
			'ccmerch-utils',
			self::utils_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);
	}

	/**
	 * Enqueues assets required by the Font Library admin page.
	 *
	 * Loads: admin CSS, ccmerch-utils.js, ccmerch-fonts.js.
	 * ccmerch-fonts.js provides FontPreview lazy-loading for the preview column.
	 *
	 * Must be called inside an admin_enqueue_scripts callback that has already
	 * verified the correct page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_fonts_admin(): void {
		wp_enqueue_style(
			'ccmerch-alert-admin',
			self::admin_css(),
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_script(
			'ccmerch-utils',
			self::utils_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);

		wp_enqueue_script(
			'ccmerch-fonts',
			self::fonts_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);
	}

	/**
	 * Enqueues assets required by the Creator Profiles admin page.
	 *
	 * Loads: admin CSS, ccmerch-utils.js (for Copy Link button).
	 *
	 * Must be called inside an admin_enqueue_scripts callback that has already
	 * verified the correct page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_creator_admin(): void {
		wp_enqueue_style(
			'ccmerch-alert-admin',
			self::admin_css(),
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_script(
			'ccmerch-utils',
			self::utils_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);
	}

	/**
	 * Enqueues assets required by the Chatbot admin page.
	 *
	 * Loads: admin CSS, ccmerch-utils.js.
	 *
	 * Must be called inside an admin_enqueue_scripts callback that has already
	 * verified the correct page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_chatbot_admin(): void {
		wp_enqueue_style(
			'ccmerch-alert-admin',
			self::admin_css(),
			[],
			CCMERCH_ALERT_VERSION
		);

		wp_enqueue_script(
			'ccmerch-utils',
			self::utils_js(),
			[],
			CCMERCH_ALERT_VERSION,
			true
		);
	}
}
