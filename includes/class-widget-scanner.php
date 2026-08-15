<?php
/**
 * Elementor widget scanner.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads registered Elementor widget metadata.
 */
final class Widget_Scanner {
	/**
	 * Elementor detector.
	 *
	 * @var Elementor_Detector
	 */
	private Elementor_Detector $detector;

	/**
	 * Widget scanner constructor.
	 */
	public function __construct( Elementor_Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Gets widget metadata from Elementor after initialization.
	 *
	 * @return array<int, array{name: string, title: string, categories: array<int, string>, class_name: string, class_file: string}>
	 */
	public function get_widgets(): array {
		if ( ! $this->detector->is_initialized() || ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$elementor = \Elementor\Plugin::$instance ?? null;

		if ( ! is_object( $elementor ) || empty( $elementor->widgets_manager ) || ! is_object( $elementor->widgets_manager ) ) {
			return array();
		}

		$widgets_manager = $elementor->widgets_manager;

		if ( ! method_exists( $widgets_manager, 'get_widget_types' ) ) {
			return array();
		}

		$widget_types = $widgets_manager->get_widget_types();

		if ( ! is_array( $widget_types ) ) {
			return array();
		}

		$widgets = array();

		foreach ( $widget_types as $fallback_name => $widget ) {
			if ( ! is_object( $widget ) ) {
				continue;
			}

			$metadata = $this->read_widget_metadata( $widget, (string) $fallback_name );

			if ( null !== $metadata ) {
				$widgets[] = $metadata;
			}
		}

		usort(
			$widgets,
			static function ( array $first, array $second ): int {
				return strcasecmp( $first['name'], $second['name'] );
			}
		);

		return $widgets;
	}

	/**
	 * Reads metadata from a single widget object.
	 *
	 * @param object $widget Widget instance.
	 * @param string $fallback_name Fallback widget key.
	 * @return array{name: string, title: string, categories: array<int, string>, class_name: string, class_file: string}|null
	 */
	private function read_widget_metadata( object $widget, string $fallback_name ): ?array {
		try {
			$name       = method_exists( $widget, 'get_name' ) ? (string) $widget->get_name() : $fallback_name;
			$title      = method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '';
			$categories = method_exists( $widget, 'get_categories' ) ? $widget->get_categories() : array();
		} catch ( Throwable $throwable ) {
			return null;
		}

		if ( '' === $name ) {
			$name = $fallback_name;
		}

		$class_name = get_class( $widget );

		return array(
			'name'       => sanitize_text_field( $name ),
			'title'      => sanitize_text_field( $title ),
			'categories' => $this->normalize_categories( $categories ),
			'class_name' => $class_name,
			'class_file' => $this->get_class_file( $class_name ),
		);
	}

	/**
	 * Gets a widget class file path for internal source matching.
	 *
	 * @param string $class_name PHP class name.
	 */
	private function get_class_file( string $class_name ): string {
		try {
			$reflection = new \ReflectionClass( $class_name );
			$file_name  = $reflection->getFileName();
		} catch ( Throwable $throwable ) {
			return '';
		}

		return is_string( $file_name ) ? wp_normalize_path( $file_name ) : '';
	}

	/**
	 * Normalizes widget categories into display-safe strings.
	 *
	 * @param mixed $categories Raw category data.
	 * @return array<int, string>
	 */
	private function normalize_categories( mixed $categories ): array {
		if ( ! is_array( $categories ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $categories as $category ) {
			if ( is_scalar( $category ) ) {
				$category = sanitize_text_field( (string) $category );

				if ( '' !== $category ) {
					$normalized[] = $category;
				}
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
