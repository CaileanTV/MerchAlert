<?php
/**
 * Overlay settings.
 *
 * @package CCMerchAlert\Overlay
 */

namespace CCMerchAlert\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages all overlay-specific options via the WordPress Settings API.
 *
 * Owns option keys, field registration, sanitization, and the overlay ID
 * generator. Rendering of the overlay settings page is delegated to
 * {@see \CCMerchAlert\Admin\Overlay_Admin}.
 *
 * The overlay_id field is intentionally excluded from the settings form — it
 * is set only via {@see generate_id()}, never via form submission. The sanitize
 * callback preserves whichever ID is already present so a form save cannot
 * accidentally clear it.
 *
 * Shared static methods — used by both Overlay_Settings (global overlay) and
 * Creator_Overlay_Profile (per-creator overlay) to ensure the sanitize and
 * defaults logic is defined exactly once:
 *
 *   field_defaults()        — all appearance/audio/layout defaults (no overlay_id)
 *   layout_defaults()       — default element positions for 1920 × 1080
 *   sanitize_fields()       — sanitizes all appearance and audio fields from POST
 *   sanitize_category_map() — sanitizes and filters the category → audio attachment map
 *   sanitize_layout_data()  — validates and coerces raw layout input
 *   sanitize_font_weight()  — whitelists font weight values
 *   sanitize_text_align()   — whitelists text alignment values
 *
 * Must never: render admin pages. Must never contain routing logic.
 */
final class Overlay_Settings implements Overlay_Settings_Contract {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/** wp_options key for all overlay settings. */
	public const OPTION_KEY = 'ccmerch_overlay_settings';

	/** Admin page slug — must match the submenu slug in Overlay_Admin. */
	public const PAGE = 'ccmerch-alert-overlay';

	// Field keys — stored in the serialised array under OPTION_KEY.
	public const FIELD_OVERLAY_ID       = 'overlay_id';
	public const FIELD_TEMPLATE         = 'template';
	public const FIELD_FONT_FAMILY      = 'font_family';
	public const FIELD_FONT_SIZE        = 'font_size';
	public const FIELD_FONT_WEIGHT      = 'font_weight';
	public const FIELD_TEXT_COLOR       = 'text_color';
	public const FIELD_TEXT_SHADOW      = 'text_shadow';
	public const FIELD_TEXT_ALIGN       = 'text_align';
	public const FIELD_IMAGE_SIZE       = 'image_size';
	public const FIELD_BG_COLOR         = 'bg_color';
	public const FIELD_BG_OPACITY       = 'bg_opacity';
	public const FIELD_BORDER_RADIUS    = 'border_radius';
	public const FIELD_PADDING          = 'padding';
	public const FIELD_ANIM_DURATION    = 'anim_duration';
	public const FIELD_DISPLAY_DURATION = 'display_duration';

	/** Audio settings section ID. */
	public const SECTION_AUDIO = 'ccmerch_overlay_audio';

	// Audio field keys.
	public const FIELD_AUDIO_ENABLED  = 'audio_enabled';
	public const FIELD_AUDIO_DEFAULT  = 'audio_default_sound';
	public const FIELD_AUDIO_CAT_MAP  = 'audio_category_map';

	/** Secondary alert text template; uses the same $Variable substitution as FIELD_TEMPLATE. */
	public const FIELD_SECONDARY_TEMPLATE = 'secondary_template';

	/** Stored element positions and canvas options (managed by the Layout Editor canvas). */
	public const FIELD_LAYOUT = 'layout';

	/** wp_options key for the config version timestamp — bumped on every settings save. */
	public const OPTION_VERSION_KEY = 'ccmerch_overlay_settings_version';

	// -------------------------------------------------------------------------
	// Properties
	// -------------------------------------------------------------------------

	/** @var array<string, mixed>|null In-memory cache; loaded once per request. */
	private ?array $options = null;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public function __construct() {
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'update_option_' . self::OPTION_KEY, [ $this, 'on_settings_updated' ] );
	}

	// -------------------------------------------------------------------------
	// Settings API registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the option, sections, and all field definitions.
	 *
	 * Hooked to admin_init so WordPress processes the options.php form and
	 * builds the settings-page table automatically.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			self::PAGE,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => $this->defaults(),
			]
		);

		// Audio section — the only section rendered via do_settings_sections().
		// Templates and Appearance are rendered inside the Layout Editor section
		// by Overlay_Admin::render_layout_editor_section().
		add_settings_section( self::SECTION_AUDIO, __( 'Audio', 'ccmerch-alert' ), '__return_false', self::PAGE );

		add_settings_field(
			self::FIELD_AUDIO_ENABLED,
			__( 'Alert Sound', 'ccmerch-alert' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE,
			self::SECTION_AUDIO,
			[
				'field'       => self::FIELD_AUDIO_ENABLED,
				'label_for'   => self::OPTION_KEY . '_' . self::FIELD_AUDIO_ENABLED,
				'description' => __( 'Play a sound when an alert appears.', 'ccmerch-alert' ),
			]
		);

		add_settings_field(
			self::FIELD_AUDIO_DEFAULT,
			__( 'Default Sound', 'ccmerch-alert' ),
			[ $this, 'render_audio_attachment_field' ],
			self::PAGE,
			self::SECTION_AUDIO,
			[
				'field' => self::FIELD_AUDIO_DEFAULT,
			]
		);

		add_settings_field(
			self::FIELD_AUDIO_CAT_MAP,
			__( 'Category Sounds', 'ccmerch-alert' ),
			[ $this, 'render_audio_category_map_field' ],
			self::PAGE,
			self::SECTION_AUDIO,
			[
				'field' => self::FIELD_AUDIO_CAT_MAP,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Sanitizes and coerces submitted form values before storage.
	 *
	 * WordPress calls this callback twice per save: once from options.php with
	 * the raw form POST, and once from update_option() internally. Both passes
	 * produce safe output because every sanitization function used here is
	 * idempotent on already-clean input.
	 *
	 * The overlay_id key is never part of the form. It is taken from $input if
	 * present (second-pass from update_option), otherwise from the currently
	 * stored value, so a form save can never overwrite or lose the ID.
	 *
	 * All appearance and audio field sanitization is delegated to the shared
	 * static sanitize_fields() method so Creator_Overlay_Profile can reuse
	 * the same logic without duplication.
	 *
	 * @param  mixed $input Raw input from the submitted form.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input = \is_array( $input ) ? $input : [];

		// Preserve overlay_id — never part of the form.
		$overlay_id = '' !== ( $input[ self::FIELD_OVERLAY_ID ] ?? '' )
			? (string) $input[ self::FIELD_OVERLAY_ID ]
			: (string) $this->get( self::FIELD_OVERLAY_ID );

		// Inject stored layout when not in the form submission (second sanitize pass).
		if ( null === ( $input[ self::FIELD_LAYOUT ] ?? null ) ) {
			$input[ self::FIELD_LAYOUT ] = (array) $this->get( self::FIELD_LAYOUT );
		}

		// Category map: complex JSON structure with array→JSON conversion, admin-only.
		$cat_map = self::sanitize_category_map( $input[ self::FIELD_AUDIO_CAT_MAP ] ?? '{}' );

		$fields = self::sanitize_fields( $input, $cat_map );
		$fields[ self::FIELD_OVERLAY_ID ] = $overlay_id;

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders a multi-line textarea field.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_textarea_field( array $args ): void {
		$field = (string) $args['field'];
		$value = (string) $this->get( $field );
		$desc  = isset( $args['description'] )
			? (string) $args['description']
			: __( 'Available variables: $FirstName, $Product, $Variation, $Category, $Quantity, $OrderNumber', 'ccmerch-alert' );
		?>
		<textarea
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . $field ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
			rows="5"
			class="large-text"
		><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( '' !== $desc ) : ?>
		<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders a single-line text input field.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_text_field( array $args ): void {
		$field = (string) $args['field'];
		$value = (string) $this->get( $field );
		$desc  = (string) ( $args['description'] ?? '' );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . $field ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		>
		<?php if ( '' !== $desc ) : ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders a numeric input field.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_number_field( array $args ): void {
		$field  = (string) $args['field'];
		$value  = (string) $this->get( $field );
		$suffix = (string) ( $args['suffix'] ?? '' );
		$min    = (int) ( $args['min'] ?? 0 );
		$max    = (int) ( $args['max'] ?? 9999 );
		$desc   = (string) ( $args['description'] ?? '' );
		?>
		<input
			type="number"
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . $field ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="<?php echo esc_attr( (string) $min ); ?>"
			max="<?php echo esc_attr( (string) $max ); ?>"
			style="width: 80px;"
		>
		<?php if ( '' !== $suffix ) : ?>
			<span><?php echo esc_html( $suffix ); ?></span>
		<?php endif; ?>
		<?php if ( '' !== $desc ) : ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders an HTML color picker input.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_color_field( array $args ): void {
		$field = (string) $args['field'];
		$value = (string) $this->get( $field );
		?>
		<input
			type="color"
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . $field ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		>
		<?php
	}

	/**
	 * Renders a select dropdown field.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_select_field( array $args ): void {
		$field   = (string) $args['field'];
		$value   = (string) $this->get( $field );
		$options = (array) ( $args['options'] ?? [] );
		?>
		<select
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . $field ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
		>
			<?php foreach ( $options as $option_value => $option_label ) : ?>
				<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>>
					<?php echo esc_html( (string) $option_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders a group of radio buttons for a mutually exclusive choice.
	 *
	 * Does not use label_for because there is no single associated input.
	 * Accessibility is provided by the <fieldset> and inline <label> elements.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_radio_field( array $args ): void {
		$field   = (string) $args['field'];
		$current = (string) $this->get( $field );
		$options = (array)  ( $args['options'] ?? [] );
		$name    = self::OPTION_KEY . '[' . $field . ']';
		?>
		<fieldset>
			<?php foreach ( $options as $option_value => $option_label ) : ?>
				<label style="display:inline-flex;align-items:center;gap:6px;margin-right:16px;">
					<input
						type="radio"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo esc_attr( (string) $option_value ); ?>"
						<?php checked( $current, (string) $option_value ); ?>
					>
					<?php echo esc_html( (string) $option_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Renders a checkbox field.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$field = (string) $args['field'];
		$value = (string) $this->get( $field );
		$desc  = (string) ( $args['description'] ?? '' );
		$id    = self::OPTION_KEY . '_' . $field;
		?>
		<input
			type="checkbox"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
			value="1"
			<?php checked( $value, '1' ); ?>
		>
		<?php if ( '' !== $desc ) : ?>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $desc ); ?></label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders an audio attachment picker field.
	 *
	 * Stores the WordPress attachment ID in a hidden input. A media-library
	 * button (wired up by inline JS in Overlay_Admin) opens the file picker
	 * filtered to audio MIME types.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_audio_attachment_field( array $args ): void {
		$field    = (string) $args['field'];
		$att_id   = (int) $this->get( $field );
		$title    = $att_id > 0 ? (string) get_the_title( $att_id ) : '';
		$input_id = self::OPTION_KEY . '_' . $field;
		?>
		<input
			type="hidden"
			id="<?php echo esc_attr( $input_id ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . $field . ']' ); ?>"
			value="<?php echo esc_attr( (string) $att_id ); ?>"
			class="ccmerch-audio-attachment-id"
		>
		<button
			type="button"
			class="button ccmerch-audio-select-btn"
			data-field="<?php echo esc_attr( $input_id ); ?>"
		><?php esc_html_e( 'Select Sound File', 'ccmerch-alert' ); ?></button>
		<span
			class="ccmerch-audio-filename"
			id="<?php echo esc_attr( $input_id . '_name' ); ?>"
		><?php echo '' !== $title ? esc_html( $title ) : esc_html__( 'No file selected', 'ccmerch-alert' ); ?></span>
		<button
			type="button"
			class="button ccmerch-audio-clear-btn"
			data-field="<?php echo esc_attr( $input_id ); ?>"
			<?php echo $att_id > 0 ? '' : 'style="display:none;"'; ?>
		><?php esc_html_e( 'Remove', 'ccmerch-alert' ); ?></button>
		<p class="description"><?php esc_html_e( 'MP3 or WAV file from the media library. Used when no category-specific sound matches.', 'ccmerch-alert' ); ?></p>
		<?php
	}

	/**
	 * Renders the category → sound mapping table.
	 *
	 * Lists all WooCommerce product categories. Each row has its own sound
	 * picker. Requires WooCommerce to be active; shows a notice when it is not.
	 *
	 * @param array<string, mixed> $args Field args from add_settings_field().
	 * @return void
	 */
	public function render_audio_category_map_field( array $args ): void {
		$stored_json = (string) $this->get( self::FIELD_AUDIO_CAT_MAP );
		$stored_map  = \json_decode( $stored_json, true );
		$stored_map  = \is_array( $stored_map ) ? $stored_map : [];

		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $categories ) || ! \is_array( $categories ) || empty( $categories ) ) {
			echo '<p class="description">' . esc_html__( 'No WooCommerce product categories found. Categories appear here once WooCommerce is active and product categories exist.', 'ccmerch-alert' ) . '</p>';
			return;
		}
		?>
		<table class="widefat ccmerch-audio-cat-table" style="max-width:580px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Category', 'ccmerch-alert' ); ?></th>
					<th><?php esc_html_e( 'Alert Sound', 'ccmerch-alert' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $categories as $term ) :
				$term_id  = (int) $term->term_id;
				$att_id   = (int) ( $stored_map[ $term_id ] ?? 0 );
				$title    = $att_id > 0 ? (string) get_the_title( $att_id ) : '';
				$input_id = 'ccmerch_audio_cat_' . $term_id;
			?>
				<tr>
					<td><?php echo esc_html( $term->name ); ?></td>
					<td>
						<input
							type="hidden"
							id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_AUDIO_CAT_MAP . '][' . $term_id . ']' ); ?>"
							value="<?php echo esc_attr( (string) $att_id ); ?>"
							class="ccmerch-audio-attachment-id"
						>
						<button
							type="button"
							class="button button-small ccmerch-audio-select-btn"
							data-field="<?php echo esc_attr( $input_id ); ?>"
						><?php esc_html_e( 'Select', 'ccmerch-alert' ); ?></button>
						<span
							class="ccmerch-audio-filename"
							id="<?php echo esc_attr( $input_id . '_name' ); ?>"
						><?php echo '' !== $title ? esc_html( $title ) : '&mdash;'; ?></span>
						<button
							type="button"
							class="button button-small ccmerch-audio-clear-btn"
							data-field="<?php echo esc_attr( $input_id ); ?>"
							<?php echo $att_id > 0 ? '' : 'style="display:none;"'; ?>
						><?php esc_html_e( 'Remove', 'ccmerch-alert' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Override the default sound for a specific product category. The first matching category wins.', 'ccmerch-alert' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Public data API
	// -------------------------------------------------------------------------

	/**
	 * Returns a single setting value, falling back to the registered default.
	 *
	 * @param  string $key Setting key (one of the FIELD_* constants).
	 * @return mixed
	 */
	public function get( string $key ): mixed {
		$value = $this->all()[ $key ] ?? null;

		if ( null !== $value ) {
			return $value;
		}

		return $this->defaults()[ $key ] ?? null;
	}

	/**
	 * Persists a single setting value and invalidates the in-memory cache.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		$options         = $this->all();
		$options[ $key ] = $value;
		$this->options   = $options;
		update_option( self::OPTION_KEY, $options );
	}

	/**
	 * Returns all settings, merged over the module defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->options ) {
			$stored        = get_option( self::OPTION_KEY, [] );
			$stored        = \is_array( $stored ) ? $stored : [];
			$this->options = \array_merge( $this->defaults(), $stored );
		}

		return $this->options;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_overlay_id(): string {
		return (string) $this->get( self::FIELD_OVERLAY_ID );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_poll_action(): string {
		return 'ccmerch_overlay_poll';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_stream_action(): string {
		return 'ccmerch_overlay_stream';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_longpoll_action(): string {
		return 'ccmerch_overlay_longpoll';
	}

	/**
	 * Returns the Unix timestamp at which these settings were last saved.
	 *
	 * Always reads directly from the database, bypassing the WordPress object
	 * cache and alloptions cache, so the value is accurate when called inside
	 * a long-running SSE loop where in-process caches would otherwise be stale.
	 *
	 * @return int Unix timestamp, or 0 if settings have never been saved.
	 */
	public function get_config_version(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_VERSION_KEY
			)
		);
		return null !== $value ? (int) $value : 0;
	}

	/**
	 * Bumps the config version and invalidates the in-memory option cache.
	 *
	 * Hooked to update_option_{OPTION_KEY}. Runs in the admin PHP process that
	 * saved the settings; active SSE loop processes detect the bump via
	 * get_config_version() which always reads from the database directly.
	 *
	 * @return void
	 */
	public function on_settings_updated(): void {
		global $wpdb;
		// Direct upsert so the new timestamp lands in the DB immediately,
		// regardless of the WordPress option cache state in this process.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'no')
				 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				self::OPTION_VERSION_KEY,
				(string) \time()
			)
		);
		$this->options = null;
		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( '[CCMerch Config Sync] Settings saved — version key bumped to ' . \time() . '. Active SSE loops will detect this within 1 s.' );
		}
	}

	/**
	 * Reloads settings directly from the database, discarding all caches.
	 *
	 * Required in SSE loop iterations where the WordPress object cache and
	 * alloptions cache both hold values from connection-start time and would
	 * mask any settings changes made by a concurrent admin-save request.
	 *
	 * @return void
	 */
	public function reload_from_db(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw           = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_KEY
			)
		);
		$stored        = null !== $raw ? maybe_unserialize( $raw ) : [];
		$stored        = \is_array( $stored ) ? $stored : [];
		$this->options = \array_merge( $this->defaults(), $stored );
	}

	/**
	 * Generates a new cryptographically random overlay ID, stores it, and
	 * returns it. Any previous overlay URL is immediately invalidated.
	 *
	 * @return string 32-character lowercase hex string.
	 */
	public function generate_id(): string {
		$id = \bin2hex( \random_bytes( 16 ) );
		$this->set( self::FIELD_OVERLAY_ID, $id );
		return $id;
	}

	/**
	 * Returns the public URL for the overlay browser source.
	 *
	 * Returns an empty string when no overlay ID has been generated yet.
	 *
	 * @return string
	 */
	public function get_overlay_url(): string {
		$id = (string) $this->get( self::FIELD_OVERLAY_ID );

		if ( '' === $id ) {
			return '';
		}

		return home_url( '/ccmerch-alert/overlay/' . rawurlencode( $id ) . '/' );
	}

	// -------------------------------------------------------------------------
	// Static shared helpers
	// =========================================================================
	// These methods are public static so Creator_Overlay_Profile can delegate
	// to them without duplicating the logic. Neither the field names nor the
	// sanitize rules should ever differ between the global and per-creator
	// overlay — there is one canonical implementation here.
	// -------------------------------------------------------------------------

	/**
	 * Returns the default values for all appearance, audio, and layout fields.
	 *
	 * Does NOT include overlay_id — that is specific to Overlay_Settings.
	 * Creator_Overlay_Profile calls this method to avoid duplicating defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function field_defaults(): array {
		return [
			self::FIELD_TEMPLATE            => '$Product was purchased by $UserName!',
			self::FIELD_SECONDARY_TEMPLATE  => '',
			self::FIELD_FONT_FAMILY         => 'inherit',
			self::FIELD_FONT_SIZE           => '32',
			self::FIELD_FONT_WEIGHT         => '700',
			self::FIELD_TEXT_COLOR          => '#ffffff',
			self::FIELD_TEXT_SHADOW         => '2px 2px 4px rgba(0,0,0,0.8)',
			self::FIELD_TEXT_ALIGN          => 'center',
			self::FIELD_IMAGE_SIZE          => '120',
			self::FIELD_BG_COLOR            => '#000000',
			self::FIELD_BG_OPACITY          => '80',
			self::FIELD_BORDER_RADIUS       => '8',
			self::FIELD_PADDING             => '20',
			self::FIELD_ANIM_DURATION       => '500',
			self::FIELD_DISPLAY_DURATION    => '5000',
			self::FIELD_AUDIO_ENABLED       => '0',
			self::FIELD_AUDIO_DEFAULT       => '0',
			self::FIELD_AUDIO_CAT_MAP       => '{}',
			self::FIELD_LAYOUT              => self::layout_defaults(),
		];
	}

	/**
	 * Sanitizes all appearance and audio fields from raw POST input.
	 *
	 * Shared between Overlay_Settings::sanitize() (global overlay) and
	 * Creator_Overlay_Profile::sanitize() (per-creator overlay) so the rules
	 * are defined exactly once. Callers handle context-specific concerns:
	 *
	 *  - Overlay_Settings adds overlay_id and passes the pre-sanitized cat_map.
	 *  - Creator_Overlay_Profile passes '{}' for cat_map (portal v1 limitation).
	 *
	 * If FIELD_LAYOUT is absent from $input, layout_defaults() is returned rather
	 * than the caller's current stored layout. Callers that need to preserve the
	 * stored layout should inject it into $input[FIELD_LAYOUT] before calling.
	 *
	 * @param  array<string, mixed> $input       Raw POST input array.
	 * @param  string               $cat_map_json Pre-sanitized JSON category map.
	 * @return array<string, mixed>
	 */
	public static function sanitize_fields( array $input, string $cat_map_json = '{}' ): array {
		$template = sanitize_textarea_field(
			(string) ( $input[ self::FIELD_TEMPLATE ] ?? self::field_defaults()[ self::FIELD_TEMPLATE ] )
		);
		if ( '' === $template ) {
			$template = (string) self::field_defaults()[ self::FIELD_TEMPLATE ];
		}

		$secondary_template = sanitize_textarea_field(
			(string) ( $input[ self::FIELD_SECONDARY_TEMPLATE ] ?? '' )
		);

		$layout_input = $input[ self::FIELD_LAYOUT ] ?? null;
		$layout       = null !== $layout_input
			? self::sanitize_layout_data( $layout_input )
			: self::layout_defaults();

		return [
			self::FIELD_TEMPLATE            => $template,
			self::FIELD_SECONDARY_TEMPLATE  => $secondary_template,
			self::FIELD_FONT_FAMILY         => sanitize_text_field( (string) ( $input[ self::FIELD_FONT_FAMILY ]    ?? 'inherit' ) ),
			self::FIELD_FONT_SIZE           => (string) \max( 8,    \min( 200,   (int) ( $input[ self::FIELD_FONT_SIZE ]        ?? 32 ) ) ),
			self::FIELD_FONT_WEIGHT         => self::sanitize_font_weight( $input[ self::FIELD_FONT_WEIGHT ]    ?? '700' ),
			self::FIELD_TEXT_COLOR          => sanitize_hex_color( (string) ( $input[ self::FIELD_TEXT_COLOR ]    ?? '#ffffff' ) ) ?? '#ffffff',
			self::FIELD_TEXT_SHADOW         => sanitize_text_field( (string) ( $input[ self::FIELD_TEXT_SHADOW ]   ?? '' ) ),
			self::FIELD_TEXT_ALIGN          => self::sanitize_text_align( $input[ self::FIELD_TEXT_ALIGN ] ?? 'center' ),
			self::FIELD_IMAGE_SIZE          => (string) \max( 32,   \min( 512,   (int) ( $input[ self::FIELD_IMAGE_SIZE ]       ?? 120 ) ) ),
			self::FIELD_BG_COLOR            => sanitize_hex_color( (string) ( $input[ self::FIELD_BG_COLOR ]      ?? '#000000' ) ) ?? '#000000',
			self::FIELD_BG_OPACITY          => (string) \max( 0,    \min( 100,   (int) ( $input[ self::FIELD_BG_OPACITY ]       ?? 80 ) ) ),
			self::FIELD_BORDER_RADIUS       => (string) \max( 0,    \min( 100,   (int) ( $input[ self::FIELD_BORDER_RADIUS ]    ?? 8 ) ) ),
			self::FIELD_PADDING             => (string) \max( 0,    \min( 200,   (int) ( $input[ self::FIELD_PADDING ]          ?? 20 ) ) ),
			self::FIELD_ANIM_DURATION       => (string) \max( 0,    \min( 5000,  (int) ( $input[ self::FIELD_ANIM_DURATION ]    ?? 500 ) ) ),
			self::FIELD_DISPLAY_DURATION    => (string) \max( 1000, \min( 30000, (int) ( $input[ self::FIELD_DISPLAY_DURATION ] ?? 5000 ) ) ),
			self::FIELD_AUDIO_ENABLED       => '1' === ( $input[ self::FIELD_AUDIO_ENABLED ] ?? '0' ) ? '1' : '0',
			self::FIELD_AUDIO_DEFAULT       => (string) \max( 0, (int) ( $input[ self::FIELD_AUDIO_DEFAULT ] ?? 0 ) ),
			self::FIELD_AUDIO_CAT_MAP       => $cat_map_json,
			self::FIELD_LAYOUT              => $layout,
		];
	}

	/**
	 * Whitelists the font weight value.
	 *
	 * Public static so Creator_Overlay_Profile can call it without duplicating
	 * the allowed-values list.
	 *
	 * @param  mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_font_weight( mixed $value ): string {
		$allowed = [ '100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold' ];
		$value   = (string) $value;
		return \in_array( $value, $allowed, true ) ? $value : '700';
	}

	/**
	 * Whitelists the text alignment value.
	 *
	 * Public static so Creator_Overlay_Profile can call it without duplicating
	 * the allowed-values list.
	 *
	 * @param  mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_text_align( mixed $value ): string {
		$allowed = [ 'left', 'center', 'right' ];
		$value   = (string) $value;
		return \in_array( $value, $allowed, true ) ? $value : 'center';
	}

	// -------------------------------------------------------------------------
	// Layout sub-system
	// -------------------------------------------------------------------------

	/**
	 * Returns the current layout data, merging stored values over the defaults.
	 *
	 * Falls back to the legacy `ccmerch_layout_settings` option so existing
	 * users who were on version 1.9.0 keep their element positions after the
	 * upgrade to 2.0.0.
	 *
	 * @return array<string, mixed>
	 */
	public function get_layout(): array {
		$stored = $this->get( self::FIELD_LAYOUT );

		if ( \is_array( $stored ) && ! empty( $stored['elements'] ) ) {
			return $stored;
		}

		$legacy = get_option( 'ccmerch_layout_settings', [] );
		if ( \is_array( $legacy ) && ! empty( $legacy['elements'] ) ) {
			return self::sanitize_layout_data( $legacy );
		}

		return self::layout_defaults();
	}

	/**
	 * Outputs hidden <input> fields for all layout element positions and canvas options.
	 *
	 * Shared by Overlay_Admin (global overlay settings form) and
	 * Creator_Portal_Renderer (creator portal form) so the Layout Editor JS
	 * has a single source of truth for the HTML it reads and writes.
	 *
	 * Input IDs use the pattern ccmerch-el-{id}-{prop} so layout-editor.js can
	 * locate them with getElementById. Input names follow the nested array format
	 * expected by sanitize() on both Overlay_Settings and Creator_Overlay_Profile.
	 *
	 * @param array<string, mixed> $layout Current layout data.
	 * @return void
	 */
	public static function render_layout_hidden_inputs( array $layout ): void {
		$option = self::OPTION_KEY;
		$field  = self::FIELD_LAYOUT;

		$elements = [
			'product_image'  => [ 'x', 'y', 'w', 'h', 'visible', 'lock_aspect', 'border_radius' ],
			'main_text'      => [ 'x', 'y', 'w', 'h', 'visible' ],
			'secondary_text' => [ 'x', 'y', 'w', 'h', 'visible' ],
		];

		foreach ( $elements as $id => $props ) {
			$el = $layout['elements'][ $id ] ?? [];
			foreach ( $props as $prop ) {
				$raw = $el[ $prop ] ?? null;

				if ( 'visible' === $prop || 'lock_aspect' === $prop ) {
					$value = ! empty( $raw ) ? '1' : '0';
				} else {
					$value = (string) ( $raw ?? '' );
				}

				printf(
					'<input type="hidden" id="%s" name="%s[%s][elements][%s][%s]" value="%s">',
					esc_attr( 'ccmerch-el-' . $id . '-' . $prop ),
					esc_attr( $option ),
					esc_attr( $field ),
					esc_attr( $id ),
					esc_attr( $prop ),
					esc_attr( $value )
				);
			}
		}

		$canvas = $layout['canvas'] ?? [];
		printf(
			'<input type="hidden" id="ccmerch-canvas-snap_grid" name="%s[%s][canvas][snap_grid]" value="%s">',
			esc_attr( $option ),
			esc_attr( $field ),
			esc_attr( ! empty( $canvas['snap_grid'] ) ? '1' : '0' )
		);
		printf(
			'<input type="hidden" id="ccmerch-canvas-grid_size" name="%s[%s][canvas][grid_size]" value="%s">',
			esc_attr( $option ),
			esc_attr( $field ),
			esc_attr( (string) ( (int) ( $canvas['grid_size'] ?? 10 ) ) )
		);
	}

	/**
	 * Returns the default element positions for a 1920 × 1080 canvas.
	 *
	 * Public static so Creator_Overlay_Profile can use the same defaults
	 * without duplicating this array. All coordinates are in 1920 × 1080 space.
	 *
	 * @return array<string, mixed>
	 */
	public static function layout_defaults(): array {
		return [
			'elements' => [
				'product_image'  => [
					'x'            => 40,
					'y'            => 880,
					'w'            => 120,
					'h'            => 120,
					'visible'      => true,
					'lock_aspect'  => true,
					'border_radius'=> 4,
				],
				'main_text'      => [
					'x'       => 176,
					'y'       => 895,
					'w'       => 700,
					'h'       => 80,
					'visible' => true,
				],
				'secondary_text' => [
					'x'       => 176,
					'y'       => 985,
					'w'       => 700,
					'h'       => 50,
					'visible' => false,
				],
			],
			'canvas' => [
				'snap_grid' => false,
				'grid_size' => 10,
			],
		];
	}

	/**
	 * Validates and coerces raw layout data from form hidden-inputs or from the
	 * legacy option. Returns a complete layout array merged over the defaults.
	 *
	 * Public static so Creator_Overlay_Profile can call it without duplicating
	 * this logic. Only x, y, w, h, visible, lock_aspect, and border_radius are
	 * accepted per element — typography and colour come from global overlay settings.
	 *
	 * @param  mixed $input Raw layout value (array from form POST or from DB).
	 * @return array<string, mixed>
	 */
	public static function sanitize_layout_data( mixed $input ): array {
		if ( ! \is_array( $input ) ) {
			return self::layout_defaults();
		}

		$defaults = self::layout_defaults();
		$elements = [];

		foreach ( $defaults['elements'] as $id => $def ) {
			$raw = \is_array( $input['elements'][ $id ] ?? null )
				? $input['elements'][ $id ]
				: [];

			$el = [
				'x'       => isset( $raw['x'] ) ? (int) $raw['x'] : (int) $def['x'],
				'y'       => isset( $raw['y'] ) ? (int) $raw['y'] : (int) $def['y'],
				'w'       => \max( 10, isset( $raw['w'] ) ? (int) $raw['w'] : (int) $def['w'] ),
				'h'       => \max( 10, isset( $raw['h'] ) ? (int) $raw['h'] : (int) $def['h'] ),
				'visible' => isset( $raw['visible'] )
					? \filter_var( $raw['visible'], \FILTER_VALIDATE_BOOLEAN )
					: (bool) $def['visible'],
			];

			if ( isset( $def['lock_aspect'] ) ) {
				$el['lock_aspect'] = isset( $raw['lock_aspect'] )
					? \filter_var( $raw['lock_aspect'], \FILTER_VALIDATE_BOOLEAN )
					: (bool) $def['lock_aspect'];
			}

			if ( isset( $def['border_radius'] ) ) {
				$el['border_radius'] = \max( 0, \min( 500, isset( $raw['border_radius'] )
					? (int) $raw['border_radius']
					: (int) $def['border_radius'] ) );
			}

			$elements[ $id ] = $el;
		}

		$canvas_raw = \is_array( $input['canvas'] ?? null ) ? $input['canvas'] : [];
		$snap_raw   = $canvas_raw['snap_grid'] ?? $defaults['canvas']['snap_grid'];
		$gridsize   = \max( 1, \min( 100, (int) ( $canvas_raw['grid_size'] ?? $defaults['canvas']['grid_size'] ) ) );

		return [
			'elements' => $elements,
			'canvas'   => [
				'snap_grid' => \filter_var( $snap_raw, \FILTER_VALIDATE_BOOLEAN ),
				'grid_size' => $gridsize,
			],
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns validated defaults for every overlay setting.
	 *
	 * Includes overlay_id (empty string) in addition to the shared field_defaults().
	 * Keep private — only Overlay_Settings needs the overlay_id default.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return \array_merge(
			[ self::FIELD_OVERLAY_ID => '' ],
			self::field_defaults()
		);
	}

	/**
	 * Sanitizes the JSON-encoded category → attachment-ID map.
	 *
	 * Accepts either an array (first sanitize pass, raw form POST) or a JSON
	 * string (second sanitize pass, from update_option). Always returns a
	 * compact JSON object with integer term-ID keys and integer attachment-ID
	 * values. Categories with no assigned sound (att_id = 0) are excluded.
	 *
	 * When $allowed_term_ids is non-empty, only entries whose term ID appears in
	 * that list are kept. Pass a creator's assigned category IDs to enforce
	 * creator-scoped audio isolation — entries for other creators' categories are
	 * silently dropped even if submitted in the POST body.
	 *
	 * Public static so Creator_Overlay_Profile can call it without duplicating
	 * the sanitize logic.
	 *
	 * @param  mixed $input            Raw category map value (array or JSON string).
	 * @param  int[] $allowed_term_ids Term IDs the caller is permitted to save audio for.
	 *                                 Empty array = no restriction (admin global overlay).
	 * @return string JSON-encoded map, e.g. '{"5":42}'.
	 */
	public static function sanitize_category_map( mixed $input, array $allowed_term_ids = [] ): string {
		$cleaned = [];

		if ( \is_array( $input ) ) {
			foreach ( $input as $term_id => $att_id ) {
				$tid = (int) $term_id;
				$aid = (int) $att_id;
				if ( $tid > 0 && $aid > 0 ) {
					$cleaned[ $tid ] = $aid;
				}
			}
		} elseif ( \is_string( $input ) && '' !== $input ) {
			$decoded = \json_decode( $input, true );
			if ( \is_array( $decoded ) ) {
				foreach ( $decoded as $term_id => $att_id ) {
					$tid = (int) $term_id;
					$aid = (int) $att_id;
					if ( $tid > 0 && $aid > 0 ) {
						$cleaned[ $tid ] = $aid;
					}
				}
			}
		}

		// When scoped to a creator, silently drop any entry that is not in the
		// creator's own category assignments — prevents a malicious portal POST
		// from saving audio for categories that belong to other creators.
		if ( ! empty( $allowed_term_ids ) ) {
			$cleaned = \array_filter(
				$cleaned,
				static fn( int $tid ) => \in_array( $tid, $allowed_term_ids, true ),
				\ARRAY_FILTER_USE_KEY
			);
		}

		return ! empty( $cleaned ) ? (string) wp_json_encode( $cleaned ) : '{}';
	}
}
