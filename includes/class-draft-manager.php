<?php
/**
 * Draft manager.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and rolls back AIBC-owned draft pages only.
 */
final class Draft_Manager {
	public const META_CREATED       = '_aibc_created';
	public const META_CREATED_AT    = '_aibc_created_at';
	public const META_BRIEF         = '_aibc_brief';
	public const META_PLAN          = '_aibc_page_plan';
	public const META_USED_WIDGETS  = '_aibc_used_widgets';
	public const META_BLOCKED       = '_aibc_blocked_widgets';
	public const META_ELEMENTOR_DATA = '_aibc_elementor_data';
	public const META_VALIDATION_STATUS = '_aibc_validation_status';
	public const META_VALIDATION_ERRORS = '_aibc_validation_errors';
	public const META_ROLLED_BACK_AT    = '_aibc_rolled_back_at';
	public const META_ROLLBACK_REASON   = '_aibc_rollback_reason';
	public const META_DESIGN_SYSTEM     = '_aibc_design_system';
	public const META_PUBLISHED_AT      = '_aibc_published_at';
	public const META_PUBLISHED_BY      = '_aibc_published_by';
	public const META_REVISIONS         = '_aibc_revisions';

	private const MAX_REVISIONS = 5;

	private Page_Plan_Builder $page_plan_builder;
	private Elementor_Validator $validator;
	private Review_Workflow $review_workflow;

	/**
	 * Draft manager constructor.
	 */
	public function __construct( Page_Plan_Builder $page_plan_builder, Elementor_Validator $validator, Review_Workflow $review_workflow ) {
		$this->page_plan_builder = $page_plan_builder;
		$this->validator         = $validator;
		$this->review_workflow   = $review_workflow;
	}

	/**
	 * Creates a plugin-owned draft page.
	 *
	 * @param string                         $brief Admin brief.
	 * @param array<int, array<string,mixed>> $widgets Current widgets.
	 * @param string                         $template_slug Optional page template slug.
	 * @param array<int,array<string,mixed>> $sections Optional AI-authored sections.
	 * @param string                         $title Optional AI-supplied page title.
	 * @return int|\WP_Error
	 */
	public function create_draft( string $brief, array $widgets, string $template_slug = '', array $sections = array(), string $title = '', string $engine = '' ): int|\WP_Error {
		$brief  = sanitize_textarea_field( $brief );
		$engine = 'atomic' === sanitize_key( $engine ) ? 'atomic' : 'legacy';

		if ( 'atomic' === $engine && ! Atomic_Builder::is_supported() ) {
			return new \WP_Error( 'aibc_atomic_unsupported', __( 'This site\'s Elementor does not register atomic element types (needs Elementor 4 with atomic elements active). Use the legacy engine.', 'ai-builder-connector' ), array( 'status' => 422 ) );
		}

		if ( '' === trim( $brief ) ) {
			return new \WP_Error( 'aibc_empty_brief', __( 'A brief is required before creating a draft.', 'ai-builder-connector' ) );
		}

		$plan = $this->page_plan_builder->build_plan( $brief, $widgets, $template_slug, $sections );

		if ( empty( $plan['sections'] ) ) {
			$blocked = isset( $plan['blocked_widgets'] ) && is_array( $plan['blocked_widgets'] ) ? $plan['blocked_widgets'] : array();

			return new \WP_Error(
				'aibc_plan_blocked',
				__( 'No allowed widgets are available for the draft plan. Each requested widget must be registered in Elementor and its addon and the widget itself must be allowed.', 'ai-builder-connector' ),
				array( 'blocked_widgets' => $blocked )
			);
		}

		$plan_validation = $this->validator->validate_plan( $plan, $widgets );

		if ( ! $plan_validation->is_valid() ) {
			return $this->validation_error( 'aibc_plan_validation_failed', $plan_validation );
		}

		$title = $this->build_title( '' !== trim( $title ) ? $title : $brief );

		if ( 'atomic' === $engine ) {
			$plan['engine'] = 'atomic';
			$atomic_builder = new Atomic_Builder();
			$elementor_data = $atomic_builder->build_elementor_data( $plan );

			if ( is_wp_error( $elementor_data ) ) {
				return $elementor_data;
			}
		} else {
			$elementor_data = $this->page_plan_builder->build_elementor_data( $plan );
		}

		if ( empty( $elementor_data ) ) {
			return new \WP_Error( 'aibc_elementor_data_empty', __( 'No Elementor data could be generated for the draft plan.', 'ai-builder-connector' ) );
		}

		if ( 'legacy' === $engine ) {
			$data_validation = $this->validator->validate_elementor_data( $elementor_data, $widgets );

			if ( ! $data_validation->is_valid() ) {
				return $this->validation_error( 'aibc_elementor_validation_failed', $data_validation );
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $this->page_plan_builder->build_draft_content( $plan ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_CREATED, '1' );
		update_post_meta( $post_id, self::META_CREATED_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_BRIEF, $brief );
		update_post_meta( $post_id, self::META_PLAN, $this->sanitize_plan( $plan ) );
		update_post_meta( $post_id, self::META_USED_WIDGETS, array_values( (array) ( $plan['used_widgets'] ?? array() ) ) );
		update_post_meta( $post_id, self::META_BLOCKED, array_values( (array) ( $plan['blocked_widgets'] ?? array() ) ) );
		update_post_meta( $post_id, self::META_DESIGN_SYSTEM, $this->sanitize_design_system( (array) ( $plan['design_system'] ?? array() ) ) );
		$this->save_elementor_meta( $post_id, $elementor_data );
		$this->save_validation_result( $post_id, 'pending', array() );
		$this->review_workflow->transition( (int) $post_id, Review_Workflow::STATUS_GENERATED, __( 'Draft generated and awaiting saved-data validation.', 'ai-builder-connector' ) );

		if ( 'atomic' === $engine ) {
			// Atomic local styles are compiled to CSS by Elementor's document
			// save pipeline — a raw meta write renders unstyled. Re-save through
			// the document so the atomic style engine generates the page CSS.
			if ( class_exists( '\Elementor\Plugin' ) ) {
				$document = \Elementor\Plugin::instance()->documents->get( (int) $post_id, false );

				if ( $document ) {
					$document->save( array( 'elements' => $elementor_data ) );
				}

				if ( isset( \Elementor\Plugin::instance()->files_manager ) ) {
					\Elementor\Plugin::instance()->files_manager->clear_cache();
				}
			}

			// Atomic data uses Elementor 4 element types, not the legacy widget
			// registry, so the widget-map validator does not apply. Structure is
			// enforced by Atomic_Builder itself (fixed element set, sanitized text).
			$this->save_validation_result( (int) $post_id, 'passed', array() );
			$this->review_workflow->mark_generated( (int) $post_id, true, __( 'Atomic draft generated and ready for administrator review.', 'ai-builder-connector' ) );

			return (int) $post_id;
		}

		$saved_validation = $this->validator->verify_saved_draft( (int) $post_id, $widgets );

		if ( ! $saved_validation->is_valid() ) {
			$this->save_validation_result( (int) $post_id, 'failed', $saved_validation->get_errors() );
			$this->review_workflow->transition( (int) $post_id, Review_Workflow::STATUS_VALIDATION_FAILED, $this->validation_summary( $saved_validation ) );
			$this->cleanup_failed_generation( (int) $post_id, __( 'Post-save validation failed.', 'ai-builder-connector' ) );

			return $this->validation_error( 'aibc_post_save_validation_failed', $saved_validation );
		}

		$this->save_validation_result( (int) $post_id, 'passed', array() );
		$this->review_workflow->mark_generated( (int) $post_id, true, __( 'Draft generated and ready for administrator review.', 'ai-builder-connector' ) );

		return (int) $post_id;
	}

	/**
	 * Revises an active AIBC draft with new AI-authored sections.
	 *
	 * The current state is snapshotted before mutation. A failed post-save
	 * validation restores the snapshot instead of trashing the draft. Every
	 * successful revision returns the draft to Needs Review.
	 *
	 * @param int                            $post_id Draft page ID.
	 * @param array<int,array<string,mixed>> $widgets Current widgets.
	 * @param array<int,array<string,mixed>> $sections AI-authored sections.
	 * @param string                         $title Optional new AI-supplied title.
	 * @return int|\WP_Error
	 */
	public function revise_draft( int $post_id, array $widgets, array $sections, string $title = '' ): int|\WP_Error {
		if ( $this->is_aibc_published_page( $post_id ) ) {
			return new \WP_Error( 'aibc_published_revise_blocked', __( 'Published AIBC pages cannot be revised. An administrator must unpublish the page first.', 'ai-builder-connector' ) );
		}

		if ( ! $this->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_revise', __( 'Only active AI Builder Connector draft pages can be revised.', 'ai-builder-connector' ) );
		}

		$stored_plan = $this->get_plan( $post_id );

		if ( 'atomic' === (string) ( $stored_plan['engine'] ?? '' ) ) {
			return new \WP_Error( 'aibc_atomic_revise_unsupported', __( 'Atomic drafts cannot be revised in place yet. Delete this draft and create a new atomic draft with the updated sections.', 'ai-builder-connector' ), array( 'status' => 422 ) );
		}

		if ( empty( $sections ) ) {
			return new \WP_Error( 'aibc_revise_sections_required', __( 'At least one AI-authored section is required to revise a draft.', 'ai-builder-connector' ) );
		}

		$brief = get_post_meta( $post_id, self::META_BRIEF, true );
		$brief = is_string( $brief ) ? sanitize_textarea_field( $brief ) : '';

		if ( '' === trim( $brief ) ) {
			return new \WP_Error( 'aibc_revise_missing_brief', __( 'The stored brief for this draft is missing, so it cannot be revised.', 'ai-builder-connector' ) );
		}

		$stored_plan   = $this->get_plan( $post_id );
		$template_slug = sanitize_key( (string) ( $stored_plan['template'] ?? '' ) );

		$plan = $this->page_plan_builder->build_plan( $brief, $widgets, $template_slug, $sections );

		if ( empty( $plan['sections'] ) ) {
			return new \WP_Error( 'aibc_plan_blocked', __( 'No allowed widgets are available for the revised draft plan.', 'ai-builder-connector' ) );
		}

		$plan_validation = $this->validator->validate_plan( $plan, $widgets );

		if ( ! $plan_validation->is_valid() ) {
			return $this->validation_error( 'aibc_revision_plan_validation_failed', $plan_validation );
		}

		$elementor_data = $this->page_plan_builder->build_elementor_data( $plan );

		if ( empty( $elementor_data ) ) {
			return new \WP_Error( 'aibc_elementor_data_empty', __( 'No Elementor data could be generated for the revised draft plan.', 'ai-builder-connector' ) );
		}

		$data_validation = $this->validator->validate_elementor_data( $elementor_data, $widgets );

		if ( ! $data_validation->is_valid() ) {
			return $this->validation_error( 'aibc_revision_elementor_validation_failed', $data_validation );
		}

		$snapshot = $this->create_snapshot( $post_id );

		if ( '' !== trim( $title ) ) {
			$updated = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $this->build_title( $title ),
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		update_post_meta( $post_id, self::META_PLAN, $this->sanitize_plan( $plan ) );
		update_post_meta( $post_id, self::META_USED_WIDGETS, array_values( (array) ( $plan['used_widgets'] ?? array() ) ) );
		update_post_meta( $post_id, self::META_BLOCKED, array_values( (array) ( $plan['blocked_widgets'] ?? array() ) ) );
		update_post_meta( $post_id, self::META_DESIGN_SYSTEM, $this->sanitize_design_system( (array) ( $plan['design_system'] ?? array() ) ) );
		$this->save_elementor_meta( $post_id, $elementor_data );
		$this->save_validation_result( $post_id, 'pending', array() );

		$saved_validation = $this->validator->verify_saved_draft( $post_id, $widgets );

		if ( ! $saved_validation->is_valid() ) {
			$this->apply_snapshot( $post_id, $snapshot );
			$this->review_workflow->transition( $post_id, Review_Workflow::STATUS_NEEDS_REVIEW, __( 'A revision failed post-save validation. The previous version was restored.', 'ai-builder-connector' ) );

			return $this->validation_error( 'aibc_revision_post_save_validation_failed', $saved_validation );
		}

		$this->push_revision( $post_id, $snapshot );
		$this->save_validation_result( $post_id, 'passed', array() );
		$this->review_workflow->mark_generated( $post_id, true, __( 'Draft revised through MCP and needs administrator review again.', 'ai-builder-connector' ) );

		return $post_id;
	}

	/**
	 * Restores the most recent stored revision snapshot for an active draft.
	 */
	public function restore_last_revision( int $post_id, string $note = '' ): bool|\WP_Error {
		if ( $this->is_aibc_published_page( $post_id ) ) {
			return new \WP_Error( 'aibc_published_rollback_blocked', __( 'Published AIBC pages cannot be rolled back. An administrator must unpublish the page first.', 'ai-builder-connector' ) );
		}

		if ( ! $this->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_revision_restore', __( 'Only active AI Builder Connector draft pages can restore a revision.', 'ai-builder-connector' ) );
		}

		$revisions = $this->get_revisions( $post_id );

		if ( empty( $revisions ) ) {
			return new \WP_Error( 'aibc_no_revisions', __( 'This draft has no stored revisions to restore.', 'ai-builder-connector' ) );
		}

		$snapshot = array_pop( $revisions );
		$applied  = $this->apply_snapshot( $post_id, $snapshot );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		update_post_meta( $post_id, self::META_REVISIONS, $revisions );

		if ( '' === $note ) {
			$note = __( 'The previous revision was restored.', 'ai-builder-connector' );
		}

		$this->review_workflow->transition( $post_id, Review_Workflow::STATUS_NEEDS_REVIEW, sanitize_text_field( $note ) );

		return true;
	}

	/**
	 * Gets the stored revision snapshot count for a draft.
	 */
	public function get_revision_count( int $post_id ): int {
		return count( $this->get_revisions( $post_id ) );
	}

	/**
	 * Gets stored blocked widget warnings for a draft.
	 *
	 * @return array<int,array{widget:string,reason:string}>
	 */
	public function get_blocked_widgets( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_BLOCKED, true );

		return is_array( $value ) ? $this->sanitize_blocked_widgets( $value ) : array();
	}

	/**
	 * Publishes an approved, validation-passed AIBC draft. Admin-gated callers only.
	 */
	public function publish_draft( int $post_id ): bool|\WP_Error {
		if ( ! $this->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_publish', __( 'Only active AI Builder Connector draft pages can be published.', 'ai-builder-connector' ) );
		}

		if ( 'passed' !== $this->get_validation_status( $post_id ) ) {
			return new \WP_Error( 'aibc_publish_validation_blocked', __( 'Only validation-passed AIBC drafts can be published.', 'ai-builder-connector' ) );
		}

		$marked = $this->review_workflow->mark_published( $post_id );

		if ( is_wp_error( $marked ) ) {
			return $marked;
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			$this->review_workflow->transition( $post_id, Review_Workflow::STATUS_APPROVED, __( 'Publishing failed. The page remains an approved draft.', 'ai-builder-connector' ) );

			return $result;
		}

		update_post_meta( $post_id, self::META_PUBLISHED_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_PUBLISHED_BY, get_current_user_id() );

		return true;
	}

	/**
	 * Returns a published AIBC page to draft status. Admin-gated callers only.
	 */
	public function unpublish_page( int $post_id ): bool|\WP_Error {
		if ( ! $this->is_aibc_published_page( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_unpublish', __( 'Only published AI Builder Connector pages can be unpublished.', 'ai-builder-connector' ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_post_meta( $post_id, self::META_PUBLISHED_AT );
		delete_post_meta( $post_id, self::META_PUBLISHED_BY );
		$this->review_workflow->mark_unpublished( $post_id );

		return true;
	}

	/**
	 * Rolls back a plugin-created draft by moving it to trash.
	 */
	public function rollback_draft( int $post_id, string $reason = '' ): bool|\WP_Error {
		if ( $this->is_aibc_published_page( $post_id ) ) {
			return new \WP_Error( 'aibc_published_rollback_blocked', __( 'Published AIBC pages cannot be rolled back. An administrator must unpublish the page first.', 'ai-builder-connector' ) );
		}

		if ( ! $this->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_rollback', __( 'Only AI Builder Connector draft pages can be rolled back.', 'ai-builder-connector' ) );
		}

		if ( '' === $reason ) {
			$reason = __( 'Administrator rollback from AI Builder Connector.', 'ai-builder-connector' );
		}

		update_post_meta( $post_id, self::META_ROLLED_BACK_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_ROLLBACK_REASON, sanitize_text_field( $reason ) );
		$this->review_workflow->transition( $post_id, Review_Workflow::STATUS_ROLLED_BACK, $reason );

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			return new \WP_Error( 'aibc_rollback_failed', __( 'The draft could not be moved to trash.', 'ai-builder-connector' ) );
		}

		return true;
	}

	/**
	 * Gets plugin-created drafts.
	 *
	 * @return array<int,\WP_Post>
	 */
	public function get_created_drafts(): array {
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'publish', 'trash' ),
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => self::META_CREATED,
				'meta_value'     => '1',
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Gets one AIBC-created page, including trashed rollback records.
	 */
	/**
	 * Sets SEO meta title/description on an AIBC page (also feeds Yoast when present).
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public function set_seo_meta( int $post_id, string $meta_title, string $meta_description ): array|\WP_Error {
		if ( null === $this->get_aibc_page( $post_id ) ) {
			return new \WP_Error( 'aibc_not_aibc_page', __( 'Only AI Builder pages can be updated.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		$meta_title       = sanitize_text_field( $meta_title );
		$meta_description = sanitize_text_field( $meta_description );

		if ( '' === $meta_title && '' === $meta_description ) {
			return new \WP_Error( 'aibc_seo_empty', __( 'Provide a meta_title, a meta_description, or both.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		if ( '' !== $meta_title ) {
			update_post_meta( $post_id, '_aibc_seo_title', $meta_title );
			update_post_meta( $post_id, '_yoast_wpseo_title', $meta_title );
		}

		if ( '' !== $meta_description ) {
			update_post_meta( $post_id, '_aibc_seo_description', $meta_description );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
		}

		return array(
			'meta_title'       => $meta_title,
			'meta_description' => $meta_description,
		);
	}

	/**
	 * Sets sanitized page-scoped custom CSS on an AIBC page.
	 *
	 * CSS only, by design: no JavaScript, no PHP, no HTML can pass through here.
	 *
	 * @return array<string,int>|\WP_Error
	 */
	public function set_custom_css( int $post_id, string $css ): array|\WP_Error {
		if ( null === $this->get_aibc_page( $post_id ) ) {
			return new \WP_Error( 'aibc_not_aibc_page', __( 'Only AI Builder pages can be updated.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		$css = (string) wp_strip_all_tags( $css );
		$css = str_replace( array( '<', '\\3c', '\\3C' ), '', $css );

		if ( strlen( $css ) > 20000 ) {
			return new \WP_Error( 'aibc_css_too_long', __( 'Custom CSS is limited to 20,000 characters.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		foreach ( array( '@import', 'javascript:', 'expression(', 'behavior:', '-moz-binding' ) as $blocked ) {
			if ( false !== stripos( $css, $blocked ) ) {
				return new \WP_Error(
					'aibc_css_blocked',
					sprintf(
						/* translators: %s: blocked CSS token. */
						__( 'Custom CSS was rejected: "%s" is not allowed.', 'ai-builder-connector' ),
						$blocked
					),
					array( 'status' => 422 )
				);
			}
		}

		update_post_meta( $post_id, '_aibc_custom_css', $css );

		return array( 'css_length' => strlen( $css ) );
	}

	/**
	 * Gets stored custom CSS for a page ('' when none).
	 */
	public function get_custom_css( int $post_id ): string {
		$css = get_post_meta( $post_id, '_aibc_custom_css', true );

		return is_string( $css ) ? $css : '';
	}

	public function get_aibc_page( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );

		if (
			$post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& in_array( $post->post_status, array( 'draft', 'publish', 'trash' ), true )
			&& '1' === get_post_meta( $post_id, self::META_CREATED, true )
		) {
			return $post;
		}

		return null;
	}

	/**
	 * Checks whether a page is an AIBC-created draft.
	 */
	public function is_aibc_draft( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& 'draft' === $post->post_status
			&& '1' === get_post_meta( $post_id, self::META_CREATED, true );
	}

	/**
	 * Checks whether a page is AIBC-owned, including rollback records in trash.
	 */
	public function is_aibc_page( int $post_id ): bool {
		return null !== $this->get_aibc_page( $post_id );
	}

	/**
	 * Checks whether a page is an AIBC-created page that is currently published.
	 */
	public function is_aibc_published_page( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& 'publish' === $post->post_status
			&& '1' === get_post_meta( $post_id, self::META_CREATED, true );
	}

	/**
	 * Validates an active AIBC draft again and updates review state.
	 *
	 * @param array<int,array<string,mixed>> $widgets Current widgets.
	 */
	public function validate_draft_again( int $post_id, array $widgets ): Validation_Result|\WP_Error {
		if ( ! $this->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_validate_again', __( 'Only active AI Builder Connector draft pages can be validated again.', 'ai-builder-connector' ) );
		}

		$result = $this->validator->verify_saved_draft( $post_id, $widgets );

		if ( $result->is_valid() ) {
			$this->save_validation_result( $post_id, 'passed', array() );
			$this->review_workflow->mark_generated( $post_id, true, __( 'Draft validation passed during review.', 'ai-builder-connector' ) );
		} else {
			$this->save_validation_result( $post_id, 'failed', $result->get_errors() );
			$this->review_workflow->mark_generated( $post_id, false, $this->validation_summary( $result ) );
		}

		return $result;
	}

	/**
	 * Permanently deletes an AIBC-owned draft or rollback record.
	 */
	public function delete_ai_draft( int $post_id ): bool|\WP_Error {
		if ( $this->is_aibc_published_page( $post_id ) ) {
			return new \WP_Error( 'aibc_published_delete_blocked', __( 'Published AIBC pages cannot be deleted. An administrator must unpublish the page first.', 'ai-builder-connector' ) );
		}

		if ( ! $this->is_aibc_page( $post_id ) ) {
			return new \WP_Error( 'aibc_invalid_delete', __( 'Only AI Builder Connector draft pages can be deleted here.', 'ai-builder-connector' ) );
		}

		$result = wp_delete_post( $post_id, true );

		if ( ! $result ) {
			return new \WP_Error( 'aibc_delete_failed', __( 'The AI Builder Connector draft could not be deleted.', 'ai-builder-connector' ) );
		}

		return true;
	}

	/**
	 * Gets a brief excerpt.
	 */
	public function get_brief_excerpt( int $post_id ): string {
		$brief = get_post_meta( $post_id, self::META_BRIEF, true );

		return is_string( $brief ) ? wp_trim_words( sanitize_textarea_field( $brief ), 24 ) : '';
	}

	/**
	 * Gets used widgets.
	 *
	 * @return array<int,string>
	 */
	public function get_used_widgets( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_USED_WIDGETS, true );

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
	}

	/**
	 * Gets blocked widget count.
	 */
	public function get_blocked_widget_count( int $post_id ): int {
		$value = get_post_meta( $post_id, self::META_BLOCKED, true );

		return is_array( $value ) ? count( $value ) : 0;
	}

	/**
	 * Checks whether a draft has AIBC-generated Elementor data.
	 */
	public function has_elementor_data( int $post_id ): bool {
		$data = get_post_meta( $post_id, self::META_ELEMENTOR_DATA, true );

		return is_string( $data ) && '' !== $data;
	}

	/**
	 * Gets the recorded publish time for an AIBC page.
	 */
	public function get_published_at( int $post_id ): string {
		$value = get_post_meta( $post_id, self::META_PUBLISHED_AT, true );

		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Gets validation status for an AIBC draft.
	 */
	public function get_validation_status( int $post_id ): string {
		$status = get_post_meta( $post_id, self::META_VALIDATION_STATUS, true );

		return is_string( $status ) && '' !== $status ? sanitize_key( $status ) : 'unknown';
	}

	/**
	 * Gets the saved validation error count.
	 */
	public function get_validation_error_count( int $post_id ): int {
		$errors = get_post_meta( $post_id, self::META_VALIDATION_ERRORS, true );

		return is_array( $errors ) ? count( $errors ) : 0;
	}

	/**
	 * Gets the saved design-system snapshot for a draft.
	 *
	 * @return array<string,mixed>
	 */
	public function get_design_system( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_DESIGN_SYSTEM, true );

		return is_array( $value ) ? $this->sanitize_design_system( $value ) : array();
	}

	/**
	 * Gets the stored page plan.
	 *
	 * @return array<string,mixed>
	 */
	public function get_plan( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_PLAN, true );

		return is_array( $value ) ? $this->sanitize_plan( $value ) : array();
	}

	/**
	 * Builds a safe draft title.
	 */
	private function build_title( string $brief ): string {
		$title = wp_trim_words( sanitize_text_field( $brief ), 12, '' );

		// Strip any existing draft prefix so client-supplied titles are never double-prefixed.
		$title = trim( (string) preg_replace( '/^(?:\s*(?:AIBC\s+Draft|AI\s+Builder\s+Draft)\s*[-\x{2013}\x{2014}:]+\s*)+/iu', '', $title ) );

		if ( '' === $title ) {
			$title = __( 'AI Builder Draft', 'ai-builder-connector' );
		}

		return sprintf(
			/* translators: %s: brief-derived title. */
			__( 'AIBC Draft - %s', 'ai-builder-connector' ),
			$title
		);
	}

	/**
	 * Sanitizes a plan for post meta storage.
	 *
	 * @param array<string,mixed> $plan Raw plan.
	 * @return array<string,mixed>
	 */
	private function sanitize_plan( array $plan ): array {
		$sections = array();

		foreach ( (array) ( $plan['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$row = array(
				'label'         => sanitize_text_field( (string) ( $section['label'] ?? '' ) ),
				'widget'        => sanitize_text_field( (string) ( $section['widget'] ?? '' ) ),
				'source'        => sanitize_text_field( (string) ( $section['source'] ?? '' ) ),
				'section_style' => sanitize_key( (string) ( $section['section_style'] ?? '' ) ),
				'description'   => sanitize_text_field( (string) ( $section['description'] ?? '' ) ),
			);

			if ( isset( $section['settings'] ) && is_array( $section['settings'] ) ) {
				$row['settings'] = $this->sanitize_section_settings( $section['settings'] );
			}

			$sections[] = $row;
		}

		return array(
			'brief'           => sanitize_textarea_field( (string) ( $plan['brief'] ?? '' ) ),
			'engine'          => 'atomic' === sanitize_key( (string) ( $plan['engine'] ?? '' ) ) ? 'atomic' : 'legacy',
			'created_at'      => sanitize_text_field( (string) ( $plan['created_at'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $plan['status'] ?? '' ) ),
			'content_mode'    => sanitize_key( (string) ( $plan['content_mode'] ?? 'template' ) ),
			'template'        => sanitize_key( (string) ( $plan['template'] ?? '' ) ),
			'template_title'  => sanitize_text_field( (string) ( $plan['template_title'] ?? '' ) ),
			'design_system'   => $this->sanitize_design_system( (array) ( $plan['design_system'] ?? array() ) ),
			'sections'        => $sections,
			'used_widgets'    => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $plan['used_widgets'] ?? array() ) ) ) ),
			'blocked_widgets' => $this->sanitize_blocked_widgets( (array) ( $plan['blocked_widgets'] ?? array() ) ),
		);
	}

	/**
	 * Conservatively re-sanitizes stored AI-authored section settings.
	 *
	 * Values were already type-sanitized by Widget_Content_Sanitizer at plan
	 * build time; this pass protects the post meta layer independently.
	 *
	 * @param array<string,mixed> $settings Section settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_section_settings( array $settings ): array {
		$sanitized = array();

		foreach ( $settings as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( 'editor' === $key && is_string( $value ) ) {
				$sanitized[ $key ] = wp_kses_post( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$nested = array();

				foreach ( $value as $nested_key => $nested_value ) {
					$nested_key = sanitize_key( (string) $nested_key );

					if ( '' !== $nested_key && ( is_scalar( $nested_value ) || null === $nested_value ) ) {
						$nested[ $nested_key ] = sanitize_text_field( (string) $nested_value );
					}
				}

				$sanitized[ $key ] = $nested;
			}
		}

		return $sanitized;
	}

	/**
	 * Gets the stored revision snapshots for a draft, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_revisions( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META_REVISIONS, true );

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Builds a snapshot of the draft's current revisable state.
	 *
	 * @return array<string,mixed>
	 */
	private function create_snapshot( int $post_id ): array {
		$post = get_post( $post_id );
		$json = get_post_meta( $post_id, self::META_ELEMENTOR_DATA, true );

		$errors = get_post_meta( $post_id, self::META_VALIDATION_ERRORS, true );

		return array(
			'revised_at'        => gmdate( 'c' ),
			'title'             => $post instanceof \WP_Post ? sanitize_text_field( $post->post_title ) : '',
			'elementor_json'    => is_string( $json ) ? $json : '',
			'plan'              => $this->get_plan( $post_id ),
			'used_widgets'      => $this->get_used_widgets( $post_id ),
			'blocked_widgets'   => $this->get_blocked_widgets( $post_id ),
			'design_system'     => $this->get_design_system( $post_id ),
			'validation_status' => $this->get_validation_status( $post_id ),
			'validation_errors' => is_array( $errors ) ? $this->sanitize_validation_errors( $errors ) : array(),
		);
	}

	/**
	 * Applies a stored snapshot back onto the draft.
	 *
	 * @param array<string,mixed> $snapshot Snapshot from create_snapshot().
	 */
	private function apply_snapshot( int $post_id, array $snapshot ): bool|\WP_Error {
		$title = sanitize_text_field( (string) ( $snapshot['title'] ?? '' ) );

		if ( '' !== $title ) {
			$updated = wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $title,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$json = (string) ( $snapshot['elementor_json'] ?? '' );

		if ( '' !== $json ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_unslash( $json ) ) );
			update_post_meta( $post_id, self::META_ELEMENTOR_DATA, wp_slash( wp_unslash( $json ) ) );
			$this->clear_elementor_render_cache( $post_id );
		}

		$plan = is_array( $snapshot['plan'] ?? null ) ? $snapshot['plan'] : array();
		update_post_meta( $post_id, self::META_PLAN, $this->sanitize_plan( $plan ) );
		update_post_meta( $post_id, self::META_USED_WIDGETS, array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $snapshot['used_widgets'] ?? array() ) ) ) ) );
		update_post_meta( $post_id, self::META_BLOCKED, $this->sanitize_blocked_widgets( (array) ( $snapshot['blocked_widgets'] ?? array() ) ) );
		update_post_meta( $post_id, self::META_DESIGN_SYSTEM, $this->sanitize_design_system( (array) ( $snapshot['design_system'] ?? array() ) ) );
		$this->save_validation_result(
			$post_id,
			sanitize_key( (string) ( $snapshot['validation_status'] ?? 'unknown' ) ),
			is_array( $snapshot['validation_errors'] ?? null ) ? $snapshot['validation_errors'] : array()
		);

		return true;
	}

	/**
	 * Pushes a snapshot onto the capped revision history.
	 *
	 * @param array<string,mixed> $snapshot Snapshot from create_snapshot().
	 */
	private function push_revision( int $post_id, array $snapshot ): void {
		$revisions   = $this->get_revisions( $post_id );
		$revisions[] = $snapshot;

		if ( count( $revisions ) > self::MAX_REVISIONS ) {
			$revisions = array_slice( $revisions, -self::MAX_REVISIONS );
		}

		update_post_meta( $post_id, self::META_REVISIONS, $revisions );
	}

	/**
	 * Saves Elementor metadata for an editable Elementor draft.
	 *
	 * @param int                         $post_id Post ID.
	 * @param array<int,array<string,mixed>> $elementor_data Elementor data.
	 */
	private function save_elementor_meta( int $post_id, array $elementor_data ): void {
		$json = wp_json_encode( $elementor_data );

		if ( ! is_string( $json ) ) {
			return;
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, self::META_ELEMENTOR_DATA, wp_slash( $json ) );
		$this->clear_elementor_render_cache( $post_id );
	}

	/**
	 * Clears Elementor's regenerable render caches after a direct data write.
	 *
	 * Elementor caches rendered element markup and generated CSS per post.
	 * Writing _elementor_data directly does not invalidate them, so revised
	 * or restored content would keep rendering the stale cached markup.
	 */
	private function clear_elementor_render_cache( int $post_id ): void {
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			} catch ( \Throwable $e ) {
				// Cache file deletion is best-effort; meta deletion above already forces regeneration.
				unset( $e );
			}
		}
	}

	/**
	 * Saves validation status and errors.
	 *
	 * @param int                                                          $post_id Post ID.
	 * @param string                                                       $status Validation status.
	 * @param array<int,array{code:string,message:string,context:array<string,mixed>}> $errors Validation errors.
	 */
	private function save_validation_result( int $post_id, string $status, array $errors ): void {
		update_post_meta( $post_id, self::META_VALIDATION_STATUS, sanitize_key( $status ) );
		update_post_meta( $post_id, self::META_VALIDATION_ERRORS, $this->sanitize_validation_errors( $errors ) );
	}

	/**
	 * Moves a failed generated draft out of the active draft list.
	 */
	private function cleanup_failed_generation( int $post_id, string $reason ): void {
		update_post_meta( $post_id, self::META_ROLLED_BACK_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_ROLLBACK_REASON, sanitize_text_field( $reason ) );
		wp_trash_post( $post_id );
	}

	/**
	 * Builds a WP_Error from a validation result.
	 */
	private function validation_error( string $code, Validation_Result $result ): \WP_Error {
		$message = $this->validation_summary( $result );

		return new \WP_Error( $code, $message );
	}

	/**
	 * Builds a compact validation summary.
	 */
	private function validation_summary( Validation_Result $result ): string {
		$messages = $result->get_error_messages();

		return ! empty( $messages ) ? implode( ' ', array_slice( $messages, 0, 3 ) ) : __( 'Validation failed.', 'ai-builder-connector' );
	}

	/**
	 * Sanitizes validation errors for post meta.
	 *
	 * @param array<int,array{code:string,message:string,context:array<string,mixed>}> $errors Raw errors.
	 * @return array<int,array{code:string,message:string,context:array<string,string>}>
	 */
	private function sanitize_validation_errors( array $errors ): array {
		$sanitized = array();

		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}

			$context = array();

			foreach ( (array) ( $error['context'] ?? array() ) as $key => $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$context[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
				}
			}

			$sanitized[] = array(
				'code'    => sanitize_key( (string) ( $error['code'] ?? '' ) ),
				'message' => sanitize_text_field( (string) ( $error['message'] ?? '' ) ),
				'context' => $context,
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitizes blocked widget warnings for post meta.
	 *
	 * @param array<int,mixed> $blocked_widgets Raw blocked widget rows.
	 * @return array<int,array{widget:string,reason:string}>
	 */
	private function sanitize_blocked_widgets( array $blocked_widgets ): array {
		$sanitized = array();

		foreach ( $blocked_widgets as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$sanitized[] = array(
				'widget' => sanitize_text_field( (string) ( $item['widget'] ?? '' ) ),
				'reason' => sanitize_text_field( (string) ( $item['reason'] ?? '' ) ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitizes a saved design-system token snapshot.
	 *
	 * @param array<string,mixed> $tokens Raw tokens.
	 * @return array<string,mixed>
	 */
	private function sanitize_design_system( array $tokens ): array {
		$sanitized = array();

		foreach ( $tokens as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				$sanitized[ $key ] = (int) $value;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$nested = array();

				foreach ( $value as $nested_key => $nested_value ) {
					$nested_key = sanitize_key( (string) $nested_key );

					if ( '' === $nested_key ) {
						continue;
					}

					if ( is_numeric( $nested_value ) ) {
						$nested[ $nested_key ] = (int) $nested_value;
					} elseif ( is_scalar( $nested_value ) || null === $nested_value ) {
						$nested[ $nested_key ] = sanitize_text_field( (string) $nested_value );
					} elseif ( is_array( $nested_value ) ) {
						$nested[ $nested_key ] = array_values( array_filter( array_map( 'absint', $nested_value ) ) );
					}
				}

				$sanitized[ $key ] = $nested;
			}
		}

		return $sanitized;
	}
}
