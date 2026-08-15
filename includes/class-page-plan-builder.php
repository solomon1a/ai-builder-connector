<?php
/**
 * Page plan builder.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a deterministic local draft plan from an admin brief.
 */
final class Page_Plan_Builder {
	/**
	 * Permission manager.
	 *
	 * @var Permission_Manager
	 */
	private Permission_Manager $permission_manager;

	/**
	 * Design system.
	 *
	 * @var Design_System
	 */
	private Design_System $design_system;

	/**
	 * Template registry.
	 *
	 * @var Template_Registry
	 */
	private Template_Registry $template_registry;

	/**
	 * Verified widget definitions.
	 *
	 * @var Widget_Definition_Registry
	 */
	private Widget_Definition_Registry $widget_definitions;

	/**
	 * AI content sanitizer.
	 *
	 * @var Widget_Content_Sanitizer
	 */
	private Widget_Content_Sanitizer $content_sanitizer;

	private const MAX_AUTHORED_SECTIONS = 20;

	/**
	 * Allowed AI-selectable section style presets.
	 */
	private const SECTION_STYLES = array( 'light', 'muted', 'dark', 'brand', 'gradient' );

	/**
	 * Page plan builder constructor.
	 */
	public function __construct( Permission_Manager $permission_manager, Design_System $design_system, Template_Registry $template_registry, Widget_Definition_Registry $widget_definitions, Widget_Content_Sanitizer $content_sanitizer ) {
		$this->permission_manager = $permission_manager;
		$this->design_system      = $design_system;
		$this->template_registry  = $template_registry;
		$this->widget_definitions = $widget_definitions;
		$this->content_sanitizer  = $content_sanitizer;
	}

	/**
	 * Builds a safe plan preview from currently scanned widgets.
	 *
	 * @param string                         $brief Admin brief.
	 * @param array<int, array<string,mixed>> $widgets Current widgets.
	 * @return array<string,mixed>
	 */
	public function build_plan( string $brief, array $widgets, string $template_slug = '', array $authored_sections = array() ): array {
		$brief = sanitize_textarea_field( $brief );
		$template_slug = $this->template_registry->resolve_page_template_slug( $template_slug );
		$template      = $this->template_registry->get_page_template( $template_slug );
		$authored      = $this->normalize_authored_sections( $authored_sections );
		$content_mode  = ! empty( $authored ) ? 'ai' : 'template';
		$requested     = ! empty( $authored ) ? $authored : $this->template_requests( is_array( $template ) ? $template : array() );

		$widget_map = $this->index_widgets( $widgets );
		$sections   = array();
		$blocked    = array();
		$used       = array();

		foreach ( $requested as $request ) {
			$widget_name = sanitize_text_field( (string) ( $request['widget'] ?? '' ) );
			$purpose     = sanitize_text_field( (string) ( $request['label'] ?? '' ) );

			if ( '' === $widget_name ) {
				continue;
			}

			if ( ! isset( $widget_map[ $widget_name ] ) ) {
				$blocked[] = array(
					'widget' => $widget_name,
					'reason' => __( 'Widget is not registered in the current Elementor scan.', 'ai-builder-connector' ),
				);
				continue;
			}

			$widget = $widget_map[ $widget_name ];

			if ( ! $this->permission_manager->is_widget_allowed( $widget ) ) {
				$blocked[] = array(
					'widget' => $widget_name,
					'reason' => __( 'Widget or source addon is not currently allowed.', 'ai-builder-connector' ),
				);
				continue;
			}

			$style = sanitize_key( (string) ( $request['section_style'] ?? '' ) );

			$section = array(
				'label'         => '' !== $purpose ? $purpose : sanitize_text_field( (string) ( $widget['title'] ?? $widget_name ) ),
				'widget'        => $widget_name,
				'source'        => sanitize_text_field( (string) ( $widget['source_name'] ?? '' ) ),
				'section_style' => in_array( $style, self::SECTION_STYLES, true ) ? $style : '',
				'description'   => '' !== sanitize_text_field( (string) ( $request['description'] ?? '' ) )
					? sanitize_text_field( (string) $request['description'] )
					: $this->describe_section( $widget_name, $brief, $purpose ),
			);

			if ( ! empty( $request['content'] ) && is_array( $request['content'] ) ) {
				$sanitized = $this->content_sanitizer->sanitize_content( $widget_name, $request['content'] );

				if ( ! empty( $sanitized['settings'] ) ) {
					$section['settings'] = $sanitized['settings'];
				}

				foreach ( $sanitized['rejected'] as $rejection ) {
					$blocked[] = array(
						'widget' => $widget_name,
						'reason' => sprintf(
							/* translators: 1: rejected setting key, 2: rejection reason. */
							__( 'Content setting "%1$s" was dropped: %2$s', 'ai-builder-connector' ),
							sanitize_key( (string) ( $rejection['setting'] ?? '' ) ),
							sanitize_text_field( (string) ( $rejection['reason'] ?? '' ) )
						),
					);
				}
			}

			$used[]     = $widget_name;
			$sections[] = $section;
		}

		// Only substitute placeholder widgets in template mode. In AI-authored mode
		// the AI asked for specific widgets; if they were all blocked, report that
		// honestly instead of silently building a draft from unrelated widgets.
		if ( empty( $sections ) && 'ai' !== $content_mode ) {
			foreach ( $widget_map as $widget ) {
				if ( ! $this->permission_manager->is_widget_allowed( $widget ) ) {
					continue;
				}

				$widget_name = sanitize_text_field( (string) ( $widget['name'] ?? '' ) );

				if ( '' === $widget_name ) {
					continue;
				}

				$used[] = $widget_name;
				$sections[] = array(
					'label'       => __( 'Allowed widget placeholder', 'ai-builder-connector' ),
					'widget'      => $widget_name,
					'source'      => sanitize_text_field( (string) ( $widget['source_name'] ?? '' ) ),
					'description' => __( 'Use this allowed widget only as a review-safe placeholder in the draft plan.', 'ai-builder-connector' ),
				);

				if ( count( $sections ) >= 3 ) {
					break;
				}
			}
		}

		return array(
			'brief'           => $brief,
			'created_at'      => gmdate( 'c' ),
			'status'          => empty( $sections ) ? 'blocked' : 'ready',
			'content_mode'    => $content_mode,
			'template'        => $template_slug,
			'template_title'  => is_array( $template ) ? sanitize_text_field( (string) ( $template['title'] ?? '' ) ) : '',
			'design_system'   => $this->design_system->get_tokens(),
			'sections'        => $sections,
			'used_widgets'    => array_values( array_unique( $used ) ),
			'blocked_widgets' => $blocked,
		);
	}

	/**
	 * Builds draft page content from a plan.
	 *
	 * @param array<string,mixed> $plan Draft plan.
	 */
	public function build_draft_content( array $plan ): string {
		$brief    = isset( $plan['brief'] ) ? sanitize_textarea_field( (string) $plan['brief'] ) : '';
		$sections = isset( $plan['sections'] ) && is_array( $plan['sections'] ) ? $plan['sections'] : array();

		$content = "<!-- wp:heading -->\n<h2>" . esc_html__( 'AI Builder Connector Draft Plan', 'ai-builder-connector' ) . "</h2>\n<!-- /wp:heading -->\n\n";

		if ( '' !== $brief ) {
			$content .= "<!-- wp:paragraph -->\n<p>" . esc_html( $brief ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}

		foreach ( $sections as $section ) {
			$label       = isset( $section['label'] ) ? sanitize_text_field( (string) $section['label'] ) : '';
			$description = isset( $section['description'] ) ? sanitize_text_field( (string) $section['description'] ) : '';

			$content .= "<!-- wp:heading {\"level\":3} -->\n<h3>" . esc_html( $label ) . "</h3>\n<!-- /wp:heading -->\n\n";
			$content .= "<!-- wp:paragraph -->\n<p>" . esc_html( $description ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}

		return $content;
	}

	/**
	 * Builds minimal Elementor data for an editable draft page.
	 *
	 * @param array<string,mixed> $plan Draft plan.
	 * @return array<int,array<string,mixed>>
	 */
	public function build_elementor_data( array $plan ): array {
		$sections = isset( $plan['sections'] ) && is_array( $plan['sections'] ) ? $plan['sections'] : array();
		$tokens   = $this->plan_design_tokens( $plan );

		$groups = $this->group_sections( $sections );

		if ( empty( $groups ) ) {
			return array();
		}

		$out   = array();
		$index = 0;

		foreach ( $groups as $group ) {
			$section = $this->build_visual_section( $group, $index, $plan, $tokens );

			if ( ! empty( $section ) ) {
				$out[]  = $section;
				$index++;
			}
		}

		return $out;
	}

	/**
	 * Groups a flat list of widget requests into visual sections.
	 *
	 * Widgets are grouped by their section label. When no labels are provided,
	 * each new heading (after the first) starts a fresh section.
	 *
	 * @param array<int,array<string,mixed>> $sections Flat widget requests.
	 * @return array<int,array<string,mixed>>
	 */
	private function group_sections( array $sections ): array {
		$groups  = array();
		$current = null;

		foreach ( $sections as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$widget = sanitize_text_field( (string) ( $item['widget'] ?? '' ) );

			if ( '' === $widget ) {
				continue;
			}

			$label      = trim( (string) ( $item['label'] ?? '' ) );
			$is_heading = ( 'heading' === $widget );
			$start_new  = false;

			if ( null === $current ) {
				$start_new = true;
			} elseif ( '' !== $label && '' !== $current['label'] && $label !== $current['label'] ) {
				$start_new = true;
			} elseif ( '' === $label && '' === $current['label'] && $is_heading && $current['has_heading'] ) {
				$start_new = true;
			}

			$item_style = sanitize_key( (string) ( $item['section_style'] ?? '' ) );

			if ( $start_new ) {
				if ( null !== $current ) {
					$groups[] = $current;
				}

				$current = array(
					'label'       => $label,
					'items'       => array( $item ),
					'has_heading' => $is_heading,
					'style'       => $item_style,
				);
			} else {
				$current['items'][]     = $item;
				$current['has_heading'] = $current['has_heading'] || $is_heading;

				if ( '' === $current['label'] && '' !== $label ) {
					$current['label'] = $label;
				}

				if ( '' === $current['style'] && '' !== $item_style ) {
					$current['style'] = $item_style;
				}
			}
		}

		if ( null !== $current ) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * Builds one visual Elementor section from a widget group.
	 *
	 * @param array<string,mixed> $group  Grouped widgets.
	 * @param int                 $index  Zero-based section index.
	 * @param array<string,mixed> $plan   Draft plan.
	 * @param array<string,mixed> $tokens Design tokens.
	 * @return array<string,mixed>
	 */
	private function build_visual_section( array $group, int $index, array $plan, array $tokens ): array {
		$items = isset( $group['items'] ) && is_array( $group['items'] ) ? $group['items'] : array();

		if ( empty( $items ) ) {
			return array();
		}

		$types    = array_map(
			static fn( $item ) => sanitize_text_field( (string) ( $item['widget'] ?? '' ) ),
			$items
		);
		$centered = in_array( 'button', $types, true ) && count( $items ) <= 4;

		// Use the AI-selected section style, or fall back to alternating
		// light/muted backgrounds so sections still separate visually.
		$style = (string) ( $group['style'] ?? '' );

		if ( '' === $style ) {
			$style = 0 === $index % 2 ? 'light' : 'muted';
		}

		$dark    = $this->is_dark_style( $style );
		$content = $this->build_section_content( $items, $plan, $tokens, $centered, $dark, $style );

		if ( empty( $content ) ) {
			return array();
		}

		return array(
			'id'       => $this->element_id( 'section-' . $index ),
			'elType'   => 'section',
			'settings' => $this->section_style_settings( $style, $tokens ),
			'elements' => array(
				array(
					'id'       => $this->element_id( 'column-' . $index ),
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $content,
				),
			),
		);
	}

	/**
	 * Builds the inner elements of a section, laying repeated card widgets into
	 * a multi-column row instead of stacking them.
	 *
	 * @param array<int,array<string,mixed>> $items    Widget requests.
	 * @param array<string,mixed>            $plan     Draft plan.
	 * @param array<string,mixed>            $tokens   Design tokens.
	 * @param bool                           $centered Whether text widgets center.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_section_content( array $items, array $plan, array $tokens, bool $centered, bool $dark = false, string $style = '' ): array {
		$card_types = array( 'icon-box', 'image', 'image-box', 'icon-list', 'price-box', 'call-to-action' );
		$content    = array();
		$count      = count( $items );
		$i          = 0;

		while ( $i < $count ) {
			$type = sanitize_text_field( (string) ( $items[ $i ]['widget'] ?? '' ) );
			$run  = 1;

			while ( $i + $run < $count && sanitize_text_field( (string) ( $items[ $i + $run ]['widget'] ?? '' ) ) === $type ) {
				$run++;
			}

			$columnize = ( $run >= 3 ) || ( $run >= 2 && in_array( $type, $card_types, true ) );

			if ( $columnize ) {
				$columns  = array();
				$col_size = (int) floor( 100 / $run );

				for ( $j = 0; $j < $run; $j++ ) {
					$columns[] = array(
						'id'       => $this->element_id( 'icol' ),
						'elType'   => 'column',
						'settings' => array( '_column_size' => $col_size ),
						'elements' => array( $this->build_widget_element( $items[ $i + $j ], $plan, $tokens, true, $dark, $style ) ),
					);
				}

				$content[] = array(
					'id'       => $this->element_id( 'isection' ),
					'elType'   => 'section',
					'isInner'  => true,
					'settings' => array( 'gap' => 'default' ),
					'elements' => $columns,
				);

				$i += $run;
			} else {
				$content[] = $this->build_widget_element( $items[ $i ], $plan, $tokens, $centered, $dark, $style );
				$i++;
			}
		}

		return $content;
	}

	/**
	 * Builds a single widget element with merged authored + default settings.
	 *
	 * @param array<string,mixed> $section  Widget request.
	 * @param array<string,mixed> $plan     Draft plan.
	 * @param array<string,mixed> $tokens   Design tokens.
	 * @param bool                $centered Whether to center text widgets.
	 * @return array<string,mixed>
	 */
	private function build_widget_element( array $section, array $plan, array $tokens, bool $centered, bool $dark = false, string $style = '' ): array {
		$widget_name = sanitize_text_field( (string) ( $section['widget'] ?? '' ) );
		$authored    = isset( $section['settings'] ) && is_array( $section['settings'] ) ? $section['settings'] : array();
		$settings    = array_merge( $this->widget_settings( $widget_name, $plan, $section, $tokens ), $authored );

		if ( $centered && in_array( $widget_name, array( 'heading', 'text-editor', 'button' ), true ) && ! isset( $settings['align'] ) ) {
			$settings['align'] = 'center';
		}

		// On dark/brand/gradient sections, flip core widget text to light so it
		// stays readable, and make the button contrast on a brand-colored panel.
		if ( $dark ) {
			if ( 'heading' === $widget_name && ! isset( $authored['title_color'] ) ) {
				$settings['title_color'] = '#ffffff';
			}

			if ( 'text-editor' === $widget_name && ! isset( $authored['text_color'] ) ) {
				$settings['text_color'] = '#f1f5f9';
			}

			if ( 'button' === $widget_name && 'brand' === $style ) {
				$settings['background_color']  = '#ffffff';
				$settings['button_text_color'] = (string) ( $tokens['colors']['primary'] ?? '#2563eb' );
			}
		}

		return array(
			'id'         => $this->element_id( $widget_name . '-widget' ),
			'elType'     => 'widget',
			'widgetType' => $widget_name,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Whether a section style uses a dark background (needs light text).
	 */
	private function is_dark_style( string $style ): bool {
		return in_array( $style, array( 'dark', 'brand', 'gradient' ), true );
	}

	/**
	 * Builds Elementor section settings for a style preset.
	 *
	 * @param string              $style  Style preset.
	 * @param array<string,mixed> $tokens Design tokens.
	 * @return array<string,mixed>
	 */
	private function section_style_settings( string $style, array $tokens ): array {
		$colors    = is_array( $tokens['colors'] ?? null ) ? $tokens['colors'] : array();
		$primary   = (string) ( $colors['primary'] ?? '#2563eb' );
		$secondary = (string) ( $colors['secondary'] ?? '#0f172a' );
		$light_bg  = (string) ( $colors['background'] ?? '#ffffff' );

		$settings = array(
			'content_width' => 'boxed',
			'gap'           => 'default',
			'padding'       => array(
				'unit'     => 'px',
				'top'      => '80',
				'right'    => '24',
				'bottom'   => '80',
				'left'     => '24',
				'isLinked' => false,
			),
		);

		switch ( $style ) {
			case 'muted':
				$settings['background_background'] = 'classic';
				$settings['background_color']      = '#f6f7f9';
				break;

			case 'dark':
				$settings['background_background'] = 'classic';
				$settings['background_color']      = $secondary;
				break;

			case 'brand':
				$settings['background_background'] = 'classic';
				$settings['background_color']      = $primary;
				break;

			case 'gradient':
				$settings['background_background']     = 'gradient';
				$settings['background_color']          = $primary;
				$settings['background_color_b']        = $secondary;
				$settings['background_gradient_type']  = 'linear';
				$settings['background_gradient_angle'] = array( 'unit' => 'deg', 'size' => 135 );
				break;

			case 'light':
			default:
				$settings['background_background'] = 'classic';
				$settings['background_color']      = $light_bg;
				break;
		}

		return $settings;
	}

	/**
	 * Indexes widgets by internal name.
	 *
	 * @param array<int, array<string,mixed>> $widgets Current widgets.
	 * @return array<string,array<string,mixed>>
	 */
	private function index_widgets( array $widgets ): array {
		$map = array();

		foreach ( $widgets as $widget ) {
			$name = isset( $widget['name'] ) ? sanitize_text_field( (string) $widget['name'] ) : '';

			if ( '' !== $name ) {
				$map[ $name ] = $widget;
			}
		}

		return $map;
	}

	/**
	 * Describes a planned section.
	 */
	private function describe_section( string $widget_name, string $brief, string $purpose = '' ): string {
		if ( '' !== $purpose ) {
			return sprintf(
				/* translators: 1: template section purpose, 2: widget name. */
				__( '%1$s Uses the %2$s widget with Phase 9 template-safe content placeholders.', 'ai-builder-connector' ),
				sanitize_text_field( $purpose ),
				sanitize_text_field( $widget_name )
			);
		}

		switch ( $widget_name ) {
			case 'heading':
				return __( 'Create a clear draft headline based on the approved brief.', 'ai-builder-connector' );
			case 'text-editor':
				return __( 'Add concise supporting copy summarizing the page intent.', 'ai-builder-connector' );
			case 'button':
				return __( 'Add a draft call-to-action placeholder for admin review.', 'ai-builder-connector' );
			case 'image':
				return __( 'Reserve a safe image placeholder for later manual selection.', 'ai-builder-connector' );
			default:
				return '' !== $brief ? $brief : __( 'Draft section for admin review.', 'ai-builder-connector' );
		}
	}

	/**
	 * Normalizes AI-authored section requests.
	 *
	 * @param array<int,mixed> $authored_sections Raw authored sections.
	 * @return array<int,array{widget:string,label:string,description:string,content:array<string,mixed>}>
	 */
	private function normalize_authored_sections( array $authored_sections ): array {
		$normalized = array();

		foreach ( $authored_sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$widget_name = sanitize_text_field( (string) ( $section['widget'] ?? '' ) );

			if ( '' === $widget_name ) {
				continue;
			}

			$style = sanitize_key( (string) ( $section['section_style'] ?? '' ) );

			$normalized[] = array(
				'widget'        => $widget_name,
				'label'         => sanitize_text_field( (string) ( $section['label'] ?? '' ) ),
				'description'   => sanitize_text_field( (string) ( $section['description'] ?? '' ) ),
				'content'       => isset( $section['content'] ) && is_array( $section['content'] ) ? $section['content'] : array(),
				'section_style' => in_array( $style, self::SECTION_STYLES, true ) ? $style : '',
			);

			if ( count( $normalized ) >= self::MAX_AUTHORED_SECTIONS ) {
				break;
			}
		}

		return $normalized;
	}

	/**
	 * Builds requested widget purposes from a template.
	 *
	 * @param array<string,mixed> $template Page template.
	 * @return array<int,array{widget:string,label:string}>
	 */
	private function template_requests( array $template ): array {
		$requests = array();

		foreach ( (array) ( $template['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$title   = sanitize_text_field( (string) ( $section['title'] ?? '' ) );
			$purpose = sanitize_text_field( (string) ( $section['purpose'] ?? '' ) );

			foreach ( (array) ( $section['recommended_widgets'] ?? array() ) as $widget_name ) {
				$widget_name = sanitize_text_field( (string) $widget_name );

				if ( '' === $widget_name ) {
					continue;
				}

				$requests[] = array(
					'widget' => $widget_name,
					'label'  => trim( $title . ': ' . $purpose ),
				);
			}
		}

		if ( empty( $requests ) ) {
			$requests = array(
				array(
					'widget' => 'heading',
					'label'  => __( 'Hero heading', 'ai-builder-connector' ),
				),
				array(
					'widget' => 'text-editor',
					'label'  => __( 'Intro copy', 'ai-builder-connector' ),
				),
				array(
					'widget' => 'button',
					'label'  => __( 'Primary call to action', 'ai-builder-connector' ),
				),
				array(
					'widget' => 'image',
					'label'  => __( 'Supporting image placeholder', 'ai-builder-connector' ),
				),
			);
		}

		return $requests;
	}

	/**
	 * Builds a stable Elementor-style element ID.
	 */
	private function element_id( string $seed ): string {
		return substr( md5( $seed . wp_rand() ), 0, 7 );
	}

	/**
	 * Builds widget settings for minimal Elementor core widgets.
	 *
	 * @param string              $widget_name Widget name.
	 * @param array<string,mixed> $plan Draft plan.
	 * @param array<string,mixed> $section Plan section.
	 * @return array<string,mixed>
	 */
	private function widget_settings( string $widget_name, array $plan, array $section, array $tokens ): array {
		$brief       = sanitize_textarea_field( (string) ( $plan['brief'] ?? '' ) );
		$description = sanitize_text_field( (string) ( $section['description'] ?? '' ) );
		$colors      = is_array( $tokens['colors'] ?? null ) ? $tokens['colors'] : array();
		$typography  = is_array( $tokens['typography'] ?? null ) ? $tokens['typography'] : array();
		$radius      = is_array( $tokens['radius'] ?? null ) ? $tokens['radius'] : array();

		switch ( $widget_name ) {
			case 'heading':
				return array(
					'title'                  => $this->brief_heading( $brief ),
					'header_size'            => 'h1',
					'title_color'            => (string) ( $colors['secondary'] ?? '#0f172a' ),
					'typography_typography'  => 'custom',
					'typography_font_family' => (string) ( $typography['heading_font_family'] ?? 'Arial' ),
					'typography_font_size'   => array(
						'unit' => 'px',
						'size' => (int) ( $typography['heading_size'] ?? 44 ),
					),
					'typography_font_weight' => (string) (int) ( $typography['heading_weight'] ?? 700 ),
					'typography_line_height' => array(
						'unit' => 'em',
						'size' => 1.2,
					),
				);
			case 'text-editor':
				return array(
					'editor'                 => wpautop( esc_html( $this->brief_intro( $brief ) ) ),
					'text_color'             => (string) ( $colors['text'] ?? '#111827' ),
					'typography_typography'  => 'custom',
					'typography_font_family' => (string) ( $typography['font_family'] ?? 'Arial' ),
					'typography_font_size'   => array(
						'unit' => 'px',
						'size' => (int) ( $typography['base_size'] ?? 16 ),
					),
					'typography_font_weight' => (string) (int) ( $typography['body_weight'] ?? 400 ),
					'typography_line_height' => array(
						'unit' => 'em',
						'size' => 1.6,
					),
				);
			case 'button':
				return array(
					'text'                   => __( 'Contact Us', 'ai-builder-connector' ),
					'link'                   => array(
						'url'         => '#',
						'is_external' => '',
						'nofollow'    => '',
					),
					'background_color'       => (string) ( $colors['primary'] ?? '#2563eb' ),
					'button_text_color'      => '#ffffff',
					'border_radius'          => array(
						'unit'     => 'px',
						'top'      => (string) (int) ( $radius['button'] ?? 6 ),
						'right'    => (string) (int) ( $radius['button'] ?? 6 ),
						'bottom'   => (string) (int) ( $radius['button'] ?? 6 ),
						'left'     => (string) (int) ( $radius['button'] ?? 6 ),
						'isLinked' => true,
					),
					'typography_typography'  => 'custom',
					'typography_font_family' => (string) ( $typography['font_family'] ?? 'Arial' ),
					'typography_font_weight' => '600',
				);
			case 'image':
				return array(
					'image'      => array(
						'url' => '',
						'id'  => '',
					),
					'caption'    => __( 'Image placeholder for review', 'ai-builder-connector' ),
					'align'      => 'center',
					'image_size' => 'large',
					'border_radius' => array(
						'unit'     => 'px',
						'top'      => (string) (int) ( $radius['medium'] ?? 8 ),
						'right'    => (string) (int) ( $radius['medium'] ?? 8 ),
						'bottom'   => (string) (int) ( $radius['medium'] ?? 8 ),
						'left'     => (string) (int) ( $radius['medium'] ?? 8 ),
						'isLinked' => true,
					),
				);
			default:
				$defaults = $this->widget_definitions->default_settings( $widget_name );

				if ( ! empty( $defaults ) ) {
					return $defaults;
				}

				// Unknown/addon widget with no registry defaults: leave settings empty
				// so Elementor applies its own control defaults. Injecting a bogus
				// 'title' here would fail validation for non-title widgets.
				return array();
		}
	}

	/**
	 * Gets design tokens from a plan, falling back to the active design system.
	 *
	 * @param array<string,mixed> $plan Draft plan.
	 * @return array<string,mixed>
	 */
	private function plan_design_tokens( array $plan ): array {
		return isset( $plan['design_system'] ) && is_array( $plan['design_system'] ) ? $plan['design_system'] : $this->design_system->get_tokens();
	}

	/**
	 * Creates a safe heading from the brief.
	 */
	private function brief_heading( string $brief ): string {
		$heading = wp_trim_words( sanitize_text_field( $brief ), 8, '' );

		return '' !== $heading ? $heading : __( 'Review Draft', 'ai-builder-connector' );
	}

	/**
	 * Creates safe intro copy from the brief.
	 */
	private function brief_intro( string $brief ): string {
		$intro = wp_trim_words( sanitize_textarea_field( $brief ), 36 );

		return '' !== $intro ? $intro : __( 'Draft copy for administrator review.', 'ai-builder-connector' );
	}
}
