<?php
/**
 * Settings and rescan handling.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers settings and saves manual scan snapshots.
 */
final class Settings {
	/**
	 * Elementor detector.
	 *
	 * @var Elementor_Detector
	 */
	private Elementor_Detector $elementor_detector;

	/**
	 * Widget scanner.
	 *
	 * @var Widget_Scanner
	 */
	private Widget_Scanner $widget_scanner;

	/**
	 * Addon detector.
	 *
	 * @var Addon_Detector
	 */
	private Addon_Detector $addon_detector;

	/**
	 * Source resolver.
	 *
	 * @var Widget_Source_Resolver
	 */
	private Widget_Source_Resolver $source_resolver;

	/**
	 * Design system.
	 *
	 * @var Design_System
	 */
	private Design_System $design_system;

	/**
	 * Settings constructor.
	 */
	public function __construct(
		Elementor_Detector $elementor_detector,
		Widget_Scanner $widget_scanner,
		Addon_Detector $addon_detector,
		Widget_Source_Resolver $source_resolver,
		Design_System $design_system
	) {
		$this->elementor_detector = $elementor_detector;
		$this->widget_scanner     = $widget_scanner;
		$this->addon_detector     = $addon_detector;
		$this->source_resolver    = $source_resolver;
		$this->design_system      = $design_system;
	}

	/**
	 * Registers WordPress settings.
	 */
	public function register(): void {
		register_setting(
			'aibc_permissions',
			Permission_Manager::OPTION_ALLOWED_ADDONS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_addons' ),
				'default'           => array( Addon_Detector::ELEMENTOR_CORE ),
			)
		);

		register_setting(
			'aibc_permissions',
			Permission_Manager::OPTION_ALLOWED_WIDGETS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_widgets' ),
				'default'           => array(),
			)
		);

		register_setting(
			'aibc_permissions',
			Design_System::OPTION_DESIGN_SYSTEM,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->design_system, 'sanitize_config' ),
				'default'           => $this->design_system->get_config(),
			)
		);
	}

	/**
	 * Sanitizes addon allowlist saves.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public function sanitize_addons( mixed $value ): array {
		$manager = new Permission_Manager();

		return $manager->sanitize_slug_list( $value );
	}

	/**
	 * Sanitizes widget allowlist saves.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public function sanitize_widgets( mixed $value ): array {
		$manager = new Permission_Manager();

		return $manager->sanitize_widget_list( $value );
	}

	/**
	 * Handles manual widget rescans.
	 */
	public function handle_rescan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to rescan widgets.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_rescan_widgets' );

		$widgets = $this->source_resolver->resolve_widgets( $this->widget_scanner->get_widgets() );
		$sources = $this->addon_detector->get_detected_sources();

		$this->save_scan( $widgets, $sources );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'ai-builder-connector',
					'aibc_rescanned' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Saves a normalized scan snapshot and summary.
	 *
	 * @param array<int, array<string, mixed>>    $widgets Widget rows.
	 * @param array<string, array<string, mixed>> $sources Source rows.
	 */
	private function save_scan( array $widgets, array $sources ): void {
		$summary = $this->build_summary( $widgets, $sources );
		$snapshot = array();

		foreach ( $widgets as $widget ) {
			$snapshot[] = array(
				'name'              => sanitize_text_field( (string) ( $widget['name'] ?? '' ) ),
				'title'             => sanitize_text_field( (string) ( $widget['title'] ?? '' ) ),
				'categories'        => $this->sanitize_categories( $widget['categories'] ?? array() ),
				'class_name'        => sanitize_text_field( (string) ( $widget['class_name'] ?? '' ) ),
				'source_slug'       => sanitize_key( (string) ( $widget['source_slug'] ?? Addon_Detector::UNKNOWN_SOURCE ) ),
				'source_name'       => sanitize_text_field( (string) ( $widget['source_name'] ?? __( 'Unknown Source', 'ai-builder-connector' ) ) ),
				'confidence'        => sanitize_key( (string) ( $widget['confidence'] ?? 'unknown' ) ),
				'detection_status'  => sanitize_key( (string) ( $widget['detection_status'] ?? 'unknown' ) ),
				'review_required'   => ! empty( $widget['review_required'] ),
			);
		}

		update_option( Permission_Manager::OPTION_SCAN_SUMMARY, $summary, false );
		update_option( Permission_Manager::OPTION_SCAN_SNAPSHOT, $snapshot, false );
	}

	/**
	 * Builds a lightweight scan summary.
	 *
	 * @param array<int, array<string, mixed>>    $widgets Widget rows.
	 * @param array<string, array<string, mixed>> $sources Source rows.
	 * @return array<string, mixed>
	 */
	private function build_summary( array $widgets, array $sources ): array {
		$source_slugs = array();
		$unknown      = 0;

		foreach ( $widgets as $widget ) {
			$source_slug = sanitize_key( (string) ( $widget['source_slug'] ?? Addon_Detector::UNKNOWN_SOURCE ) );
			$source_slugs[ $source_slug ] = true;

			if ( Addon_Detector::UNKNOWN_SOURCE === $source_slug ) {
				$unknown++;
			}
		}

		$addon_versions = array();

		foreach ( $sources as $slug => $source ) {
			if ( Addon_Detector::UNKNOWN_SOURCE === $slug ) {
				continue;
			}

			$addon_versions[ sanitize_key( $slug ) ] = array(
				'name'    => sanitize_text_field( (string) ( $source['name'] ?? '' ) ),
				'version' => sanitize_text_field( (string) ( $source['version'] ?? '' ) ),
				'active'  => ! empty( $source['active'] ),
			);
		}

		return array(
			'scanned_at'          => gmdate( 'c' ),
			'elementor_version'   => sanitize_text_field( $this->elementor_detector->get_version() ),
			'detected_addons'     => $addon_versions,
			'widget_count'        => count( $widgets ),
			'source_count'        => count( $source_slugs ),
			'unknown_widget_count' => $unknown,
		);
	}

	/**
	 * Sanitizes category strings.
	 *
	 * @param mixed $categories Raw categories.
	 * @return array<int, string>
	 */
	private function sanitize_categories( mixed $categories ): array {
		if ( ! is_array( $categories ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $categories as $category ) {
			if ( is_scalar( $category ) ) {
				$category = sanitize_text_field( (string) $category );

				if ( '' !== $category ) {
					$sanitized[] = $category;
				}
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}
