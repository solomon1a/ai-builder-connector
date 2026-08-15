<?php
/**
 * Elementor widget introspector.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-builds a safe, sanitized definition for any registered Elementor widget
 * by reading its live control stack. Used as a fallback for addon widgets that
 * are not in the hand-verified widget pack.
 *
 * Every value is derived from Elementor's own control metadata and mapped to the
 * existing content-sanitizer rule types. Only content-safe control types are ever
 * exposed; styling, media, and complex controls are skipped. All reads are wrapped
 * so a misbehaving third-party widget can never fatal the request.
 */
final class Widget_Introspector {
	/**
	 * Maximum AI-authorable fields exposed per introspected widget.
	 */
	private const MAX_FIELDS = 24;

	/**
	 * Elementor detector.
	 *
	 * @var Elementor_Detector
	 */
	private Elementor_Detector $detector;

	/**
	 * Cached widget-type map (name => Widget_Base), built once per request.
	 *
	 * @var array<string,object>|null
	 */
	private ?array $widget_types = null;

	/**
	 * Introspector constructor.
	 */
	public function __construct( Elementor_Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Builds a definition-shaped array for a registered widget, or null.
	 *
	 * @param string $widget_name Elementor widget name.
	 * @return array<string,mixed>|null
	 */
	public function build_definition( string $widget_name ): ?array {
		$widget_name = sanitize_text_field( $widget_name );

		if ( '' === $widget_name ) {
			return null;
		}

		$widget = $this->get_widget( $widget_name );

		if ( null === $widget ) {
			return null;
		}

		try {
			$controls = method_exists( $widget, 'get_controls' ) ? $widget->get_controls() : array();
			$title    = method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : $widget_name;
		} catch ( Throwable $throwable ) {
			return null;
		}

		if ( ! is_array( $controls ) || empty( $controls ) ) {
			return null;
		}

		$content_settings = array();
		$defaults         = array();

		foreach ( $controls as $control_name => $control ) {
			if ( count( $content_settings ) >= self::MAX_FIELDS ) {
				break;
			}

			$key = sanitize_key( (string) $control_name );

			if ( '' === $key || str_starts_with( $key, '_' ) ) {
				continue;
			}

			if ( ! is_array( $control ) || $this->is_non_content_tab( $control ) ) {
				continue;
			}

			$rule = $this->map_control( $control );

			if ( null === $rule ) {
				continue;
			}

			// Skip choice controls that are clearly styling (typography, spacing,
			// alignment, etc.) rather than content. Some addons inject these into
			// the content tab, and exposing them as AI-authorable adds only noise.
			if ( 'choice' === ( $rule['type'] ?? '' ) && $this->is_styling_name( $key ) ) {
				continue;
			}

			$content_settings[ $key ] = $rule;

			$default = $this->safe_default( $control );

			if ( null !== $default ) {
				$defaults[ $key ] = $default;
			}
		}

		if ( empty( $content_settings ) ) {
			return null;
		}

		$supported = array_keys( $content_settings );

		return array(
			'identifier'                => $widget_name,
			'title'                     => sanitize_text_field( '' !== $title ? $title : $widget_name ),
			'source_plugin'             => '',
			'support_status'            => 'introspected',
			'required_settings'         => array(),
			'supported_settings'        => $supported,
			'default_settings'          => $defaults,
			'content_settings'          => $content_settings,
			'validation_rules'          => array(
				'registered_widget_required',
				'addon_must_be_allowed',
				'widget_must_be_allowed',
				'unsupported_settings_rejected',
				'introspected_content_only',
			),
			'responsive_capabilities'   => array( 'desktop', 'tablet', 'mobile' ),
			'tested_elementor_versions' => array(),
			'known_limitations'         => __( 'Auto-detected addon widget. Only safe content fields (text, rich text, links, choices) are AI-authorable; styling stays at Elementor defaults.', 'ai-builder-connector' ),
		);
	}

	/**
	 * Maps one Elementor control to a sanitizer rule, or null if not content-safe.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 * @return array<string,mixed>|null
	 */
	private function map_control( array $control ): ?array {
		$type = sanitize_key( (string) ( $control['type'] ?? '' ) );

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'number':
				return array( 'type' => 'text' );
			case 'wysiwyg':
				return array( 'type' => 'rich_text' );
			case 'url':
				return array( 'type' => 'link' );
			case 'select':
				$choices = $this->option_keys( $control );

				return ! empty( $choices ) ? array( 'type' => 'choice', 'choices' => $choices ) : null;
			default:
				return null;
		}
	}

	/**
	 * Extracts safe option keys from a select control.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 * @return array<int,string>
	 */
	private function option_keys( array $control ): array {
		$options = $control['options'] ?? null;

		if ( ! is_array( $options ) || empty( $options ) ) {
			return array();
		}

		$keys = array();

		foreach ( array_keys( $options ) as $option_key ) {
			if ( is_scalar( $option_key ) ) {
				$clean = sanitize_text_field( (string) $option_key );

				if ( '' !== $clean ) {
					$keys[] = $clean;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Returns a scalar or link-object default that is safe to store, or null.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 */
	private function safe_default( array $control ): mixed {
		if ( ! array_key_exists( 'default', $control ) ) {
			return null;
		}

		$default = $control['default'];

		if ( is_scalar( $default ) ) {
			$value = sanitize_text_field( (string) $default );

			return '' !== $value ? $value : null;
		}

		// Elementor URL controls default to a link array; keep only the url string shape.
		if ( is_array( $default ) && array_key_exists( 'url', $default ) && is_scalar( $default['url'] ) ) {
			$url = sanitize_text_field( (string) $default['url'] );

			return array(
				'url'         => $url,
				'is_external' => '',
				'nofollow'    => '',
			);
		}

		return null;
	}

	/**
	 * Checks whether a control name looks like a styling option rather than content.
	 */
	private function is_styling_name( string $key ): bool {
		return 1 === preg_match(
			'/(typo|font|weight|transform|decoration|align|style|size|color|colour|width|height|gap|space|spacing|margin|padding|position|order|radius|border|shadow|animation|opacity|overflow|rotate|scale|offset|indent|letter|line_height|vertical|horizontal)/',
			$key
		);
	}

	/**
	 * Checks whether a control explicitly belongs to a non-content tab.
	 *
	 * @param array<string,mixed> $control Control metadata.
	 */
	private function is_non_content_tab( array $control ): bool {
		$tab = sanitize_key( (string) ( $control['tab'] ?? '' ) );

		return in_array( $tab, array( 'style', 'advanced', 'settings', 'layout' ), true );
	}

	/**
	 * Gets a registered widget instance by name, or null.
	 *
	 * @param string $widget_name Widget name.
	 */
	private function get_widget( string $widget_name ): ?object {
		$map = $this->widget_type_map();

		$widget = $map[ $widget_name ] ?? null;

		return is_object( $widget ) ? $widget : null;
	}

	/**
	 * Builds and caches the Elementor widget-type map for this request.
	 *
	 * @return array<string,object>
	 */
	private function widget_type_map(): array {
		if ( is_array( $this->widget_types ) ) {
			return $this->widget_types;
		}

		$this->widget_types = array();

		if ( ! $this->detector->is_initialized() || ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->widget_types;
		}

		try {
			$elementor = \Elementor\Plugin::$instance ?? null;

			if ( ! is_object( $elementor ) || empty( $elementor->widgets_manager ) || ! is_object( $elementor->widgets_manager ) ) {
				return $this->widget_types;
			}

			$manager = $elementor->widgets_manager;

			if ( ! method_exists( $manager, 'get_widget_types' ) ) {
				return $this->widget_types;
			}

			$types = $manager->get_widget_types();
		} catch ( Throwable $throwable ) {
			return $this->widget_types;
		}

		if ( ! is_array( $types ) ) {
			return $this->widget_types;
		}

		foreach ( $types as $name => $widget ) {
			if ( ! is_object( $widget ) ) {
				continue;
			}

			try {
				$resolved = method_exists( $widget, 'get_name' ) ? (string) $widget->get_name() : (string) $name;
			} catch ( Throwable $throwable ) {
				$resolved = (string) $name;
			}

			$resolved = sanitize_text_field( '' !== $resolved ? $resolved : (string) $name );

			if ( '' !== $resolved ) {
				$this->widget_types[ $resolved ] = $widget;
			}
		}

		return $this->widget_types;
	}
}
