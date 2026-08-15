<?php
/**
 * Review workflow service.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and exposes draft review states.
 */
final class Review_Workflow {
	public const META_STATUS      = '_aibc_review_status';
	public const META_NOTE        = '_aibc_review_note';
	public const META_REVIEWED_AT = '_aibc_reviewed_at';
	public const META_REVIEWED_BY = '_aibc_reviewed_by';

	public const STATUS_PLANNED           = 'planned';
	public const STATUS_GENERATING        = 'generating';
	public const STATUS_GENERATED         = 'generated';
	public const STATUS_VALIDATION_FAILED = 'validation_failed';
	public const STATUS_NEEDS_REVIEW      = 'needs_review';
	public const STATUS_APPROVED          = 'approved';
	public const STATUS_REJECTED          = 'rejected';
	public const STATUS_ROLLED_BACK       = 'rolled_back';
	public const STATUS_FAILED            = 'failed';
	public const STATUS_PUBLISHED         = 'published';

	/**
	 * Gets supported review status labels.
	 *
	 * @return array<string,string>
	 */
	public function status_labels(): array {
		return array(
			self::STATUS_PLANNED           => __( 'Planned', 'ai-builder-connector' ),
			self::STATUS_GENERATING        => __( 'Generating', 'ai-builder-connector' ),
			self::STATUS_GENERATED         => __( 'Generated', 'ai-builder-connector' ),
			self::STATUS_VALIDATION_FAILED => __( 'Validation Failed', 'ai-builder-connector' ),
			self::STATUS_NEEDS_REVIEW      => __( 'Needs Review', 'ai-builder-connector' ),
			self::STATUS_APPROVED          => __( 'Approved', 'ai-builder-connector' ),
			self::STATUS_REJECTED          => __( 'Rejected', 'ai-builder-connector' ),
			self::STATUS_ROLLED_BACK       => __( 'Rolled Back', 'ai-builder-connector' ),
			self::STATUS_FAILED            => __( 'Failed', 'ai-builder-connector' ),
			self::STATUS_PUBLISHED         => __( 'Published', 'ai-builder-connector' ),
		);
	}

	/**
	 * Gets one normalized status label.
	 */
	public function status_label( string $status ): string {
		$status = $this->normalize_status( $status );
		$labels = $this->status_labels();

		return $labels[ $status ] ?? $labels[ self::STATUS_NEEDS_REVIEW ];
	}

	/**
	 * Gets public review state for an AIBC page.
	 *
	 * @return array<string,mixed>
	 */
	public function get_state( int $post_id ): array {
		$status = $this->get_status( $post_id );

		return array(
			'status'      => $status,
			'label'       => $this->status_label( $status ),
			'note'        => $this->get_note( $post_id ),
			'reviewed_at' => sanitize_text_field( (string) get_post_meta( $post_id, self::META_REVIEWED_AT, true ) ),
			'reviewed_by' => absint( get_post_meta( $post_id, self::META_REVIEWED_BY, true ) ),
		);
	}

	/**
	 * Gets the current review status.
	 */
	public function get_status( int $post_id ): string {
		$status = get_post_meta( $post_id, self::META_STATUS, true );

		if ( is_string( $status ) && '' !== $status ) {
			return $this->normalize_status( $status );
		}

		if ( '' !== (string) get_post_meta( $post_id, Draft_Manager::META_ROLLED_BACK_AT, true ) ) {
			return self::STATUS_ROLLED_BACK;
		}

		$validation_status = get_post_meta( $post_id, Draft_Manager::META_VALIDATION_STATUS, true );

		if ( 'failed' === sanitize_key( (string) $validation_status ) ) {
			return self::STATUS_VALIDATION_FAILED;
		}

		return self::STATUS_NEEDS_REVIEW;
	}

	/**
	 * Saves a review transition.
	 */
	public function transition( int $post_id, string $status, string $note = '' ): bool|\WP_Error {
		if ( ! $this->is_aibc_page( $post_id ) ) {
			return new \WP_Error( 'aibc_review_invalid_draft', __( 'Only AI Builder Connector pages can enter review workflow.', 'ai-builder-connector' ) );
		}

		$status = $this->normalize_status( $status );

		update_post_meta( $post_id, self::META_STATUS, $status );
		update_post_meta( $post_id, self::META_NOTE, sanitize_textarea_field( $note ) );
		update_post_meta( $post_id, self::META_REVIEWED_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_REVIEWED_BY, get_current_user_id() );

		return true;
	}

	/**
	 * Marks a generated draft according to validation result.
	 */
	public function mark_generated( int $post_id, bool $valid, string $note = '' ): void {
		$status = $valid ? self::STATUS_NEEDS_REVIEW : self::STATUS_VALIDATION_FAILED;
		$this->transition( $post_id, $status, $note );
	}

	/**
	 * Marks a draft as approved without publishing it.
	 */
	public function approve( int $post_id, string $note = '' ): bool|\WP_Error {
		if ( 'passed' !== sanitize_key( (string) get_post_meta( $post_id, Draft_Manager::META_VALIDATION_STATUS, true ) ) ) {
			return new \WP_Error( 'aibc_review_approval_blocked', __( 'Only validation-passed AIBC drafts can be approved.', 'ai-builder-connector' ) );
		}

		return $this->transition( $post_id, self::STATUS_APPROVED, $note );
	}

	/**
	 * Marks a draft as rejected without deleting it.
	 */
	public function reject( int $post_id, string $note = '' ): bool|\WP_Error {
		return $this->transition( $post_id, self::STATUS_REJECTED, $note );
	}

	/**
	 * Marks an approved AIBC page as published by an administrator.
	 */
	public function mark_published( int $post_id, string $note = '' ): bool|\WP_Error {
		if ( self::STATUS_APPROVED !== $this->get_status( $post_id ) ) {
			return new \WP_Error( 'aibc_review_publish_blocked', __( 'Only approved AIBC drafts can be marked as published.', 'ai-builder-connector' ) );
		}

		if ( '' === $note ) {
			$note = __( 'Published by an administrator from AI Builder Connector.', 'ai-builder-connector' );
		}

		return $this->transition( $post_id, self::STATUS_PUBLISHED, $note );
	}

	/**
	 * Returns a published AIBC page to the approved review state.
	 */
	public function mark_unpublished( int $post_id, string $note = '' ): bool|\WP_Error {
		if ( self::STATUS_PUBLISHED !== $this->get_status( $post_id ) ) {
			return new \WP_Error( 'aibc_review_unpublish_blocked', __( 'Only published AIBC pages can be unpublished.', 'ai-builder-connector' ) );
		}

		if ( '' === $note ) {
			$note = __( 'Unpublished by an administrator and returned to draft.', 'ai-builder-connector' );
		}

		return $this->transition( $post_id, self::STATUS_APPROVED, $note );
	}

	/**
	 * Gets a saved review note.
	 */
	public function get_note( int $post_id ): string {
		$note = get_post_meta( $post_id, self::META_NOTE, true );

		return is_string( $note ) ? sanitize_textarea_field( $note ) : '';
	}

	/**
	 * Normalizes review status values.
	 */
	public function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return array_key_exists( $status, $this->status_labels() ) ? $status : self::STATUS_NEEDS_REVIEW;
	}

	/**
	 * Checks whether a post is AIBC-owned.
	 */
	private function is_aibc_page( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& '1' === get_post_meta( $post_id, Draft_Manager::META_CREATED, true );
	}
}
