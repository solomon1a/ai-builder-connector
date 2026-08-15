<?php
/**
 * Elementor widget source resolver.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves widget ownership to Elementor Core, Elementor Pro, addons, or Unknown Source.
 */
final class Widget_Source_Resolver {
	/**
	 * Addon detector.
	 *
	 * @var Addon_Detector
	 */
	private Addon_Detector $addon_detector;

	/**
	 * Resolver constructor.
	 */
	public function __construct( Addon_Detector $addon_detector ) {
		$this->addon_detector = $addon_detector;
	}

	/**
	 * Adds source metadata to widget rows.
	 *
	 * @param array<int, array<string, mixed>> $widgets Widget rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function resolve_widgets( array $widgets ): array {
		$sources = $this->addon_detector->get_detected_sources();

		foreach ( $widgets as $index => $widget ) {
			$resolution = $this->resolve_widget( $widget, $sources );

			$widgets[ $index ]['source_slug']       = $resolution['source_slug'];
			$widgets[ $index ]['source_name']       = $resolution['source_name'];
			$widgets[ $index ]['confidence']        = $resolution['confidence'];
			$widgets[ $index ]['detection_status']  = $resolution['detection_status'];
			$widgets[ $index ]['review_required']   = $resolution['review_required'];
		}

		return $widgets;
	}

	/**
	 * Resolves one widget source.
	 *
	 * @param array<string, mixed>               $widget Widget row.
	 * @param array<string, array<string, mixed>> $sources Detected sources.
	 * @return array{source_slug: string, source_name: string, confidence: string, detection_status: string, review_required: bool}
	 */
	private function resolve_widget( array $widget, array $sources ): array {
		$class_name = isset( $widget['class_name'] ) ? (string) $widget['class_name'] : '';
		$class_file = isset( $widget['class_file'] ) ? wp_normalize_path( (string) $widget['class_file'] ) : '';

		foreach ( $sources as $slug => $source ) {
			if ( Addon_Detector::UNKNOWN_SOURCE === $slug || empty( $source['plugin_dir'] ) ) {
				continue;
			}

			$plugin_dir = trailingslashit( wp_normalize_path( (string) $source['plugin_dir'] ) );

			if ( '' !== $class_file && 0 === strpos( $class_file, $plugin_dir ) ) {
				return $this->result( $slug, (string) $source['name'], 'exact', 'detected', false );
			}
		}

		$definitions = $this->addon_detector->get_known_definitions();

		foreach ( $definitions as $slug => $definition ) {
			if ( ! isset( $sources[ $slug ] ) ) {
				continue;
			}

			foreach ( (array) ( $definition['namespaces'] ?? array() ) as $namespace ) {
				if ( is_string( $namespace ) && '' !== $namespace && 0 === strpos( $class_name, $namespace ) ) {
					return $this->result( $slug, (string) $sources[ $slug ]['name'], 'strong', 'detected', false );
				}
			}
		}

		$weak_match = $this->find_weak_match( $widget, $definitions, $sources );

		if ( null !== $weak_match ) {
			return $this->result( Addon_Detector::UNKNOWN_SOURCE, __( 'Unknown Source', 'ai-builder-connector' ), 'weak', 'review', true );
		}

		return $this->result( Addon_Detector::UNKNOWN_SOURCE, __( 'Unknown Source', 'ai-builder-connector' ), 'unknown', 'unknown', false );
	}

	/**
	 * Finds loose prefix/category matches without assigning ownership.
	 *
	 * @param array<string, mixed>                $widget Widget row.
	 * @param array<string, array<string, mixed>> $definitions Known definitions.
	 * @param array<string, array<string, mixed>> $sources Detected sources.
	 * @return string|null
	 */
	private function find_weak_match( array $widget, array $definitions, array $sources ): ?string {
		$name       = isset( $widget['name'] ) ? (string) $widget['name'] : '';
		$categories = isset( $widget['categories'] ) && is_array( $widget['categories'] ) ? $widget['categories'] : array();

		foreach ( $definitions as $slug => $definition ) {
			if ( ! isset( $sources[ $slug ] ) ) {
				continue;
			}

			foreach ( (array) ( $definition['prefixes'] ?? array() ) as $prefix ) {
				if ( is_string( $prefix ) && '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
					return $slug;
				}
			}

			foreach ( (array) ( $definition['categories'] ?? array() ) as $category ) {
				if ( is_string( $category ) && in_array( $category, $categories, true ) ) {
					return $slug;
				}
			}
		}

		return null;
	}

	/**
	 * Builds a source resolution result.
	 */
	private function result( string $slug, string $name, string $confidence, string $status, bool $review_required ): array {
		return array(
			'source_slug'      => sanitize_key( $slug ),
			'source_name'      => sanitize_text_field( $name ),
			'confidence'       => sanitize_key( $confidence ),
			'detection_status' => sanitize_key( $status ),
			'review_required'  => $review_required,
		);
	}
}
