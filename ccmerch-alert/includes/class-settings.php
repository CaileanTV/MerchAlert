<?php
/**
 * Settings module.
 *
 * @package CCMerchAlert
 */

namespace CCMerchAlert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin settings via the WordPress Settings API and Options API.
 *
 * Responsibilities:
 *  - Owns all setting keys, page slug, and section IDs as constants.
 *  - Registers the option, section, and fields with the Settings API.
 *  - Provides sanitization and default values.
 *  - Exposes a simple get/set/all interface for reading settings in other modules.
 *
 * Field rendering callbacks live here so that the HTML for each field stays
 * next to the field's registration and sanitization logic.
 *
 * Instantiated by {@see Plugin::load_components()} on every request.
 */
final class Settings {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * The wp_options key under which all plugin settings are stored.
	 *
	 * Using a single serialised array keeps the options table clean and makes
	 * it trivial to delete all plugin data on uninstall.
	 */
	public const OPTION_KEY = 'ccmerch_alert_settings';

	/**
	 * The admin page slug on which the Settings API sections and fields
	 * are registered. Must match the menu slug passed to add_submenu_page().
	 */
	public const PAGE = 'ccmerch-alert-settings';

	/**
	 * ID of the General settings section.
	 */
	public const SECTION_GENERAL = 'ccmerch_alert_general';

	/**
	 * Key for the "Enable Alerts" checkbox setting.
	 */
	public const FIELD_ENABLE_ALERTS = 'enable_alerts';

	/**
	 * Key for the "Dashboard Auto Refresh Interval" setting.
	 * Stored in seconds internally; converted to/from display units in the renderer.
	 */
	public const FIELD_REFRESH_INTERVAL = 'refresh_interval';

	/**
	 * ID of the Media settings section.
	 */
	public const SECTION_MEDIA = 'ccmerch_alert_media';

	/**
	 * Key for the "Maximum Audio File Size" setting.
	 * Stored in bytes. Default: 10 485 760 (10 MB).
	 * Clamped to [102 400, 524 288 000] (100 KB – 500 MB).
	 */
	public const FIELD_MAX_AUDIO_SIZE = 'max_audio_size';

	/**
	 * Key for the "Maximum Audio Length" setting.
	 * Stored in seconds. Default: 30. Clamped to [1, 3600].
	 */
	public const FIELD_MAX_AUDIO_DURATION = 'max_audio_duration';

	/**
	 * ID of the Fonts settings section.
	 */
	public const SECTION_FONTS = 'ccmerch_alert_fonts';

	/**
	 * Key for the "Google Fonts API Key" setting.
	 * Default: '' (empty). Required to sync the Google Fonts catalog.
	 */
	public const FIELD_GOOGLE_FONTS_API_KEY = 'google_fonts_api_key';

	/**
	 * ID of the Checkout settings section.
	 */
	public const SECTION_CHECKOUT = 'ccmerch_alert_checkout';

	/**
	 * Key for the "Enable Customer Username Field" checkbox setting.
	 * Default: 0 (disabled). When enabled, a text field appears during WooCommerce
	 * checkout so customers can enter their streaming/community username.
	 */
	public const FIELD_USERNAME_ENABLED = 'username_enabled';

	/**
	 * Key for the "Checkout Field Label" text setting.
	 * Default: 'Stream Username'. Shown as the field label during checkout.
	 * Examples: 'Twitch Username', 'Kick Username', 'Display Username'.
	 */
	public const FIELD_USERNAME_LABEL = 'username_label';

	// -------------------------------------------------------------------------
	// Properties
	// -------------------------------------------------------------------------

	/**
	 * In-memory cache of the options array, loaded once on first access.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $options = null;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public function __construct() {
		add_action( 'admin_init', [ $this, 'register' ] );
	}

	// -------------------------------------------------------------------------
	// Settings API registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the option, section, and all fields with the WordPress
	 * Settings API.
	 *
	 * Hooked onto `admin_init` so WordPress can process the options.php
	 * form submission and redirect back with the updated values.
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

		add_settings_section(
			self::SECTION_GENERAL,
			__( 'General', 'ccmerch-alert' ),
			'__return_false',  // No section description.
			self::PAGE
		);

		add_settings_field(
			self::FIELD_ENABLE_ALERTS,
			__( 'Enable Alerts', 'ccmerch-alert' ),
			[ $this, 'render_field_enable_alerts' ],
			self::PAGE,
			self::SECTION_GENERAL,
			[
				// Tells WordPress to wrap the field label in <label for="…">.
				'label_for' => self::OPTION_KEY . '_' . self::FIELD_ENABLE_ALERTS,
			]
		);

		add_settings_field(
			self::FIELD_REFRESH_INTERVAL,
			__( 'Dashboard Auto Refresh Interval', 'ccmerch-alert' ),
			[ $this, 'render_field_refresh_interval' ],
			self::PAGE,
			self::SECTION_GENERAL
			// No label_for — field contains two inputs (number + select).
		);

		add_settings_section(
			self::SECTION_MEDIA,
			__( 'Media', 'ccmerch-alert' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			self::FIELD_MAX_AUDIO_SIZE,
			__( 'Maximum Audio File Size', 'ccmerch-alert' ),
			[ $this, 'render_field_max_audio_size' ],
			self::PAGE,
			self::SECTION_MEDIA
		);

		add_settings_field(
			self::FIELD_MAX_AUDIO_DURATION,
			__( 'Maximum Audio Length', 'ccmerch-alert' ),
			[ $this, 'render_field_max_audio_duration' ],
			self::PAGE,
			self::SECTION_MEDIA
		);

		add_settings_section(
			self::SECTION_FONTS,
			__( 'Google Fonts', 'ccmerch-alert' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			self::FIELD_GOOGLE_FONTS_API_KEY,
			__( 'Google Fonts API Key', 'ccmerch-alert' ),
			[ $this, 'render_field_google_fonts_api_key' ],
			self::PAGE,
			self::SECTION_FONTS,
			[
				'label_for' => self::OPTION_KEY . '_' . self::FIELD_GOOGLE_FONTS_API_KEY,
			]
		);

		add_settings_section(
			self::SECTION_CHECKOUT,
			__( 'Checkout', 'ccmerch-alert' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			self::FIELD_USERNAME_ENABLED,
			__( 'Enable Customer Username Field', 'ccmerch-alert' ),
			[ $this, 'render_field_username_enabled' ],
			self::PAGE,
			self::SECTION_CHECKOUT,
			[
				'label_for' => self::OPTION_KEY . '_' . self::FIELD_USERNAME_ENABLED,
			]
		);

		add_settings_field(
			self::FIELD_USERNAME_LABEL,
			__( 'Checkout Field Label', 'ccmerch-alert' ),
			[ $this, 'render_field_username_label' ],
			self::PAGE,
			self::SECTION_CHECKOUT,
			[
				'label_for' => self::OPTION_KEY . '_' . self::FIELD_USERNAME_LABEL,
			]
		);
	}

	/**
	 * Sanitizes and coerces the raw $_POST values before they are stored.
	 *
	 * Unchecked checkboxes are absent from the POST payload, so each boolean
	 * field must explicitly be set to 0 when the key is missing.
	 *
	 * @param  mixed $input Raw input from the submitted form.
	 * @return array<string, mixed> Sanitized options array.
	 */
	public function sanitize( mixed $input ): array {
		$input = \is_array( $input ) ? $input : [];

		return [
			self::FIELD_ENABLE_ALERTS        => ! empty( $input[ self::FIELD_ENABLE_ALERTS ] ) ? 1 : 0,
			self::FIELD_REFRESH_INTERVAL     => $this->sanitize_refresh_interval( $input ),
			self::FIELD_MAX_AUDIO_SIZE       => $this->sanitize_max_audio_size( $input ),
			self::FIELD_MAX_AUDIO_DURATION   => $this->sanitize_max_audio_duration( $input ),
			self::FIELD_GOOGLE_FONTS_API_KEY => sanitize_text_field( (string) ( $input[ self::FIELD_GOOGLE_FONTS_API_KEY ] ?? '' ) ),
			self::FIELD_USERNAME_ENABLED     => ! empty( $input[ self::FIELD_USERNAME_ENABLED ] ) ? 1 : 0,
			self::FIELD_USERNAME_LABEL       => sanitize_text_field( (string) ( $input[ self::FIELD_USERNAME_LABEL ] ?? '' ) ),
		];
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders the "Enable Alerts" checkbox.
	 *
	 * WordPress calls this callback with the $args registered in
	 * add_settings_field(), so the input id is derived from label_for rather
	 * than being duplicated here.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_enable_alerts( array $args ): void {
		$enabled = (bool) $this->get( self::FIELD_ENABLE_ALERTS, 1 );
		?>
		<input
			type="checkbox"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_ENABLE_ALERTS . ']' ); ?>"
			value="1"
			<?php checked( $enabled ); ?>
		>
		<?php
	}

	/**
	 * Renders the "Dashboard Auto Refresh Interval" number + unit field pair.
	 *
	 * Stores seconds internally; converts to minutes for display when the stored
	 * value is a whole-minute multiple ≥ 60.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_refresh_interval( array $args ): void {
		$seconds = (int) $this->get( self::FIELD_REFRESH_INTERVAL );

		if ( $seconds >= 60 && 0 === $seconds % 60 ) {
			$display_value = (string) ( $seconds / 60 );
			$display_unit  = 'minutes';
		} else {
			$display_value = (string) $seconds;
			$display_unit  = 'seconds';
		}
		?>
		<input
			type="number"
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . self::FIELD_REFRESH_INTERVAL . '_value' ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_REFRESH_INTERVAL . '_value]' ); ?>"
			value="<?php echo esc_attr( $display_value ); ?>"
			min="1"
			max="999"
			style="width: 70px;"
		>
		<select
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . self::FIELD_REFRESH_INTERVAL . '_unit' ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_REFRESH_INTERVAL . '_unit]' ); ?>"
		>
			<option value="seconds" <?php selected( $display_unit, 'seconds' ); ?>>
				<?php esc_html_e( 'Seconds', 'ccmerch-alert' ); ?>
			</option>
			<option value="minutes" <?php selected( $display_unit, 'minutes' ); ?>>
				<?php esc_html_e( 'Minutes', 'ccmerch-alert' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Renders the "Maximum Audio File Size" number + unit field pair.
	 *
	 * Stores bytes internally; converts to MB for display when the stored value is
	 * a whole-MB multiple, otherwise displays in KB.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_max_audio_size( array $args ): void {
		$bytes = (int) $this->get( self::FIELD_MAX_AUDIO_SIZE );

		if ( $bytes >= ( 1024 * 1024 ) && 0 === $bytes % ( 1024 * 1024 ) ) {
			$display_value = (string) ( $bytes / ( 1024 * 1024 ) );
			$display_unit  = 'MB';
		} else {
			$display_value = (string) (int) \round( $bytes / 1024 );
			$display_unit  = 'KB';
		}
		?>
		<input
			type="number"
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . self::FIELD_MAX_AUDIO_SIZE . '_value' ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_MAX_AUDIO_SIZE . '_value]' ); ?>"
			value="<?php echo esc_attr( $display_value ); ?>"
			min="1"
			max="999"
			style="width: 70px;"
		>
		<select
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . self::FIELD_MAX_AUDIO_SIZE . '_unit' ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_MAX_AUDIO_SIZE . '_unit]' ); ?>"
		>
			<option value="MB" <?php selected( $display_unit, 'MB' ); ?>>MB</option>
			<option value="KB" <?php selected( $display_unit, 'KB' ); ?>>KB</option>
		</select>
		<p class="description"><?php esc_html_e( 'Maximum file size for audio uploads. Default: 10 MB.', 'ccmerch-alert' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Maximum Audio Length" number + unit field pair.
	 *
	 * Stores seconds internally; converts to minutes for display when the stored
	 * value is a whole-minute multiple ≥ 60.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_max_audio_duration( array $args ): void {
		$seconds = (int) $this->get( self::FIELD_MAX_AUDIO_DURATION );

		if ( $seconds >= 60 && 0 === $seconds % 60 ) {
			$display_value = (string) ( $seconds / 60 );
			$display_unit  = 'minutes';
		} else {
			$display_value = (string) $seconds;
			$display_unit  = 'seconds';
		}
		?>
		<input
			type="number"
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . self::FIELD_MAX_AUDIO_DURATION . '_value' ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_MAX_AUDIO_DURATION . '_value]' ); ?>"
			value="<?php echo esc_attr( $display_value ); ?>"
			min="1"
			max="999"
			style="width: 70px;"
		>
		<select
			id="<?php echo esc_attr( self::OPTION_KEY . '_' . self::FIELD_MAX_AUDIO_DURATION . '_unit' ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_MAX_AUDIO_DURATION . '_unit]' ); ?>"
		>
			<option value="seconds" <?php selected( $display_unit, 'seconds' ); ?>>
				<?php esc_html_e( 'Seconds', 'ccmerch-alert' ); ?>
			</option>
			<option value="minutes" <?php selected( $display_unit, 'minutes' ); ?>>
				<?php esc_html_e( 'Minutes', 'ccmerch-alert' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Maximum playback length for uploaded audio clips. Default: 30 seconds.', 'ccmerch-alert' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Google Fonts API Key" text input.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_google_fonts_api_key( array $args ): void {
		$value = (string) $this->get( self::FIELD_GOOGLE_FONTS_API_KEY );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_GOOGLE_FONTS_API_KEY . ']' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
		>
		<p class="description">
			<?php esc_html_e( 'Required to sync the Google Fonts catalog. Get a free key at Google Cloud Console → APIs & Services → Credentials. Once saved, go to Font Library and click "Sync Google Fonts Catalog".', 'ccmerch-alert' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the "Enable Customer Username Field" checkbox.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_username_enabled( array $args ): void {
		$enabled = (bool) $this->get( self::FIELD_USERNAME_ENABLED );
		?>
		<input
			type="checkbox"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_USERNAME_ENABLED . ']' ); ?>"
			value="1"
			<?php checked( $enabled ); ?>
		>
		<p class="description"><?php esc_html_e( 'Add an optional text field to WooCommerce checkout so customers can enter their streaming or community username. The value is available as the $UserName template variable in overlay alerts.', 'ccmerch-alert' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Checkout Field Label" text input.
	 *
	 * @param  array<string, mixed> $args Args registered with add_settings_field().
	 * @return void
	 */
	public function render_field_username_label( array $args ): void {
		$label = (string) $this->get( self::FIELD_USERNAME_LABEL );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_KEY . '[' . self::FIELD_USERNAME_LABEL . ']' ); ?>"
			value="<?php echo esc_attr( $label ); ?>"
			placeholder="<?php esc_attr_e( 'Stream Username', 'ccmerch-alert' ); ?>"
			class="regular-text"
		>
		<p class="description"><?php esc_html_e( 'The label shown on the checkout form. Examples: Twitch Username, Kick Username, Display Username.', 'ccmerch-alert' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Public data API
	// -------------------------------------------------------------------------

	/**
	 * Returns a single setting value, falling back to the registered default.
	 *
	 * @param  string $key     Setting key.
	 * @param  mixed  $default Fallback when the key is absent (overrides the
	 *                         module default — use sparingly).
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$value = $this->all()[ $key ] ?? null;

		if ( null !== $value ) {
			return $value;
		}

		return $default ?? ( $this->defaults()[ $key ] ?? null );
	}

	/**
	 * Persists a single setting value and updates the in-memory cache.
	 *
	 * @param  string $key   Setting key.
	 * @param  mixed  $value Value to store.
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		$options         = $this->all();
		$options[ $key ] = $value;
		$this->options   = $options;

		update_option( self::OPTION_KEY, $options );
	}

	/**
	 * Returns all settings, merged over the registered defaults.
	 *
	 * Defaults are applied for keys that have never been saved, so callers
	 * always receive a complete and predictable array.
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
	 * Deletes all plugin settings from the database.
	 *
	 * Called statically by uninstall.php so no plugin instance is required.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		delete_option( self::OPTION_KEY );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalises the refresh interval sub-fields (value + unit) into a single
	 * integer seconds value, clamped to a safe range.
	 *
	 * Min 5 s — prevents accidental DDoS of the admin-ajax endpoint.
	 * Max 3 600 s (1 h) — beyond this the dashboard is no longer "real-time".
	 *
	 * @param  array<string, mixed> $input Sanitized POST input array.
	 * @return int Seconds, clamped to [5, 3600].
	 */
	private function sanitize_refresh_interval( array $input ): int {
		// update_option() calls sanitize_option() a second time on the already-processed
		// value. At that point the sub-field keys (_value, _unit) are gone and only the
		// computed seconds key is present — pass it straight through the clamp.
		if ( ! isset( $input[ self::FIELD_REFRESH_INTERVAL . '_value' ] ) ) {
			return \max( 5, \min( 3600, (int) ( $input[ self::FIELD_REFRESH_INTERVAL ] ?? 15 ) ) );
		}

		$raw_value = \max( 1, (int) $input[ self::FIELD_REFRESH_INTERVAL . '_value' ] );
		$unit      = (string) ( $input[ self::FIELD_REFRESH_INTERVAL . '_unit' ] ?? 'seconds' );
		$seconds   = 'minutes' === $unit ? $raw_value * 60 : $raw_value;

		return \max( 5, \min( 3600, $seconds ) );
	}

	/**
	 * Normalises the max audio size sub-fields (value + unit) into a single bytes
	 * value, clamped to [102 400, 524 288 000] (100 KB – 500 MB).
	 *
	 * Handles the double-sanitize pass: when the sub-field keys are absent,
	 * the stored bytes value is passed directly through the clamp.
	 *
	 * @param  array<string, mixed> $input Sanitized POST input array.
	 * @return int Bytes, clamped to [102400, 524288000].
	 */
	private function sanitize_max_audio_size( array $input ): int {
		if ( ! isset( $input[ self::FIELD_MAX_AUDIO_SIZE . '_value' ] ) ) {
			return \max( 102400, \min( 524288000, (int) ( $input[ self::FIELD_MAX_AUDIO_SIZE ] ?? 10485760 ) ) );
		}

		$raw_value = \max( 1, (int) $input[ self::FIELD_MAX_AUDIO_SIZE . '_value' ] );
		$unit      = (string) ( $input[ self::FIELD_MAX_AUDIO_SIZE . '_unit' ] ?? 'MB' );
		$bytes     = 'MB' === $unit ? $raw_value * 1024 * 1024 : $raw_value * 1024;

		return \max( 102400, \min( 524288000, $bytes ) );
	}

	/**
	 * Normalises the max audio duration sub-fields (value + unit) into a single
	 * seconds value, clamped to [1, 3600].
	 *
	 * @param  array<string, mixed> $input Sanitized POST input array.
	 * @return int Seconds, clamped to [1, 3600].
	 */
	private function sanitize_max_audio_duration( array $input ): int {
		if ( ! isset( $input[ self::FIELD_MAX_AUDIO_DURATION . '_value' ] ) ) {
			return \max( 1, \min( 3600, (int) ( $input[ self::FIELD_MAX_AUDIO_DURATION ] ?? 30 ) ) );
		}

		$raw_value = \max( 1, (int) $input[ self::FIELD_MAX_AUDIO_DURATION . '_value' ] );
		$unit      = (string) ( $input[ self::FIELD_MAX_AUDIO_DURATION . '_unit' ] ?? 'seconds' );
		$seconds   = 'minutes' === $unit ? $raw_value * 60 : $raw_value;

		return \max( 1, \min( 3600, $seconds ) );
	}

	/**
	 * Returns the default values for every setting.
	 *
	 * "Enable Alerts" defaults to 1 (on) so new installs work out of the box.
	 * "Refresh Interval" defaults to 15 seconds.
	 * "Max Audio Size" defaults to 10 MB (10 485 760 bytes).
	 * "Max Audio Duration" defaults to 30 seconds.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return [
			self::FIELD_ENABLE_ALERTS        => 1,
			self::FIELD_REFRESH_INTERVAL     => 15,
			self::FIELD_MAX_AUDIO_SIZE       => 10485760,
			self::FIELD_MAX_AUDIO_DURATION   => 30,
			self::FIELD_GOOGLE_FONTS_API_KEY => '',
			self::FIELD_USERNAME_ENABLED     => 0,
			self::FIELD_USERNAME_LABEL       => 'Stream Username',
		];
	}
}
