<?php
/**
 * Elementor detection service.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects Elementor installation, activation, version, and initialization state.
 */
final class Elementor_Detector {
	private const ELEMENTOR_PLUGIN_FILE = 'elementor/elementor.php';

	/**
	 * Returns Elementor status details.
	 *
	 * @return array{installed: bool, active: bool, initialized: bool, version: string}
	 */
	public function get_status(): array {
		return array(
			'installed'    => $this->is_installed(),
			'active'       => $this->is_active(),
			'initialized'  => $this->is_initialized(),
			'version'      => $this->get_version(),
		);
	}

	/**
	 * Checks whether Elementor is installed.
	 */
	public function is_installed(): bool {
		$plugins = $this->get_plugins();

		return isset( $plugins[ self::ELEMENTOR_PLUGIN_FILE ] )
			|| defined( 'ELEMENTOR_VERSION' )
			|| class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Checks whether Elementor is active.
	 */
	public function is_active(): bool {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::ELEMENTOR_PLUGIN_FILE ) ) {
			return true;
		}

		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Checks whether Elementor has fired its initialization hook.
	 */
	public function is_initialized(): bool {
		return $this->is_active()
			&& did_action( 'elementor/init' ) > 0
			&& class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Gets the detected Elementor version.
	 */
	public function get_version(): string {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			return (string) ELEMENTOR_VERSION;
		}

		$plugins = $this->get_plugins();

		if ( isset( $plugins[ self::ELEMENTOR_PLUGIN_FILE ]['Version'] ) ) {
			return (string) $plugins[ self::ELEMENTOR_PLUGIN_FILE ]['Version'];
		}

		return '';
	}

	/**
	 * Loads the installed plugin list when available.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		return get_plugins();
	}
}
