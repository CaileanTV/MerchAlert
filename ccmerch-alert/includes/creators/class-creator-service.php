<?php
/**
 * Creator Service.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

use CCMerchAlert\Integrations\WooCommerce;
use CCMerchAlert\Overlay\Overlay_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business-logic layer for Creator Profile operations.
 *
 * This class sits between the admin UI and the data layer. It owns:
 *  - Category uniqueness enforcement (one term per creator in the current phase).
 *  - Input validation that requires cross-table knowledge.
 *  - WooCommerce active-state gating for any operation that touches categories.
 *
 * Future responsibilities (DO NOT implement now):
 *  - Order → category → creator routing for the Alert Engine.
 *  - Per-creator overlay profile selection.
 *  - Per-creator alert template resolution.
 *  - Per-creator audio profile.
 *  - Per-creator browser source linking.
 *
 * Must never: render HTML. Must never: register WordPress hooks.
 * Must never: implement alert routing until explicitly required.
 */
final class Creator_Service {

	/**
	 * First names used for simulated test alerts and overlay designer previews.
	 *
	 * Never use real WooCommerce customer data for previews or test alerts.
	 * This constant is the single source of truth for the predefined name list —
	 * both the Test Alert system and the Preview Data Provider read from here.
	 *
	 * @var string[]
	 */
	public const TEST_FIRST_NAMES = [
		'Alex', 'Emma', 'Oliver', 'Sofia', 'Lucas',
		'Isabella', 'Noah', 'Freja', 'William', 'Ella',
	];

	/**
	 * @param Creator_Repository         $repository         Creator data access layer.
	 * @param Creator_Overlay_Repository $overlay_repository Creator overlay settings data access.
	 * @param WooCommerce                $woocommerce        WooCommerce integration (active-state gating).
	 */
	public function __construct(
		private readonly Creator_Repository $repository,
		private readonly Creator_Overlay_Repository $overlay_repository,
		private readonly WooCommerce $woocommerce,
	) {}

	// -------------------------------------------------------------------------
	// Read operations
	// -------------------------------------------------------------------------

	/**
	 * Returns true when WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active(): bool {
		return $this->woocommerce->is_active();
	}

	/**
	 * Returns all creator profiles, ordered by creation date descending.
	 *
	 * @return Creator_Profile[]
	 */
	public function get_all_creators(): array {
		return $this->repository->find_all();
	}

	/**
	 * Returns a single creator profile by primary key, or null if not found.
	 *
	 * @param  int $id Primary key.
	 * @return Creator_Profile|null
	 */
	public function get_creator( int $id ): ?Creator_Profile {
		return $this->repository->find_by_id( $id );
	}

	/**
	 * Returns the WooCommerce category term IDs assigned to a creator.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return int[]
	 */
	public function get_category_ids( int $creator_id ): array {
		return $this->repository->get_category_ids( $creator_id );
	}

	/**
	 * Returns the config version (updated_at as Unix timestamp) for a creator's
	 * overlay, queried fresh from the database so it is accurate inside an SSE loop.
	 *
	 * @param  string $overlay_id 32-char hex overlay access token.
	 * @return int Unix timestamp, or 0 when not found.
	 */
	public function get_overlay_config_version( string $overlay_id ): int {
		return $this->overlay_repository->get_updated_at_by_overlay_id( $overlay_id );
	}

	/**
	 * Returns a randomly selected WooCommerce product from the categories assigned
	 * to a creator. Used exclusively by the Test Alert system to build creator-scoped
	 * simulated alerts without touching real customer data or WooCommerce orders.
	 *
	 * Returns null when WooCommerce is inactive, the creator has no category
	 * assignments, or no published products exist in the assigned categories.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return array{product_id:int,product_name:string,featured_image_attachment_id:int|null,category_term_ids:int[]}|null
	 */
	public function get_random_product_for_creator( int $creator_id ): ?array {
		if ( ! $this->woocommerce->is_active() ) {
			return null;
		}

		$term_ids = $this->get_category_ids( $creator_id );

		if ( empty( $term_ids ) ) {
			return null;
		}

		// Fetch exactly 1 random product so MySQL only needs to sort and return one
		// row. The WP_Post object comes back in memory — no separate get_post() call
		// needed. no_found_rows skips the SQL_CALC_FOUND_ROWS COUNT query.
		$posts = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'rand',
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $term_ids,
					'operator' => 'IN',
				],
			],
		] );

		if ( empty( $posts ) ) {
			return null;
		}

		$post      = $posts[0];
		$image_id  = (int) get_post_thumbnail_id( $post->ID );
		$cat_terms = get_the_terms( $post->ID, 'product_cat' );
		$cat_ids   = [];

		if ( \is_array( $cat_terms ) ) {
			foreach ( $cat_terms as $term ) {
				if ( \in_array( (int) $term->term_id, $term_ids, true ) ) {
					$cat_ids[] = (int) $term->term_id;
				}
			}
		}

		return [
			'product_id'                   => $post->ID,
			'product_name'                 => $post->post_title,
			'featured_image_attachment_id' => $image_id > 0 ? $image_id : null,
			'category_term_ids'            => $cat_ids,
		];
	}

	/**
	 * Builds simulated preview data scoped strictly to a creator's assigned categories.
	 *
	 * This is the single shared Preview Data Provider used by both the Overlay Designer
	 * canvas preview and the Test Alert system. Never queries real customer data.
	 *
	 * Returns an array shaped identically to the JS `preview` object consumed by
	 * layout-editor.js: { first_name, product, quantity, image_url, mock }.
	 *
	 * Returns mock fallback data when WooCommerce is inactive, the creator has no
	 * category assignments, or no published products exist in the assigned categories.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return array{first_name:string,product:string,quantity:int,image_url:string,mock:bool}
	 */
	public function build_preview_data( int $creator_id ): array {
		$first_name = self::TEST_FIRST_NAMES[ \array_rand( self::TEST_FIRST_NAMES ) ];
		$product    = $this->get_random_product_for_creator( $creator_id );

		if ( null === $product ) {
			return [
				'mock'       => true,
				'first_name' => $first_name,
				'product'    => 'Test Product',
				'quantity'   => 1,
				'image_url'  => '',
			];
		}

		$image_url = '';
		if ( null !== $product['featured_image_attachment_id'] ) {
			$src = wp_get_attachment_image_src( $product['featured_image_attachment_id'], 'thumbnail' );
			if ( false !== $src ) {
				$image_url = esc_url_raw( $src[0] );
			}
		}

		return [
			'mock'       => false,
			'first_name' => esc_html( $first_name ),
			'product'    => esc_html( $product['product_name'] ),
			'quantity'   => 1,
			'image_url'  => $image_url,
		];
	}

	/**
	 * Returns the WooCommerce product categories available for assignment.
	 *
	 * Returns an empty array when WooCommerce is inactive (taxonomy absent).
	 *
	 * @return \WP_Term[]
	 */
	public function get_woocommerce_categories(): array {
		if ( ! $this->woocommerce->is_active() ) {
			return [];
		}

		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );

		return \is_array( $terms ) ? $terms : [];
	}

	/**
	 * Returns a creator profile by its portal access token.
	 *
	 * Used by Creator_Portal_Controller to validate incoming portal URL requests.
	 * Returns null when no profile has the given token.
	 *
	 * @param  string $token 64-char hex portal access token.
	 * @return Creator_Profile|null
	 */
	public function get_creator_by_token( string $token ): ?Creator_Profile {
		return $this->repository->find_by_access_token( $token );
	}

	/**
	 * Builds the public Creator Portal URL for a profile.
	 *
	 * Returns an empty string when the profile has no access token yet
	 * (e.g. a profile created before schema 1.1 that has not yet been
	 * backfilled by maybe_install()).
	 *
	 * @param  Creator_Profile $creator Creator profile.
	 * @return string Absolute URL, or empty string when token is absent.
	 */
	public function get_portal_url( Creator_Profile $creator ): string {
		if ( '' === $creator->creator_access_token ) {
			return '';
		}

		return home_url( '/ccmerch-alert/creator/' . $creator->creator_access_token . '/' );
	}

	/**
	 * Returns the creator profile assigned to a WooCommerce category term ID.
	 *
	 * Primary future use: given an order item's category, find the creator to
	 * route the alert to. Returns null when WooCommerce is inactive.
	 *
	 * @param  int $term_id WooCommerce product_cat term ID.
	 * @return Creator_Profile|null
	 */
	public function find_creator_for_category( int $term_id ): ?Creator_Profile {
		if ( ! $this->woocommerce->is_active() ) {
			return null;
		}

		return $this->repository->find_by_category_term_id( $term_id );
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	/**
	 * Creates a new creator profile with a category assignment.
	 *
	 * Business rules enforced:
	 *  - WooCommerce must be active (category taxonomy must exist).
	 *  - A category must be selected (term_id > 0).
	 *  - The selected category must not already be assigned to another creator.
	 *
	 * Generates a UUID v4 and a cryptographically secure 64-char hex access
	 * token before delegating to the repository.
	 *
	 * @param  string $creator_name Display name.
	 * @param  string $description  Optional description.
	 * @param  string $status       'active' or 'disabled'.
	 * @param  int    $term_id      WooCommerce product_cat term ID to assign.
	 * @return int|string New creator ID on success; error code string on failure.
	 */
	public function create_creator( string $creator_name, string $description, string $status, int $term_id ): int|string {
		if ( ! $this->woocommerce->is_active() ) {
			return 'wc_inactive';
		}

		if ( $term_id <= 0 ) {
			return 'no_category';
		}

		if ( $this->repository->is_category_taken( $term_id ) ) {
			return 'cat_taken';
		}

		$creator_id = $this->repository->create(
			$creator_name,
			$description,
			$status,
			wp_generate_uuid4(),
			$this->generate_token()
		);

		if ( false === $creator_id ) {
			return 'db_fail';
		}

		$this->repository->assign_category( $creator_id, $term_id );

		// Create a default overlay profile for this creator.
		$this->overlay_repository->create( $creator_id, $this->generate_overlay_id() );

		return $creator_id;
	}

	/**
	 * Generates a new access token for an existing creator profile, replacing
	 * the previous one and immediately invalidating the old portal URL.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return string|false The new 64-char hex token on success; false on DB error.
	 */
	public function reset_access_token( int $creator_id ): string|false {
		$token = $this->generate_token();

		if ( ! $this->repository->update_access_token( $creator_id, $token ) ) {
			return false;
		}

		return $token;
	}

	/**
	 * Updates an existing creator profile and re-assigns its category.
	 *
	 * The old category assignment is removed and replaced with the new one.
	 * A creator can keep its current category (the uniqueness check excludes
	 * the creator being edited).
	 *
	 * Business rules enforced:
	 *  - WooCommerce must be active.
	 *  - Creator must exist.
	 *  - A category must be selected (term_id > 0).
	 *  - The selected category must not already be assigned to another creator.
	 *
	 * @param  int    $id           Creator primary key.
	 * @param  string $creator_name New display name.
	 * @param  string $description  New description.
	 * @param  string $status       New status.
	 * @param  int    $term_id      New WooCommerce product_cat term ID.
	 * @return true|string True on success; error code string on failure.
	 */
	public function update_creator( int $id, string $creator_name, string $description, string $status, int $term_id ): true|string {
		if ( ! $this->woocommerce->is_active() ) {
			return 'wc_inactive';
		}

		if ( null === $this->repository->find_by_id( $id ) ) {
			return 'not_found';
		}

		if ( $term_id <= 0 ) {
			return 'no_category';
		}

		if ( $this->repository->is_category_taken( $term_id, $id ) ) {
			return 'cat_taken';
		}

		if ( ! $this->repository->update( $id, $creator_name, $description, $status ) ) {
			return 'db_fail';
		}

		$this->repository->remove_category_assignments( $id );
		$this->repository->assign_category( $id, $term_id );

		return true;
	}

	/**
	 * Returns the creator_id associated with a given overlay_id, or null.
	 *
	 * Used by Creator_Overlay_Controller to route browser-source requests.
	 *
	 * @param  string $overlay_id 32-char hex overlay access token.
	 * @return int|null
	 */
	public function get_overlay_profile_id_by_overlay_id( string $overlay_id ): ?int {
		return $this->overlay_repository->find_creator_id_by_overlay_id( $overlay_id );
	}

	/**
	 * Returns the overlay profile for a creator, or null if none exists.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return Creator_Overlay_Profile|null
	 */
	public function get_overlay_profile( int $creator_id ): ?Creator_Overlay_Profile {
		$row = $this->overlay_repository->find_by_creator_id( $creator_id );

		if ( null === $row ) {
			return null;
		}

		$settings = [];
		if ( isset( $row['settings'] ) && '' !== $row['settings'] ) {
			$decoded = \json_decode( (string) $row['settings'], true );
			$settings = \is_array( $decoded ) ? $decoded : [];
		}

		return new Creator_Overlay_Profile(
			(int) $row['creator_id'],
			(string) $row['overlay_id'],
			$settings,
		);
	}

	/**
	 * Generates a new browser-source overlay ID for a creator.
	 *
	 * The old URL is immediately invalidated. Returns the new overlay ID on
	 * success, or false on DB error.
	 *
	 * @param  int $creator_id Creator profile primary key.
	 * @return string|false New 32-char hex overlay ID, or false on error.
	 */
	public function reset_overlay_id( int $creator_id ): string|false {
		$new_id = $this->generate_overlay_id();

		if ( ! $this->overlay_repository->update_overlay_id( $creator_id, $new_id ) ) {
			return false;
		}

		return $new_id;
	}

	/**
	 * Saves sanitized overlay settings for a creator.
	 *
	 * @param  int                  $creator_id Creator profile primary key.
	 * @param  array<string, mixed> $settings   Sanitized settings array.
	 * @return bool True on success.
	 */
	public function save_overlay_settings( int $creator_id, array $settings ): bool {
		return $this->overlay_repository->save_settings( $creator_id, $settings );
	}

	/**
	 * Deletes a creator profile and all its category assignments.
	 *
	 * @param  int $id Creator primary key.
	 * @return bool True on success.
	 */
	public function delete_creator( int $id ): bool {
		$this->overlay_repository->delete_by_creator_id( $id );
		return $this->repository->delete( $id );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Generates a cryptographically secure, URL-safe portal access token.
	 *
	 * 32 random bytes → 64 lowercase hex chars → 256 bits of entropy.
	 *
	 * @return string 64-char hex string.
	 */
	private function generate_token(): string {
		return \bin2hex( \random_bytes( 32 ) );
	}

	/**
	 * Generates a cryptographically secure overlay ID.
	 *
	 * 16 random bytes → 32 lowercase hex chars → 128 bits of entropy.
	 * Matches the generation used by Overlay_Settings::generate_id().
	 *
	 * @return string 32-char hex string.
	 */
	private function generate_overlay_id(): string {
		return \bin2hex( \random_bytes( 16 ) );
	}
}
