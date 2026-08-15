<?php
/**
 * Elementor 4 atomic element builder.
 *
 * Builds pages from AIBC plans using Elementor 4's atomic elements
 * (e-flexbox, e-heading, e-paragraph, e-button, e-image, e-divider)
 * with $$type-wrapped props and local style classes — much lighter
 * output than legacy widgets.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts validated AIBC plans into atomic Elementor data.
 */
class Atomic_Builder {

	/**
	 * Widget-name → atomic element map.
	 */
	private const WIDGET_MAP = array(
		'heading'     => 'e-heading',
		'text-editor' => 'e-paragraph',
		'button'      => 'e-button',
		'image'       => 'e-image',
		'divider'     => 'e-divider',
	);

	/**
	 * Whether the site's Elementor registers atomic element types.
	 */
	public static function is_supported(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! method_exists( '\Elementor\Plugin', 'instance' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::instance();

		if ( isset( $elementor->elements_manager ) && is_object( $elementor->elements_manager ) && method_exists( $elementor->elements_manager, 'get_element_types' ) ) {
			$types = $elementor->elements_manager->get_element_types();

			if ( is_array( $types ) && ( isset( $types['e-flexbox'] ) || isset( $types['e-div-block'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Status summary for the MCP surface.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		return array(
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown',
			'supports_atomic'   => self::is_supported(),
			'recommended'       => self::is_supported() ? 'atomic' : 'legacy',
			'atomic_widgets'    => array_keys( self::WIDGET_MAP ),
			'note'              => __( 'Pass engine:"atomic" to create_elementor_draft to build with Elementor 4 atomic elements (lighter markup, faster pages). Only the listed widgets are supported in atomic mode; other widgets need the legacy engine.', 'ai-builder-connector' ),
		);
	}

	/**
	 * Builds atomic Elementor data from a validated plan.
	 *
	 * @param array<string,mixed> $plan Validated plan.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function build_elementor_data( array $plan ): array|\WP_Error {
		$sections = isset( $plan['sections'] ) && is_array( $plan['sections'] ) ? $plan['sections'] : array();
		$design   = isset( $plan['design_system'] ) && is_array( $plan['design_system'] ) ? $plan['design_system'] : array();
		$colors   = isset( $design['colors'] ) && is_array( $design['colors'] ) ? $design['colors'] : array();

		$unsupported = array();

		foreach ( $sections as $section ) {
			$widget = sanitize_text_field( (string) ( $section['widget'] ?? '' ) );

			if ( '' !== $widget && ! isset( self::WIDGET_MAP[ $widget ] ) ) {
				$unsupported[] = $widget;
			}
		}

		if ( ! empty( $unsupported ) ) {
			return new \WP_Error(
				'aibc_atomic_unsupported_widget',
				sprintf(
					/* translators: 1: unsupported widget list, 2: supported list. */
					__( 'Atomic engine does not support: %1$s. Supported atomic widgets: %2$s. Use the legacy engine for other widgets.', 'ai-builder-connector' ),
					implode( ', ', array_unique( $unsupported ) ),
					implode( ', ', array_keys( self::WIDGET_MAP ) )
				),
				array( 'status' => 422 )
			);
		}

		// Group consecutive rows sharing a label into one visual section.
		$groups = array();
		$last   = null;

		foreach ( $sections as $section ) {
			$label = sanitize_text_field( (string) ( $section['label'] ?? '' ) );

			if ( null === $last || ( '' !== $label && $label !== $last['label'] ) ) {
				$groups[] = array(
					'label' => $label,
					'style' => sanitize_key( (string) ( $section['section_style'] ?? '' ) ),
					'items' => array(),
				);
				$last    = &$groups[ count( $groups ) - 1 ];
			}

			if ( '' === $last['style'] && '' !== sanitize_key( (string) ( $section['section_style'] ?? '' ) ) ) {
				$last['style'] = sanitize_key( (string) ( $section['section_style'] ?? '' ) );
			}

			$last['items'][] = $section;
		}
		unset( $last );

		$out = array();

		foreach ( array_values( $groups ) as $index => $group ) {
			$style = '' !== $group['style'] ? $group['style'] : ( 0 === $index % 2 ? 'light' : 'muted' );
			$out[] = $this->build_section( $group['items'], $style, $colors, $index );
		}

		return $out;
	}

	/**
	 * Builds one flexbox section with its children.
	 *
	 * @param array<int,array<string,mixed>> $items  Widget requests.
	 * @param string                         $style  Section style key.
	 * @param array<string,string>           $colors Design colors.
	 */
	private function build_section( array $items, string $style, array $colors, int $index ): array {
		$dark    = in_array( $style, array( 'dark', 'brand', 'gradient' ), true );
		$primary = sanitize_text_field( (string) ( $colors['primary'] ?? '#1dbf73' ) );
		$text    = sanitize_text_field( (string) ( $colors['text'] ?? '#111827' ) );

		$backgrounds = array(
			'light'    => '#ffffff',
			'muted'    => '#f6f7f9',
			'dark'     => '#111827',
			'brand'    => $primary,
			'gradient' => sanitize_text_field( (string) ( $colors['secondary'] ?? '#374151' ) ),
		);

		$children = array();
		$position = 0;

		foreach ( $items as $item ) {
			$child = $this->build_child( $item, $dark, $primary, $text, 0 === $index && 0 === $position );

			if ( ! empty( $child ) ) {
				$children[] = $child;
				$position++;
			}
		}

		$id      = $this->element_id( 'sec' . $index );
		$element = array(
			'id'              => $id,
			'elType'          => 'e-flexbox',
			'settings'        => array(
				'tag'     => $this->p_string( 'section' ),
				'classes' => $this->p_classes(),
			),
			'elements'        => $children,
			'isInner'         => false,
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		$background = $backgrounds[ $style ] ?? '#ffffff';

		$this->apply_style(
			$element,
			array(
				'flex-direction' => $this->p_string( 'column' ),
				'align-items'    => $this->p_string( 'center' ),
				'gap'            => $this->p_size( 20 ),
				'padding'        => $this->p_dimensions( 0 === $index ? 88 : 64, 24 ),
				'background'     => $this->p_background( $background ),
				'color'          => $this->p_color( $dark ? '#ffffff' : $text ),
			)
		);

		return $element;
	}

	/**
	 * Builds one atomic child element from a widget request.
	 *
	 * @param array<string,mixed> $item Widget request.
	 */
	private function build_child( array $item, bool $dark, string $primary, string $text, bool $hero = false ): array {
		$widget  = sanitize_text_field( (string) ( $item['widget'] ?? '' ) );
		$type    = self::WIDGET_MAP[ $widget ] ?? '';
		$content = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : array();

		if ( '' === $type ) {
			return array();
		}

		$settings = array( 'classes' => $this->p_classes() );
		$styles   = array( 'color' => $this->p_color( $dark ? '#ffffff' : $text ) );

		switch ( $type ) {
			case 'e-heading':
				$title             = wp_strip_all_tags( (string) ( $content['title'] ?? __( 'Heading', 'ai-builder-connector' ) ) );
				$settings['title'] = $this->p_html( $title );
				$settings['tag']   = $this->p_string( $hero ? 'h1' : 'h2' );

				$styles['text-align']  = $this->p_string( 'center' );
				$styles['font-size']   = $this->p_size( $hero ? 44 : 32 );
				$styles['font-weight'] = $this->p_string( '700' );
				$styles['line-height'] = $this->p_size( 1.2, 'em' );
				$styles['max-width']   = $this->p_size( 820 );
				break;

			case 'e-paragraph':
				$paragraph             = wp_strip_all_tags( (string) ( $content['editor'] ?? $content['text'] ?? '' ) );
				$settings['paragraph'] = $this->p_html( '' !== $paragraph ? $paragraph : __( 'Text', 'ai-builder-connector' ) );

				$styles['text-align']  = $this->p_string( 'center' );
				$styles['font-size']   = $this->p_size( 17 );
				$styles['line-height'] = $this->p_size( 1.7, 'em' );
				$styles['max-width']   = $this->p_size( 720 );
				break;

			case 'e-button':
				$label            = wp_strip_all_tags( (string) ( $content['text'] ?? __( 'Learn more', 'ai-builder-connector' ) ) );
				$settings['text'] = $this->p_html( $label );
				$link             = isset( $content['link'] ) && is_array( $content['link'] ) ? (string) ( $content['link']['url'] ?? '' ) : (string) ( $content['link'] ?? '' );

				if ( '' !== $link ) {
					$settings['link'] = $this->p_link( esc_url_raw( $link ) );
				}

				$button_bg = $dark ? '#ffffff' : $primary;

				$styles = array(
					'background'    => $this->p_background( $button_bg ),
					'color'         => $this->p_color( $dark ? $primary : '#ffffff' ),
					'padding'       => $this->p_dimensions( 14, 28 ),
					'border-radius' => $this->p_border_radius( 10 ),
					'font-weight'   => $this->p_string( '600' ),
				);
				break;

			case 'e-image':
				$url = '';
				$att = 0;

				if ( isset( $content['image'] ) && is_array( $content['image'] ) ) {
					$url = (string) ( $content['image']['url'] ?? '' );
					$att = (int) ( $content['image']['id'] ?? 0 );
				} elseif ( isset( $content['url'] ) ) {
					$url = (string) $content['url'];
				}

				if ( '' === $url && $att <= 0 ) {
					return array();
				}

				$settings['image'] = array(
					'$$type' => 'image',
					'value'  => array(
						'src' => array(
							'id'  => $this->p_number( $att ),
							'url' => $this->p_url( esc_url_raw( $url ) ),
						),
					),
				);
				$styles = array( 'max-width' => $this->p_size( 100, '%' ) );
				break;

			case 'e-divider':
				$styles = array( 'max-width' => $this->p_size( 120 ) );
				break;
		}

		$element = array(
			'id'              => $this->element_id( $widget ),
			'elType'          => 'widget',
			'widgetType'      => $type,
			'isInner'         => false,
			'settings'        => $settings,
			'elements'        => array(),
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		$this->apply_style( $element, $styles );

		return $element;
	}

	/**
	 * Attaches a local style class to an element.
	 *
	 * @param array<string,mixed> $element Element (by reference).
	 * @param array<string,mixed> $props   $$type-wrapped CSS props.
	 */
	private function apply_style( array &$element, array $props ): void {
		if ( empty( $props ) ) {
			return;
		}

		$class_id = 'e-' . $element['id'] . '-' . substr( md5( $element['id'] . wp_json_encode( array_keys( $props ) ) ), 0, 7 );

		$element['styles'][ $class_id ] = array(
			'id'       => $class_id,
			'label'    => 'local',
			'type'     => 'class',
			'variants' => array(
				array(
					'meta'       => array(
						'breakpoint' => 'desktop',
						'state'      => null,
					),
					'props'      => $props,
					'custom_css' => null,
				),
			),
		);

		$element['settings']['classes'] = array(
			'$$type' => 'classes',
			'value'  => array( $class_id ),
		);
	}

	private function element_id( string $seed ): string {
		return substr( md5( uniqid( $seed, true ) ), 0, 7 );
	}

	/** @return array<string,mixed> */
	private function p_string( string $v ): array {
		return array( '$$type' => 'string', 'value' => $v );
	}

	/** @return array<string,mixed> */
	private function p_number( int|float $v ): array {
		return array( '$$type' => 'number', 'value' => $v );
	}

	/** @return array<string,mixed> */
	private function p_size( int|float $size, string $unit = 'px' ): array {
		return array( '$$type' => 'size', 'value' => array( 'size' => $size, 'unit' => $unit ) );
	}

	/** @return array<string,mixed> */
	private function p_color( string $v ): array {
		return array( '$$type' => 'color', 'value' => $v );
	}

	/**
	 * Builds a dimensions prop (padding/margin) from block and inline sizes.
	 *
	 * @return array<string,mixed>
	 */
	private function p_dimensions( int|float $block, int|float $inline ): array {
		return array(
			'$$type' => 'dimensions',
			'value'  => array(
				'block-start'  => $this->p_size( $block ),
				'block-end'    => $this->p_size( $block ),
				'inline-start' => $this->p_size( $inline ),
				'inline-end'   => $this->p_size( $inline ),
			),
		);
	}

	/**
	 * Builds a background prop from a solid colour.
	 *
	 * @return array<string,mixed>
	 */
	private function p_background( string $color ): array {
		return array(
			'$$type' => 'background',
			'value'  => array( 'color' => $this->p_color( $color ) ),
		);
	}

	/**
	 * Builds a border-radius prop with equal corners.
	 *
	 * @return array<string,mixed>
	 */
	private function p_border_radius( int|float $radius ): array {
		// Elementor's style schema accepts a plain size for border-radius.
		return $this->p_size( $radius );
	}

	/** @return array<string,mixed> */
	private function p_html( string $text ): array {
		return array(
			'$$type' => 'html-v3',
			'value'  => array(
				'content'  => $this->p_string( $text ),
				'children' => array(),
			),
		);
	}

	/** @return array<string,mixed> */
	private function p_url( string $v ): array {
		return array( '$$type' => 'url', 'value' => $v );
	}

	/** @return array<string,mixed> */
	private function p_link( string $url ): array {
		return array(
			'$$type' => 'link',
			'value'  => array(
				'destination' => $this->p_url( $url ),
				'tag'         => $this->p_string( 'a' ),
			),
		);
	}

	/** @return array<string,mixed> */
	private function p_classes(): array {
		return array( '$$type' => 'classes', 'value' => array() );
	}
}
