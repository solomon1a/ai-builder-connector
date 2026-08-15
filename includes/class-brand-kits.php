<?php
/**
 * Curated brand kits: one-click color + typography presets for the design system.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ten original color/typography kits. Applying a kit updates the AIBC
 * design system (used for AI-built drafts only) and keeps a one-level
 * backup so the previous design can be restored.
 */
class Brand_Kits {

	public const OPTION_BACKUP = 'aibc_design_backup';

	/**
	 * Design system service.
	 */
	private Design_System $design_system;

	public function __construct( Design_System $design_system ) {
		$this->design_system = $design_system;
	}

	/**
	 * Returns all kits keyed by slug.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function get_kits(): array {
		return array(
			'fresh-mint'     => array( 'name' => 'Fresh Mint', 'preset' => 'bold', 'primary_color' => '#10b981', 'secondary_color' => '#134e4a', 'accent_color' => '#34d399', 'font_family' => 'Inter', 'heading_font_family' => 'Poppins' ),
			'deep-indigo'    => array( 'name' => 'Deep Indigo', 'preset' => 'professional', 'primary_color' => '#4f46e5', 'secondary_color' => '#1e1b4b', 'accent_color' => '#8b5cf6', 'font_family' => 'Inter', 'heading_font_family' => 'Space Grotesk' ),
			'sunset-agency'  => array( 'name' => 'Sunset Agency', 'preset' => 'bold', 'primary_color' => '#f97316', 'secondary_color' => '#7c2d12', 'accent_color' => '#fbbf24', 'font_family' => 'Nunito Sans', 'heading_font_family' => 'Archivo' ),
			'ocean-trust'    => array( 'name' => 'Ocean Trust', 'preset' => 'professional', 'primary_color' => '#0284c7', 'secondary_color' => '#0c4a6e', 'accent_color' => '#38bdf8', 'font_family' => 'Source Sans 3', 'heading_font_family' => 'Merriweather' ),
			'rose-boutique'  => array( 'name' => 'Rose Boutique', 'preset' => 'elegant', 'primary_color' => '#e11d48', 'secondary_color' => '#4c0519', 'accent_color' => '#fb7185', 'font_family' => 'Lora', 'heading_font_family' => 'Playfair Display' ),
			'slate-law'      => array( 'name' => 'Slate Law', 'preset' => 'elegant', 'primary_color' => '#334155', 'secondary_color' => '#0f172a', 'accent_color' => '#b45309', 'font_family' => 'Source Serif 4', 'heading_font_family' => 'Libre Baskerville' ),
			'lime-fitness'   => array( 'name' => 'Lime Fitness', 'preset' => 'bold', 'primary_color' => '#84cc16', 'secondary_color' => '#1a2e05', 'accent_color' => '#facc15', 'font_family' => 'Roboto', 'heading_font_family' => 'Oswald' ),
			'plum-creative'  => array( 'name' => 'Plum Creative', 'preset' => 'bold', 'primary_color' => '#9333ea', 'secondary_color' => '#3b0764', 'accent_color' => '#f0abfc', 'font_family' => 'Work Sans', 'heading_font_family' => 'Sora' ),
			'sand-warm'      => array( 'name' => 'Warm Sand', 'preset' => 'elegant', 'primary_color' => '#b45309', 'secondary_color' => '#451a03', 'accent_color' => '#f59e0b', 'font_family' => 'PT Sans', 'heading_font_family' => 'DM Serif Display' ),
			'mono-tech'      => array( 'name' => 'Mono Tech', 'preset' => 'professional', 'primary_color' => '#111827', 'secondary_color' => '#374151', 'accent_color' => '#22d3ee', 'font_family' => 'IBM Plex Sans', 'heading_font_family' => 'IBM Plex Mono' ),
		);
	}

	/**
	 * Public list for the MCP surface (read-only).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function list_public(): array {
		$out = array();

		foreach ( $this->get_kits() as $slug => $kit ) {
			$out[] = array_merge( array( 'slug' => $slug ), $kit );
		}

		return $out;
	}

	/**
	 * Applies a kit to the AIBC design system (admin action only).
	 *
	 * @param string $slug Kit slug.
	 * @return array<string,string>|\WP_Error The applied kit on success.
	 */
	public function apply( string $slug ): array|\WP_Error {
		$slug = sanitize_key( $slug );
		$kits = $this->get_kits();

		if ( ! isset( $kits[ $slug ] ) ) {
			return new \WP_Error( 'aibc_unknown_kit', __( 'Unknown brand kit.', 'ai-builder-connector' ) );
		}

		update_option( self::OPTION_BACKUP, $this->design_system->get_config(), false );

		$kit    = $kits[ $slug ];
		$config = array_merge( $this->design_system->get_config(), array(
			'preset'              => $kit['preset'],
			'primary_color'       => $kit['primary_color'],
			'secondary_color'     => $kit['secondary_color'],
			'accent_color'        => $kit['accent_color'],
			'font_family'         => $kit['font_family'],
			'heading_font_family' => $kit['heading_font_family'],
		) );

		update_option( Design_System::OPTION_DESIGN_SYSTEM, $this->design_system->sanitize_config( $config ), false );

		return $kit;
	}

	/**
	 * Restores the design saved before the last kit apply.
	 */
	public function restore_backup(): bool {
		$backup = get_option( self::OPTION_BACKUP, array() );

		if ( ! is_array( $backup ) || empty( $backup ) ) {
			return false;
		}

		update_option( Design_System::OPTION_DESIGN_SYSTEM, $this->design_system->sanitize_config( $backup ), false );
		delete_option( self::OPTION_BACKUP );

		return true;
	}

	/**
	 * Whether a design backup exists.
	 */
	public function has_backup(): bool {
		$backup = get_option( self::OPTION_BACKUP, array() );

		return is_array( $backup ) && ! empty( $backup );
	}
}
