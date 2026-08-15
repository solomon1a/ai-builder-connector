<?php
/**
 * Design system token service.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and exposes safe design tokens for generated Elementor drafts.
 */
final class Design_System {
	public const OPTION_DESIGN_SYSTEM = 'aibc_design_system';

	/**
	 * Gets the active design system configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function get_config(): array {
		return $this->normalize_config( get_option( self::OPTION_DESIGN_SYSTEM, array() ) );
	}

	/**
	 * Reads the site's brand colors and fonts from the active Elementor global kit.
	 *
	 * Returns only the keys it can resolve, sanitized. Empty array when no
	 * Elementor kit is available.
	 *
	 * @return array<string,string>
	 */
	public function get_site_brand(): array {
		$kit_id = (int) get_option( 'elementor_active_kit', 0 );

		if ( $kit_id <= 0 ) {
			return array();
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		$brand = array();

		$color_map = array(
			'primary'   => 'primary_color',
			'secondary' => 'secondary_color',
			'text'      => 'text_color',
			'accent'    => 'accent_color',
		);

		$colors = isset( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ? $settings['system_colors'] : array();

		foreach ( $colors as $color ) {
			if ( ! is_array( $color ) ) {
				continue;
			}

			$id  = sanitize_key( (string) ( $color['_id'] ?? '' ) );
			$hex = $this->sanitize_hex_color( (string) ( $color['color'] ?? '' ), '' );

			if ( isset( $color_map[ $id ] ) && '' !== $hex ) {
				$brand[ $color_map[ $id ] ] = $hex;
			}
		}

		$typography = isset( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ? $settings['system_typography'] : array();

		foreach ( $typography as $font ) {
			if ( ! is_array( $font ) ) {
				continue;
			}

			$id     = sanitize_key( (string) ( $font['_id'] ?? '' ) );
			$family = $this->sanitize_font_family( (string) ( $font['typography_font_family'] ?? '' ), '' );

			if ( '' === $family ) {
				continue;
			}

			// Elementor convention: Primary is headings, Text is body copy.
			if ( 'primary' === $id && ! isset( $brand['heading_font_family'] ) ) {
				$brand['heading_font_family'] = $family;
			}

			if ( 'text' === $id && ! isset( $brand['font_family'] ) ) {
				$brand['font_family'] = $family;
			}
		}

		return $brand;
	}

	/**
	 * Gets public tokens for plans, MCP responses, and draft metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function get_tokens(): array {
		$config = $this->get_config();
		$preset = $this->preset_tokens( (string) $config['preset'] );

		$preset['preset'] = sanitize_key( (string) $config['preset'] );
		$preset['colors']['primary'] = (string) $config['primary_color'];
		$preset['colors']['secondary'] = (string) $config['secondary_color'];
		$preset['colors']['accent'] = (string) $config['accent_color'];
		$preset['colors']['text'] = (string) $config['text_color'];
		$preset['colors']['background'] = (string) $config['background_color'];
		$preset['typography']['font_family'] = (string) $config['font_family'];
		$preset['typography']['heading_font_family'] = (string) $config['heading_font_family'];
		$preset['layout']['container_width'] = (int) $config['container_width'];
		$preset['radius']['button'] = (int) $config['button_radius'];

		return $this->sanitize_tokens( $preset );
	}

	/**
	 * Gets available preset labels.
	 *
	 * @return array<string,string>
	 */
	public function get_preset_labels(): array {
		return array(
			'professional' => __( 'Professional', 'ai-builder-connector' ),
			'bold'         => __( 'Bold', 'ai-builder-connector' ),
			'minimal'      => __( 'Minimal', 'ai-builder-connector' ),
		);
	}

	/**
	 * Sanitizes settings saved from the admin page.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string,mixed>
	 */
	public function sanitize_config( mixed $value ): array {
		return $this->normalize_config( $value );
	}

	/**
	 * Normalizes configuration values.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>
	 */
	private function normalize_config( mixed $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$defaults = array(
			'preset'              => 'professional',
			'primary_color'       => '#2563eb',
			'secondary_color'     => '#0f172a',
			'accent_color'        => '#f59e0b',
			'text_color'          => '#111827',
			'background_color'    => '#ffffff',
			'font_family'         => 'Arial',
			'heading_font_family' => 'Arial',
			'container_width'     => 1200,
			'button_radius'       => 6,
		);

		$config = wp_parse_args( $value, $defaults );
		$preset = sanitize_key( (string) $config['preset'] );

		if ( ! array_key_exists( $preset, $this->get_preset_labels() ) ) {
			$preset = 'professional';
		}

		return array(
			'preset'              => $preset,
			'primary_color'       => $this->sanitize_hex_color( (string) $config['primary_color'], $defaults['primary_color'] ),
			'secondary_color'     => $this->sanitize_hex_color( (string) $config['secondary_color'], $defaults['secondary_color'] ),
			'accent_color'        => $this->sanitize_hex_color( (string) $config['accent_color'], $defaults['accent_color'] ),
			'text_color'          => $this->sanitize_hex_color( (string) $config['text_color'], $defaults['text_color'] ),
			'background_color'    => $this->sanitize_hex_color( (string) $config['background_color'], $defaults['background_color'] ),
			'font_family'         => $this->sanitize_font_family( (string) $config['font_family'], $defaults['font_family'] ),
			'heading_font_family' => $this->sanitize_font_family( (string) $config['heading_font_family'], $defaults['heading_font_family'] ),
			'container_width'     => $this->clamp_int( $config['container_width'], 720, 1600, (int) $defaults['container_width'] ),
			'button_radius'       => $this->clamp_int( $config['button_radius'], 0, 32, (int) $defaults['button_radius'] ),
		);
	}

	/**
	 * Gets base preset tokens.
	 *
	 * @return array<string,mixed>
	 */
	private function preset_tokens( string $preset ): array {
		$tokens = array(
			'colors'     => array(
				'primary'    => '#2563eb',
				'secondary'  => '#0f172a',
				'accent'     => '#f59e0b',
				'text'       => '#111827',
				'background' => '#ffffff',
			),
			'typography' => array(
				'font_family'         => 'Arial',
				'heading_font_family' => 'Arial',
				'base_size'           => 16,
				'heading_size'        => 44,
				'heading_weight'      => 700,
				'body_weight'         => 400,
			),
			'layout'     => array(
				'container_width' => 1200,
			),
			'spacing'    => array(
				'scale'       => array( 8, 16, 24, 32, 48, 64 ),
				'section_gap' => 48,
			),
			'radius'     => array(
				'small'  => 4,
				'medium' => 8,
				'button' => 6,
			),
			'buttons'    => array(
				'style'       => 'solid',
				'font_weight' => 600,
			),
			'responsive' => array(
				'mobile_breakpoint' => 767,
				'tablet_breakpoint' => 1024,
				'mobile_section_gap' => 28,
			),
		);

		if ( 'bold' === $preset ) {
			$tokens['colors']['primary'] = '#dc2626';
			$tokens['colors']['secondary'] = '#18181b';
			$tokens['colors']['accent'] = '#22c55e';
			$tokens['typography']['heading_size'] = 52;
			$tokens['spacing']['section_gap'] = 64;
			$tokens['radius']['button'] = 12;
		}

		if ( 'minimal' === $preset ) {
			$tokens['colors']['primary'] = '#374151';
			$tokens['colors']['secondary'] = '#111827';
			$tokens['colors']['accent'] = '#0ea5e9';
			$tokens['typography']['heading_size'] = 40;
			$tokens['spacing']['section_gap'] = 40;
			$tokens['radius']['button'] = 4;
		}

		return $tokens;
	}

	/**
	 * Sanitizes public token output.
	 *
	 * @param array<string,mixed> $tokens Raw tokens.
	 * @return array<string,mixed>
	 */
	private function sanitize_tokens( array $tokens ): array {
		return array(
			'preset'     => sanitize_key( (string) ( $tokens['preset'] ?? 'professional' ) ),
			'colors'     => array(
				'primary'    => $this->sanitize_hex_color( (string) ( $tokens['colors']['primary'] ?? '' ), '#2563eb' ),
				'secondary'  => $this->sanitize_hex_color( (string) ( $tokens['colors']['secondary'] ?? '' ), '#0f172a' ),
				'accent'     => $this->sanitize_hex_color( (string) ( $tokens['colors']['accent'] ?? '' ), '#f59e0b' ),
				'text'       => $this->sanitize_hex_color( (string) ( $tokens['colors']['text'] ?? '' ), '#111827' ),
				'background' => $this->sanitize_hex_color( (string) ( $tokens['colors']['background'] ?? '' ), '#ffffff' ),
			),
			'typography' => array(
				'font_family'         => $this->sanitize_font_family( (string) ( $tokens['typography']['font_family'] ?? '' ), 'Arial' ),
				'heading_font_family' => $this->sanitize_font_family( (string) ( $tokens['typography']['heading_font_family'] ?? '' ), 'Arial' ),
				'base_size'           => $this->clamp_int( $tokens['typography']['base_size'] ?? 16, 12, 24, 16 ),
				'heading_size'        => $this->clamp_int( $tokens['typography']['heading_size'] ?? 44, 24, 72, 44 ),
				'heading_weight'      => $this->clamp_int( $tokens['typography']['heading_weight'] ?? 700, 300, 900, 700 ),
				'body_weight'         => $this->clamp_int( $tokens['typography']['body_weight'] ?? 400, 300, 900, 400 ),
			),
			'layout'     => array(
				'container_width' => $this->clamp_int( $tokens['layout']['container_width'] ?? 1200, 720, 1600, 1200 ),
			),
			'spacing'    => array(
				'scale'       => $this->sanitize_int_list( $tokens['spacing']['scale'] ?? array( 8, 16, 24, 32, 48, 64 ), 0, 160 ),
				'section_gap' => $this->clamp_int( $tokens['spacing']['section_gap'] ?? 48, 16, 120, 48 ),
			),
			'radius'     => array(
				'small'  => $this->clamp_int( $tokens['radius']['small'] ?? 4, 0, 32, 4 ),
				'medium' => $this->clamp_int( $tokens['radius']['medium'] ?? 8, 0, 48, 8 ),
				'button' => $this->clamp_int( $tokens['radius']['button'] ?? 6, 0, 32, 6 ),
			),
			'buttons'    => array(
				'style'       => sanitize_key( (string) ( $tokens['buttons']['style'] ?? 'solid' ) ),
				'font_weight' => $this->clamp_int( $tokens['buttons']['font_weight'] ?? 600, 300, 900, 600 ),
			),
			'responsive' => array(
				'mobile_breakpoint' => $this->clamp_int( $tokens['responsive']['mobile_breakpoint'] ?? 767, 320, 900, 767 ),
				'tablet_breakpoint' => $this->clamp_int( $tokens['responsive']['tablet_breakpoint'] ?? 1024, 768, 1280, 1024 ),
				'mobile_section_gap' => $this->clamp_int( $tokens['responsive']['mobile_section_gap'] ?? 28, 12, 80, 28 ),
			),
		);
	}

	/**
	 * Sanitizes a hex color with fallback.
	 */
	private function sanitize_hex_color( string $color, string $fallback ): string {
		$color = sanitize_hex_color( $color );

		return is_string( $color ) && '' !== $color ? $color : $fallback;
	}

	/**
	 * Sanitizes a conservative font-family value.
	 */
	private function sanitize_font_family( string $font_family, string $fallback ): string {
		$font_family = sanitize_text_field( $font_family );

		if ( '' === $font_family || ! preg_match( '/^[A-Za-z0-9 ,_-]{1,80}$/', $font_family ) ) {
			return $fallback;
		}

		return $font_family;
	}

	/**
	 * Clamps an integer setting.
	 */
	private function clamp_int( mixed $value, int $min, int $max, int $fallback ): int {
		$value = is_numeric( $value ) ? (int) $value : $fallback;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Sanitizes a list of integers.
	 *
	 * @param mixed $items Raw items.
	 * @return array<int,int>
	 */
	private function sanitize_int_list( mixed $items, int $min, int $max ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$values = array();

		foreach ( $items as $item ) {
			if ( is_numeric( $item ) ) {
				$values[] = max( $min, min( $max, (int) $item ) );
			}
		}

		return array_values( array_unique( $values ) );
	}
}
