<?php
/**
 * MCP action log.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores compact audit entries for MCP actions.
 */
final class MCP_Action_Log {
	public const OPTION_ACTION_LOG = 'aibc_mcp_action_log';

	/**
	 * Records an action.
	 *
	 * @param string              $connection_id Connection ID.
	 * @param string              $tool_name Tool name.
	 * @param string              $status Action status.
	 * @param array<string,mixed> $context Safe context.
	 */
	public function record( string $connection_id, string $tool_name, string $status, array $context = array() ): string {
		$actions = $this->get_actions();
		$id      = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'aibc_action_', true );

		array_unshift(
			$actions,
			array(
				'id'            => sanitize_text_field( $id ),
				'connection_id' => sanitize_text_field( $connection_id ),
				'tool'          => sanitize_key( $tool_name ),
				'status'        => sanitize_key( $status ),
				'context'       => $this->sanitize_context( $context ),
				'created_at'    => gmdate( 'c' ),
			)
		);

		update_option( self::OPTION_ACTION_LOG, array_slice( $actions, 0, 100 ), false );

		return $id;
	}

	/**
	 * Gets logged actions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_actions(): array {
		$value = get_option( self::OPTION_ACTION_LOG, array() );

		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}

	/**
	 * Finds one action by ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_action( string $action_id ): ?array {
		$action_id = sanitize_text_field( $action_id );

		foreach ( $this->get_actions() as $action ) {
			if ( $action_id === (string) ( $action['id'] ?? '' ) ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * Sanitizes context values before storage.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,mixed>
	 */
	private function sanitize_context( array $context ): array {
		$sanitized = array();

		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$items = array();

				foreach ( $value as $item ) {
					if ( is_scalar( $item ) || null === $item ) {
						$items[] = sanitize_text_field( (string) $item );
					}
				}

				$sanitized[ $key ] = array_values( array_filter( $items ) );
			}
		}

		return $sanitized;
	}
}
