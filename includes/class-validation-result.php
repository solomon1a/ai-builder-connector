<?php
/**
 * Validation result object.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects validation errors without throwing.
 */
final class Validation_Result {
	/**
	 * Validation errors.
	 *
	 * @var array<int,array{code:string,message:string,context:array<string,mixed>}>
	 */
	private array $errors = array();

	/**
	 * Adds a validation error.
	 *
	 * @param string              $code Error code.
	 * @param string              $message Human-readable error.
	 * @param array<string,mixed> $context Safe context.
	 */
	public function add_error( string $code, string $message, array $context = array() ): void {
		$this->errors[] = array(
			'code'    => sanitize_key( $code ),
			'message' => sanitize_text_field( $message ),
			'context' => $this->sanitize_context( $context ),
		);
	}

	/**
	 * Merges another result into this one.
	 */
	public function merge( self $result ): void {
		foreach ( $result->get_errors() as $error ) {
			$this->errors[] = $error;
		}
	}

	/**
	 * Checks whether no errors were collected.
	 */
	public function is_valid(): bool {
		return empty( $this->errors );
	}

	/**
	 * Gets validation errors.
	 *
	 * @return array<int,array{code:string,message:string,context:array<string,mixed>}>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Gets plain validation messages.
	 *
	 * @return array<int,string>
	 */
	public function get_error_messages(): array {
		return array_values(
			array_map(
				static fn ( array $error ): string => $error['message'],
				$this->errors
			)
		);
	}

	/**
	 * Sanitizes context before storage.
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
			}
		}

		return $sanitized;
	}
}
