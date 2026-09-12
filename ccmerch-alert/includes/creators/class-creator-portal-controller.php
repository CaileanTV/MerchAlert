<?php
/**
 * Creator Portal controller.
 *
 * @package CCMerchAlert\Creators
 */

namespace CCMerchAlert\Creators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all public Creator Portal routing.
 *
 * Responsibilities:
 *  - Registers the WordPress rewrite rule for the Creator Portal URL.
 *  - Exposes the ccmerch_creator_token query variable.
 *  - Intercepts matching requests on template_redirect and delegates to the
 *    renderer after validating the access token and creator status.
 *
 * Security model:
 *  - The 64-char hex access token (256-bit entropy) in the URL is the sole
 *    authentication credential. No WordPress login is required.
 *  - Invalid tokens, unknown tokens, and disabled profiles all return the
 *    same 404 response to prevent information leakage.
 *  - hash_equals() prevents timing-attack enumeration of valid tokens.
 *
 * Must never: output HTML directly (delegated to Creator_Portal_Renderer).
 * Must never: write to the database.
 * Must never: handle admin mutations (those belong in Creator_Admin).
 */
final class Creator_Portal_Controller {

	/** WordPress query var that carries the access token from the rewrite rule. */
	public const QUERY_VAR = 'ccmerch_creator_token';

	/** Rewrite pattern — matches /ccmerch-alert/creator/{token}/. */
	public const REWRITE_RULE = '^ccmerch-alert/creator/([^/]+)/?$';

	/** Rewrite substitution string. */
	public const REWRITE_QUERY = 'index.php?ccmerch_creator_token=$matches[1]';

	/**
	 * @param Creator_Repository    $repository Repository for token lookups.
	 * @param Creator_Portal_Renderer $renderer   Standalone HTML page producer.
	 */
	public function __construct(
		private readonly Creator_Repository $repository,
		private readonly Creator_Portal_Renderer $renderer,
	) {
		add_action( 'init',              [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',        [ $this, 'register_query_vars'    ] );
		add_action( 'template_redirect', [ $this, 'maybe_render_portal'    ] );
	}

	// -------------------------------------------------------------------------
	// Rewrite rule
	// -------------------------------------------------------------------------

	/**
	 * Registers the Creator Portal rewrite rule.
	 *
	 * Called on init every request so the rule is always present in the
	 * in-memory WP_Rewrite object. The persistent cache is rebuilt by
	 * Overlay_Controller::maybe_flush_rewrite() on wp_loaded after activation.
	 *
	 * @return void
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule( self::REWRITE_RULE, self::REWRITE_QUERY, 'top' );
	}

	/**
	 * Exposes the creator token query var to WordPress.
	 *
	 * @param  string[] $vars Registered public query variables.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	// -------------------------------------------------------------------------
	// Route handler
	// -------------------------------------------------------------------------

	/**
	 * Intercepts Creator Portal URL requests, validates the token, and renders.
	 *
	 * Fires on template_redirect. Returns early when the query var is absent
	 * so all normal WordPress requests are completely unaffected.
	 *
	 * Validation sequence:
	 *  1. Query var present — otherwise not our route.
	 *  2. Token resolves to a known creator profile in the database.
	 *  3. Stored token matches the request token via hash_equals().
	 *  4. Creator profile status is 'active'.
	 *
	 * Any failure returns a 404. The same response is returned for all failure
	 * cases so callers cannot distinguish a bad token from a disabled profile.
	 *
	 * @return void
	 */
	public function maybe_render_portal(): void {
		$token = (string) get_query_var( self::QUERY_VAR, '' );

		if ( '' === $token ) {
			return;
		}

		$creator = $this->repository->find_by_access_token( $token );

		if ( null === $creator ) {
			wp_die( '', '', [ 'response' => 404 ] );
		}

		// hash_equals() prevents timing-attack enumeration of valid tokens even
		// though the DB lookup already validated the token.
		if ( ! \hash_equals( $creator->creator_access_token, $token ) ) {
			wp_die( '', '', [ 'response' => 404 ] );
		}

		if ( 'active' !== $creator->status ) {
			wp_die( '', '', [ 'response' => 404 ] );
		}

		// Never returns — outputs full HTML document and exits.
		$this->renderer->render_page( $creator );
	}
}
