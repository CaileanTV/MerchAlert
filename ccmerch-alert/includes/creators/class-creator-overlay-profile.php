<?php
/**
 * Creator Overlay Profile.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

use CCMerchAlert\Overlay\Overlay_Settings;
use CCMerchAlert\Overlay\Overlay_Settings_Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-creator overlay settings store.
 *
 * Wraps one row from ccmerch_creator_overlay_settings and provides the same
 * interface as Overlay_Settings so Overlay_Renderer can use it unchanged.
 *
 * Unlike Overlay_Settings (which uses the WordPress Settings API and wp_options),
 * this class stores its settings as a JSON blob in a custom DB table, keyed by
 * creator_id. All field keys and defaults are identical to Overlay_Settings
 * because they delegate directly to its public static methods — there is one
 * canonical definition of defaults and sanitization rules.
 *
 * Write operations go through Creator_Overlay_Repository; this class is
 * read-only from the outside. Mutations (generate_id, save) return updated
 * data rather than mutating internal state.
 *
 * Must never: render HTML. Must never: register WordPress hooks.
 * Must never: contain routing or AJAX logic.
 * Must never: duplicate sanitize or defaults logic that belongs in Overlay_Settings.
 */
final class Creator_Overlay_Profile implements Overlay_Settings_Contract {

	/** @var array<string, mixed> Cached merged settings (loaded once per instance). */
	private array $data;

	/**
	 * @param int                  $creator_id  Creator profile primary key.
	 * @param string               $overlay_id  32-char hex browser-source access token.
	 * @param array<string, mixed> $settings    Raw settings array decoded from JSON.
	 */
	public function __construct(
		public readonly int    $creator_id,
		public readonly string $overlay_id,
		array                  $settings,
	) {
		$this->data = \array_merge( Overlay_Settings::field_defaults(), $settings );
	}

	// -------------------------------------------------------------------------
	// Overlay_Settings_Contract implementation
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function get( string $key ): mixed {
		return $this->data[ $key ] ?? Overlay_Settings::field_defaults()[ $key ] ?? null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_overlay_id(): string {
		return $this->overlay_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_poll_action(): string {
		return 'ccmerch_creator_overlay_poll';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_stream_action(): string {
		return 'ccmerch_creator_overlay_stream';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_longpoll_action(): string {
		return 'ccmerch_creator_overlay_longpoll';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_layout(): array {
		$stored = $this->data[ Overlay_Settings::FIELD_LAYOUT ] ?? null;

		if ( \is_array( $stored ) && ! empty( $stored['elements'] ) ) {
			return $stored;
		}

		return Overlay_Settings::layout_defaults();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns the public browser-source URL for this creator's overlay.
	 *
	 * @return string Absolute URL, or empty string when overlay_id is absent.
	 */
	public function get_overlay_url(): string {
		if ( '' === $this->overlay_id ) {
			return '';
		}

		return home_url( '/ccmerch-alert/creator-overlay/' . rawurlencode( $this->overlay_id ) . '/' );
	}

	/**
	 * Sanitizes a raw settings array submitted from the creator portal form.
	 *
	 * Delegates all field sanitization to Overlay_Settings::sanitize_fields() so
	 * the rules are defined in one place. The layout is preserved from the current
	 * stored value when the form POST does not include it.
	 *
	 * The audio category map is sanitized and filtered to the creator's own
	 * assigned categories via $allowed_term_ids. This enforces audio isolation:
	 * a creator can only save audio for categories that belong to their profile,
	 * even if a malicious POST body contains foreign category IDs.
	 *
	 * The caller (Creator_Overlay_Controller::handle_save) is responsible for
	 * fetching the creator's assigned term IDs and passing them here.
	 *
	 * @param  mixed $input            Raw POST input array.
	 * @param  int[] $allowed_term_ids Creator's assigned WC category term IDs.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input, array $allowed_term_ids = [] ): array {
		$input = \is_array( $input ) ? $input : [];

		// Preserve stored layout when the form doesn't include it (second sanitize pass).
		if ( null === ( $input[ Overlay_Settings::FIELD_LAYOUT ] ?? null ) ) {
			$input[ Overlay_Settings::FIELD_LAYOUT ] = $this->get_layout();
		}

		// Sanitize audio category map, restricted to the creator's own categories.
		$cat_map_json = Overlay_Settings::sanitize_category_map(
			$input[ Overlay_Settings::FIELD_AUDIO_CAT_MAP ] ?? [],
			$allowed_term_ids
		);

		return Overlay_Settings::sanitize_fields( $input, $cat_map_json );
	}
}
