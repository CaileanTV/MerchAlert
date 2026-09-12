<?php
/**
 * REST API controller for releases.
 *
 * @package CCMerchAlert\Api
 * @feature release_manager
 */

namespace CCMerchAlert\Api;

use CCMerchAlert\Releases\Release_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the GET /wp-json/ccmerch/v1/releases endpoint.
 *
 * The endpoint is public (no authentication required) — changelog data is
 * informational and safe to expose without credentials. The Creator Portal
 * fetches it from the browser without a WP nonce.
 *
 * Optional query parameters:
 *   ?stage=beta     — filter to one stage slug
 *   ?feature=audio  — filter to releases containing this feature tag
 *
 * Instantiated unconditionally (not inside is_admin()) because rest_api_init
 * fires on both front-end and admin requests.
 *
 * Must never: write to the database.
 * Must never: require authentication for GET requests.
 * Must never: be instantiated more than once (call register() once in Plugin).
 */
final class Releases_Controller {

	public const REST_NAMESPACE = 'ccmerch/v1';
	public const ROUTE          = '/releases';

	public function __construct( private readonly Release_Repository $repository ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_releases' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'stage'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'feature' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	public function get_releases( \WP_REST_Request $request ): \WP_REST_Response {
		$stage   = $request->get_param( 'stage' )   ?: null;
		$feature = $request->get_param( 'feature' ) ?: null;

		$releases = $this->repository->get_all( $stage, $feature );

		return new \WP_REST_Response(
			\array_map( static fn( $r ) => $r->to_api_array(), $releases ),
			200
		);
	}
}
