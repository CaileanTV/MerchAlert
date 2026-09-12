<?php
/**
 * Shared overlay configuration UI renderer.
 *
 * @package CCMerchAlert\Overlay
 */

namespace CCMerchAlert\Overlay;

use CCMerchAlert\OrderLog\Order_Repository;
use CCMerchAlert\UI\Components\Audio_Upload_Component;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces shared HTML for the overlay configuration form.
 *
 * Both Overlay_Admin (global overlay) and Creator_Portal_Renderer (per-creator
 * overlay) call these static methods so the Overlay Designer, hidden appearance
 * fields, and Audio section are rendered from a single implementation.
 *
 * The only contextual differences between Admin and Portal are:
 *  - The settings values array passed in (Overlay_Settings::all() vs Creator_Overlay_Profile::all()).
 *  - Whether wp.media() picker buttons are available ($with_media_picker). Admin passes true
 *    because wp_enqueue_media() is called there. Portal passes false — creators may not be
 *    WordPress users, so wp.media() cannot be guaranteed; number inputs are shown instead.
 *
 * The form option key prefix is always Overlay_Settings::OPTION_KEY in both contexts.
 * Creator_Overlay_Controller::handle_save() reads $_POST[Overlay_Settings::OPTION_KEY]
 * and passes it through Creator_Overlay_Profile::sanitize(), which expects the same keys.
 *
 * Must never: be instantiated — all methods are static.
 * Must never: register WordPress hooks.
 * Must never: contain business logic or database access (except resolve_preview_data).
 */
final class Overlay_Config_Renderer {

	/**
	 * Renders the hidden appearance fields div.
	 *
	 * These inputs are part of the form POST but are not visible on screen.
	 * layout-editor.js reads them via getFormVal() and writes to them via
	 * applyAppearanceProp() when the Properties panel changes. They are
	 * included in the next save automatically because they are inside the form.
	 *
	 * @param array<string, mixed> $values     All current overlay setting values.
	 * @param string               $option_key Form field name prefix (Overlay_Settings::OPTION_KEY).
	 * @return void
	 */
	public static function render_appearance_fields( array $values, string $option_key ): void {
		$s = static fn( string $k, mixed $default = '' ): mixed => $values[ $k ] ?? $default;
		?>
		<!--
			Hidden appearance fields — part of the form POST but not rendered visually.
			The Overlay Designer Properties panel reads and writes these inputs so all
			appearance changes are saved when the page-level form is submitted.
		-->
		<div id="ccmerch-overlay-appearance-fields" style="display:none;" aria-hidden="true">

			<textarea
				id="<?php echo esc_attr( $option_key . '_' . Overlay_Settings::FIELD_TEMPLATE ); ?>"
				name="<?php echo esc_attr( $option_key . '[' . Overlay_Settings::FIELD_TEMPLATE . ']' ); ?>"
				rows="5"
				class="large-text"
			><?php echo esc_textarea( (string) $s( Overlay_Settings::FIELD_TEMPLATE, '$Product was purchased by $FirstName!' ) ); ?></textarea>

			<textarea
				id="<?php echo esc_attr( $option_key . '_' . Overlay_Settings::FIELD_SECONDARY_TEMPLATE ); ?>"
				name="<?php echo esc_attr( $option_key . '[' . Overlay_Settings::FIELD_SECONDARY_TEMPLATE . ']' ); ?>"
				rows="2"
				class="large-text"
			><?php echo esc_textarea( (string) $s( Overlay_Settings::FIELD_SECONDARY_TEMPLATE, '' ) ); ?></textarea>

			<input
				type="text"
				id="<?php echo esc_attr( $option_key . '_font_family' ); ?>"
				name="<?php echo esc_attr( $option_key . '[font_family]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_FONT_FAMILY, 'inherit' ) ); ?>">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_font_size' ); ?>"
				name="<?php echo esc_attr( $option_key . '[font_size]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_FONT_SIZE, '32' ) ); ?>"
				min="8"
				max="200">

			<select
				id="<?php echo esc_attr( $option_key . '_font_weight' ); ?>"
				name="<?php echo esc_attr( $option_key . '[font_weight]' ); ?>">
				<?php foreach ( [ '400', '500', '600', '700', '800', '900' ] as $w ) : ?>
					<option
						value="<?php echo esc_attr( $w ); ?>"
						<?php selected( (string) $s( Overlay_Settings::FIELD_FONT_WEIGHT, '700' ), $w ); ?>
					><?php echo esc_html( $w ); ?></option>
				<?php endforeach; ?>
			</select>

			<input
				type="color"
				id="<?php echo esc_attr( $option_key . '_text_color' ); ?>"
				name="<?php echo esc_attr( $option_key . '[text_color]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_TEXT_COLOR, '#ffffff' ) ); ?>">

			<input
				type="text"
				id="<?php echo esc_attr( $option_key . '_text_shadow' ); ?>"
				name="<?php echo esc_attr( $option_key . '[text_shadow]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_TEXT_SHADOW, '' ) ); ?>">

			<?php foreach ( [ 'left', 'center', 'right' ] as $align ) : ?>
			<input
				type="radio"
				name="<?php echo esc_attr( $option_key . '[text_align]' ); ?>"
				value="<?php echo esc_attr( $align ); ?>"
				<?php checked( (string) $s( Overlay_Settings::FIELD_TEXT_ALIGN, 'center' ), $align ); ?>>
			<?php endforeach; ?>

			<input
				type="color"
				id="<?php echo esc_attr( $option_key . '_bg_color' ); ?>"
				name="<?php echo esc_attr( $option_key . '[bg_color]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_BG_COLOR, '#000000' ) ); ?>">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_bg_opacity' ); ?>"
				name="<?php echo esc_attr( $option_key . '[bg_opacity]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_BG_OPACITY, '80' ) ); ?>"
				min="0"
				max="100">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_border_radius' ); ?>"
				name="<?php echo esc_attr( $option_key . '[border_radius]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_BORDER_RADIUS, '8' ) ); ?>"
				min="0"
				max="100">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_padding' ); ?>"
				name="<?php echo esc_attr( $option_key . '[padding]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_PADDING, '20' ) ); ?>"
				min="0"
				max="200">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_anim_duration' ); ?>"
				name="<?php echo esc_attr( $option_key . '[anim_duration]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_ANIM_DURATION, '500' ) ); ?>"
				min="0"
				max="5000">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_display_duration' ); ?>"
				name="<?php echo esc_attr( $option_key . '[display_duration]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_DISPLAY_DURATION, '5000' ) ); ?>"
				min="1000"
				max="30000">

			<input
				type="number"
				id="<?php echo esc_attr( $option_key . '_image_size' ); ?>"
				name="<?php echo esc_attr( $option_key . '[image_size]' ); ?>"
				value="<?php echo esc_attr( (string) $s( Overlay_Settings::FIELD_IMAGE_SIZE, '120' ) ); ?>"
				min="32"
				max="512">

		</div>
		<?php
	}

	/**
	 * Renders the 1920×1080 Overlay Designer: toolbar, canvas, and layout hidden inputs.
	 *
	 * The canvas is built and animated by layout-editor.js after page load. The hidden
	 * layout inputs are updated in real time by the JS as elements are dragged or resized,
	 * then included in the next form submit so positions are persisted.
	 *
	 * @param array<string, mixed> $layout      Current layout data from get_layout().
	 * @param array<string, mixed> $refresh_btn Optional Refresh Preview button config. When non-empty,
	 *                                          a "Refresh Preview" button is rendered in the toolbar.
	 *                                          Keys: action (AJAX action), auth_field (POST field name),
	 *                                          auth_value (token), ajax_url (admin-ajax.php URL).
	 * @return void
	 */
	public static function render_designer( array $layout, array $refresh_btn = [] ): void {
		$snap_grid = ! empty( $layout['canvas']['snap_grid'] );
		$grid_size = (int) ( $layout['canvas']['grid_size'] ?? 10 );
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Overlay Designer', 'ccmerch-alert' ); ?></h2>
		<p class="description" style="max-width:860px;margin-bottom:16px;">
			<?php esc_html_e( '1920 × 1080 canvas — drag elements to reposition them. Click any element or the canvas background to edit its properties in the panel on the right.', 'ccmerch-alert' ); ?>
		</p>

		<div class="ccmerch-layout-toolbar">
			<label class="ccmerch-layout-toolbar__snap">
				<input type="checkbox" id="ccmerch-snap-toggle" <?php checked( $snap_grid ); ?>>
				<?php esc_html_e( 'Snap to grid', 'ccmerch-alert' ); ?>
			</label>
			<span
				id="ccmerch-grid-size-wrap"
				class="ccmerch-layout-toolbar__gridsize"
				<?php echo $snap_grid ? '' : 'style="display:none;"'; ?>
			>
				<?php esc_html_e( 'Grid:', 'ccmerch-alert' ); ?>
				<input type="number" id="ccmerch-grid-size" value="<?php echo esc_attr( (string) $grid_size ); ?>" min="5" max="100">
				<?php esc_html_e( 'px', 'ccmerch-alert' ); ?>
			</span>
			<div class="ccmerch-layout-toolbar__spacer"></div>
			<?php if ( ! empty( $refresh_btn ) ) : ?>
			<button
				type="button"
				class="button"
				data-ccmerch-refresh-preview="1"
				data-ccmerch-refresh-action="<?php echo esc_attr( (string) ( $refresh_btn['action'] ?? '' ) ); ?>"
				data-ccmerch-refresh-auth-field="<?php echo esc_attr( (string) ( $refresh_btn['auth_field'] ?? '' ) ); ?>"
				data-ccmerch-refresh-auth-value="<?php echo esc_attr( (string) ( $refresh_btn['auth_value'] ?? '' ) ); ?>"
				data-ccmerch-refresh-ajax-url="<?php echo esc_attr( (string) ( $refresh_btn['ajax_url'] ?? '' ) ); ?>"
			><?php esc_html_e( 'Refresh Preview', 'ccmerch-alert' ); ?></button>
			<?php endif; ?>
		</div>

		<div class="ccmerch-layout-editor" style="margin-bottom:24px;">
			<div class="ccmerch-layout-canvas-col">
				<div id="ccmerch-layout-canvas-wrap" class="ccmerch-layout-canvas-wrap"></div>
			</div>
			<div class="ccmerch-layout-props-col">
				<div class="ccmerch-layout-props">
					<div id="ccmerch-props-empty" class="ccmerch-layout-props__empty">
						<p><?php esc_html_e( 'Click an element to edit its properties, or click the canvas background for Background and Timing settings.', 'ccmerch-alert' ); ?></p>
					</div>
					<div id="ccmerch-props-panel" class="ccmerch-layout-props__panel" style="display:none;"></div>
				</div>
			</div>
		</div>

		<?php Overlay_Settings::render_layout_hidden_inputs( $layout ); ?>
		<?php
	}

	/**
	 * Renders the full Audio section card.
	 *
	 * Includes: Enable Audio checkbox, Default Sound field, and the per-category
	 * sound mapping table.
	 *
	 * Default Sound rendering depends on which parameters are provided:
	 *
	 * When $audio_upload_args is non-empty (upload component context):
	 *   - Default Sound renders Audio_Upload_Component::render() — file upload
	 *     widget with preview, Replace, and Delete buttons. The hidden form field
	 *     is managed by the component and updated via AJAX; the user clicks
	 *     "Save Settings" to persist the attachment_id.
	 *   - $with_media_picker still controls the category map table.
	 *
	 * When $audio_upload_args is empty and $with_media_picker is true (admin):
	 *   - Default Sound uses a hidden input + wp.media() picker button.
	 *   - Each category row uses the same picker pattern.
	 *
	 * When $audio_upload_args is empty and $with_media_picker is false (portal, legacy):
	 *   - Default Sound uses a visible number input for the attachment ID.
	 *   - Each category row uses a number input.
	 *
	 * When $allowed_term_ids is a non-null array (Creator Portal context), the
	 * category map table shows ONLY the categories in that list. Passing null
	 * (admin context) shows all WooCommerce product categories.
	 *
	 * @param array<string, mixed> $values             Current overlay setting values.
	 * @param string               $option_key         Form field name prefix (Overlay_Settings::OPTION_KEY).
	 * @param bool                 $with_media_picker   Whether to render wp.media() picker buttons (admin only).
	 * @param int[]|null           $allowed_term_ids    Term IDs to show in the category map; null = all categories.
	 * @param array<string, mixed> $audio_upload_args   When non-empty, Default Sound uses Audio_Upload_Component.
	 *   - upload_action (string)  AJAX action for upload endpoint.
	 *   - delete_action (string)  AJAX action for delete endpoint.
	 *   - auth_field    (string)  POST field name for auth credential.
	 *   - auth_value    (string)  Auth credential value.
	 *   - nonce_field   (string)  Secondary nonce field name ('_nonce' for portal; '' for admin).
	 *   - nonce_value   (string)  Nonce value.
	 *   - ajax_url      (string)  admin-ajax.php URL.
	 * @return void
	 */
	public static function render_audio_section(
		array  $values,
		string $option_key,
		bool   $with_media_picker = true,
		?array $allowed_term_ids  = null,
		array  $audio_upload_args = []
	): void {
		$audio_enabled    = (string) ( $values[ Overlay_Settings::FIELD_AUDIO_ENABLED ] ?? '0' );
		$default_att      = (int) ( $values[ Overlay_Settings::FIELD_AUDIO_DEFAULT ] ?? 0 );
		$cat_map_json     = (string) ( $values[ Overlay_Settings::FIELD_AUDIO_CAT_MAP ] ?? '{}' );
		$cat_map          = \json_decode( $cat_map_json, true );
		$cat_map          = \is_array( $cat_map ) ? $cat_map : [];

		$default_title    = $default_att > 0 ? (string) get_the_title( $default_att ) : '';
		$enabled_field_id = $option_key . '_' . Overlay_Settings::FIELD_AUDIO_ENABLED;
		$default_field_id = $option_key . '_' . Overlay_Settings::FIELD_AUDIO_DEFAULT;
		$use_component    = ! empty( $audio_upload_args );
		?>
		<div class="ccmerch-card" style="margin-top:24px;">
			<div class="ccmerch-card__header">
				<?php if ( is_admin() ) : ?>
					<span class="dashicons dashicons-format-audio ccmerch-card__icon" aria-hidden="true"></span>
				<?php else : ?>
					<span class="ccmerch-card__icon" aria-hidden="true">🔊</span>
				<?php endif; ?>
				<h2 class="ccmerch-card__title"><?php esc_html_e( 'Audio', 'ccmerch-alert' ); ?></h2>
			</div>
			<div class="ccmerch-card__body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Alert Sound', 'ccmerch-alert' ); ?></th>
						<td>
							<input
								type="checkbox"
								id="<?php echo esc_attr( $enabled_field_id ); ?>"
								name="<?php echo esc_attr( $option_key . '[' . Overlay_Settings::FIELD_AUDIO_ENABLED . ']' ); ?>"
								value="1"
								<?php checked( '1', $audio_enabled ); ?>
							>
							<label for="<?php echo esc_attr( $enabled_field_id ); ?>">
								<?php esc_html_e( 'Play a sound when an alert appears.', 'ccmerch-alert' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Sound', 'ccmerch-alert' ); ?></th>
						<td>
							<?php if ( $use_component ) : ?>
								<?php
								Audio_Upload_Component::render(
									$default_att,
									$option_key . '[' . Overlay_Settings::FIELD_AUDIO_DEFAULT . ']',
									$audio_upload_args
								);
								?>
							<?php elseif ( $with_media_picker ) : ?>
								<input
									type="hidden"
									id="<?php echo esc_attr( $default_field_id ); ?>"
									name="<?php echo esc_attr( $option_key . '[' . Overlay_Settings::FIELD_AUDIO_DEFAULT . ']' ); ?>"
									value="<?php echo esc_attr( (string) $default_att ); ?>"
									class="ccmerch-audio-attachment-id"
								>
								<button
									type="button"
									class="button ccmerch-audio-select-btn"
									data-field="<?php echo esc_attr( $default_field_id ); ?>"
								><?php esc_html_e( 'Select Sound File', 'ccmerch-alert' ); ?></button>
								<span
									class="ccmerch-audio-filename"
									id="<?php echo esc_attr( $default_field_id . '_name' ); ?>"
								><?php echo '' !== $default_title ? esc_html( $default_title ) : esc_html__( 'No file selected', 'ccmerch-alert' ); ?></span>
								<button
									type="button"
									class="button ccmerch-audio-clear-btn"
									data-field="<?php echo esc_attr( $default_field_id ); ?>"
									<?php echo $default_att > 0 ? '' : 'style="display:none;"'; ?>
								><?php esc_html_e( 'Remove', 'ccmerch-alert' ); ?></button>
								<p class="description"><?php esc_html_e( 'MP3 or WAV file from the media library. Used when no category-specific sound matches.', 'ccmerch-alert' ); ?></p>
							<?php else : ?>
								<input
									type="number"
									id="<?php echo esc_attr( $default_field_id ); ?>"
									name="<?php echo esc_attr( $option_key . '[' . Overlay_Settings::FIELD_AUDIO_DEFAULT . ']' ); ?>"
									value="<?php echo esc_attr( (string) $default_att ); ?>"
									min="0"
									style="width:120px;"
								>
								<?php if ( '' !== $default_title ) : ?>
									<span class="ccmerch-audio-filename"><?php echo esc_html( $default_title ); ?></span>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'WordPress Media Library attachment ID of your alert sound file. Ask your administrator for the ID.', 'ccmerch-alert' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Category Sounds', 'ccmerch-alert' ); ?></th>
						<td><?php self::render_audio_category_map( $cat_map, $option_key, $with_media_picker, $allowed_term_ids ); ?></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Browser Source URL card.
	 *
	 * Shared by Overlay_Admin (global overlay) and Creator_Portal_Renderer
	 * (per-creator overlay) so the URL display, Copy button, Open button, and
	 * Generate ID form structure are defined in a single place.
	 *
	 * The Copy button uses the HTTP-safe `onclick="ccmerchCopyToClipboard(this, url)"`
	 * pattern (CLAUDE.md) — callers must load ccmerch-utils.js before this card is
	 * rendered (admin: wp_enqueue_script; portal: <script src>).
	 *
	 * The auth fields inside the Generate ID form differ between admin (wp_nonce_field)
	 * and portal (creator_token + _nonce), so the caller passes them via the
	 * `$generate_form_fields` callable, which is invoked inside the <form> element
	 * before the submit button.
	 *
	 * When $use_ajax_generate is true (Creator Portal), the Generate ID form posts
	 * to admin-ajax.php and carries a data-ccmerch-generate-id attribute so the
	 * delegated listener in ccmerch-utils.js intercepts it, performs the AJAX call,
	 * and updates the URL display in place without a page reload.
	 *
	 * When $use_ajax_generate is false (Admin, default), the form posts to
	 * admin-post.php using the WordPress admin redirect flow.
	 *
	 * @param string   $overlay_url          Current overlay URL (empty string when no ID yet).
	 * @param string   $icon_html            Escaped icon HTML (dashicon span or emoji span).
	 * @param callable $generate_form_fields Callable that outputs hidden fields inside the form.
	 * @param string   $card_style           Inline style on the outer card div.
	 * @param bool     $use_ajax_generate    When true, the form targets admin-ajax.php and the
	 *                                       generate-id flow is handled by ccmerch-utils.js AJAX.
	 * @return void
	 */
	public static function render_browser_source_card(
		string   $overlay_url,
		string   $icon_html,
		callable $generate_form_fields,
		string   $card_style = 'margin-top:20px;',
		bool     $use_ajax_generate = false
	): void {
		$has_id   = '' !== $overlay_url;
		$form_url = $use_ajax_generate
			? esc_url( admin_url( 'admin-ajax.php' ) )
			: esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="ccmerch-card" id="ccmerch-browser-source-card" style="<?php echo esc_attr( $card_style ); ?>">
			<div class="ccmerch-card__header">
				<?php echo $icon_html; // phpcs:ignore -- already escaped by caller ?>
				<h2 class="ccmerch-card__title"><?php esc_html_e( 'Browser Source URL', 'ccmerch-alert' ); ?></h2>
			</div>
			<div class="ccmerch-card__body" style="gap:12px;">
				<div id="ccmerch-overlay-url-section">
				<?php if ( $has_id ) : ?>
				<div class="ccmerch-overlay-url">
					<input
						type="text"
						id="ccmerch-overlay-url-input"
						class="large-text"
						value="<?php echo esc_attr( $overlay_url ); ?>"
						readonly
					>
					<div class="ccmerch-overlay-url__actions">
						<button
							type="button"
							id="ccmerch-overlay-copy-btn"
							class="button"
							data-url="<?php echo esc_attr( $overlay_url ); ?>"
							data-copied="<?php esc_attr_e( '✓ Copied!', 'ccmerch-alert' ); ?>"
							data-copy-fail="<?php esc_attr_e( 'Unable to copy automatically.', 'ccmerch-alert' ); ?>"
							onclick="ccmerchCopyToClipboard( this, this.dataset.url )"
						><?php esc_html_e( 'Copy URL', 'ccmerch-alert' ); ?></button>
						<a
							id="ccmerch-overlay-open-link"
							href="<?php echo esc_url( $overlay_url ); ?>"
							target="_blank"
							rel="noopener"
							class="button"
						><?php esc_html_e( 'Open in Browser', 'ccmerch-alert' ); ?></a>
					</div>
				</div>
				<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No overlay URL exists yet. Generate an overlay ID to get started.', 'ccmerch-alert' ); ?>
				</p>
				<?php endif; ?>
				</div>

				<form
					method="post"
					action="<?php echo $form_url; // phpcs:ignore -- already escaped above ?>"
					style="margin:0;"
					<?php if ( $use_ajax_generate ) : ?>data-ccmerch-generate-id="1"<?php endif; ?>
				>
					<?php ( $generate_form_fields )(); ?>
					<button type="submit" class="button <?php echo $has_id ? 'button-secondary' : 'button-primary'; ?>">
						<?php echo $has_id
							? esc_html__( 'Generate New Overlay ID', 'ccmerch-alert' )
							: esc_html__( 'Generate Overlay ID', 'ccmerch-alert' ); ?>
					</button>
					<?php if ( $has_id ) : ?>
					<span class="description" style="margin-left:8px;">
						<?php esc_html_e( 'Warning: this invalidates the current OBS URL immediately.', 'ccmerch-alert' ); ?>
					</span>
					<?php endif; ?>
				</form>
				<?php if ( $use_ajax_generate ) : ?>
				<div id="ccmerch-generate-id-feedback" role="status" style="display:none;font-size:0.875rem;"></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Resolves preview data for the layout editor canvas.
	 *
	 * Uses the most recent logged order when available so the canvas shows real
	 * product names and images. Falls back to mock data when the log is empty.
	 * No WooCommerce queries are made — data comes from the Order Log only.
	 *
	 * @param  Order_Repository $repository Order log repository.
	 * @return array<string, mixed>
	 */
	public static function resolve_preview_data( Order_Repository $repository ): array {
		$entries = $repository->get_recent( 1 );

		if ( ! empty( $entries ) ) {
			$entry = $entries[0];
			$item  = $entry->items[0] ?? null;

			$image_url = '';
			if ( null !== $item && null !== $item->image_attachment_id ) {
				$src = wp_get_attachment_image_src( $item->image_attachment_id, 'thumbnail' );
				if ( false !== $src ) {
					$image_url = esc_url_raw( $src[0] );
				}
			}

			return [
				'mock'       => false,
				'first_name' => esc_html( $entry->first_name ),
				'product'    => esc_html( null !== $item ? $item->product_name : 'Product' ),
				'quantity'   => null !== $item ? (int) $item->quantity : 1,
				'image_url'  => $image_url,
			];
		}

		return [
			'mock'       => true,
			'first_name' => 'Demo',
			'product'    => 'Test Product',
			'quantity'   => 1,
			'image_url'  => '',
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Renders the per-category alert sound mapping table.
	 *
	 * When $allowed_term_ids is null (admin context), all WooCommerce product_cat
	 * terms are listed. When it is an int[] (Creator Portal context), only terms
	 * whose ID appears in that list are shown — creators see and can configure
	 * audio only for the categories assigned to their own Creator Profile.
	 *
	 * @param array<int, int> $stored_map        Decoded term_id → attachment_id map.
	 * @param string          $option_key        Form field name prefix.
	 * @param bool            $with_media_picker  Whether to render picker buttons or number inputs.
	 * @param int[]|null      $allowed_term_ids   Term IDs to include; null = all categories.
	 * @return void
	 */
	private static function render_audio_category_map(
		array  $stored_map,
		string $option_key,
		bool   $with_media_picker,
		?array $allowed_term_ids = null
	): void {
		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		if ( is_wp_error( $categories ) || ! \is_array( $categories ) || empty( $categories ) ) {
			echo '<p class="description">'
				. esc_html__( 'No WooCommerce product categories found. Categories appear here once WooCommerce is active and product categories exist.', 'ccmerch-alert' )
				. '</p>';
			return;
		}

		// Creator Portal: restrict to the creator's own assigned categories.
		if ( null !== $allowed_term_ids ) {
			$categories = \array_values( \array_filter(
				$categories,
				static fn( \WP_Term $t ) => \in_array( (int) $t->term_id, $allowed_term_ids, true )
			) );

			if ( empty( $categories ) ) {
				echo '<p class="description">'
					. esc_html__( 'No categories are assigned to your creator profile. Contact your administrator to assign a WooCommerce product category.', 'ccmerch-alert' )
					. '</p>';
				return;
			}
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
				$term_id    = (int) $term->term_id;
				$att_id     = (int) ( $stored_map[ $term_id ] ?? 0 );
				$title      = $att_id > 0 ? (string) get_the_title( $att_id ) : '';
				$input_id   = 'ccmerch_audio_cat_' . $term_id;
				$input_name = $option_key . '[' . Overlay_Settings::FIELD_AUDIO_CAT_MAP . '][' . $term_id . ']';
			?>
				<tr>
					<td><?php echo esc_html( $term->name ); ?></td>
					<td>
						<?php if ( $with_media_picker ) : ?>
							<input
								type="hidden"
								id="<?php echo esc_attr( $input_id ); ?>"
								name="<?php echo esc_attr( $input_name ); ?>"
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
						<?php else : ?>
							<input
								type="number"
								id="<?php echo esc_attr( $input_id ); ?>"
								name="<?php echo esc_attr( $input_name ); ?>"
								value="<?php echo esc_attr( (string) $att_id ); ?>"
								min="0"
								style="width:100px;"
							>
							<?php if ( '' !== $title ) : ?>
								<span class="ccmerch-audio-filename"><?php echo esc_html( $title ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Override the default sound for a specific product category. The first matching category wins.', 'ccmerch-alert' ); ?></p>
		<?php
	}
}
