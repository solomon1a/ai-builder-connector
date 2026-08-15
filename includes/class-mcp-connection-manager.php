<?php
/**
 * MCP connection manager.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, stores, authenticates, and revokes MCP connections.
 */
final class MCP_Connection_Manager {
	public const OPTION_CONNECTIONS = 'aibc_mcp_connections';
	public const OPTION_DISABLED    = 'aibc_mcp_disabled';
	public const OPTION_AUDIT_LOG   = 'aibc_mcp_audit_log';

	private const TOKEN_PREFIX = 'aibc_';
	private const TRANSIENT_PREFIX = 'aibc_mcp_token_';

	/**
	 * Gets default scoped MCP tool permissions.
	 *
	 * @return array<int,string>
	 */
	public function get_default_permissions(): array {
		return array(
			'ping',
			'get_site_context',
			'get_builder_status',
			'get_connection_permissions',
			'get_design_system',
			'list_widget_definitions',
			'get_widget_definition',
			'list_page_templates',
			'get_page_template',
			'list_allowed_addons',
			'list_allowed_widgets',
			'list_ai_drafts',
			'get_ai_draft',
			'create_page_plan',
			'validate_page_plan',
			'create_elementor_draft',
			'revise_elementor_draft',
			'validate_elementor_draft',
			'get_preview_link',
			'list_ai_actions',
			'get_action_details',
			'approve_ai_draft',
			'reject_ai_draft',
			'rollback_action',
			'delete_ai_draft',
			'search_stock_images',
			'import_stock_image',
			'set_draft_seo_meta',
			'set_draft_custom_css',
			'save_draft_as_template',
			'list_saved_templates',
			'list_brand_kits',
			'get_atomic_status',
		);
	}

	/**
	 * Creates a new connection and returns its stored row plus plaintext token.
	 *
	 * @return array{connection:array<string,mixed>,token:string}
	 */
	public function create_connection( string $label, int $expires_in_days ): array {
		$connections = $this->get_connections();
		$id          = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'aibc_', true );
		$token       = self::TOKEN_PREFIX . wp_generate_password( 48, false, false );
		$expires_at  = $expires_in_days > 0 ? gmdate( 'c', time() + ( DAY_IN_SECONDS * $expires_in_days ) ) : '';

		$connection = array(
			'id'          => sanitize_text_field( $id ),
			'label'       => sanitize_text_field( '' !== trim( $label ) ? $label : __( 'MCP Connection', 'ai-builder-connector' ) ),
			'token_hash'  => wp_hash_password( $token ),
			'token_last4' => substr( $token, -4 ),
			'permissions' => $this->get_default_permissions(),
			'created_at'  => gmdate( 'c' ),
			'expires_at'  => $expires_at,
			'last_used_at' => '',
			'revoked_at'  => '',
		);

		$connections[ $connection['id'] ] = $connection;
		update_option( self::OPTION_CONNECTIONS, $connections, false );

		return array(
			'connection' => $connection,
			'token'      => $token,
		);
	}

	/**
	 * Stores a plaintext token briefly for one-time admin display.
	 */
	public function store_one_time_token( string $connection_id, string $token ): void {
		set_transient( $this->transient_key( $connection_id ), $token, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Consumes a one-time plaintext token for the current administrator.
	 */
	public function consume_one_time_token( string $connection_id ): string {
		$key   = $this->transient_key( $connection_id );
		$token = get_transient( $key );

		if ( is_string( $token ) ) {
			delete_transient( $key );
			return $token;
		}

		return '';
	}

	/**
	 * Gets all connections.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_connections(): array {
		$value = get_option( self::OPTION_CONNECTIONS, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$connections = array();

		foreach ( $value as $id => $connection ) {
			if ( is_array( $connection ) ) {
				$row = $this->sanitize_connection( $connection );
				if ( '' !== $row['id'] ) {
					$connections[ $row['id'] ] = $row;
				}
			}
		}

		return $connections;
	}

	/**
	 * Revokes a connection.
	 */
	public function revoke_connection( string $connection_id ): bool {
		$connections   = $this->get_connections();
		$connection_id = sanitize_text_field( $connection_id );

		if ( ! isset( $connections[ $connection_id ] ) ) {
			return false;
		}

		$connections[ $connection_id ]['revoked_at'] = gmdate( 'c' );
		update_option( self::OPTION_CONNECTIONS, $connections, false );

		return true;
	}

	/**
	 * Authenticates a bearer token.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function authenticate_token( string $token ): array|\WP_Error {
		$token = trim( $token );

		if ( $this->is_disabled() ) {
			return new \WP_Error( 'aibc_mcp_disabled', __( 'MCP access is disabled.', 'ai-builder-connector' ), array( 'status' => 403 ) );
		}

		if ( '' === $token || ! str_starts_with( $token, self::TOKEN_PREFIX ) ) {
			return new \WP_Error( 'aibc_mcp_missing_token', __( 'A valid MCP bearer token is required.', 'ai-builder-connector' ), array( 'status' => 401 ) );
		}

		$connections = $this->get_connections();

		foreach ( $connections as $connection ) {
			if ( '' !== (string) $connection['revoked_at'] || $this->is_expired( $connection ) ) {
				continue;
			}

			if ( wp_check_password( $token, (string) $connection['token_hash'] ) ) {
				$this->touch_connection( (string) $connection['id'] );
				return $connection;
			}
		}

		return new \WP_Error( 'aibc_mcp_invalid_token', __( 'The MCP bearer token is invalid or expired.', 'ai-builder-connector' ), array( 'status' => 401 ) );
	}

	/**
	 * Gets the Authorization bearer token from a REST request.
	 */
	public function get_bearer_token( \WP_REST_Request $request ): string {
		$header = $request->get_header( 'authorization' );

		if ( ! is_string( $header ) || '' === $header ) {
			return '';
		}

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	/**
	 * Checks whether MCP access is globally disabled.
	 */
	public function is_disabled(): bool {
		return '1' === get_option( self::OPTION_DISABLED, '0' );
	}

	/**
	 * Sets the emergency disable flag.
	 */
	public function set_disabled( bool $disabled ): void {
		update_option( self::OPTION_DISABLED, $disabled ? '1' : '0', false );
	}

	/**
	 * Records a compact MCP request audit row.
	 */
	public function log_request( string $connection_id, string $method, string $tool_name, string $status ): void {
		$log = get_option( self::OPTION_AUDIT_LOG, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'connection_id' => sanitize_text_field( $connection_id ),
				'method'        => sanitize_text_field( $method ),
				'tool'          => sanitize_text_field( $tool_name ),
				'status'        => sanitize_key( $status ),
				'created_at'    => gmdate( 'c' ),
			)
		);

		update_option( self::OPTION_AUDIT_LOG, array_slice( $log, 0, 50 ), false );
	}

	/**
	 * Checks whether a connection has a permission.
	 *
	 * @param array<string,mixed> $connection Connection row.
	 */
	public function has_permission( array $connection, string $permission ): bool {
		$permissions = isset( $connection['permissions'] ) && is_array( $connection['permissions'] ) ? array_map( 'sanitize_key', $connection['permissions'] ) : array();

		// Full draft-builder connections (created with the complete default scope)
		// automatically gain tools added in later plugin versions. Reduced-scope
		// connections keep their exact stored list.
		if ( in_array( 'create_elementor_draft', $permissions, true ) && in_array( 'delete_ai_draft', $permissions, true ) ) {
			$permissions = array_values( array_unique( array_merge( $permissions, $this->get_default_permissions() ) ) );
		}

		return in_array( sanitize_key( $permission ), $permissions, true );
	}

	/**
	 * Checks whether a connection has expired.
	 *
	 * @param array<string,mixed> $connection Connection row.
	 */
	public function is_expired( array $connection ): bool {
		$expires_at = isset( $connection['expires_at'] ) ? (string) $connection['expires_at'] : '';

		return '' !== $expires_at && strtotime( $expires_at ) <= time();
	}

	/**
	 * Gets a safe public connection row for display/API responses.
	 *
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>
	 */
	public function public_connection( array $connection ): array {
		return array(
			'id'           => sanitize_text_field( (string) ( $connection['id'] ?? '' ) ),
			'label'        => sanitize_text_field( (string) ( $connection['label'] ?? '' ) ),
			'token_last4'  => sanitize_text_field( (string) ( $connection['token_last4'] ?? '' ) ),
			'permissions'  => array_values( array_map( 'sanitize_key', (array) ( $connection['permissions'] ?? array() ) ) ),
			'created_at'   => sanitize_text_field( (string) ( $connection['created_at'] ?? '' ) ),
			'expires_at'   => sanitize_text_field( (string) ( $connection['expires_at'] ?? '' ) ),
			'last_used_at' => sanitize_text_field( (string) ( $connection['last_used_at'] ?? '' ) ),
			'revoked_at'   => sanitize_text_field( (string) ( $connection['revoked_at'] ?? '' ) ),
			'expired'      => $this->is_expired( $connection ),
		);
	}

	/**
	 * Updates last-used time.
	 */
	private function touch_connection( string $connection_id ): void {
		$connections = $this->get_connections();

		if ( isset( $connections[ $connection_id ] ) ) {
			$connections[ $connection_id ]['last_used_at'] = gmdate( 'c' );
			update_option( self::OPTION_CONNECTIONS, $connections, false );
		}
	}

	/**
	 * Builds a current-user transient key.
	 */
	private function transient_key( string $connection_id ): string {
		return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . md5( $connection_id );
	}

	/**
	 * Sanitizes one connection row.
	 *
	 * @param array<string,mixed> $connection Raw row.
	 * @return array<string,mixed>
	 */
	private function sanitize_connection( array $connection ): array {
		return array(
			'id'           => sanitize_text_field( (string) ( $connection['id'] ?? '' ) ),
			'label'        => sanitize_text_field( (string) ( $connection['label'] ?? '' ) ),
			'token_hash'   => (string) ( $connection['token_hash'] ?? '' ),
			'token_last4'  => sanitize_text_field( (string) ( $connection['token_last4'] ?? '' ) ),
			'permissions'  => array_values( array_map( 'sanitize_key', (array) ( $connection['permissions'] ?? array() ) ) ),
			'created_at'   => sanitize_text_field( (string) ( $connection['created_at'] ?? '' ) ),
			'expires_at'   => sanitize_text_field( (string) ( $connection['expires_at'] ?? '' ) ),
			'last_used_at' => sanitize_text_field( (string) ( $connection['last_used_at'] ?? '' ) ),
			'revoked_at'   => sanitize_text_field( (string) ( $connection['revoked_at'] ?? '' ) ),
		);
	}
}
