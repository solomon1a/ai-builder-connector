<?php
/**
 * Elementor addon detector.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects known Elementor-related plugin sources.
 */
final class Addon_Detector {
	public const ELEMENTOR_CORE = 'elementor-core';
	public const ELEMENTOR_PRO  = 'elementor-pro';
	public const UNKNOWN_SOURCE = 'unknown-source';

	/**
	 * Elementor detector.
	 *
	 * @var Elementor_Detector
	 */
	private Elementor_Detector $elementor_detector;

	/**
	 * Addon detector constructor.
	 */
	public function __construct( Elementor_Detector $elementor_detector ) {
		$this->elementor_detector = $elementor_detector;
	}

	/**
	 * Gets all known source definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_known_definitions(): array {
		return array(
			self::ELEMENTOR_CORE => array(
				'name'        => __( 'Elementor Core', 'ai-builder-connector' ),
				'plugin_file' => 'elementor/elementor.php',
				'namespaces'  => array( 'Elementor\\' ),
				'classes'     => array( '\Elementor\Plugin' ),
				'constants'   => array( 'ELEMENTOR_VERSION' ),
				'prefixes'    => array(),
				'categories'  => array( 'basic', 'general', 'wordpress' ),
				'virtual'     => true,
				'verified'    => true,
			),
			self::ELEMENTOR_PRO  => array(
				'name'        => __( 'Elementor Pro', 'ai-builder-connector' ),
				'plugin_file' => 'elementor-pro/elementor-pro.php',
				'namespaces'  => array( 'ElementorPro\\' ),
				'classes'     => array( '\ElementorPro\Plugin' ),
				'constants'   => array( 'ELEMENTOR_PRO_VERSION' ),
				'prefixes'    => array(),
				'categories'  => array( 'pro-elements', 'theme-elements', 'theme-elements-single', 'theme-elements-archive' ),
				'virtual'     => true,
				'verified'    => false,
			),
			'wpforms'           => array(
				'name'        => __( 'WPForms', 'ai-builder-connector' ),
				'plugin_file' => 'wpforms-lite/wpforms.php',
				'plugin_files' => array( 'wpforms-lite/wpforms.php', 'wpforms/wpforms.php' ),
				'namespaces'  => array( 'WPForms\\' ),
				'classes'     => array( '\WPForms\WPForms' ),
				'constants'   => array( 'WPFORMS_VERSION' ),
				'prefixes'    => array( 'wpforms' ),
				'categories'  => array(),
				'verified'    => false,
			),
			'essential-addons-for-elementor-lite' => array(
				'name'        => __( 'Essential Addons for Elementor', 'ai-builder-connector' ),
				'plugin_file' => 'essential-addons-for-elementor-lite/essential_adons_elementor.php',
				'plugin_files' => array( 'essential-addons-for-elementor-lite/essential_adons_elementor.php', 'essential-addons-elementor/essential_adons_elementor.php' ),
				'namespaces'  => array( 'Essential_Addons_Elementor\\', 'Essential_Addons_Elementor_Pro\\' ),
				'classes'     => array( '\Essential_Addons_Elementor\Classes\Bootstrap' ),
				'constants'   => array( 'EAEL_PLUGIN_VERSION', 'EAEL_PRO_PLUGIN_VERSION' ),
				'prefixes'    => array( 'eael' ),
				'categories'  => array( 'essential-addons-elementor' ),
				'verified'    => false,
			),
			'elementskit-lite'  => array(
				'name'        => __( 'ElementsKit', 'ai-builder-connector' ),
				'plugin_file' => 'elementskit-lite/elementskit-lite.php',
				'plugin_files' => array( 'elementskit-lite/elementskit-lite.php', 'elementskit/elementskit.php' ),
				'namespaces'  => array( 'ElementsKit_Lite\\', 'ElementsKit\\' ),
				'classes'     => array( '\ElementsKit_Lite\Plugin' ),
				'constants'   => array( 'ELEMENTSKIT_VERSION' ),
				'prefixes'    => array( 'elementskit', 'ekit' ),
				'categories'  => array( 'elementskit' ),
				'verified'    => false,
			),
			'premium-addons-for-elementor' => array(
				'name'        => __( 'Premium Addons for Elementor', 'ai-builder-connector' ),
				'plugin_file' => 'premium-addons-for-elementor/premium-addons-for-elementor.php',
				'namespaces'  => array( 'PremiumAddons\\' ),
				'classes'     => array( '\PremiumAddons\Includes\Premium_Template_Tags' ),
				'constants'   => array( 'PREMIUM_ADDONS_VERSION' ),
				'prefixes'    => array( 'premium' ),
				'categories'  => array( 'premium-elements' ),
				'verified'    => false,
			),
			'happy-elementor-addons' => array(
				'name'        => __( 'Happy Addons', 'ai-builder-connector' ),
				'plugin_file' => 'happy-elementor-addons/plugin.php',
				'namespaces'  => array( 'Happy_Addons\\', 'Happy_Addons_Pro\\' ),
				'classes'     => array( '\Happy_Addons\Elementor\Widgets_Manager' ),
				'constants'   => array( 'HAPPY_ADDONS_VERSION' ),
				'prefixes'    => array( 'ha' ),
				'categories'  => array( 'happy_addons_category' ),
				'verified'    => false,
			),
			'ultimate-elementor' => array(
				'name'        => __( 'Ultimate Addons for Elementor', 'ai-builder-connector' ),
				'plugin_file' => 'ultimate-elementor/ultimate-elementor.php',
				'namespaces'  => array( 'UltimateElementor\\', 'UAE\\' ),
				'classes'     => array( '\UltimateElementor\Classes\UAEL_Helper' ),
				'constants'   => array( 'ULTIMATE_ELEMENTOR_VERSION', 'UAEL_VER' ),
				'prefixes'    => array( 'uael' ),
				'categories'  => array( 'ultimate-elements' ),
				'verified'    => false,
			),
			'powerpack-lite-for-elementor' => array(
				'name'        => __( 'PowerPack Addons', 'ai-builder-connector' ),
				'plugin_file' => 'powerpack-lite-for-elementor/powerpack-lite-elementor.php',
				'plugin_files' => array( 'powerpack-lite-for-elementor/powerpack-lite-elementor.php', 'powerpack-elements/powerpack-elements.php' ),
				'namespaces'  => array( 'PowerpackElements\\', 'PowerPackElements\\' ),
				'classes'     => array(),
				'constants'   => array( 'POWERPACK_ELEMENTS_VER' ),
				'prefixes'    => array( 'pp' ),
				'categories'  => array( 'powerpack-elements' ),
				'verified'    => false,
			),
			'the-plus-addons-for-elementor-page-builder' => array(
				'name'        => __( 'The Plus Addons', 'ai-builder-connector' ),
				'plugin_file' => 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php',
				'plugin_files' => array( 'the-plus-addons-for-elementor-page-builder/theplus_elementor_addon.php', 'theplus_elementor_addon/theplus_elementor_addon.php' ),
				'namespaces'  => array( 'TheplusAddons\\' ),
				'classes'     => array(),
				'constants'   => array( 'THEPLUS_VERSION' ),
				'prefixes'    => array( 'tp', 'theplus' ),
				'categories'  => array( 'plus-essential' ),
				'verified'    => false,
			),
		);
	}

	/**
	 * Gets detected active source plugins plus virtual Elementor sources.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_detected_sources(): array {
		$sources      = array();
		$plugins      = $this->get_plugins();
		$active_files = $this->get_active_plugin_files();
		$definitions  = $this->get_known_definitions();

		foreach ( $definitions as $slug => $definition ) {
			$plugin_files = $this->get_definition_plugin_files( $definition );
			$plugin_file  = $this->find_installed_plugin_file( $plugin_files, $plugins );
			$active       = $this->is_definition_active( $definition, $active_files );
			$version      = $this->get_definition_version( $definition, $plugins, $plugin_file );
			$installed    = '' !== $plugin_file || $this->definition_has_runtime_signal( $definition );

			if ( self::ELEMENTOR_CORE === $slug ) {
				$installed = $this->elementor_detector->is_installed();
				$active    = $this->elementor_detector->is_active();
				$version   = $this->elementor_detector->get_version();
			}

			if ( ! $installed && ! $active ) {
				continue;
			}

			$sources[ $slug ] = array(
				'slug'          => $slug,
				'name'          => (string) $definition['name'],
				'plugin_file'   => $plugin_file,
				'plugin_dir'    => $this->get_plugin_dir_from_file( $plugin_file ),
				'version'       => $version,
				'active'        => $active,
				'detected'      => true,
				'verified'      => ! empty( $definition['verified'] ),
				'detection'     => $active ? 'detected' : 'inactive',
				'widget_count'  => 0,
			);
		}

		$sources[ self::UNKNOWN_SOURCE ] = array(
			'slug'         => self::UNKNOWN_SOURCE,
			'name'         => __( 'Unknown Source', 'ai-builder-connector' ),
			'plugin_file'  => '',
			'plugin_dir'   => '',
			'version'      => '',
			'active'       => false,
			'detected'     => true,
			'verified'     => false,
			'detection'    => 'unknown',
			'widget_count' => 0,
		);

		return $sources;
	}

	/**
	 * Gets installed plugins.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'get_plugins' ) ? get_plugins() : array();
	}

	/**
	 * Gets active plugin files.
	 *
	 * @return array<int, string>
	 */
	public function get_active_plugin_files(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = get_option( 'active_plugins', array() );

		return is_array( $active_plugins ) ? array_map( 'sanitize_text_field', $active_plugins ) : array();
	}

	/**
	 * Gets plugin files for a definition.
	 *
	 * @param array<string, mixed> $definition Source definition.
	 * @return array<int, string>
	 */
	public function get_definition_plugin_files( array $definition ): array {
		$files = array();

		if ( ! empty( $definition['plugin_file'] ) && is_string( $definition['plugin_file'] ) ) {
			$files[] = $definition['plugin_file'];
		}

		if ( ! empty( $definition['plugin_files'] ) && is_array( $definition['plugin_files'] ) ) {
			foreach ( $definition['plugin_files'] as $plugin_file ) {
				if ( is_string( $plugin_file ) ) {
					$files[] = $plugin_file;
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Gets the absolute directory for a plugin file.
	 *
	 * @param string $plugin_file Plugin file relative to WP plugins directory.
	 */
	public function get_plugin_dir_from_file( string $plugin_file ): string {
		if ( '' === $plugin_file ) {
			return '';
		}

		return wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . dirname( $plugin_file ) );
	}

	/**
	 * Finds an installed plugin file from candidates.
	 *
	 * @param array<int, string>                  $plugin_files Candidate plugin files.
	 * @param array<string, array<string, string>> $plugins Installed plugins.
	 */
	private function find_installed_plugin_file( array $plugin_files, array $plugins ): string {
		foreach ( $plugin_files as $plugin_file ) {
			if ( isset( $plugins[ $plugin_file ] ) ) {
				return $plugin_file;
			}
		}

		return '';
	}

	/**
	 * Checks whether a definition is active.
	 *
	 * @param array<string, mixed> $definition Source definition.
	 * @param array<int, string>   $active_files Active plugin files.
	 */
	private function is_definition_active( array $definition, array $active_files ): bool {
		foreach ( $this->get_definition_plugin_files( $definition ) as $plugin_file ) {
			if ( in_array( $plugin_file, $active_files, true ) ) {
				return true;
			}
		}

		return $this->definition_has_runtime_signal( $definition );
	}

	/**
	 * Checks runtime constants/classes for a definition.
	 *
	 * @param array<string, mixed> $definition Source definition.
	 */
	private function definition_has_runtime_signal( array $definition ): bool {
		foreach ( (array) ( $definition['constants'] ?? array() ) as $constant ) {
			if ( is_string( $constant ) && defined( $constant ) ) {
				return true;
			}
		}

		foreach ( (array) ( $definition['classes'] ?? array() ) as $class_name ) {
			if ( is_string( $class_name ) && class_exists( $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets a definition version.
	 *
	 * @param array<string, mixed>                  $definition Source definition.
	 * @param array<string, array<string, string>> $plugins Installed plugins.
	 * @param string                               $plugin_file Installed plugin file.
	 */
	private function get_definition_version( array $definition, array $plugins, string $plugin_file ): string {
		foreach ( (array) ( $definition['constants'] ?? array() ) as $constant ) {
			if ( is_string( $constant ) && defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		if ( '' !== $plugin_file && isset( $plugins[ $plugin_file ]['Version'] ) ) {
			return (string) $plugins[ $plugin_file ]['Version'];
		}

		return '';
	}
}
