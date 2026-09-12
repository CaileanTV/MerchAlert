<?php
/**
 * Translation Manager admin page.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Translations\String_Registry;
use CCMerchAlert\Translations\Translation_Service;
use CCMerchAlert\UI\Asset_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the CCMerch Alert → Translations admin page.
 *
 * Shows every registered translatable string in a searchable, filterable table.
 * Admins can view the current override per key, edit the value inline, save via
 * AJAX, or reset an individual key back to its registered default.
 *
 * The table is client-side filterable (search + context dropdown) — no page
 * reloads required for filtering. AJAX save/reset use the shared
 * `ccmerch-utils.js` delegated listener pattern (data-attribute driven).
 *
 * Must never: output HTML outside render methods.
 * Must never: query the database directly (delegates to Translation_Service).
 */
final class Translations_Admin {

	public const PAGE         = 'ccmerch-alert-translations';
	public const AJAX_SAVE    = 'ccmerch_translation_save';
	public const AJAX_RESET   = 'ccmerch_translation_reset';
	public const NONCE_ACTION = 'ccmerch_translation_manage';

	public function __construct() {
		add_action( 'admin_menu',                                      [ $this, 'register_page'    ] );
		add_action( 'admin_enqueue_scripts',                           [ $this, 'enqueue_assets'   ] );
		add_action( 'wp_ajax_' . self::AJAX_SAVE,                      [ $this, 'handle_save'      ] );
		add_action( 'wp_ajax_' . self::AJAX_RESET,                     [ $this, 'handle_reset'     ] );
	}

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the Translations submenu page under the CCMerch Alert top-level menu.
	 */
	public function register_page(): void {
		add_submenu_page(
			'ccmerch-alert',
			'Translations — CCMerch Alert',
			'Translations',
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues admin CSS and the shared ccmerch-utils.js on the Translations page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( \strpos( $hook_suffix, self::PAGE ) === false ) {
			return;
		}
		Asset_Loader::enqueue_translations_admin();
		wp_localize_script(
			'ccmerch-utils',
			'ccmerchTranslations',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
				'saveAction'  => self::AJAX_SAVE,
				'resetAction' => self::AJAX_RESET,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the full Translation Manager admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$strings  = Translation_Service::all_for_admin( 'en' );
		$contexts = String_Registry::contexts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CCMerch Alert — Translations', 'ccmerch-alert' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Customise the text strings used across the plugin. Leave a field empty or matching the default to use the built-in text.', 'ccmerch-alert' ); ?>
			</p>

			<!-- ── Toolbar ───────────────────────────────────────────────────── -->
			<div class="ccmerch-trans-toolbar">
				<input
					type="search"
					id="ccmerch-trans-search"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Search keys or text…', 'ccmerch-alert' ); ?>"
					data-ccmerch-trans-search="1"
				>
				<select id="ccmerch-trans-context" data-ccmerch-trans-context="1">
					<option value=""><?php esc_html_e( 'All contexts', 'ccmerch-alert' ); ?></option>
					<?php foreach ( $contexts as $ctx ) : ?>
					<option value="<?php echo esc_attr( $ctx ); ?>"><?php echo esc_html( \ucfirst( $ctx ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- ── Translations table ────────────────────────────────────────── -->
			<table
				id="ccmerch-trans-table"
				class="wp-list-table widefat fixed striped ccmerch-trans-table"
				data-ccmerch-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
				data-ccmerch-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
				data-ccmerch-save-action="<?php echo esc_attr( self::AJAX_SAVE ); ?>"
				data-ccmerch-reset-action="<?php echo esc_attr( self::AJAX_RESET ); ?>"
			>
				<thead>
					<tr>
						<th class="ccmerch-trans-col-context"><?php esc_html_e( 'Context', 'ccmerch-alert' ); ?></th>
						<th class="ccmerch-trans-col-key"><?php esc_html_e( 'Key', 'ccmerch-alert' ); ?></th>
						<th class="ccmerch-trans-col-default"><?php esc_html_e( 'Default Text', 'ccmerch-alert' ); ?></th>
						<th class="ccmerch-trans-col-override"><?php esc_html_e( 'Override', 'ccmerch-alert' ); ?></th>
						<th class="ccmerch-trans-col-actions"><?php esc_html_e( 'Actions', 'ccmerch-alert' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $strings as $entry ) : ?>
					<tr
						class="ccmerch-trans-row<?php echo $entry['is_overridden'] ? ' ccmerch-trans-row--overridden' : ''; ?>"
						data-trans-key="<?php echo esc_attr( $entry['key'] ); ?>"
						data-trans-context="<?php echo esc_attr( $entry['context'] ); ?>"
					>
						<td class="ccmerch-trans-col-context">
							<span class="ccmerch-trans-context-badge"><?php echo esc_html( $entry['context'] ); ?></span>
						</td>
						<td class="ccmerch-trans-col-key">
							<code><?php echo esc_html( $entry['key'] ); ?></code>
						</td>
						<td class="ccmerch-trans-col-default">
							<?php echo esc_html( $entry['default_text'] ); ?>
						</td>
						<td class="ccmerch-trans-col-override">
							<input
								type="text"
								class="regular-text ccmerch-trans-input"
								value="<?php echo esc_attr( $entry['current_value'] ); ?>"
								placeholder="<?php echo esc_attr( $entry['default_text'] ); ?>"
								data-trans-input="<?php echo esc_attr( $entry['key'] ); ?>"
								aria-label="<?php echo esc_attr( sprintf( 'Override for %s', $entry['key'] ) ); ?>"
							>
							<span class="ccmerch-trans-feedback" role="status" aria-live="polite"></span>
						</td>
						<td class="ccmerch-trans-col-actions">
							<button
								type="button"
								class="button button-primary button-small"
								data-ccmerch-translation-save="1"
								data-trans-key="<?php echo esc_attr( $entry['key'] ); ?>"
							><?php esc_html_e( 'Save', 'ccmerch-alert' ); ?></button>
							<?php if ( $entry['is_overridden'] ) : ?>
							<button
								type="button"
								class="button button-secondary button-small"
								data-ccmerch-translation-reset="1"
								data-trans-key="<?php echo esc_attr( $entry['key'] ); ?>"
							><?php esc_html_e( 'Reset', 'ccmerch-alert' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p id="ccmerch-trans-no-results" class="description" style="display:none;">
				<?php esc_html_e( 'No strings match your search.', 'ccmerch-alert' ); ?>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: saves a single string override.
	 *
	 * POST params: trans_key, trans_value, lang (optional, defaults to 'en').
	 *
	 * @return never
	 */
	public function handle_save(): never {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorised.', 'reason' => 'cap', 'step' => 'auth' ] );
		}

		$key   = sanitize_text_field( (string) ( $_POST['trans_key']   ?? '' ) );
		$value = sanitize_text_field( (string) ( $_POST['trans_value'] ?? '' ) );
		$lang  = sanitize_key(        (string) ( $_POST['lang']        ?? 'en' ) );

		if ( '' === $key || ! String_Registry::get_definition( $key ) ) {
			wp_send_json_error( [ 'message' => 'Invalid key.', 'reason' => 'invalid_key', 'step' => 'validate' ] );
		}

		if ( '' === $lang ) {
			$lang = 'en';
		}

		$ok = Translation_Service::save( $key, $value, $lang );

		wp_send_json_success( [
			'key'          => $key,
			'effective'    => Translation_Service::t( $key ),
			'is_overridden' => null !== \CCMerchAlert\Translations\Translation_Store::get( $key, $lang ),
		] );
	}

	/**
	 * AJAX handler: resets a single string to its registered default.
	 *
	 * POST params: trans_key, lang (optional, defaults to 'en').
	 *
	 * @return never
	 */
	public function handle_reset(): never {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorised.', 'reason' => 'cap', 'step' => 'auth' ] );
		}

		$key  = sanitize_text_field( (string) ( $_POST['trans_key'] ?? '' ) );
		$lang = sanitize_key(        (string) ( $_POST['lang']      ?? 'en' ) );

		if ( '' === $key || ! String_Registry::get_definition( $key ) ) {
			wp_send_json_error( [ 'message' => 'Invalid key.', 'reason' => 'invalid_key', 'step' => 'validate' ] );
		}

		if ( '' === $lang ) {
			$lang = 'en';
		}

		Translation_Service::reset( $key, $lang );

		wp_send_json_success( [
			'key'     => $key,
			'default' => String_Registry::default_for( $key ),
		] );
	}
}
