<?php
/**
 * Template registry.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides deterministic page and section templates for draft plans.
 */
final class Template_Registry {
	/**
	 * Lists page templates.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_page_templates(): array {
		return array_values(
			array_map(
				array( $this, 'public_template' ),
				$this->page_templates()
			)
		);
	}

	/**
	 * Gets a page template by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_page_template( string $slug ): ?array {
		$slug      = sanitize_key( $slug );
		$templates = $this->page_templates();

		return isset( $templates[ $slug ] ) ? $this->public_template( $templates[ $slug ] ) : null;
	}

	/**
	 * Gets the default template slug.
	 */
	public function default_page_template(): string {
		return 'homepage';
	}

	/**
	 * Gets a safe template slug, falling back to the default.
	 */
	public function resolve_page_template_slug( string $slug ): string {
		$slug = sanitize_key( $slug );

		return isset( $this->page_templates()[ $slug ] ) ? $slug : $this->default_page_template();
	}

	/**
	 * Gets section definitions for a page template.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_page_sections( string $slug ): array {
		$template = $this->get_page_template( $this->resolve_page_template_slug( $slug ) );

		return is_array( $template ) ? (array) ( $template['sections'] ?? array() ) : array();
	}

	/**
	 * Gets available section templates.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_section_templates(): array {
		return array_values(
			array_map(
				array( $this, 'public_section_template' ),
				$this->section_templates()
			)
		);
	}

	/**
	 * Builds public page template metadata.
	 *
	 * @param array<string,mixed> $template Raw template.
	 * @return array<string,mixed>
	 */
	private function public_template( array $template ): array {
		$sections = array();

		foreach ( (array) ( $template['sections'] ?? array() ) as $section_slug ) {
			$section_slug = sanitize_key( (string) $section_slug );
			$section      = $this->section_templates()[ $section_slug ] ?? null;

			if ( is_array( $section ) ) {
				$sections[] = $this->public_section_template( $section );
			}
		}

		return array(
			'slug'                => sanitize_key( (string) ( $template['slug'] ?? '' ) ),
			'title'               => sanitize_text_field( (string) ( $template['title'] ?? '' ) ),
			'description'         => sanitize_text_field( (string) ( $template['description'] ?? '' ) ),
			'required_fields'     => $this->sanitize_string_list( $template['required_fields'] ?? array() ),
			'recommended_widgets' => $this->template_widgets( $sections ),
			'responsive_behavior' => sanitize_text_field( (string) ( $template['responsive_behavior'] ?? '' ) ),
			'validation_rules'    => $this->sanitize_string_list( $template['validation_rules'] ?? array() ),
			'sections'            => $sections,
		);
	}

	/**
	 * Builds public section template metadata.
	 *
	 * @param array<string,mixed> $section Raw section.
	 * @return array<string,mixed>
	 */
	private function public_section_template( array $section ): array {
		return array(
			'slug'                => sanitize_key( (string) ( $section['slug'] ?? '' ) ),
			'title'               => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
			'purpose'             => sanitize_text_field( (string) ( $section['purpose'] ?? '' ) ),
			'allowed_layouts'     => $this->sanitize_string_list( $section['allowed_layouts'] ?? array() ),
			'required_fields'     => $this->sanitize_string_list( $section['required_fields'] ?? array() ),
			'recommended_widgets' => $this->sanitize_string_list( $section['recommended_widgets'] ?? array() ),
			'responsive_behavior' => sanitize_text_field( (string) ( $section['responsive_behavior'] ?? '' ) ),
			'validation_rules'    => $this->sanitize_string_list( $section['validation_rules'] ?? array() ),
		);
	}

	/**
	 * Defines page templates.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function page_templates(): array {
		return array(
			'homepage'     => array(
				'slug'                => 'homepage',
				'title'               => __( 'Homepage', 'ai-builder-connector' ),
				'description'         => __( 'A draft homepage structure with hero, services, proof, FAQ, and CTA sections.', 'ai-builder-connector' ),
				'required_fields'     => array( 'headline', 'intro', 'primary_call_to_action' ),
				'sections'            => array( 'hero', 'services', 'testimonials', 'faq', 'cta' ),
				'responsive_behavior' => __( 'Single-column stacking on mobile with preserved section order.', 'ai-builder-connector' ),
				'validation_rules'    => array( 'must_use_allowed_widgets', 'must_remain_draft', 'must_include_design_system' ),
			),
			'about-page'   => array(
				'slug'                => 'about-page',
				'title'               => __( 'About Page', 'ai-builder-connector' ),
				'description'         => __( 'A review draft for company story, trust markers, and a closing CTA.', 'ai-builder-connector' ),
				'required_fields'     => array( 'headline', 'story_summary', 'primary_call_to_action' ),
				'sections'            => array( 'hero', 'about', 'features', 'cta' ),
				'responsive_behavior' => __( 'Story and feature blocks stack vertically on mobile.', 'ai-builder-connector' ),
				'validation_rules'    => array( 'must_use_allowed_widgets', 'must_remain_draft', 'must_include_design_system' ),
			),
			'services-page' => array(
				'slug'                => 'services-page',
				'title'               => __( 'Services Page', 'ai-builder-connector' ),
				'description'         => __( 'A service-focused draft with overview, service blocks, FAQ, and CTA.', 'ai-builder-connector' ),
				'required_fields'     => array( 'headline', 'service_summary', 'primary_call_to_action' ),
				'sections'            => array( 'hero', 'services', 'features', 'faq', 'cta' ),
				'responsive_behavior' => __( 'Service blocks collapse into a readable mobile sequence.', 'ai-builder-connector' ),
				'validation_rules'    => array( 'must_use_allowed_widgets', 'must_remain_draft', 'must_include_design_system' ),
			),
			'contact-page'  => array(
				'slug'                => 'contact-page',
				'title'               => __( 'Contact Page', 'ai-builder-connector' ),
				'description'         => __( 'A contact-oriented draft with intro copy and next-step call to action.', 'ai-builder-connector' ),
				'required_fields'     => array( 'headline', 'contact_summary', 'primary_call_to_action' ),
				'sections'            => array( 'hero', 'contact', 'faq' ),
				'responsive_behavior' => __( 'Contact details and support copy stack for mobile review.', 'ai-builder-connector' ),
				'validation_rules'    => array( 'must_use_allowed_widgets', 'must_remain_draft', 'must_include_design_system' ),
			),
			'landing-page'  => array(
				'slug'                => 'landing-page',
				'title'               => __( 'Landing Page', 'ai-builder-connector' ),
				'description'         => __( 'A concise campaign draft with hero, benefits, proof, pricing, and CTA.', 'ai-builder-connector' ),
				'required_fields'     => array( 'offer', 'audience', 'primary_call_to_action' ),
				'sections'            => array( 'hero', 'features', 'pricing', 'testimonials', 'cta' ),
				'responsive_behavior' => __( 'Conversion sections remain ordered and scannable on mobile.', 'ai-builder-connector' ),
				'validation_rules'    => array( 'must_use_allowed_widgets', 'must_remain_draft', 'must_include_design_system' ),
			),
		);
	}

	/**
	 * Defines section templates.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function section_templates(): array {
		return array(
			'hero'         => $this->section( 'hero', __( 'Hero', 'ai-builder-connector' ), __( 'Introduce the page promise and primary action.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'button', 'image' ), array( 'headline', 'intro', 'primary_call_to_action' ) ),
			'features'     => $this->section( 'features', __( 'Features', 'ai-builder-connector' ), __( 'Summarize important differentiators.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'button' ), array( 'feature_summary' ) ),
			'services'     => $this->section( 'services', __( 'Services', 'ai-builder-connector' ), __( 'Present service categories for review.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'button' ), array( 'service_summary' ) ),
			'about'        => $this->section( 'about', __( 'About', 'ai-builder-connector' ), __( 'Explain the organisation or project background.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'image' ), array( 'story_summary' ) ),
			'testimonials' => $this->section( 'testimonials', __( 'Testimonials', 'ai-builder-connector' ), __( 'Reserve space for social proof or review copy.', 'ai-builder-connector' ), array( 'heading', 'text-editor' ), array( 'proof_summary' ) ),
			'pricing'      => $this->section( 'pricing', __( 'Pricing', 'ai-builder-connector' ), __( 'Outline offer tiers or pricing placeholders.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'button' ), array( 'offer_summary' ) ),
			'faq'          => $this->section( 'faq', __( 'FAQ', 'ai-builder-connector' ), __( 'Answer common review questions.', 'ai-builder-connector' ), array( 'heading', 'text-editor' ), array( 'question_summary' ) ),
			'cta'          => $this->section( 'cta', __( 'CTA', 'ai-builder-connector' ), __( 'Close with a clear next step.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'button' ), array( 'primary_call_to_action' ) ),
			'contact'      => $this->section( 'contact', __( 'Contact', 'ai-builder-connector' ), __( 'Provide a safe contact placeholder without media or form integration.', 'ai-builder-connector' ), array( 'heading', 'text-editor', 'button' ), array( 'contact_summary' ) ),
		);
	}

	/**
	 * Builds one section template row.
	 *
	 * @param array<int,string> $widgets Recommended widgets.
	 * @param array<int,string> $fields Required fields.
	 * @return array<string,mixed>
	 */
	private function section( string $slug, string $title, string $purpose, array $widgets, array $fields ): array {
		return array(
			'slug'                => $slug,
			'title'               => $title,
			'purpose'             => $purpose,
			'allowed_layouts'     => array( 'single-column', 'two-column' ),
			'required_fields'     => $fields,
			'recommended_widgets' => $widgets,
			'responsive_behavior' => __( 'Use a single-column mobile stack with readable spacing.', 'ai-builder-connector' ),
			'validation_rules'    => array( 'allowed_widgets_only', 'safe_settings_only', 'design_tokens_required' ),
		);
	}

	/**
	 * Builds unique widget list from section rows.
	 *
	 * @param array<int,array<string,mixed>> $sections Public section rows.
	 * @return array<int,string>
	 */
	private function template_widgets( array $sections ): array {
		$widgets = array();

		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['recommended_widgets'] ?? array() ) as $widget ) {
				$widget = sanitize_text_field( (string) $widget );

				if ( '' !== $widget ) {
					$widgets[] = $widget;
				}
			}
		}

		return array_values( array_unique( $widgets ) );
	}

	/**
	 * Sanitizes string lists.
	 *
	 * @param mixed $items Raw items.
	 * @return array<int,string>
	 */
	private function sanitize_string_list( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$strings = array();

		foreach ( $items as $item ) {
			if ( is_scalar( $item ) ) {
				$strings[] = sanitize_text_field( (string) $item );
			}
		}

		return array_values( array_filter( $strings ) );
	}
}
