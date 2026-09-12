<?php
/**
 * Changelog Manager admin page.
 *
 * @package CCMerchAlert\Admin
 * @feature release_manager
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Releases\Feature_Tag_Registry;
use CCMerchAlert\Releases\Release;
use CCMerchAlert\Releases\Release_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the CCMerch Alert → Changelog admin submenu page.
 *
 * Three views dispatch from a single render_page() callback via ?action=:
 *   (none)   → release list table with Edit / Delete actions
 *   create   → blank create form
 *   edit     → pre-filled edit form (requires ?id=N)
 *
 * All mutations use admin_post_ + Post/Redirect/Get. Nonces:
 *   save   → ccmerch_release_save          (shared for create and update)
 *   delete → ccmerch_release_delete_{id}   (per-release prevents cross-deletion)
 *
 * Feature tags must come from Feature_Tag_Registry::TAGS. Free-text tags
 * submitted in POST are silently stripped by filter_valid() before persistence.
 *
 * Must never: enqueue assets on pages other than its own.
 * Must never: query the database directly — use Release_Repository.
 * Must never: duplicate validation logic from handle_save() in render_form().
 */
final class Changelog_Admin {

	public const PAGE          = 'ccmerch-alert-changelog';
	public const ACTION_SAVE   = 'ccmerch_release_save';
	public const ACTION_DELETE = 'ccmerch_release_delete';
	public const NONCE_SAVE    = 'ccmerch_release_save';

	private const VALID_STAGES = [ 'alpha', 'beta', 'production' ];

	public function __construct( private readonly Release_Repository $repository ) {
		add_action( 'admin_menu',                           [ $this, 'register_menu'  ] );
		add_action( 'admin_post_' . self::ACTION_SAVE,   [ $this, 'handle_save'   ] );
		add_action( 'admin_post_' . self::ACTION_DELETE, [ $this, 'handle_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'ccmerch-alert',
			__( 'Changelog Manager', 'ccmerch-alert' ),
			__( 'Changelog', 'ccmerch-alert' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	// ── Mutation handlers ──────────────────────────────────────────────────────

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ccmerch-alert' ) );
		}

		check_admin_referer( self::NONCE_SAVE, '_ccmerch_nonce' );

		$id      = (int) ( $_POST['release_id'] ?? 0 );
		$version = sanitize_text_field( $_POST['version']     ?? '' );
		$stage   = sanitize_key(        $_POST['stage']       ?? '' );
		$title   = sanitize_text_field( $_POST['title']       ?? '' );
		$desc    = sanitize_textarea_field( $_POST['description'] ?? '' );

		$raw_tags = ( isset( $_POST['feature_tags'] ) && \is_array( $_POST['feature_tags'] ) )
			? \array_map( 'sanitize_key', (array) $_POST['feature_tags'] )
			: [];
		$tags = Feature_Tag_Registry::filter_valid( $raw_tags );

		// Validate.
		if ( ! \preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			$this->redirect_with_error( $id, 'invalid_version' );
			return;
		}
		if ( ! \in_array( $stage, self::VALID_STAGES, true ) ) {
			$this->redirect_with_error( $id, 'invalid_stage' );
			return;
		}
		if ( '' === $title ) {
			$this->redirect_with_error( $id, 'missing_title' );
			return;
		}

		$data = compact( 'version', 'stage', 'title', 'desc', 'tags' );
		$data = [
			'version'      => $version,
			'stage'        => $stage,
			'title'        => $title,
			'description'  => $desc,
			'feature_tags' => $tags,
		];

		if ( $id > 0 ) {
			$ok = $this->repository->update( $id, $data );
		} else {
			$ok = false !== $this->repository->create( $data );
		}

		$notice = $ok ? 'release_saved' : 'db_fail';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ccmerch_notice=' . $notice ) );
		exit;
	}

	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ccmerch-alert' ) );
		}

		$id = (int) ( $_POST['release_id'] ?? 0 );
		check_admin_referer( 'ccmerch_release_delete_' . $id, '_ccmerch_nonce' );

		if ( $id > 0 ) {
			$this->repository->delete( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&ccmerch_notice=release_deleted' ) );
		exit;
	}

	// ── Page renderer ──────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ccmerch-alert' ) );
		}

		$action = sanitize_key( $_GET['action']          ?? '' );
		$id     = (int)         ( $_GET['id']            ?? 0 );
		$notice = sanitize_key( $_GET['ccmerch_notice']  ?? '' );
		$error  = sanitize_key( $_GET['ccmerch_error']   ?? '' );

		echo '<div class="wrap">';
		$this->render_notices( $notice, $error );

		if ( 'create' === $action ) {
			$this->render_form( null );
		} elseif ( 'edit' === $action && $id > 0 ) {
			$release = $this->repository->get_by_id( $id );
			if ( null === $release ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Release not found.', 'ccmerch-alert' ) . '</p></div>';
				$this->render_list();
			} else {
				$this->render_form( $release );
			}
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	// ── Private renderers ──────────────────────────────────────────────────────

	private function render_notices( string $notice, string $error ): void {
		$success_map = [
			'release_saved'   => __( 'Release saved.', 'ccmerch-alert' ),
			'release_deleted' => __( 'Release deleted.', 'ccmerch-alert' ),
		];
		$error_map = [
			'invalid_version' => __( 'Version must be in X.Y.Z format (e.g. 1.0.0).', 'ccmerch-alert' ),
			'invalid_stage'   => __( 'Stage must be Alpha, Beta, or Production.', 'ccmerch-alert' ),
			'missing_title'   => __( 'Title is required.', 'ccmerch-alert' ),
			'db_fail'         => __( 'Database error. Please try again.', 'ccmerch-alert' ),
		];

		if ( isset( $success_map[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success_map[ $notice ] ) . '</p></div>';
		}
		if ( isset( $error_map[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error_map[ $error ] ) . '</p></div>';
		}
	}

	private function render_list(): void {
		$releases   = $this->repository->get_all();
		$create_url = admin_url( 'admin.php?page=' . self::PAGE . '&action=create' );
		$post_url   = admin_url( 'admin-post.php' );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Changelog Manager', 'ccmerch-alert' ); ?></h1>
		<a href="<?php echo esc_url( $create_url ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add Release', 'ccmerch-alert' ); ?>
		</a>
		<hr class="wp-header-end">

		<?php if ( empty( $releases ) ) : ?>
		<p><?php esc_html_e( 'No releases yet. Click "Add Release" to create the first one.', 'ccmerch-alert' ); ?></p>
		<?php else : ?>
		<table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
			<thead>
				<tr>
					<th style="width:50px;"><?php esc_html_e( 'ID', 'ccmerch-alert' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Version', 'ccmerch-alert' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Stage', 'ccmerch-alert' ); ?></th>
					<th><?php esc_html_e( 'Title', 'ccmerch-alert' ); ?></th>
					<th><?php esc_html_e( 'Feature Tags', 'ccmerch-alert' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Date', 'ccmerch-alert' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Actions', 'ccmerch-alert' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $releases as $release ) :
					$edit_url = admin_url( 'admin.php?page=' . self::PAGE . '&action=edit&id=' . $release->id );
					$labels   = \array_map( [ Feature_Tag_Registry::class, 'label' ], $release->feature_tags );
				?>
				<tr>
					<td><?php echo esc_html( (string) $release->id ); ?></td>
					<td><strong><?php echo esc_html( $release->version ); ?></strong></td>
					<td><?php echo esc_html( $release->stage_label() ); ?></td>
					<td><?php echo esc_html( $release->title ); ?></td>
					<td><?php echo esc_html( \implode( ', ', $labels ) ); ?></td>
					<td><?php echo esc_html( $release->created_at->format( 'Y-m-d' ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'ccmerch-alert' ); ?></a>
						&nbsp;|&nbsp;
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
							<input type="hidden" name="action"     value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
							<input type="hidden" name="release_id" value="<?php echo esc_attr( (string) $release->id ); ?>">
							<?php wp_nonce_field( 'ccmerch_release_delete_' . $release->id, '_ccmerch_nonce' ); ?>
							<button
								type="submit"
								class="button-link"
								style="color:#b32d2e;"
								onclick="return confirm( '<?php echo esc_js( __( 'Delete this release? This cannot be undone.', 'ccmerch-alert' ) ); ?>' )"
							><?php esc_html_e( 'Delete', 'ccmerch-alert' ); ?></button>
						</form>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif;
	}

	private function render_form( ?Release $release ): void {
		$is_edit    = null !== $release;
		$page_title = $is_edit ? __( 'Edit Release', 'ccmerch-alert' ) : __( 'Add Release', 'ccmerch-alert' );
		$btn_label  = $is_edit ? __( 'Update Release', 'ccmerch-alert' ) : __( 'Publish Release', 'ccmerch-alert' );
		$back_url   = admin_url( 'admin.php?page=' . self::PAGE );

		$v_version = $is_edit ? $release->version     : '';
		$v_stage   = $is_edit ? $release->stage       : 'beta';
		$v_title   = $is_edit ? $release->title       : '';
		$v_desc    = $is_edit ? $release->description : '';
		$v_tags    = $is_edit ? $release->feature_tags : [];
		?>
		<h1><?php echo esc_html( $page_title ); ?></h1>
		<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Releases', 'ccmerch-alert' ); ?></a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action"     value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
			<input type="hidden" name="release_id" value="<?php echo esc_attr( $is_edit ? (string) $release->id : '0' ); ?>">
			<?php wp_nonce_field( self::NONCE_SAVE, '_ccmerch_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="ccmerch-r-version"><?php esc_html_e( 'Version', 'ccmerch-alert' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="ccmerch-r-version"
							name="version"
							value="<?php echo esc_attr( $v_version ); ?>"
							class="regular-text"
							placeholder="1.0.0"
							required
						>
						<p class="description"><?php esc_html_e( 'Semantic version in X.Y.Z format (e.g. 1.0.0).', 'ccmerch-alert' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ccmerch-r-stage"><?php esc_html_e( 'Stage', 'ccmerch-alert' ); ?></label>
					</th>
					<td>
						<select id="ccmerch-r-stage" name="stage">
							<?php foreach ( self::VALID_STAGES as $slug ) : ?>
							<option
								value="<?php echo esc_attr( $slug ); ?>"
								<?php selected( $v_stage, $slug ); ?>
							><?php echo esc_html( \ucfirst( $slug ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ccmerch-r-title"><?php esc_html_e( 'Title', 'ccmerch-alert' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="ccmerch-r-title"
							name="title"
							value="<?php echo esc_attr( $v_title ); ?>"
							class="large-text"
							required
						>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ccmerch-r-desc"><?php esc_html_e( 'Description', 'ccmerch-alert' ); ?></label>
					</th>
					<td>
						<textarea
							id="ccmerch-r-desc"
							name="description"
							rows="4"
							class="large-text"
						><?php echo esc_textarea( $v_desc ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feature Tags', 'ccmerch-alert' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Feature Tags', 'ccmerch-alert' ); ?></legend>
							<ul style="margin:0;columns:2;list-style:none;padding:0;">
								<?php foreach ( Feature_Tag_Registry::TAGS as $slug => $label ) : ?>
								<li style="margin-bottom:4px;">
									<label>
										<input
											type="checkbox"
											name="feature_tags[]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( \in_array( $slug, $v_tags, true ) ); ?>
										>
										<?php echo esc_html( $label ); ?>
									</label>
								</li>
								<?php endforeach; ?>
							</ul>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Only tags from the Feature Tag Registry are accepted.', 'ccmerch-alert' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( $btn_label ); ?>
		</form>
		<?php
	}

	private function redirect_with_error( int $id, string $code ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		$base .= $id > 0 ? '&action=edit&id=' . $id : '&action=create';
		wp_safe_redirect( $base . '&ccmerch_error=' . rawurlencode( $code ) );
		exit;
	}
}
