<?php
/**
 * AI-authored widget content sanitizer.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes AI-supplied content values against verified widget content rules.
 */
final class Widget_Content_Sanitizer {
	private const MAX_TEXT_LENGTH      = 300;
	private const MAX_RICH_TEXT_LENGTH = 8000;

	private const RICH_TEXT_TAGS = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'  => true,
			'title' => true,
			'rel'   => true,
		),
	);

	/**
	 * Verified widget definitions.
	 *
	 * @var Widget_Definition_Registry
	 */
	private Widget_Definition_Registry $widget_definitions;

	/**
	 * Sanitizer constructor.
	 */
	public function __construct( Widget_Definition_Registry $widget_definitions ) {
		$this->widget_definitions = $widget_definitions;
	}

	/**
	 * Sanitizes AI-supplied content for one verified widget.
	 *
	 * Unknown keys and unsafe values are dropped and reported, never stored.
	 *
	 * @param string              $widget_name Verified widget identifier.
	 * @param array<string,mixed> $content AI-supplied content values.
	 * @return array{settings: array<string,mixed>, rejected: array<int,array{setting:string,reason:string}>}
	 */
	public function sanitize_content( string $widget_name, array $content ): array {
		$rules    = $this->widget_definitions->content_setting_rules( $widget_name );
		$settings = array();
		$rejected = array();

		foreach ( $content as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $rules[ $key ] ) ) {
				$rejected[] = array(
					'setting' => $key,
					'reason'  => __( 'This setting is not AI-authorable for this widget.', 'ai-builder-connector' ),
				);
				continue;
			}

			$clean = $this->sanitize_value( $rules[ $key ], $value );

			if ( null === $clean ) {
				$rejected[] = array(
					'setting' => $key,
					'reason'  => __( 'The supplied value was empty, unsafe, or not allowed for this setting.', 'ai-builder-connector' ),
				);
				continue;
			}

			$settings[ $key ] = $clean;
		}

		return array(
			'settings' => $settings,
			'rejected' => $rejected,
		);
	}

	/**
	 * Sanitizes one content value by rule type. Returns null when unsafe.
	 *
	 * @param array<string,mixed> $rule Content rule with type and optional choices.
	 * @param mixed               $value Raw value.
	 */
	private function sanitize_value( array $rule, mixed $value ): mixed {
		switch ( sanitize_key( (string) ( $rule['type'] ?? '' ) ) ) {
			case 'text':
				return $this->sanitize_text( $value );
			case 'rich_text':
				return $this->sanitize_rich_text( $value );
			case 'url':
				return is_scalar( $value ) ? $this->sanitize_url( (string) $value ) : null;
			case 'choice':
				return $this->sanitize_choice( $rule, $value );
			case 'link':
				return $this->sanitize_link( $value );
			default:
				return null;
		}
	}

	/**
	 * Removes script and style blocks including their inner text.
	 *
	 * wp_kses and sanitize_text_field strip dangerous tags but keep the tag
	 * inner text, which leaves junk like "alert(1)" in AI content. This pass
	 * drops the whole block before any other processing.
	 */
	private function strip_dangerous_blocks( string $value ): string {
		$stripped = preg_replace( '#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value );
		$stripped = is_string( $stripped ) ? $stripped : $value;

		// Drop any unclosed opening block as well, to the end of the value.
		$stripped = preg_replace( '#<\s*(script|style)\b[^>]*>.*$#is', '', $stripped );

		return is_string( $stripped ) ? $stripped : $value;
	}

	/**
	 * Sanitizes a plain text value.
	 */
	private function sanitize_text( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = sanitize_text_field( $this->strip_dangerous_blocks( (string) $value ) );

		if ( mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
		}

		return '' !== trim( $text ) ? $text : null;
	}

	/**
	 * Sanitizes limited rich text for the Elementor text editor.
	 */
	private function sanitize_rich_text( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$html = trim( $this->strip_dangerous_blocks( (string) $value ) );

		if ( mb_strlen( $html ) > self::MAX_RICH_TEXT_LENGTH ) {
			$html = mb_substr( $html, 0, self::MAX_RICH_TEXT_LENGTH );
		}

		$html = wp_kses( $html, self::RICH_TEXT_TAGS );

		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return null;
		}

		if ( false === strpos( $html, '<p' ) ) {
			$html = wpautop( $html );
		}

		return $html;
	}

	/**
	 * Sanitizes a URL. Allows http, https, #anchors, and site-relative paths only.
	 */
	private function sanitize_url( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url ) {
			return null;
		}

		if ( str_starts_with( $url, '#' ) || ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) ) {
			$relative = sanitize_text_field( $url );

			return '' !== $relative ? $relative : null;
		}

		$clean = esc_url_raw( $url, array( 'http', 'https' ) );

		return '' !== $clean ? $clean : null;
	}

	/**
	 * Sanitizes a strict enum choice.
	 *
	 * @param array<string,mixed> $rule Content rule.
	 */
	private function sanitize_choice( array $rule, mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$choice  = sanitize_text_field( (string) $value );
		$choices = array_values( array_map( 'strval', (array) ( $rule['choices'] ?? array() ) ) );

		return in_array( $choice, $choices, true ) ? $choice : null;
	}

	/**
	 * Sanitizes an Elementor link object.
	 *
	 * @return array{url:string,is_external:string,nofollow:string}|null
	 */
	private function sanitize_link( mixed $value ): ?array {
		if ( is_scalar( $value ) ) {
			$value = array( 'url' => (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$url = isset( $value['url'] ) && is_scalar( $value['url'] ) ? $this->sanitize_url( (string) $value['url'] ) : null;

		if ( null === $url ) {
			return null;
		}

		return array(
			'url'         => $url,
			'is_external' => ! empty( $value['is_external'] ) ? 'on' : '',
			'nofollow'    => ! empty( $value['nofollow'] ) ? 'on' : '',
		);
	}
}
