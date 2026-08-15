<?php
/**
 * Uninstall handler for AI Builder Connector.
 *
 * @package AIBuilderConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'aibc_allowed_addons' );
delete_option( 'aibc_allowed_widgets' );
delete_option( 'aibc_widget_scan_summary' );
delete_option( 'aibc_widget_scan_snapshot' );
delete_option( 'aibc_mcp_connections' );
delete_option( 'aibc_mcp_disabled' );
delete_option( 'aibc_mcp_audit_log' );
delete_option( 'aibc_mcp_action_log' );
delete_option( 'aibc_design_system' );
delete_option( 'aibc_wizard_completed' );
delete_option( 'aibc_wizard_redirect' );
