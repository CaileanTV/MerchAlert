<?php
/**
 * Audio upload controller.
 *
 * @package CCMerchAlert\Audio
 */

namespace CCMerchAlert\Audio;

use CCMerchAlert\Auth\Auth_Context;
use CCMerchAlert\Creators\Creator_Service;
use CCMerchAlert\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles audio file upload and delete AJAX endpoints.
 *
 * Provides two endpoint pairs:
 *  - Creator portal: authenticated via creator_access_token (Auth_Context::resolve_creator).
 *    Registered for BOTH wp_ajax_ and wp_ajax_nopriv_ so the handler fires
 *    regardless of whether the visitor is a logged-in WordPress user. An admin
 *    can visit the creator portal while logged in; the wp_ajax_ (non-nopriv)
 *    variant ensures their upload still routes to the correct handler.
 *  - Admin: authenticated via manage_options + NONCE_ADMIN (Auth_Context::resolve_admin).
 *    Registered as wp_ajax_ only — admin operations require a WP login.
 *
 * Upload validation order:
 *  1. File presence and PHP upload error check.
 *  2. MIME type + extension via wp_check_filetype_and_ext().
 *  3. File size against Settings::FIELD_MAX_AUDIO_SIZE.
 *  4. wp_handle_upload() — moves file to WordPress uploads directory.
 *  5. wp_read_audio_metadata() — reads duration and audio properties.
 *  6. Duration against Settings::FIELD_MAX_AUDIO_DURATION.
 *     On failure: unlink() the moved file before returning WP_Error.
 *     This ensures no orphaned files and no orphaned DB records.
 *  7. wp_insert_attachment() — creates the media library post.
 *  8. Attachment metadata built from the already-read audio analysis and stored
 *     via wp_update_attachment_metadata(). We intentionally skip the second
 *     wp_generate_attachment_metadata() call because it re-runs getid3 and
 *     attempts to extract embedded album art via GD/Imagick, which can exhaust
 *     the PHP memory limit and kill the request (HTTP 502).
 *  9. Ownership post_meta on attachment.
 * 10. Delete old attachment (owner-verified for creator; unconditional for admin).
 *
 * File ownership is stored as post_meta on every attachment this controller creates.
 * The creator delete endpoint verifies META_CREATOR_ID matches the authenticated
 * creator before calling wp_delete_attachment(). A mismatch is logged and rejected —
 * one creator cannot delete another creator's files.
 *
 * Must never: render HTML. Must never: contain routing logic.
 * Must never: be called from the overlay poll path.
 */
final class Audio_Upload_Controller {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * Allowed file extension → MIME type pairs.
	 * Passed to wp_check_filetype_and_ext() and wp_handle_upload().
	 *
	 * Multi-extension keys (e.g. 'mp3|m4a|m4b') match WordPress's own mime type
	 * map so that WP's upload security layer accepts these types.
	 */
	private const ALLOWED_MIMES = [
		'mp3|m4a|m4b' => 'audio/mpeg',
		'wav'          => 'audio/wav',
		'ogg|oga'      => 'audio/ogg',
	];

	/**
	 * post_meta key — stores the creator_id on every uploaded attachment.
	 * Verified during delete to prevent cross-creator file access.
	 */
	public const META_CREATOR_ID = '_ccmerch_audio_creator_id';

	/**
	 * post_meta key — stores the overlay_id at upload time (informational).
	 */
	public const META_OVERLAY_ID = '_ccmerch_audio_overlay_id';

	/**
	 * post_meta key — Unix timestamp of when the file was uploaded.
	 */
	public const META_UPLOAD_TS = '_ccmerch_audio_upload_timestamp';

	/**
	 * AJAX action: creator portal audio upload.
	 * Registered for BOTH wp_ajax_ and wp_ajax_nopriv_ — see class docblock.
	 */
	public const AJAX_CREATOR_UPLOAD = 'ccmerch_creator_audio_upload';

	/**
	 * AJAX action: creator portal audio delete.
	 * Registered for BOTH wp_ajax_ and wp_ajax_nopriv_ — see class docblock.
	 */
	public const AJAX_CREATOR_DELETE = 'ccmerch_creator_audio_delete';

	/**
	 * AJAX action: admin audio upload (logged-in, manage_options).
	 */
	public const AJAX_ADMIN_UPLOAD = 'ccmerch_admin_audio_upload';

	/**
	 * AJAX action: admin audio delete (logged-in, manage_options).
	 */
	public const AJAX_ADMIN_DELETE = 'ccmerch_admin_audio_delete';

	/**
	 * Nonce action for both admin endpoints.
	 */
	public const NONCE_ADMIN = 'ccmerch_admin_audio';

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * @param Settings        $settings        Plugin settings (audio size/duration limits).
	 * @param Creator_Service $creator_service Creator lookup for token authentication.
	 */
	public function __construct(
		private readonly Settings        $settings,
		private readonly Creator_Service $creator_service,
	) {
		// Creator endpoints: register for BOTH logged-in and non-logged-in users.
		// wp_ajax_nopriv_* fires when the creator visits without a WP session.
		// wp_ajax_* fires when a logged-in admin visits the creator portal to test.
		// Auth is always via creator_access_token — WP session state is irrelevant.
		add_action( 'wp_ajax_nopriv_' . self::AJAX_CREATOR_UPLOAD, [ $this, 'handle_creator_upload' ] );
		add_action( 'wp_ajax_'        . self::AJAX_CREATOR_UPLOAD, [ $this, 'handle_creator_upload' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_CREATOR_DELETE, [ $this, 'handle_creator_delete' ] );
		add_action( 'wp_ajax_'        . self::AJAX_CREATOR_DELETE, [ $this, 'handle_creator_delete' ] );

		// Admin endpoints: logged-in only.
		add_action( 'wp_ajax_' . self::AJAX_ADMIN_UPLOAD, [ $this, 'handle_admin_upload' ] );
		add_action( 'wp_ajax_' . self::AJAX_ADMIN_DELETE, [ $this, 'handle_admin_delete' ] );
	}

	// -------------------------------------------------------------------------
	// Creator AJAX handlers (nopriv + logged-in)
	// -------------------------------------------------------------------------

	/**
	 * Handles an audio file upload from the Creator Portal.
	 *
	 * Auth: creator_access_token via Auth_Context::resolve_creator().
	 * WordPress nonces are NOT verified — see Auth_Context for rationale.
	 *
	 * @return void
	 */
	public function handle_creator_upload(): void {
		try {
			$ctx        = Auth_Context::resolve_creator( $this->creator_service );
			$old_att_id = (int) ( $_POST['old_attachment_id'] ?? 0 ); // phpcs:ignore

			$result = $this->process_upload( $ctx->creator_id, $ctx->overlay_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			// Delete old attachment only after the new one is successfully inserted.
			if ( $old_att_id > 0 ) {
				$this->delete_if_owner( $old_att_id, $ctx->creator_id );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			$this->log_error( 'handle_creator_upload', $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'ccmerch-alert' ) ] );
		}
	}

	/**
	 * Handles an audio file delete from the Creator Portal.
	 *
	 * Auth: creator_access_token via Auth_Context::resolve_creator().
	 * Verifies META_CREATOR_ID matches the authenticated creator before deleting.
	 *
	 * @return void
	 */
	public function handle_creator_delete(): void {
		try {
			$ctx    = Auth_Context::resolve_creator( $this->creator_service );
			$att_id = (int) ( $_POST['attachment_id'] ?? 0 ); // phpcs:ignore

			if ( $att_id <= 0 ) {
				wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'ccmerch-alert' ) ] );
			}

			if ( ! $this->delete_if_owner( $att_id, $ctx->creator_id ) ) {
				wp_send_json_error( [ 'message' => __( 'You do not have permission to delete this file.', 'ccmerch-alert' ) ] );
			}

			wp_send_json_success( [ 'message' => __( 'Audio deleted.', 'ccmerch-alert' ) ] );
		} catch ( \Throwable $e ) {
			$this->log_error( 'handle_creator_delete', $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'ccmerch-alert' ) ] );
		}
	}

	// -------------------------------------------------------------------------
	// Admin AJAX handlers (logged-in)
	// -------------------------------------------------------------------------

	/**
	 * Handles an audio file upload from the admin Overlay page.
	 *
	 * Auth: manage_options + NONCE_ADMIN via Auth_Context::resolve_admin().
	 *
	 * @return void
	 */
	public function handle_admin_upload(): void {
		try {
			Auth_Context::resolve_admin( self::NONCE_ADMIN );

			$old_att_id = (int) ( $_POST['old_attachment_id'] ?? 0 ); // phpcs:ignore
			$result     = $this->process_upload( 0, '' );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			if ( $old_att_id > 0 ) {
				wp_delete_attachment( $old_att_id, true );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			$this->log_error( 'handle_admin_upload', $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'ccmerch-alert' ) ] );
		}
	}

	/**
	 * Handles an audio file delete from the admin Overlay page.
	 *
	 * Auth: manage_options + NONCE_ADMIN via Auth_Context::resolve_admin().
	 * No ownership check for admin — any attachment can be deleted.
	 *
	 * @return void
	 */
	public function handle_admin_delete(): void {
		try {
			Auth_Context::resolve_admin( self::NONCE_ADMIN );

			$att_id = (int) ( $_POST['attachment_id'] ?? 0 ); // phpcs:ignore

			if ( $att_id <= 0 ) {
				wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'ccmerch-alert' ) ] );
			}

			wp_delete_attachment( $att_id, true );
			wp_send_json_success( [ 'message' => __( 'Audio deleted.', 'ccmerch-alert' ) ] );
		} catch ( \Throwable $e ) {
			$this->log_error( 'handle_admin_delete', $e->getMessage() );
			wp_send_json_error( [ 'message' => __( 'An unexpected error occurred. Please try again.', 'ccmerch-alert' ) ] );
		}
	}

	// -------------------------------------------------------------------------
	// Shared upload logic
	// -------------------------------------------------------------------------

	/**
	 * Validates and processes a multipart file upload from $_FILES.
	 *
	 * Returns a data array on success or WP_Error on any validation/upload failure.
	 * On duration validation failure, the moved file is unlinked before returning
	 * WP_Error — no orphaned files, no orphaned DB records.
	 *
	 * Intentionally skips wp_generate_attachment_metadata() to avoid a second
	 * getid3 pass and the memory-intensive embedded album art extraction (GD/Imagick
	 * processing of album art embedded in MP3 files can exhaust the PHP memory limit
	 * and kill the request with HTTP 502). Metadata is built from the already-read
	 * $audio_meta array and stored directly via wp_update_attachment_metadata().
	 *
	 * @param  int    $creator_id Creator ID for ownership meta (0 for admin context).
	 * @param  string $overlay_id Overlay ID for ownership meta (empty for admin context).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function process_upload( int $creator_id, string $overlay_id ): array|\WP_Error {
		// WordPress admin includes are not loaded on AJAX requests by default.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Verify a file was received with no PHP upload error.
		if (
			empty( $_FILES['ccmerch_audio_file'] ) // phpcs:ignore
			|| UPLOAD_ERR_OK !== (int) $_FILES['ccmerch_audio_file']['error'] // phpcs:ignore
		) {
			return new \WP_Error(
				'no_file',
				__( 'No file was received. Please try again.', 'ccmerch-alert' )
			);
		}

		$file = $_FILES['ccmerch_audio_file']; // phpcs:ignore

		// Validate MIME type and extension before moving the file.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::ALLOWED_MIMES );

		if ( ! $check['type'] || ! $check['ext'] ) {
			return new \WP_Error(
				'invalid_mime',
				__( 'The selected file type is not allowed. Please upload an MP3, WAV, or OGG file.', 'ccmerch-alert' )
			);
		}

		// Validate file size against the admin-configured maximum.
		$max_bytes = (int) $this->settings->get( Settings::FIELD_MAX_AUDIO_SIZE );

		if ( $max_bytes > 0 && $file['size'] > $max_bytes ) {
			return new \WP_Error(
				'file_too_large',
				\sprintf(
					/* translators: %s: maximum file size formatted as "10 MB". */
					__( 'The selected file exceeds the maximum upload size of %s.', 'ccmerch-alert' ),
					size_format( $max_bytes )
				)
			);
		}

		// Move file to the WordPress uploads directory.
		$moved = wp_handle_upload(
			$file,
			[
				'test_form' => false,
				'mimes'     => self::ALLOWED_MIMES,
			]
		);

		if ( isset( $moved['error'] ) ) {
			return new \WP_Error( 'upload_failed', (string) $moved['error'] );
		}

		// Raise memory limit before reading audio metadata. getid3 can be
		// memory-intensive, especially for large or unusual audio files.
		wp_raise_memory_limit( 'admin' );

		// Read audio metadata — only possible after the file is at its final path.
		// wp_read_audio_metadata() returns false when the file cannot be analysed
		// (e.g. corrupted file, unsupported sub-format). Treat that as empty metadata
		// and proceed — we fail open so valid files are not blocked by a getid3 quirk.
		$raw_meta   = wp_read_audio_metadata( $moved['file'] );
		$audio_meta = ( \is_array( $raw_meta ) && ! empty( $raw_meta ) ) ? $raw_meta : [];

		if ( empty( $audio_meta ) ) {
			$this->log_error( 'process_upload', "wp_read_audio_metadata returned no data for {$moved['file']}" );
		}

		$duration_seconds = isset( $audio_meta['length'] ) ? (int) $audio_meta['length'] : 0;
		$max_duration     = (int) $this->settings->get( Settings::FIELD_MAX_AUDIO_DURATION );

		if ( $max_duration > 0 && $duration_seconds > $max_duration ) {
			// Validation failed — unlink the moved file so no orphaned file remains.
			\unlink( $moved['file'] );
			return new \WP_Error(
				'duration_exceeded',
				\sprintf(
					/* translators: %d: maximum duration in seconds. */
					__( 'The selected audio clip exceeds the maximum duration of %d seconds.', 'ccmerch-alert' ),
					$max_duration
				)
			);
		}

		// Insert into the WordPress media library.
		$upload_dir = wp_upload_dir();
		$attachment  = [
			'guid'           => $upload_dir['url'] . '/' . \basename( $moved['file'] ),
			'post_mime_type' => $moved['type'],
			'post_title'     => \preg_replace( '/\.[^.]+$/', '', \basename( $moved['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$att_id = wp_insert_attachment( $attachment, $moved['file'] );

		if ( is_wp_error( $att_id ) ) {
			\unlink( $moved['file'] );
			return $att_id;
		}

		// Build attachment metadata from the audio analysis already performed above.
		//
		// WHY we skip wp_generate_attachment_metadata():
		//   That function calls wp_read_audio_metadata() a second time (redundant
		//   getid3 pass) and then attempts to extract embedded album art from the
		//   audio file. The art extraction uses GD or Imagick to resize the embedded
		//   JPEG/PNG. Many MP3 files carry high-resolution cover art. On servers with
		//   a 64 MB or 128 MB PHP memory limit this image processing exhausts the limit
		//   and the OS kills the PHP process — the web server receives no response and
		//   returns HTTP 502 Bad Gateway.
		//
		//   Alert sounds do not need embedded thumbnails. We get identical usable
		//   metadata by copying the fields from our already-read $audio_meta. The
		//   wp_generate_attachment_metadata filter is still applied so CDN, transcoding,
		//   and other compatible plugins can act on the data.
		$att_meta = [
			'filesize'  => (int) ( $audio_meta['filesize'] ?? $file['size'] ),
			'mime_type' => $moved['type'],
		];
		foreach ( [ 'dataformat', 'codec', 'channels', 'sample_rate', 'bitrate',
					'bitrate_mode', 'lossless', 'channelmode', 'length', 'length_formatted' ] as $audio_key ) {
			if ( isset( $audio_meta[ $audio_key ] ) ) {
				$att_meta[ $audio_key ] = $audio_meta[ $audio_key ];
			}
		}

		// Allow plugins to extend or transform the metadata.
		$att_meta = apply_filters( 'wp_generate_attachment_metadata', $att_meta, $att_id, 'create' );
		wp_update_attachment_metadata( $att_id, $att_meta );

		// Store ownership metadata for delete permission verification.
		if ( $creator_id > 0 ) {
			update_post_meta( $att_id, self::META_CREATOR_ID, $creator_id );
		}
		if ( '' !== $overlay_id ) {
			update_post_meta( $att_id, self::META_OVERLAY_ID, $overlay_id );
		}
		update_post_meta( $att_id, self::META_UPLOAD_TS, \time() );

		$duration_formatted = (string) ( $audio_meta['length_formatted'] ?? '' );
		$file_url           = (string) wp_get_attachment_url( $att_id );

		return [
			'attachment_id'      => $att_id,
			'filename'           => \basename( $moved['file'] ),
			'filesize'           => size_format( $file['size'] ),
			'duration_formatted' => $duration_formatted,
			'url'                => $file_url,
			'mime_type'          => $moved['type'],
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Deletes an attachment only when its META_CREATOR_ID matches $creator_id.
	 *
	 * Returns true on successful delete, false when ownership does not match.
	 *
	 * @param  int $att_id     Attachment post ID to delete.
	 * @param  int $creator_id Expected creator ID.
	 * @return bool
	 */
	private function delete_if_owner( int $att_id, int $creator_id ): bool {
		$stored = (int) get_post_meta( $att_id, self::META_CREATOR_ID, true );

		if ( $stored !== $creator_id ) {
			$this->log_error(
				'delete_if_owner',
				"ownership mismatch: att_id={$att_id}, expected_creator={$creator_id}, stored_creator={$stored}"
			);
			return false;
		}

		wp_delete_attachment( $att_id, true );
		return true;
	}

	/**
	 * Writes a debug message to the PHP error log when WP_DEBUG_LOG is enabled.
	 *
	 * @param string $context Short identifier for the calling method.
	 * @param string $message Error description.
	 * @return void
	 */
	private function log_error( string $context, string $message ): void {
		if ( \defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( "[Audio_Upload_Controller::{$context}] {$message}" ); // phpcs:ignore
		}
	}
}
