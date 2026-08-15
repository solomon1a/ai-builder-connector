<?php
/**
 * Main plugin coordinator.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin services into WordPress.
 */
final class Plugin {
	/**
	 * Admin page service.
	 *
	 * @var Admin_Page
	 */
	private Admin_Page $admin_page;

	/**
	 * MCP controller service.
	 *
	 * @var MCP_Controller
	 */
	private MCP_Controller $mcp_controller;

	/**
	 * Setup wizard service.
	 *
	 * @var Setup_Wizard
	 */
	private Setup_Wizard $setup_wizard;

	/**
	 * Draft manager, used for the front-end custom CSS printer.
	 */
	private Draft_Manager $draft_manager;

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
		$detector           = new Elementor_Detector();
		$scanner            = new Widget_Scanner( $detector );
		$addon_detector     = new Addon_Detector( $detector );
		$source_resolver    = new Widget_Source_Resolver( $addon_detector );
		$permission_manager = new Permission_Manager();
		$design_system      = new Design_System();
		$template_registry  = new Template_Registry();
		$widget_introspector = new Widget_Introspector( $detector );
		$widget_definitions = new Widget_Definition_Registry( $widget_introspector );
		$content_sanitizer  = new Widget_Content_Sanitizer( $widget_definitions );
		$settings           = new Settings( $detector, $scanner, $addon_detector, $source_resolver, $design_system );
		$page_plan_builder  = new Page_Plan_Builder( $permission_manager, $design_system, $template_registry, $widget_definitions, $content_sanitizer );
		$validator          = new Elementor_Validator( $permission_manager, $widget_definitions );
		$review_workflow    = new Review_Workflow();
		$draft_manager      = new Draft_Manager( $page_plan_builder, $validator, $review_workflow );
		$mcp_connections    = new MCP_Connection_Manager();
		$mcp_action_log     = new MCP_Action_Log();
		$stock_images       = new Stock_Image_Client();
		$brand_kits         = new Brand_Kits( $design_system );
		$saved_templates    = new Saved_Template_Store( $draft_manager );
		$this->draft_manager = $draft_manager;
		$mcp_tools          = new MCP_Tool_Registry( $detector, $scanner, $addon_detector, $source_resolver, $permission_manager, $design_system, $template_registry, $widget_definitions, $page_plan_builder, $validator, $draft_manager, $review_workflow, $mcp_action_log, $stock_images, $saved_templates, $brand_kits );
		$this->mcp_controller = new MCP_Controller( $mcp_connections, $mcp_tools );
		$this->admin_page   = new Admin_Page( $detector, $scanner, $addon_detector, $source_resolver, $permission_manager, $settings, $design_system, $template_registry, $widget_definitions, $page_plan_builder, $draft_manager, $review_workflow, $mcp_connections, $mcp_action_log );
		$this->admin_page->set_brand_kits( $brand_kits );
		$this->setup_wizard = new Setup_Wizard( $detector, $scanner, $source_resolver, $permission_manager, $design_system, $mcp_connections );
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_filter( 'submenu_file', array( $this->admin_page, 'highlight_submenu' ) );
		add_action( 'admin_init', array( $this->admin_page, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );
		add_action( 'admin_post_aibc_rescan_widgets', array( $this->admin_page, 'handle_rescan' ) );
		add_action( 'admin_post_aibc_create_draft', array( $this->admin_page, 'handle_create_draft' ) );
		add_action( 'admin_post_aibc_validate_draft', array( $this->admin_page, 'handle_validate_draft' ) );
		add_action( 'admin_post_aibc_review_draft', array( $this->admin_page, 'handle_review_draft' ) );
		add_action( 'admin_post_aibc_rollback_draft', array( $this->admin_page, 'handle_rollback_draft' ) );
		add_action( 'admin_post_aibc_publish_draft', array( $this->admin_page, 'handle_publish_draft' ) );
		add_action( 'admin_post_aibc_unpublish_draft', array( $this->admin_page, 'handle_unpublish_draft' ) );
		add_action( 'admin_post_aibc_delete_draft', array( $this->admin_page, 'handle_delete_draft' ) );
		add_action( 'admin_post_aibc_bulk_drafts', array( $this->admin_page, 'handle_bulk_drafts' ) );
		add_action( 'admin_post_aibc_create_mcp_connection', array( $this->admin_page, 'handle_create_mcp_connection' ) );
		add_action( 'admin_post_aibc_revoke_mcp_connection', array( $this->admin_page, 'handle_revoke_mcp_connection' ) );
		add_action( 'admin_post_aibc_toggle_mcp', array( $this->admin_page, 'handle_toggle_mcp' ) );
		add_action( 'rest_api_init', array( $this->mcp_controller, 'register_routes' ) );
		add_action( 'admin_post_aibc_enable_atomic', array( $this->admin_page, 'handle_enable_atomic' ) );
		add_action( 'admin_post_aibc_apply_brand_kit', array( $this->admin_page, 'handle_apply_brand_kit' ) );
		add_action( 'admin_post_aibc_restore_design', array( $this->admin_page, 'handle_restore_design' ) );
		add_action( 'wp_head', array( $this, 'print_draft_custom_css' ) );
		$this->setup_wizard->register();
	}

	/**
	 * Prints AI-authored, sanitized custom CSS on AIBC pages only.
	 */
	public function print_draft_custom_css(): void {
		if ( ! is_page() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();

		if ( $post_id <= 0 || null === $this->draft_manager->get_aibc_page( $post_id ) ) {
			return;
		}

		$css = $this->draft_manager->get_custom_css( $post_id );

		if ( '' === $css ) {
			return;
		}

		echo '<style id="aibc-custom-css">' . strip_tags( $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- CSS sanitized on save; tags stripped again here.
	}
}
