<?php
/**
 * Release value object.
 *
 * @package CCMerchAlert\Releases
 * @feature release_manager
 */

namespace CCMerchAlert\Releases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable snapshot of a single release row.
 *
 * Created by Release_Repository from a DB row. Never mutated after construction.
 * The to_api_array() method produces the canonical REST API response shape —
 * any consumer of the API can rely on that structure being stable.
 *
 * Must never: be mutated after construction.
 * Must never: call WordPress or WooCommerce APIs.
 * Must never: be created by anything other than Release_Repository.
 */
final class Release {

	public function __construct(
		public readonly int $id,
		public readonly string $version,
		public readonly string $stage,
		public readonly string $title,
		public readonly string $description,
		public readonly array $feature_tags,       // string[] — validated slugs
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at,
	) {}

	public function stage_label(): string {
		return match ( $this->stage ) {
			'alpha'      => 'Alpha',
			'production' => 'Production',
			default      => 'Beta',
		};
	}

	/**
	 * Returns the canonical REST API shape for this release.
	 *
	 * Adding new keys here is additive and non-breaking for existing consumers.
	 *
	 * @return array<string, mixed>
	 */
	public function to_api_array(): array {
		return [
			'id'             => $this->id,
			'version'        => $this->version,
			'stage'          => $this->stage,
			'stage_label'    => $this->stage_label(),
			'title'          => $this->title,
			'description'    => $this->description,
			'feature_tags'   => $this->feature_tags,
			'feature_labels' => \array_values(
				\array_map(
					static fn( string $tag ) => Feature_Tag_Registry::label( $tag ),
					$this->feature_tags
				)
			),
			'created_at'     => $this->created_at->format( 'Y-m-d' ),
		];
	}
}
