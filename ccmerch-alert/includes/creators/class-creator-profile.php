<?php
/**
 * Creator Profile value object.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of a single Creator Profile.
 *
 * Read model only — write operations always go through Creator_Repository
 * via Creator_Service.
 *
 * Category assignments are stored in a separate table and are NOT embedded
 * here. Use Creator_Repository::get_category_ids() (or Creator_Service
 * which delegates to it) to fetch the assigned WooCommerce term IDs.
 *
 * This separation is intentional: a future multi-category expansion only
 * requires changes to the assignment table and service, not this value object.
 */
final class Creator_Profile {

	/**
	 * @param int                $id                   Primary key in ccmerch_creator_profiles.
	 * @param string             $creator_uuid         Stable internal identifier (UUID v4).
	 * @param string             $creator_access_token 64-char hex token for the public Creator Portal URL.
	 * @param string             $creator_name         Display name of the creator.
	 * @param string             $description          Optional internal description.
	 * @param string             $status               'active' or 'disabled'.
	 * @param \DateTimeImmutable $created_at           Row creation timestamp (UTC).
	 * @param \DateTimeImmutable $updated_at           Last update timestamp (UTC).
	 */
	public function __construct(
		public readonly int                $id,
		public readonly string             $creator_uuid,
		public readonly string             $creator_access_token,
		public readonly string             $creator_name,
		public readonly string             $description,
		public readonly string             $status,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at,
	) {}
}
