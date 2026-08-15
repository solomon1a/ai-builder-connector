<?php
/**
 * Saved templates: reuse an approved draft's section plan as a starting point.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores section plans (not raw Elementor JSON) so every reuse still goes
 * through the normal validation, permission, and sanitizer pipeline.
 */
class Saved_Template_Store {

	public const OPTION_TEMPLATES = 'aibc_saved_templates';
	private const MAX_TEMPLATES   = 20;

	/**
	 * Draft manager, used to read a draft's stored plan.
	 */
	private Draft_Manager $draft_manager;

	public function __construct( Draft_Manager $draft_manager ) {
		$this->draft_manager = $draft_manager;
	}

	/**
	 * Saves an AIBC draft's plan as a reusable template.
	 *
	 * @param int    $post_id AIBC draft ID.
	 * @param string $name    Template display name.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function save_from_draft( int $post_id, string $name ): array|\WP_Error {
		$name = sanitize_text_field( $name );

		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'aibc_template_missing_name', __( 'A template name is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || '1' !== get_post_meta( $post_id, Draft_Manager::META_CREATED, true ) ) {
			return new \WP_Error( 'aibc_template_not_aibc', __( 'Only AI Builder drafts can be saved as templates.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		$plan = $this->draft_manager->get_plan( $post_id );

		if ( empty( $plan['sections'] ) || ! is_array( $plan['sections'] ) ) {
			return new \WP_Error( 'aibc_template_empty_plan', __( 'This draft has no stored plan to save.', 'ai-builder-connector' ), array( 'status' => 422 ) );
		}

		$slug      = sanitize_key( str_replace( ' ', '-', $name ) ) . '-' . substr( md5( $name . (string) $post_id ), 0, 6 );
		$templates = $this->get_all();

		if ( count( $templates ) >= self::MAX_TEMPLATES && ! isset( $templates[ $slug ] ) ) {
			return new \WP_Error( 'aibc_template_limit', __( 'Template limit reached (20). Delete one first.', 'ai-builder-connector' ), array( 'status' => 409 ) );
		}

		$templates[ $slug ] = array(
			'name'       => $name,
			'brief'      => sanitize_textarea_field( (string) ( $plan['brief'] ?? '' ) ),
			'template'   => sanitize_key( (string) ( $plan['template'] ?? '' ) ),
			'sections'   => $plan['sections'],
			'created_at' => gmdate( 'c' ),
			'source_id'  => $post_id,
		);

		update_option( self::OPTION_TEMPLATES, $templates, false );

		return array(
			'slug'     => $slug,
			'name'     => $name,
			'sections' => count( $plan['sections'] ),
		);
	}

	/**
	 * Lists saved templates (summaries).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_summaries(): array {
		$out = array();

		foreach ( $this->get_all() as $slug => $tpl ) {
			$out[] = array(
				'slug'       => $slug,
				'name'       => sanitize_text_field( (string) ( $tpl['name'] ?? '' ) ),
				'sections'   => is_array( $tpl['sections'] ?? null ) ? count( $tpl['sections'] ) : 0,
				'created_at' => sanitize_text_field( (string) ( $tpl['created_at'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Gets one saved template.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( string $slug ): ?array {
		$templates = $this->get_all();
		$slug      = sanitize_key( $slug );

		return isset( $templates[ $slug ] ) && is_array( $templates[ $slug ] ) ? $templates[ $slug ] : null;
	}

	/**
	 * Deletes one saved template.
	 */
	public function delete( string $slug ): bool {
		$templates = $this->get_all();
		$slug      = sanitize_key( $slug );

		if ( ! isset( $templates[ $slug ] ) ) {
			return false;
		}

		unset( $templates[ $slug ] );
		update_option( self::OPTION_TEMPLATES, $templates, false );

		return true;
	}

	/**
	 * Reads the raw option.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_all(): array {
		$value = get_option( self::OPTION_TEMPLATES, array() );

		return is_array( $value ) ? $value : array();
	}
}
