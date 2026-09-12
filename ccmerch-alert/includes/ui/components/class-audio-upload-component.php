<?php
/**
 * Audio Upload shared UI component.
 *
 * @package CCMerchAlert\UI\Components
 */

namespace CCMerchAlert\UI\Components;

use CCMerchAlert\Audio\Audio_Upload_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Audio Upload widget used on the Overlay admin page and the Creator Portal.
 *
 * The widget displays:
 *  - When no audio is uploaded: a "No audio uploaded." notice + Upload button.
 *  - When audio exists: filename, filesize, duration, an HTML5 audio player,
 *    a Replace button, and a Delete button.
 *
 * All AJAX parameters are stored as data-* attributes on the container element.
 * ccmerch-utils.js reads them and handles the upload/delete interactions.
 * No per-page JavaScript is required.
 *
 * The hidden <input> carrying the attachment_id is part of the parent form POST.
 * Changing the file (upload/replace/delete) updates the hidden field immediately
 * via JS; the user must still click "Save Settings" to persist the change to the
 * overlay profile.
 *
 * Must never: register WordPress hooks.
 * Must never: be instantiated — all methods are static.
 * Must never: be rendered without ccmerch-utils.js loaded on the page.
 */
final class Audio_Upload_Component {

	/**
	 * Renders the audio upload widget.
	 *
	 * @param int                  $attachment_id Current audio attachment ID; 0 when no file.
	 * @param string               $field_name    HTML name attribute for the hidden attachment_id input.
	 * @param array<string, mixed> $config        Auth/action configuration:
	 *   - upload_action (string)  AJAX action for the upload endpoint.
	 *   - delete_action (string)  AJAX action for the delete endpoint.
	 *   - auth_field    (string)  POST field name carrying the auth credential.
	 *   - auth_value    (string)  Auth credential value (nonce or creator token).
	 *   - nonce_field   (string)  Secondary POST field name for nonce ('_nonce' for portal; '' for admin).
	 *   - nonce_value   (string)  Nonce value (portal nonce or '' for admin).
	 *   - ajax_url      (string)  admin-ajax.php URL.
	 * @return void
	 */
	public static function render(
		int    $attachment_id,
		string $field_name,
		array  $config
	): void {
		$has_audio = $attachment_id > 0;

		// Resolve metadata from the stored attachment.
		$filename           = '';
		$filesize_formatted = '';
		$duration_formatted = '';
		$file_url           = '';
		$mime_type          = 'audio/mpeg';

		if ( $has_audio ) {
			$file_path = (string) get_attached_file( $attachment_id );
			$att_meta  = wp_get_attachment_metadata( $attachment_id );

			$filename = \basename( $file_path );

			if ( isset( $att_meta['filesize'] ) ) {
				$filesize_formatted = size_format( (int) $att_meta['filesize'] );
			}

			if ( isset( $att_meta['length_formatted'] ) ) {
				$duration_formatted = (string) $att_meta['length_formatted'];
			}

			$url = wp_get_attachment_url( $attachment_id );
			if ( false !== $url ) {
				$file_url = $url;
			}

			$mime = get_post_mime_type( $attachment_id );
			if ( false !== $mime ) {
				$mime_type = $mime;
			}

			// Treat a missing file as no-audio gracefully.
			if ( '' === $file_url ) {
				$has_audio = false;
			}
		}

		// Build a combined meta string e.g. "1.2 MB · 0:28".
		$meta_parts = \array_filter( [ $filesize_formatted, $duration_formatted ] );
		$meta_str   = \implode( ' · ', $meta_parts );

		// Config values with defaults.
		$upload_action = (string) ( $config['upload_action'] ?? '' );
		$delete_action = (string) ( $config['delete_action'] ?? '' );
		$auth_field    = (string) ( $config['auth_field']    ?? '' );
		$auth_value    = (string) ( $config['auth_value']    ?? '' );
		$nonce_field   = (string) ( $config['nonce_field']   ?? '' );
		$nonce_value   = (string) ( $config['nonce_value']   ?? '' );
		$ajax_url      = (string) ( $config['ajax_url']      ?? admin_url( 'admin-ajax.php' ) );

		$upload_label  = __( 'Upload Audio', 'ccmerch-alert' );
		$replace_label = __( 'Replace Audio', 'ccmerch-alert' );
		$delete_label  = __( 'Delete Audio', 'ccmerch-alert' );
		?>
		<div
			class="ccmerch-audio-upload"
			data-ccmerch-audio-container
			data-ccmerch-audio-field="<?php echo esc_attr( $field_name ); ?>"
			data-ccmerch-upload-action="<?php echo esc_attr( $upload_action ); ?>"
			data-ccmerch-delete-action="<?php echo esc_attr( $delete_action ); ?>"
			data-ccmerch-audio-auth-field="<?php echo esc_attr( $auth_field ); ?>"
			data-ccmerch-audio-auth-value="<?php echo esc_attr( $auth_value ); ?>"
			data-ccmerch-audio-nonce-field="<?php echo esc_attr( $nonce_field ); ?>"
			data-ccmerch-audio-nonce-value="<?php echo esc_attr( $nonce_value ); ?>"
			data-ccmerch-audio-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
		>
			<!-- Hidden form field — persisted when parent "Save Settings" form is submitted. -->
			<input
				type="hidden"
				name="<?php echo esc_attr( $field_name ); ?>"
				value="<?php echo esc_attr( (string) $attachment_id ); ?>"
				data-ccmerch-audio-att-id
			>

			<!-- No-audio state -->
			<div
				class="ccmerch-audio-upload__empty"
				data-ccmerch-audio-no-audio
				<?php echo $has_audio ? 'style="display:none;"' : ''; ?>
			>
				<p class="description"><?php esc_html_e( 'No audio uploaded.', 'ccmerch-alert' ); ?></p>
			</div>

			<!-- Has-audio state: metadata + HTML5 player -->
			<div
				class="ccmerch-audio-upload__info"
				data-ccmerch-audio-has-audio
				<?php echo ! $has_audio ? 'style="display:none;"' : ''; ?>
			>
				<p class="ccmerch-audio-upload__filename" data-ccmerch-audio-filename><?php echo esc_html( $filename ); ?></p>
				<?php if ( '' !== $meta_str ) : ?>
				<p class="ccmerch-audio-upload__meta" data-ccmerch-audio-filemeta><?php echo esc_html( $meta_str ); ?></p>
				<?php else : ?>
				<p class="ccmerch-audio-upload__meta" data-ccmerch-audio-filemeta></p>
				<?php endif; ?>
				<audio
					class="ccmerch-audio-upload__player"
					controls
					preload="metadata"
					data-ccmerch-audio-player
					<?php echo ! $has_audio ? 'style="display:none;"' : ''; ?>
				>
					<?php if ( $has_audio ) : ?>
					<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $mime_type ); ?>">
					<?php else : ?>
					<source src="" type="audio/mpeg">
					<?php endif; ?>
				</audio>
			</div>

			<!-- Action buttons -->
			<div class="ccmerch-audio-upload__actions">
				<button
					type="button"
					class="button button-secondary"
					data-ccmerch-audio-upload
					data-label-upload="<?php echo esc_attr( $upload_label ); ?>"
					data-label-replace="<?php echo esc_attr( $replace_label ); ?>"
				><?php echo esc_html( $has_audio ? $replace_label : $upload_label ); ?></button>
				<input
					type="file"
					accept="audio/mpeg,audio/wav,audio/ogg,.mp3,.m4a,.wav,.ogg,.oga"
					style="display:none;"
					data-ccmerch-audio-file-input
				>
				<button
					type="button"
					class="button"
					data-ccmerch-audio-delete
					<?php echo ! $has_audio ? 'style="display:none;"' : ''; ?>
				><?php echo esc_html( $delete_label ); ?></button>
			</div>

			<!-- Status / feedback area -->
			<div
				class="ccmerch-audio-upload__status"
				aria-live="polite"
				style="display:none;"
				data-ccmerch-audio-status
			></div>
		</div>
		<?php
	}
}
