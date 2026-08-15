<?php
/**
 * Permission manager.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads addon and widget allowlists.
 */
final class Permission_Manager {
	public const OPTION_ALLOWED_ADDONS  = 'aibc_allowed_addons';
	public const OPTION_ALLOWED_WIDGETS = 'aibc_allowed_widgets';
	public const OPTION_SCAN_SUMMARY    = 'aibc_widget_scan_summary';
	public const OPTION_SCAN_SNAPSHOT   = 'aibc_widget_scan_snapshot';

	/**
	 * Gets allowed addon slugs.
	 *
	 * @return array<int, string>
	 */
	public function get_allowed_addons(): array {
		$value = get_option( self::OPTION_ALLOWED_ADDONS, null );

		if ( null === $value ) {
			return array( Addon_Detector::ELEMENTOR_CORE );
		}

		return $this->sanitize_slug_list( $value );
	}

	/**
	 * Gets allowed widget names.
	 *
	 * @return array<int, string>
	 */
	public function get_allowed_widgets(): array {
		return $this->sanitize_widget_list( get_option( self::OPTION_ALLOWED_WIDGETS, array() ) );
	}

	/**
	 * Checks whether an addon is allowed.
	 */
	public function is_addon_allowed( string $addon_slug ): bool {
		return in_array( sanitize_key( $addon_slug ), $this->get_allowed_addons(), true );
	}

	/**
	 * Checks whether a widget is allowed.
	 *
	 * @param array<string, mixed> $widget Widget row.
	 */
	public function is_widget_allowed( array $widget ): bool {
		$source_slug = isset( $widget['source_slug'] ) ? sanitize_key( (string) $widget['source_slug'] ) : Addon_Detector::UNKNOWN_SOURCE;
		$name        = isset( $widget['name'] ) ? sanitize_text_field( (string) $widget['name'] ) : '';

		if ( ! $this->is_addon_allowed( $source_slug ) ) {
			return false;
		}

		if ( Addon_Detector::ELEMENTOR_CORE === $source_slug && empty( get_option( self::OPTION_ALLOWED_WIDGETS, array() ) ) ) {
			return true;
		}

		return in_array( $name, $this->get_allowed_widgets(), true );
	}

	/**
	 * Sanitizes addon slugs.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<int, string>
	 */
	public function sanitize_slug_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
	}

	/**
	 * Sanitizes widget names.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<int, string>
	 */
	public function sanitize_widget_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$widgets = array();

		foreach ( $value as $widget_name ) {
			if ( is_scalar( $widget_name ) ) {
				$widget_name = sanitize_text_field( (string) $widget_name );

				if ( '' !== $widget_name ) {
					$widgets[] = $widget_name;
				}
			}
		}

		return array_values( array_unique( $widgets ) );
	}

	/**
	 * Gets a saved scan snapshot.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_scan_snapshot(): array {
		$snapshot = get_option( self::OPTION_SCAN_SNAPSHOT, array() );

		return is_array( $snapshot ) ? $snapshot : array();
	}

	/**
	 * Gets saved scan summary.
	 *
	 * @return array<string, mixed>
	 */
	public function get_scan_summary(): array {
		$summary = get_option( self::OPTION_SCAN_SUMMARY, array() );

		return is_array( $summary ) ? $summary : array();
	}
}
