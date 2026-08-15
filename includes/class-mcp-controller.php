<?php
/**
 * MCP REST controller.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a minimal authenticated MCP endpoint.
 */
final class MCP_Controller {
	/**
	 * MCP connection manager.
	 *
	 * @var MCP_Connection_Manager
	 */
	private MCP_Connection_Manager $connection_manager;

	/**
	 * MCP tool registry.
	 *
	 * @var MCP_Tool_Registry
	 */
	private MCP_Tool_Registry $tool_registry;

	/**
	 * MCP controller constructor.
	 */
	public function __construct( MCP_Connection_Manager $connection_manager, MCP_Tool_Registry $tool_registry ) {
		$this->connection_manager = $connection_manager;
		$this->tool_registry      = $tool_registry;
	}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'ai-builder-connector/v1',
			'/mcp',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => '__return_true',
				),
				array(
					// MCP clients may probe GET (server-sent event stream) and DELETE
					// (session end). This server is POST-only, so answer with a
					// spec-correct 405 and an Allow header instead of a 404.
					'methods'             => 'GET, DELETE',
					'callback'            => array( $this, 'handle_unsupported_method' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Responds to unsupported HTTP methods on the MCP route.
	 */
	public function handle_unsupported_method(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32000,
					'message' => __( 'Method not allowed. Use POST for MCP requests.', 'ai-builder-connector' ),
				),
			),
			405
		);

		$response->header( 'Allow', 'POST' );

		return $response;
	}

	/**
	 * Handles JSON-RPC-style MCP requests.
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return $this->error_response( null, -32700, __( 'Invalid JSON request body.', 'ai-builder-connector' ), 400 );
		}

		$id     = $payload['id'] ?? null;
		$method = sanitize_text_field( (string) ( $payload['method'] ?? '' ) );

		if ( '' === $method ) {
			return $this->error_response( $id, -32600, __( 'MCP method is required.', 'ai-builder-connector' ), 400 );
		}

		$auth = $this->connection_manager->authenticate_token( $this->connection_manager->get_bearer_token( $request ) );

		if ( is_wp_error( $auth ) ) {
			$this->connection_manager->log_request( '', $method, '', 'denied' );
			return $this->error_response( $id, -32001, $auth->get_error_message(), $this->error_status( $auth, 401 ) );
		}

		// MCP notifications (e.g. notifications/initialized) expect no result body.
		// Per the Streamable HTTP transport, acknowledge with 202 and no content.
		if ( str_starts_with( $method, 'notifications/' ) ) {
			$this->connection_manager->log_request( (string) $auth['id'], $method, '', 'ok' );
			return new \WP_REST_Response( null, 202 );
		}

		if ( 'initialize' === $method ) {
			$this->connection_manager->log_request( (string) $auth['id'], $method, '', 'ok' );

			$params         = isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();
			$client_version = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] )
				? sanitize_text_field( $params['protocolVersion'] )
				: '';

			// Echo the client's protocol version when it sends one so version
			// negotiation always succeeds; otherwise fall back to a known version.
			$protocol_version = '' !== $client_version ? $client_version : '2025-03-26';

			return $this->success_response(
				$id,
				array(
					'protocolVersion' => $protocol_version,
					'serverInfo'      => array(
						'name'    => 'AI Builder Connector',
						'version' => AIBC_VERSION,
					),
					// capabilities.tools MUST be a JSON object, not an array. An
					// empty PHP array would serialize as [] and fail strict MCP
					// client schema validation, so declare the tools capability.
					'capabilities'    => array(
						'tools' => array( 'listChanged' => false ),
					),
				)
			);
		}

		if ( 'tools/list' === $method ) {
			$this->connection_manager->log_request( (string) $auth['id'], $method, '', 'ok' );
			return $this->success_response( $id, array( 'tools' => $this->filter_allowed_tools( $auth ) ) );
		}

		if ( 'tools/call' === $method ) {
			return $this->handle_tool_call( $id, $payload, $auth, $method );
		}

		$this->connection_manager->log_request( (string) $auth['id'], $method, '', 'denied' );
		return $this->error_response( $id, -32601, __( 'Unsupported MCP method.', 'ai-builder-connector' ), 404 );
	}

	/**
	 * Handles a tools/call request.
	 *
	 * @param mixed               $id JSON-RPC request ID.
	 * @param array<string,mixed> $payload Request payload.
	 * @param array<string,mixed> $connection Authenticated connection.
	 */
	private function handle_tool_call( mixed $id, array $payload, array $connection, string $method ): \WP_REST_Response {
		$params    = isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array();
		$tool_name = sanitize_key( (string) ( $params['name'] ?? '' ) );

		if ( '' === $tool_name ) {
			$this->connection_manager->log_request( (string) $connection['id'], $method, '', 'denied' );
			return $this->error_response( $id, -32602, __( 'Tool name is required.', 'ai-builder-connector' ), 400 );
		}

		if ( ! $this->connection_manager->has_permission( $connection, $tool_name ) ) {
			$this->connection_manager->log_request( (string) $connection['id'], $method, $tool_name, 'denied' );
			return $this->error_response( $id, -32003, __( 'This MCP connection is not allowed to call that tool.', 'ai-builder-connector' ), 403 );
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$result    = $this->tool_registry->call_tool( $tool_name, $connection, $arguments );

		if ( is_wp_error( $result ) ) {
			$this->connection_manager->log_request( (string) $connection['id'], $method, $tool_name, 'error' );
			return $this->error_response( $id, -32000, $result->get_error_message(), $this->error_status( $result, 400 ) );
		}

		$this->connection_manager->log_request( (string) $connection['id'], $method, $tool_name, 'ok' );

		return $this->success_response(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result ),
					),
				),
				'isError' => false,
			)
		);
	}

	/**
	 * Filters visible tools by connection permissions.
	 *
	 * @param array<string,mixed> $connection Authenticated connection.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_allowed_tools( array $connection ): array {
		$tools = array();

		foreach ( $this->tool_registry->list_tools() as $tool ) {
			if ( $this->connection_manager->has_permission( $connection, (string) $tool['name'] ) ) {
				$tools[] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Builds a JSON-RPC success response.
	 *
	 * @param mixed               $id JSON-RPC request ID.
	 * @param array<string,mixed> $result Result payload.
	 */
	private function success_response( mixed $id, array $result ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Builds a JSON-RPC error response.
	 *
	 * @param mixed  $id JSON-RPC request ID.
	 * @param int    $code JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 */
	private function error_response( mixed $id, int $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => sanitize_text_field( $message ),
				),
			),
			$status
		);
	}

	/**
	 * Gets a safe HTTP status from a WP_Error object.
	 */
	private function error_status( \WP_Error $error, int $fallback ): int {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return absint( $data['status'] );
		}

		return $fallback;
	}
}
