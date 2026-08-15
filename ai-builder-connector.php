<?php
/**
 * Plugin Name: AI Builder Connector
 * Description: Creates validated Elementor review pages from AI-authored briefs and scoped MCP tools, with sanitized AI content and admin-gated publishing.
 * Version: 2.0.1
 * Requires PHP: 8.1
 * Author: Syed Saud Ahsan
 * Author URI: https://www.linkedin.com/in/syedsaudahsan/
 * Text Domain: ai-builder-connector
 *
 * @package AIBuilderConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIBC_VERSION', '2.0.1' );
define( 'AIBC_PLUGIN_FILE', __FILE__ );
define( 'AIBC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIBC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'AIBC\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file_name      = 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
		$file_path      = AIBC_PLUGIN_DIR . 'includes/' . $file_name;

		if ( is_readable( $file_path ) ) {
			require_once $file_path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( '1' !== (string) get_option( 'aibc_wizard_completed', '' ) ) {
			update_option( 'aibc_wizard_redirect', '1', false );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new AIBC\Plugin();
		$plugin->register();
	}
);
