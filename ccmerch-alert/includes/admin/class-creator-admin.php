<?php
/**
 * Creator Profiles admin page.
 *
 * @package CCMerchAlert\Admin
 */

namespace CCMerchAlert\Admin;

use CCMerchAlert\Creators\Creator_Service;
use CCMerchAlert\Creators\Creator_Profile;
use CCMerchAlert\UI\Asset_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the CCMerch Alert → Creator Profiles admin submenu page.
 *
 * Provides a full CRUD UI for creator profiles. Each profile can be assigned
 * one WooCommerce product category (term ID). Category names are resolved from
 * WooCommerce at render time — never stored as strings.
 *
 * Business rules (WC active check, category uniqueness) are enforced by
 * Creator_Service. Database operations go through Creator_Service → Creator_Repository.
 *
 * Three screens are dispatched from render_page() based on the ?action= GET param:
 *  (none) → list  — table of all creator profiles
 *  create  → form to create a new profile
 *  edit    → form to edit an existing profile (requires ?id=N)
 *
 * Create, update, and delete mutations are handled via admin_post_ hooks so
 * the Post/Redirect/Get pattern is preserved without custom rewrite rules.
 *
 * Must never: write to the database directly.
 * Must never: implement alert routing or overlay integration.
 */
final class Creator_Admin {

	/** Page slug for the Creator Profiles submenu. */
	public const PAGE = 'ccmerch-alert-creators';

	/** Nonce action for the create form. */
	public const NONCE_CREATE = 'ccmerch_creator_create';

	/** Nonce action for the update form. */
	public const NONCE_UPDATE = 'ccmerch_creator_update';

	/** admin_post_ action name for create submissions. */
	public const ACTION_CREATE = 'ccmerch_creator_create';

	/** admin_post_ action name for update submissions. */
	public const ACTION_UPDATE = 'ccmerch_creator_update';

	/** admin_post_ action name for delete submissions. */
	public const ACTION_DELETE = 'ccmerch_creator_delete';

	/** admin_post_ action name for access token reset. */
	public const ACTION_RESET_TOKEN = 'ccmerch_creator_reset_token';

	/** Nonce action for the token reset form. */
	public const NONCE_RESET_TOKEN = 'ccmerch_creator_reset_token';

	/** Hook suffix returned by add_submenu_page(). */
	private string $page_hook = '';

	/**
	 * @param Creator_Service $service Creator business-logic and data access.
	 */
	public function __construct(
		private readonly Creator_Service $service,
	) {
		add_action( 'admin_menu',            [ $this, 'register_menu'   ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'  ] );
		add_action( 'admin_post_' . self::ACTION_CREATE,      [ $this, 'handle_create'       ] );
		add_action( 'admin_post_' . self::ACTION_UPDATE,      [ $this, 'handle_update'       ] );
		add_action( 'admin_post_' . self::ACTION_DELETE,      [ $this, 'handle_delete'       ] );
		add_action( 'admin_post_' . self::ACTION_RESET_TOKEN, [ $this, 'handle_reset_token'  ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the Creator Profiles submenu under the CCMerch Alert menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->page_hook = (string) add_submenu_page(
			'ccmerch-alert',
			__( 'Creator Profiles', 'ccmerch-alert' ),
			__( 'Creator Profiles', 'ccmerch-alert' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues admin.css on the Creator Profiles page only.
	 *
	 * @param  string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		Asset_Loader::enqueue_creator_admin();
	}

	// -------------------------------------------------------------------------
	// Page dispatcher
	// -------------------------------------------------------------------------

	/**
	 * Main page renderer — dispatches to the correct screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( $_GET['action'] ?? '' );

		if ( 'create' === $action ) {
			$this->render_create_page();
		} elseif ( 'edit' === $action ) {
			$this->render_edit_page();
		} else {
			$this->render_list_page();
		}
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders the creator profiles list table.
	 *
	 * @return void
	 */
	private function render_list_page(): void {
		$creators   = $this->service->get_all_creators();
		$wc_active  = $this->service->is_woocommerce_active();
		$create_url = add_query_arg( [ 'page' => self::PAGE, 'action' => 'create' ], admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Creator Profiles', 'ccmerch-alert' ); ?></h1>
			<?php if ( $wc_active ) : ?>
			<a href="<?php echo esc_url( $create_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Create Creator Profile', 'ccmerch-alert' ); ?>
			</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php if ( ! $wc_active ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'WooCommerce is required to manage Creator Profiles. Please activate WooCommerce first.', 'ccmerch-alert' ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( empty( $creators ) ) : ?>
			<p>
				<?php esc_html_e( 'No creator profiles yet.', 'ccmerch-alert' ); ?>
				<?php if ( $wc_active ) : ?>
				<a href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create your first creator profile.', 'ccmerch-alert' ); ?></a>
				<?php endif; ?>
			</p>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Creator Name', 'ccmerch-alert' ); ?></th>
						<th scope="col" style="width:100px;"><?php esc_html_e( 'Status', 'ccmerch-alert' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Assigned Category', 'ccmerch-alert' ); ?></th>
						<th scope="col" style="width:200px;"><?php esc_html_e( 'Creator Portal', 'ccmerch-alert' ); ?></th>
						<th scope="col" style="width:130px;"><?php esc_html_e( 'Created', 'ccmerch-alert' ); ?></th>
						<th scope="col" style="width:130px;"><?php esc_html_e( 'Last Updated', 'ccmerch-alert' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $creators as $creator ) : ?>
					<?php
						$edit_url         = add_query_arg(
							[ 'page' => self::PAGE, 'action' => 'edit', 'id' => $creator->id ],
							admin_url( 'admin.php' )
						);
						$category_ids     = $this->service->get_category_ids( $creator->id );
						$category_display = $this->resolve_category_display( $category_ids );
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $creator->creator_name ); ?></a>
							</strong>
							<div class="row-actions">
								<span class="edit">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'ccmerch-alert' ); ?></a>
									&nbsp;|&nbsp;
								</span>
								<span class="delete">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'ccmerch_creator_delete_' . $creator->id, '_wpnonce', false ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
										<input type="hidden" name="creator_id" value="<?php echo esc_attr( (string) $creator->id ); ?>">
										<button
											type="submit"
											class="button-link delete"
											onclick="return confirm( '<?php echo esc_js( __( 'Delete this creator profile? This action cannot be undone.', 'ccmerch-alert' ) ); ?>' );"
										>
											<?php esc_html_e( 'Delete', 'ccmerch-alert' ); ?>
										</button>
									</form>
								</span>
							</div>
						</td>
						<td>
							<span class="ccmerch-creator-status ccmerch-creator-status--<?php echo esc_attr( $creator->status ); ?>">
								<?php echo 'active' === $creator->status ? esc_html__( 'Active', 'ccmerch-alert' ) : esc_html__( 'Disabled', 'ccmerch-alert' ); ?>
							</span>
						</td>
						<?php // $category_display is built via esc_html() in resolve_category_display(). ?>
						<td><?php echo $category_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php $this->render_portal_cell( $creator ); ?></td>
						<td><?php echo esc_html( (string) wp_date( (string) get_option( 'date_format' ), $creator->created_at->getTimestamp() ) ); ?></td>
						<td><?php echo esc_html( (string) wp_date( (string) get_option( 'date_format' ), $creator->updated_at->getTimestamp() ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the create creator profile form.
	 *
	 * @return void
	 */
	private function render_create_page(): void {
		$wc_active  = $this->service->is_woocommerce_active();
		$categories = $this->service->get_woocommerce_categories();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Create Creator Profile', 'ccmerch-alert' ); ?></h1>

			<?php $this->render_notices(); ?>
			<?php $this->render_wc_warning( $wc_active ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_CREATE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREATE ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="creator_name"><?php esc_html_e( 'Creator Name', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<input type="text" name="creator_name" id="creator_name" class="regular-text" value="" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_category_term_id"><?php esc_html_e( 'WooCommerce Category', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<?php if ( $wc_active && ! empty( $categories ) ) : ?>
							<select name="wc_category_term_id" id="wc_category_term_id">
								<option value="0"><?php esc_html_e( '— Select a category —', 'ccmerch-alert' ); ?></option>
								<?php foreach ( $categories as $term ) : ?>
								<option value="<?php echo esc_attr( (string) $term->term_id ); ?>">
									<?php echo esc_html( $term->name ); ?>
								</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Orders containing products from this category will be routed to this creator. Each category can only be assigned to one creator.', 'ccmerch-alert' ); ?>
							</p>
							<?php elseif ( $wc_active ) : ?>
							<p><?php esc_html_e( 'No WooCommerce product categories found. Create at least one product category in WooCommerce first.', 'ccmerch-alert' ); ?></p>
							<?php else : ?>
							<p><?php esc_html_e( 'WooCommerce must be active to assign categories.', 'ccmerch-alert' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="description"><?php esc_html_e( 'Description', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<textarea name="description" id="description" class="large-text" rows="4"></textarea>
							<p class="description"><?php esc_html_e( 'Optional. For internal reference only.', 'ccmerch-alert' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="status"><?php esc_html_e( 'Status', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<select name="status" id="status">
								<option value="active"><?php esc_html_e( 'Active', 'ccmerch-alert' ); ?></option>
								<option value="disabled"><?php esc_html_e( 'Disabled', 'ccmerch-alert' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<p>
					<?php submit_button( __( 'Create Creator Profile', 'ccmerch-alert' ), 'primary', 'submit', false ); ?>
					&nbsp;
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE ], admin_url( 'admin.php' ) ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'ccmerch-alert' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the edit creator profile form for the profile identified by ?id=N.
	 *
	 * @return void
	 */
	private function render_edit_page(): void {
		$id      = (int) ( $_GET['id'] ?? 0 );
		$creator = $id > 0 ? $this->service->get_creator( $id ) : null;

		if ( null === $creator ) {
			wp_die( esc_html__( 'Creator profile not found.', 'ccmerch-alert' ) );
		}

		$category_ids     = $this->service->get_category_ids( $creator->id );
		$selected_term_id = ! empty( $category_ids ) ? (int) $category_ids[0] : 0;

		$wc_active  = $this->service->is_woocommerce_active();
		$categories = $this->service->get_woocommerce_categories();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Edit Creator Profile', 'ccmerch-alert' ); ?></h1>

			<?php $this->render_notices(); ?>
			<?php $this->render_wc_warning( $wc_active ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_UPDATE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPDATE ); ?>">
				<input type="hidden" name="creator_id" value="<?php echo esc_attr( (string) $creator->id ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="creator_name"><?php esc_html_e( 'Creator Name', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<input type="text" name="creator_name" id="creator_name" class="regular-text" value="<?php echo esc_attr( $creator->creator_name ); ?>" required>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wc_category_term_id"><?php esc_html_e( 'WooCommerce Category', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<?php if ( $wc_active && ! empty( $categories ) ) : ?>
							<select name="wc_category_term_id" id="wc_category_term_id">
								<option value="0"><?php esc_html_e( '— Select a category —', 'ccmerch-alert' ); ?></option>
								<?php foreach ( $categories as $term ) : ?>
								<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $selected_term_id, $term->term_id ); ?>>
									<?php echo esc_html( $term->name ); ?>
								</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Changing the category removes the current assignment and creates a new one. Each category can only be assigned to one creator.', 'ccmerch-alert' ); ?>
							</p>
							<?php elseif ( $wc_active ) : ?>
							<p><?php esc_html_e( 'No WooCommerce product categories found.', 'ccmerch-alert' ); ?></p>
							<?php else : ?>
							<p><?php esc_html_e( 'WooCommerce must be active to assign categories.', 'ccmerch-alert' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="description"><?php esc_html_e( 'Description', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<textarea name="description" id="description" class="large-text" rows="4"><?php echo esc_textarea( $creator->description ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. For internal reference only.', 'ccmerch-alert' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="status"><?php esc_html_e( 'Status', 'ccmerch-alert' ); ?></label>
						</th>
						<td>
							<select name="status" id="status">
								<option value="active" <?php selected( $creator->status, 'active' ); ?>><?php esc_html_e( 'Active', 'ccmerch-alert' ); ?></option>
								<option value="disabled" <?php selected( $creator->status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'ccmerch-alert' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<p>
					<?php submit_button( __( 'Save Changes', 'ccmerch-alert' ), 'primary', 'submit', false ); ?>
					&nbsp;
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE ], admin_url( 'admin.php' ) ) ); ?>" class="button">
						<?php esc_html_e( 'Cancel', 'ccmerch-alert' ); ?>
					</a>
				</p>
			</form>

			<?php $this->render_portal_access_section( $creator ); ?>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// admin_post handlers
	// -------------------------------------------------------------------------

	/**
	 * Handles the create creator profile form submission.
	 *
	 * @return void
	 */
	public function handle_create(): void {
		check_admin_referer( self::NONCE_CREATE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ccmerch-alert' ) );
		}

		$name        = sanitize_text_field( wp_unslash( $_POST['creator_name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$status      = sanitize_key( $_POST['status'] ?? 'active' );
		$term_id     = (int) ( $_POST['wc_category_term_id'] ?? 0 );

		if ( ! \in_array( $status, [ 'active', 'disabled' ], true ) ) {
			$status = 'active';
		}

		if ( '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'page' => self::PAGE, 'action' => 'create', 'ccmerch_error' => 'invalid_name' ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = $this->service->create_creator( $name, $description, $status, $term_id );

		if ( \is_string( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'page' => self::PAGE, 'action' => 'create', 'ccmerch_error' => $result ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::PAGE, 'ccmerch_notice' => 'created' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the update creator profile form submission.
	 *
	 * @return void
	 */
	public function handle_update(): void {
		check_admin_referer( self::NONCE_UPDATE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ccmerch-alert' ) );
		}

		$creator_id  = (int) ( $_POST['creator_id'] ?? 0 );
		$name        = sanitize_text_field( wp_unslash( $_POST['creator_name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$status      = sanitize_key( $_POST['status'] ?? 'active' );
		$term_id     = (int) ( $_POST['wc_category_term_id'] ?? 0 );

		if ( ! \in_array( $status, [ 'active', 'disabled' ], true ) ) {
			$status = 'active';
		}

		if ( $creator_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg( [ 'page' => self::PAGE, 'ccmerch_error' => 'not_found' ], admin_url( 'admin.php' ) )
			);
			exit;
		}

		if ( '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'page' => self::PAGE, 'action' => 'edit', 'id' => $creator_id, 'ccmerch_error' => 'invalid_name' ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = $this->service->update_creator( $creator_id, $name, $description, $status, $term_id );

		if ( true !== $result ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'page' => self::PAGE, 'action' => 'edit', 'id' => $creator_id, 'ccmerch_error' => $result ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::PAGE, 'ccmerch_notice' => 'updated' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the delete creator profile form submission.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$creator_id = (int) ( $_POST['creator_id'] ?? 0 );

		check_admin_referer( 'ccmerch_creator_delete_' . $creator_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ccmerch-alert' ) );
		}

		if ( $creator_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg( [ 'page' => self::PAGE, 'ccmerch_error' => 'not_found' ], admin_url( 'admin.php' ) )
			);
			exit;
		}

		$this->service->delete_creator( $creator_id );

		wp_safe_redirect(
			add_query_arg( [ 'page' => self::PAGE, 'ccmerch_notice' => 'deleted' ], admin_url( 'admin.php' ) )
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Renders inline admin notices based on URL query parameters.
	 *
	 * Success codes: 'created', 'updated', 'deleted'.
	 * Error codes: 'invalid_name', 'no_category', 'cat_taken', 'not_found',
	 *              'db_fail', 'wc_inactive'.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		$notice = sanitize_key( $_GET['ccmerch_notice'] ?? '' );
		$error  = sanitize_key( $_GET['ccmerch_error']  ?? '' );

		if ( '' !== $notice ) {
			$message = match ( $notice ) {
				'created'     => __( 'Creator profile created successfully.', 'ccmerch-alert' ),
				'updated'     => __( 'Creator profile updated.', 'ccmerch-alert' ),
				'deleted'     => __( 'Creator profile deleted.', 'ccmerch-alert' ),
				'token_reset' => __( 'Creator Access Link has been reset. The previous link is no longer valid.', 'ccmerch-alert' ),
				default       => '',
			};
			if ( '' !== $message ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		if ( '' !== $error ) {
			$message = $this->error_message( $error );
			if ( '' !== $message ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	}

	/**
	 * Renders a WooCommerce inactive warning, or returns early when WC is active.
	 *
	 * @param  bool $wc_active Whether WooCommerce is currently active.
	 * @return void
	 */
	private function render_wc_warning( bool $wc_active ): void {
		if ( $wc_active ) {
			return;
		}
		?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'WooCommerce must be active to manage Creator Profile categories.', 'ccmerch-alert' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the Creator Portal cell for the list table.
	 *
	 * Outputs a "Copy Link" button and a compact "Reset" form. The Copy Link
	 * button uses the Clipboard API via a small inline script injected by the
	 * list renderer. The Reset form posts to admin-post.php.
	 *
	 * @param  Creator_Profile $creator Row creator profile.
	 * @return void
	 */
	private function render_portal_cell( Creator_Profile $creator ): void {
		$portal_url = $this->service->get_portal_url( $creator );

		if ( '' === $portal_url ) {
			echo '&mdash;';
			return;
		}
		?>
		<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
			<button
				type="button"
				class="button button-small"
				data-copied="<?php esc_attr_e( '✓ Copied!', 'ccmerch-alert' ); ?>"
				data-copy-fail="<?php esc_attr_e( 'Unable to copy.', 'ccmerch-alert' ); ?>"
				onclick="ccmerchCopyPortalUrl( this, '<?php echo esc_js( $portal_url ); ?>' )"
			><?php esc_html_e( 'Copy Link', 'ccmerch-alert' ); ?></button>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:contents;">
				<?php wp_nonce_field( self::NONCE_RESET_TOKEN . '_' . $creator->id, '_wpnonce', false ); ?>
				<input type="hidden" name="action"     value="<?php echo esc_attr( self::ACTION_RESET_TOKEN ); ?>">
				<input type="hidden" name="creator_id" value="<?php echo esc_attr( (string) $creator->id ); ?>">
				<input type="hidden" name="redirect_to" value="list">
				<button
					type="submit"
					class="button button-small"
					onclick="return confirm( '<?php echo esc_js( __( 'Reset the Creator Access Link? The current link will stop working immediately.', 'ccmerch-alert' ) ); ?>' );"
				><?php esc_html_e( 'Reset', 'ccmerch-alert' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the Creator Portal Access section on the edit page.
	 *
	 * Displays the current portal URL with Copy and Open buttons, plus a
	 * "Reset Creator Access Link" form that invalidates the current URL and
	 * generates a new one.
	 *
	 * @param  Creator_Profile $creator The creator being edited.
	 * @return void
	 */
	private function render_portal_access_section( Creator_Profile $creator ): void {
		$portal_url = $this->service->get_portal_url( $creator );
		?>
		<hr>
		<h2><?php esc_html_e( 'Creator Portal Access', 'ccmerch-alert' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Share this link with the creator so they can access their personal portal. The link works without a WordPress login.', 'ccmerch-alert' ); ?>
		</p>

		<?php if ( '' !== $portal_url ) : ?>
		<div class="ccmerch-overlay-url" style="max-width:600px;">
			<input type="text" class="large-text code" readonly value="<?php echo esc_url( $portal_url ); ?>">
			<div class="ccmerch-overlay-url__actions">
				<button
					type="button"
					id="ccmerch-copy-portal-link"
					class="button"
					data-copied="<?php esc_attr_e( '✓ Link copied to clipboard', 'ccmerch-alert' ); ?>"
					data-copy-fail="<?php esc_attr_e( 'Unable to copy automatically.', 'ccmerch-alert' ); ?>"
					onclick="ccmerchCopyLink( this, '<?php echo esc_js( $portal_url ); ?>' )"
				><?php esc_html_e( 'Copy Link', 'ccmerch-alert' ); ?></button>
				<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener" class="button">
					<?php esc_html_e( 'Open', 'ccmerch-alert' ); ?>
				</a>
			</div>
		</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
			<?php wp_nonce_field( self::NONCE_RESET_TOKEN . '_' . $creator->id, '_wpnonce', false ); ?>
			<input type="hidden" name="action"     value="<?php echo esc_attr( self::ACTION_RESET_TOKEN ); ?>">
			<input type="hidden" name="creator_id" value="<?php echo esc_attr( (string) $creator->id ); ?>">
			<input type="hidden" name="redirect_to" value="edit">
			<p>
				<button
					type="submit"
					class="button button-secondary"
					onclick="return confirm( '<?php echo esc_js( __( 'Reset the Creator Access Link? The current link will stop working immediately and the creator will need the new URL.', 'ccmerch-alert' ) ); ?>' );"
				><?php esc_html_e( 'Reset Creator Access Link', 'ccmerch-alert' ); ?></button>
				<span class="description" style="margin-left:10px;">
					<?php esc_html_e( 'Generates a new link and immediately invalidates the previous one.', 'ccmerch-alert' ); ?>
				</span>
			</p>
		</form>
		<?php
	}

	/**
	 * Handles the token reset form submission.
	 *
	 * Accepts submissions from both the list table and the edit page. Uses
	 * redirect_to POST param to return the admin to the correct page.
	 *
	 * @return void
	 */
	public function handle_reset_token(): void {
		$creator_id = (int) ( $_POST['creator_id'] ?? 0 );

		check_admin_referer( self::NONCE_RESET_TOKEN . '_' . $creator_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ccmerch-alert' ) );
		}

		if ( $creator_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg( [ 'page' => self::PAGE, 'ccmerch_error' => 'not_found' ], admin_url( 'admin.php' ) )
			);
			exit;
		}

		$result = $this->service->reset_access_token( $creator_id );

		if ( false === $result ) {
			$redirect_to = sanitize_key( $_POST['redirect_to'] ?? 'list' );
			$params      = 'edit' === $redirect_to
				? [ 'page' => self::PAGE, 'action' => 'edit', 'id' => $creator_id, 'ccmerch_error' => 'db_fail' ]
				: [ 'page' => self::PAGE, 'ccmerch_error' => 'db_fail' ];

			wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
			exit;
		}

		$redirect_to = sanitize_key( $_POST['redirect_to'] ?? 'list' );
		$params      = 'edit' === $redirect_to
			? [ 'page' => self::PAGE, 'action' => 'edit', 'id' => $creator_id, 'ccmerch_notice' => 'token_reset' ]
			: [ 'page' => self::PAGE, 'ccmerch_notice' => 'token_reset' ];

		wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Maps an error code from the service layer to a translatable error message.
	 *
	 * Error codes are safe enum-like strings passed via URL params — they are
	 * never translated, interpolated into HTML, or passed to $wpdb.
	 *
	 * @param  string $code Error code.
	 * @return string Translated message, or empty string for unrecognised codes.
	 */
	private function error_message( string $code ): string {
		return match ( $code ) {
			'invalid_name' => __( 'Creator name is required.', 'ccmerch-alert' ),
			'no_category'  => __( 'Please select a WooCommerce category.', 'ccmerch-alert' ),
			'cat_taken'    => __( 'That WooCommerce category is already assigned to another creator.', 'ccmerch-alert' ),
			'not_found'    => __( 'Creator profile not found.', 'ccmerch-alert' ),
			'db_fail'      => __( 'A database error occurred. Please try again.', 'ccmerch-alert' ),
			'wc_inactive'  => __( 'WooCommerce must be active to manage creator profiles.', 'ccmerch-alert' ),
			default        => '',
		};
	}

	/**
	 * Resolves category term IDs to a display string for the list table.
	 *
	 * Term names are fetched from WooCommerce taxonomy at render time so they
	 * always reflect the current taxonomy state. Returns an em-dash entity
	 * when no categories are assigned.
	 *
	 * Output is built via esc_html() on the imploded names, so calling code
	 * may echo the return value directly.
	 *
	 * @param  int[] $category_ids WooCommerce product_cat term IDs.
	 * @return string HTML-safe display string.
	 */
	private function resolve_category_display( array $category_ids ): string {
		if ( empty( $category_ids ) ) {
			return '&mdash;';
		}

		$names = [];

		foreach ( $category_ids as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}

		if ( empty( $names ) ) {
			return '&mdash;';
		}

		return esc_html( \implode( ', ', $names ) );
	}
}
