<?php
/**
 * Overlay renderer.
 *
 * @package CCMerchAlert\Overlay
 */

namespace CCMerchAlert\Overlay;

use CCMerchAlert\Engine\Alert_Event;
use CCMerchAlert\OrderLog\Order_Log_Entry;
use CCMerchAlert\OrderLog\Order_Log_Item;
use CCMerchAlert\UI\Components\Font_Loader_Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces all HTML output for the overlay module.
 *
 * Two responsibilities:
 *  1. {@see render_page()} — outputs the complete standalone HTML page served
 *     at the browser-source URL and immediately exits. Three independently
 *     positioned elements are placed using coordinates from the layout data.
 *  2. {@see render_alert()} — builds the image, text, and secondary HTML
 *     fragments returned in AJAX poll/preview responses.
 *
 * Business logic lives in Overlay_Controller and Overlay_Template_Engine.
 * This class is pure presentation.
 *
 * Must never: read from the database. Must never contain routing or AJAX logic.
 */
final class Overlay_Renderer {

	/**
	 * @param Overlay_Settings_Contract $settings Settings source (global or per-creator).
	 * @param Overlay_Template_Engine   $engine   Variable substitution engine.
	 */
	public function __construct(
		private readonly Overlay_Settings_Contract $settings,
		private readonly Overlay_Template_Engine $engine,
	) {}

	// -------------------------------------------------------------------------
	// Full-page render
	// -------------------------------------------------------------------------

	/**
	 * Outputs the complete standalone overlay HTML document and exits.
	 *
	 * Three elements are placed at absolute pixel coordinates taken from the
	 * layout data (1920×1080 space). OBS browser sources render at the configured
	 * canvas size, so no scaling is applied — coordinates map 1:1 to pixels.
	 *
	 * Global appearance settings (typography, colors, animation) are injected as
	 * CSS custom properties in a <style> block so the page requires no further
	 * AJAX calls to read appearance settings.
	 *
	 * @return never
	 */
	public function render_page(): never {
		$s      = $this->settings->all();
		$layout = $this->settings->get_layout();

		$overlay_id      = $this->settings->get_overlay_id();
		$anim_ms         = (int) $s[ Overlay_Settings::FIELD_ANIM_DURATION ];
		$display_ms      = (int) $s[ Overlay_Settings::FIELD_DISPLAY_DURATION ];
		$font_family     = (string) $s[ Overlay_Settings::FIELD_FONT_FAMILY ];
		$font_size       = (int) $s[ Overlay_Settings::FIELD_FONT_SIZE ];
		$font_weight     = (string) $s[ Overlay_Settings::FIELD_FONT_WEIGHT ];
		$text_color      = (string) $s[ Overlay_Settings::FIELD_TEXT_COLOR ];
		$text_shadow     = (string) $s[ Overlay_Settings::FIELD_TEXT_SHADOW ];
		$text_align      = (string) $s[ Overlay_Settings::FIELD_TEXT_ALIGN ];
		$bg_color        = (string) $s[ Overlay_Settings::FIELD_BG_COLOR ];
		$bg_opacity      = (int) $s[ Overlay_Settings::FIELD_BG_OPACITY ];
		$border_radius   = (int) $s[ Overlay_Settings::FIELD_BORDER_RADIUS ];
		$padding         = (int) $s[ Overlay_Settings::FIELD_PADDING ];
		$secondary_tpl   = (string) $s[ Overlay_Settings::FIELD_SECONDARY_TEMPLATE ];

		$bg_rgba = $this->hex_to_rgba( $bg_color, $bg_opacity / 100 );
		$anim_s  = ( $anim_ms / 1000 ) . 's';

		// Per-element layout data.
		$el_img  = $layout['elements']['product_image']  ?? [];
		$el_text = $layout['elements']['main_text']      ?? [];
		$el_sec  = $layout['elements']['secondary_text'] ?? [];

		$img_border_radius = (int) ( $el_img['border_radius'] ?? 4 );
		$img_visible       = (bool) ( $el_img['visible'] ?? true );
		$text_visible      = (bool) ( $el_text['visible'] ?? true );
		$sec_visible       = (bool) ( $el_sec['visible'] ?? false ) && '' !== $secondary_tpl;

		// Inline styles for each element — position + size from layout data.
		$img_style = \sprintf(
			'position:fixed;left:%dpx;top:%dpx;width:%dpx;height:%dpx;%s',
			(int) ( $el_img['x'] ?? 40 ),
			(int) ( $el_img['y'] ?? 880 ),
			(int) ( $el_img['w'] ?? 120 ),
			(int) ( $el_img['h'] ?? 120 ),
			$img_visible ? '' : 'display:none;'
		);

		$text_bg_style = \sprintf(
			'background:%s;border-radius:%dpx;padding:%dpx;',
			$bg_rgba,
			$border_radius,
			$padding
		);
		$text_style = \sprintf(
			'position:fixed;left:%dpx;top:%dpx;width:%dpx;height:%dpx;%s%s',
			(int) ( $el_text['x'] ?? 176 ),
			(int) ( $el_text['y'] ?? 895 ),
			(int) ( $el_text['w'] ?? 700 ),
			(int) ( $el_text['h'] ?? 80 ),
			$text_bg_style,
			$text_visible ? '' : 'display:none;'
		);

		$sec_style = \sprintf(
			'position:fixed;left:%dpx;top:%dpx;width:%dpx;height:%dpx;%s%s',
			(int) ( $el_sec['x'] ?? 176 ),
			(int) ( $el_sec['y'] ?? 985 ),
			(int) ( $el_sec['w'] ?? 700 ),
			(int) ( $el_sec['h'] ?? 50 ),
			$text_bg_style,
			$sec_visible ? '' : 'display:none;'
		);

		// Use filemtime() as the cache-buster so the URL changes whenever the file
		// changes, independent of the plugin version constant. This ensures OBS
		// Browser Source (and all other clients) always load the current JS/CSS
		// even when the plugin version has not been bumped.
		$js_file  = CCMERCH_ALERT_DIR . 'assets/js/overlay.js';
		$css_file = CCMERCH_ALERT_DIR . 'assets/css/overlay.css';
		$js_ver   = \file_exists( $js_file )  ? \filemtime( $js_file )  : CCMERCH_ALERT_VERSION;
		$css_ver  = \file_exists( $css_file ) ? \filemtime( $css_file ) : CCMERCH_ALERT_VERSION;

		$css_url = esc_url( CCMERCH_ALERT_URL . 'assets/css/overlay.css?ver=' . $css_ver );
		$js_url  = esc_url( CCMERCH_ALERT_URL . 'assets/js/overlay.js?ver='  . $js_ver );

		$font_url = Font_Loader_Component::build_url( $font_family );

		$config = wp_json_encode( [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'overlayId'       => $overlay_id,
			'pollAction'      => $this->settings->get_poll_action(),
			'streamAction'    => $this->settings->get_stream_action(),
			'longpollAction'  => $this->settings->get_longpoll_action(),
			'pollInterval'    => 5,
			'displayDuration' => $display_ms,
			'animDuration'    => $anim_ms,
			'initialTime'     => \time(),
			'audioEnabled'    => '1' === (string) $this->settings->get( Overlay_Settings::FIELD_AUDIO_ENABLED ),
			'hasSecondary'    => $sec_visible,
			'fontFamily'      => $font_family,
			'fontUrl'         => $font_url,
		] );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		// Rationale: all dynamic values below are explicitly escaped inline.
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CCMerch Alert Overlay</title>
<style>
:root {
	--ccmerch-anim:              <?php echo esc_attr( $anim_s ); ?>;
	--ccmerch-font-family:       <?php echo esc_attr( $font_family ); ?>;
	--ccmerch-font-size:         <?php echo esc_attr( (string) $font_size ); ?>px;
	--ccmerch-font-weight:       <?php echo esc_attr( $font_weight ); ?>;
	--ccmerch-text-color:        <?php echo esc_attr( $text_color ); ?>;
	--ccmerch-text-shadow:       <?php echo '' !== $text_shadow ? esc_attr( $text_shadow ) : 'none'; ?>;
	--ccmerch-text-align:        <?php echo esc_attr( $text_align ); ?>;
	--ccmerch-img-border-radius: <?php echo esc_attr( (string) $img_border_radius ); ?>px;
}
</style>
<?php echo Font_Loader_Component::build_link_tag( $font_family ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized by build_link_tag ?>
<link rel="stylesheet" href="<?php echo $css_url; ?>">
</head>
<body>
<div
	id="ccmerch-overlay-image"
	class="ccmerch-overlay-el ccmerch-overlay-el--hidden"
	style="<?php echo esc_attr( $img_style ); ?>"
></div>
<div
	id="ccmerch-overlay-text"
	class="ccmerch-overlay-el ccmerch-overlay-el--hidden ccmerch-overlay__text"
	style="<?php echo esc_attr( $text_style ); ?>"
></div>
<div
	id="ccmerch-overlay-secondary"
	class="ccmerch-overlay-el ccmerch-overlay-el--hidden ccmerch-overlay__text"
	style="<?php echo esc_attr( $sec_style ); ?>"
></div>
<script>var ccmerchOverlay = <?php echo $config; ?>;</script>
<script src="<?php echo $js_url; ?>"></script>
</body>
</html>
		<?php
		// phpcs:enable
		exit;
	}

	// -------------------------------------------------------------------------
	// Alert fragment render
	// -------------------------------------------------------------------------

	/**
	 * Builds the image, text, secondary, and audio fragments for one order alert.
	 *
	 * Called by Overlay_Controller for both live poll and admin preview
	 * responses. Returns an associative array so the controller can embed the
	 * fragments directly in the AJAX JSON payload.
	 *
	 * @param  Order_Log_Entry $entry Logged order to render.
	 * @return array{image_html: string, text_html: string, secondary_html: string, sound_url: string}
	 */
	public function render_alert( Order_Log_Entry $entry ): array {
		return [
			'image_html'     => $this->render_image( $entry ),
			'text_html'      => $this->render_text( $entry ),
			'secondary_html' => $this->render_secondary( $entry ),
			'sound_url'      => $this->resolve_sound_url( $entry ),
		];
	}

	/**
	 * Expands an order entry into a per-item alert queue.
	 *
	 * Returns one alert fragment array per line item, in the original WooCommerce
	 * purchase sequence. Each item gets its own image, text, secondary text, and
	 * audio — resolved independently so category routing and audio mapping are
	 * per-product, not inherited from the first item.
	 *
	 * Order-level fields ($FirstName, $OrderNumber) are preserved on every alert.
	 * The entry is never mutated; a lightweight single-item projection is used
	 * internally to satisfy the existing render_alert() contract.
	 *
	 * @param  Order_Log_Entry $entry Logged order to expand.
	 * @return array<int, array{image_html: string, text_html: string, secondary_html: string, sound_url: string}>
	 */
	public function render_items( Order_Log_Entry $entry ): array {
		$alerts = [];

		foreach ( $entry->items as $item ) {
			$item_entry = new Order_Log_Entry(
				$entry->id,
				$entry->order_id,
				$entry->order_number,
				$entry->order_date,
				$entry->first_name,
				[ $item ],
			);
			$alerts[] = $this->render_alert( $item_entry );
		}

		return $alerts;
	}

	/**
	 * Renders a single Alert_Event into overlay HTML fragments.
	 *
	 * Converts the pre-resolved Alert_Event snapshot into the Order_Log_Entry +
	 * Order_Log_Item projection that render_alert() expects, then delegates. No
	 * database queries or WooCommerce API calls are made — all data is already
	 * present on the event.
	 *
	 * Called by Creator_Overlay_Controller::handle_poll() for events read from
	 * the Alert Event queue. Each event was fully resolved at creation time by
	 * Alert_Router; the runtime never re-routes or re-queries.
	 *
	 * @param  Alert_Event $event Fully-resolved per-item alert snapshot.
	 * @return array{image_html: string, text_html: string, secondary_html: string, sound_url: string}
	 */
	public function render_event( Alert_Event $event ): array {
		$item = new Order_Log_Item(
			0,
			0,
			$event->product_id,
			$event->variation_id,
			$event->product_name,
			'',
			$event->quantity,
			$event->featured_image_attachment_id,
			$event->category_term_ids,
		);

		$entry = new Order_Log_Entry(
			0,
			$event->order_id,
			$event->order_number,
			new \DateTimeImmutable( '@' . $event->created_at ),
			$event->customer_first_name,
			[ $item ],
		);

		return $this->render_alert( $entry );
	}

	// -------------------------------------------------------------------------
	// Live config payload (used by SSE stream to push updates)
	// -------------------------------------------------------------------------

	/**
	 * Returns the complete runtime configuration as a structured array.
	 *
	 * Called by the SSE stream handler when a settings change is detected.
	 * The payload mirrors exactly what render_page() bakes into the HTML at load
	 * time, so the browser can apply it without a page reload.
	 *
	 * @return array{css_vars: array<string,string>, elements: array<string,string>, js: array<string,mixed>}
	 */
	public function get_config_payload(): array {
		$s      = $this->settings->all();
		$layout = $this->settings->get_layout();

		$anim_ms       = (int) $s[ Overlay_Settings::FIELD_ANIM_DURATION ];
		$display_ms    = (int) $s[ Overlay_Settings::FIELD_DISPLAY_DURATION ];
		$font_family   = (string) $s[ Overlay_Settings::FIELD_FONT_FAMILY ];
		$font_size     = (int) $s[ Overlay_Settings::FIELD_FONT_SIZE ];
		$font_weight   = (string) $s[ Overlay_Settings::FIELD_FONT_WEIGHT ];
		$text_color    = (string) $s[ Overlay_Settings::FIELD_TEXT_COLOR ];
		$text_shadow   = (string) $s[ Overlay_Settings::FIELD_TEXT_SHADOW ];
		$text_align    = (string) $s[ Overlay_Settings::FIELD_TEXT_ALIGN ];
		$bg_color      = (string) $s[ Overlay_Settings::FIELD_BG_COLOR ];
		$bg_opacity    = (int) $s[ Overlay_Settings::FIELD_BG_OPACITY ];
		$border_radius = (int) $s[ Overlay_Settings::FIELD_BORDER_RADIUS ];
		$padding       = (int) $s[ Overlay_Settings::FIELD_PADDING ];
		$secondary_tpl = (string) $s[ Overlay_Settings::FIELD_SECONDARY_TEMPLATE ];

		$bg_rgba = $this->hex_to_rgba( $bg_color, $bg_opacity / 100 );
		$anim_s  = ( $anim_ms / 1000 ) . 's';

		$el_img  = $layout['elements']['product_image']  ?? [];
		$el_text = $layout['elements']['main_text']      ?? [];
		$el_sec  = $layout['elements']['secondary_text'] ?? [];

		$img_border_radius = (int) ( $el_img['border_radius'] ?? 4 );
		$img_visible       = (bool) ( $el_img['visible'] ?? true );
		$text_visible      = (bool) ( $el_text['visible'] ?? true );
		$sec_visible       = (bool) ( $el_sec['visible'] ?? false ) && '' !== $secondary_tpl;

		$text_bg = \sprintf( 'background:%s;border-radius:%dpx;padding:%dpx;', $bg_rgba, $border_radius, $padding );

		return [
			'font_url' => Font_Loader_Component::build_url( $font_family ),
			'css_vars' => [
				'anim'              => $anim_s,
				'font_family'       => $font_family,
				'font_size'         => $font_size . 'px',
				'font_weight'       => $font_weight,
				'text_color'        => $text_color,
				'text_shadow'       => '' !== $text_shadow ? $text_shadow : 'none',
				'text_align'        => $text_align,
				'img_border_radius' => $img_border_radius . 'px',
			],
			'elements' => [
				'product_image' => \sprintf(
					'position:fixed;left:%dpx;top:%dpx;width:%dpx;height:%dpx;%s',
					(int) ( $el_img['x'] ?? 40 ),
					(int) ( $el_img['y'] ?? 880 ),
					(int) ( $el_img['w'] ?? 120 ),
					(int) ( $el_img['h'] ?? 120 ),
					$img_visible ? '' : 'display:none;'
				),
				'main_text' => \sprintf(
					'position:fixed;left:%dpx;top:%dpx;width:%dpx;height:%dpx;%s%s',
					(int) ( $el_text['x'] ?? 176 ),
					(int) ( $el_text['y'] ?? 895 ),
					(int) ( $el_text['w'] ?? 700 ),
					(int) ( $el_text['h'] ?? 80 ),
					$text_bg,
					$text_visible ? '' : 'display:none;'
				),
				'secondary_text' => \sprintf(
					'position:fixed;left:%dpx;top:%dpx;width:%dpx;height:%dpx;%s%s',
					(int) ( $el_sec['x'] ?? 176 ),
					(int) ( $el_sec['y'] ?? 985 ),
					(int) ( $el_sec['w'] ?? 700 ),
					(int) ( $el_sec['h'] ?? 50 ),
					$text_bg,
					$sec_visible ? '' : 'display:none;'
				),
			],
			'js' => [
				'display_duration' => $display_ms,
				'anim_duration'    => $anim_ms,
				'has_secondary'    => $sec_visible,
			],
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds the image HTML for the first line item.
	 *
	 * @param  Order_Log_Entry $entry Logged order.
	 * @return string HTML img element or empty string.
	 */
	private function render_image( Order_Log_Entry $entry ): string {
		$item       = $entry->items[0] ?? null;
		$image_size = (int) $this->settings->get( Overlay_Settings::FIELD_IMAGE_SIZE );

		if ( null === $item || null === $item->image_attachment_id ) {
			return '';
		}

		return wp_get_attachment_image(
			$item->image_attachment_id,
			[ $image_size, $image_size ],
			false,
			[ 'class' => 'ccmerch-overlay__thumb' ]
		) ?: '';
	}

	/**
	 * Renders the primary template string for innerHTML injection.
	 *
	 * All variable values are HTML-escaped by the template engine. Newlines
	 * become <br> tags.
	 *
	 * @param  Order_Log_Entry $entry Logged order.
	 * @return string Safe HTML string.
	 */
	private function render_text( Order_Log_Entry $entry ): string {
		$template = (string) $this->settings->get( Overlay_Settings::FIELD_TEMPLATE );
		return nl2br( $this->engine->render( $template, $entry ) );
	}

	/**
	 * Renders the secondary template string, or returns an empty string when the
	 * template is blank (secondary element stays hidden in that case).
	 *
	 * @param  Order_Log_Entry $entry Logged order.
	 * @return string Safe HTML string, or empty string.
	 */
	private function render_secondary( Order_Log_Entry $entry ): string {
		$template = (string) $this->settings->get( Overlay_Settings::FIELD_SECONDARY_TEMPLATE );
		if ( '' === $template ) {
			return '';
		}
		return nl2br( $this->engine->render( $template, $entry ) );
	}

	/**
	 * Resolves the audio URL for the given order entry.
	 *
	 * Resolution order:
	 *  1. If audio is disabled in settings, returns empty string immediately.
	 *  2. Iterates the first line item's category IDs against the stored
	 *     category → attachment map. Returns the URL of the first match.
	 *  3. Falls back to the default alert sound attachment.
	 *  4. Returns empty string when no sound is configured.
	 *
	 * @param  Order_Log_Entry $entry Logged order.
	 * @return string Absolute audio URL, or empty string.
	 */
	private function resolve_sound_url( Order_Log_Entry $entry ): string {
		$debug = \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		if ( '1' !== (string) $this->settings->get( Overlay_Settings::FIELD_AUDIO_ENABLED ) ) {
			if ( $debug ) {
				\error_log( '[CCMerch Audio] resolve_sound_url: audio_enabled is not 1 — returning empty.' );
			}
			return '';
		}

		$item = $entry->items[0] ?? null;

		if ( null !== $item && ! empty( $item->category_ids ) ) {
			$map_json = (string) $this->settings->get( Overlay_Settings::FIELD_AUDIO_CAT_MAP );
			$map      = \json_decode( $map_json, true );
			$map      = \is_array( $map ) ? $map : [];

			if ( $debug ) {
				\error_log( \sprintf(
					'[CCMerch Audio] resolve_sound_url: checking category map for term IDs [%s]. Map keys: [%s].',
					\implode( ', ', $item->category_ids ),
					\implode( ', ', \array_keys( $map ) )
				) );
			}

			foreach ( $item->category_ids as $term_id ) {
				$att_id = (int) ( $map[ $term_id ] ?? 0 );
				if ( $att_id > 0 ) {
					$url = wp_get_attachment_url( $att_id );
					if ( false !== $url ) {
						if ( $debug ) {
							\error_log( "[CCMerch Audio] resolve_sound_url: category {$term_id} → attachment {$att_id} → {$url}" );
						}
						return $url;
					}
					if ( $debug ) {
						\error_log( "[CCMerch Audio] resolve_sound_url: category {$term_id} → attachment {$att_id} → URL not found (deleted?)." );
					}
				}
			}
		}

		$default_att_id = (int) $this->settings->get( Overlay_Settings::FIELD_AUDIO_DEFAULT );
		if ( $default_att_id > 0 ) {
			$url = wp_get_attachment_url( $default_att_id );
			if ( false !== $url ) {
				if ( $debug ) {
					\error_log( "[CCMerch Audio] resolve_sound_url: default attachment {$default_att_id} → {$url}" );
				}
				return $url;
			}
			if ( $debug ) {
				\error_log( "[CCMerch Audio] resolve_sound_url: default attachment {$default_att_id} → URL not found (deleted?)." );
			}
		}

		if ( $debug ) {
			\error_log( '[CCMerch Audio] resolve_sound_url: no sound configured — returning empty.' );
		}

		return '';
	}

	/**
	 * Converts a hex color and a 0–1 opacity to an rgba() CSS value.
	 *
	 * @param  string $hex     Hex color, with or without leading #.
	 * @param  float  $opacity 0.0 (transparent) to 1.0 (opaque).
	 * @return string CSS rgba() value.
	 */
	private function hex_to_rgba( string $hex, float $opacity ): string {
		$hex = \ltrim( $hex, '#' );

		if ( 3 === \strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = \hexdec( \substr( $hex, 0, 2 ) );
		$g = \hexdec( \substr( $hex, 2, 2 ) );
		$b = \hexdec( \substr( $hex, 4, 2 ) );

		$opacity = \round( \max( 0.0, \min( 1.0, $opacity ) ), 2 );

		return "rgba({$r},{$g},{$b},{$opacity})";
	}
}
