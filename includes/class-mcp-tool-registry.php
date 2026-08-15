<?php
/**
 * MCP tool registry.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and executes scoped MCP tools.
 */
final class MCP_Tool_Registry {
	private Elementor_Detector $detector;
	private Widget_Scanner $scanner;
	private Addon_Detector $addon_detector;
	private Widget_Source_Resolver $source_resolver;
	private Permission_Manager $permission_manager;
	private Design_System $design_system;
	private Template_Registry $template_registry;
	private Widget_Definition_Registry $widget_definitions;
	private Page_Plan_Builder $page_plan_builder;
	private Elementor_Validator $validator;
	private Draft_Manager $draft_manager;
	private Review_Workflow $review_workflow;
	private MCP_Action_Log $action_log;

	/**
	 * Tool registry constructor.
	 */
	/**
	 * Stock image client.
	 */
	private Stock_Image_Client $stock_images;

	/**
	 * Saved template store.
	 */
	private Saved_Template_Store $saved_templates;

	/**
	 * Brand kits.
	 */
	private Brand_Kits $brand_kits;

	public function __construct(
		Elementor_Detector $detector,
		Widget_Scanner $scanner,
		Addon_Detector $addon_detector,
		Widget_Source_Resolver $source_resolver,
		Permission_Manager $permission_manager,
		Design_System $design_system,
		Template_Registry $template_registry,
		Widget_Definition_Registry $widget_definitions,
		Page_Plan_Builder $page_plan_builder,
		Elementor_Validator $validator,
		Draft_Manager $draft_manager,
		Review_Workflow $review_workflow,
		MCP_Action_Log $action_log,
		?Stock_Image_Client $stock_images = null,
		?Saved_Template_Store $saved_templates = null,
		?Brand_Kits $brand_kits = null
	) {
		$this->stock_images    = $stock_images ?? new Stock_Image_Client();
		$this->saved_templates = $saved_templates ?? new Saved_Template_Store( $draft_manager );
		$this->brand_kits      = $brand_kits ?? new Brand_Kits( $design_system );
		$this->detector           = $detector;
		$this->scanner            = $scanner;
		$this->addon_detector     = $addon_detector;
		$this->source_resolver    = $source_resolver;
		$this->permission_manager = $permission_manager;
		$this->design_system      = $design_system;
		$this->template_registry  = $template_registry;
		$this->widget_definitions = $widget_definitions;
		$this->page_plan_builder  = $page_plan_builder;
		$this->validator          = $validator;
		$this->draft_manager      = $draft_manager;
		$this->review_workflow    = $review_workflow;
		$this->action_log         = $action_log;
	}

	/**
	 * Lists read-only tool definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_tools(): array {
		$tools = array();

		foreach ( $this->tool_descriptions() as $name => $description ) {
			$tools[] = array(
				'name'        => $name,
				'description' => $description,
				'inputSchema' => $this->input_schema( $name ),
			);
		}

		return $tools;
	}

	/**
	 * Calls a scoped tool.
	 *
	 * @param string              $tool_name Tool name.
	 * @param array<string,mixed> $connection Authenticated connection.
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function call_tool( string $tool_name, array $connection, array $arguments = array() ): array|\WP_Error {
		$tool_name = sanitize_key( $tool_name );

		switch ( $tool_name ) {
			case 'ping':
				return array(
					'ok'      => true,
					'version' => AIBC_VERSION,
					'time'    => gmdate( 'c' ),
				);

			case 'get_site_context':
				return $this->get_site_context();

			case 'get_builder_status':
				return array(
					'builder'   => 'elementor',
					'elementor' => $this->detector->get_status(),
				);

			case 'get_connection_permissions':
				return array(
					'connection_id' => sanitize_text_field( (string) ( $connection['id'] ?? '' ) ),
					'permissions'   => array_values( array_map( 'sanitize_key', (array) ( $connection['permissions'] ?? array() ) ) ),
				);

			case 'get_design_system':
				return array( 'design_system' => $this->design_system->get_tokens() );

			case 'list_widget_definitions':
				return array( 'widget_definitions' => $this->list_widget_definitions() );

			case 'get_widget_definition':
				return $this->get_widget_definition( $arguments );

			case 'list_page_templates':
				return array(
					'page_templates'    => $this->template_registry->list_page_templates(),
					'section_templates' => $this->template_registry->list_section_templates(),
				);

			case 'get_page_template':
				return $this->get_page_template( $arguments );

			case 'list_allowed_addons':
				return array( 'addons' => $this->list_allowed_addons() );

			case 'list_allowed_widgets':
				return array( 'widgets' => $this->list_allowed_widgets() );

			case 'list_ai_drafts':
				return array( 'drafts' => $this->list_ai_drafts() );

			case 'get_ai_draft':
				return $this->get_ai_draft( $arguments );

			case 'create_page_plan':
				return $this->create_page_plan( $arguments, $connection );

			case 'validate_page_plan':
				return $this->validate_page_plan( $arguments );

			case 'create_elementor_draft':
				return $this->create_elementor_draft( $arguments, $connection );

			case 'revise_elementor_draft':
				return $this->revise_elementor_draft( $arguments, $connection );
			case 'validate_elementor_draft':
				return $this->validate_elementor_draft( $arguments );

			case 'get_preview_link':
				return $this->get_preview_link( $arguments );

			case 'list_ai_actions':
				return array( 'actions' => $this->list_ai_actions() );

			case 'get_action_details':
				return $this->get_action_details( $arguments );

			case 'approve_ai_draft':
				return $this->review_ai_draft( $arguments, $connection, 'approve' );

			case 'reject_ai_draft':
				return $this->review_ai_draft( $arguments, $connection, 'reject' );

			case 'rollback_action':
				return $this->rollback_action( $arguments, $connection );

			case 'delete_ai_draft':
				return $this->delete_ai_draft( $arguments, $connection );

			case 'search_stock_images':
				return $this->stock_images->search(
					isset( $arguments['query'] ) && is_scalar( $arguments['query'] ) ? (string) $arguments['query'] : '',
					isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1
				);

			case 'import_stock_image':
				$imported = $this->stock_images->import( isset( $arguments['image_id'] ) && is_scalar( $arguments['image_id'] ) ? (string) $arguments['image_id'] : '' );
				if ( ! is_wp_error( $imported ) ) {
					$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'import_stock_image', 'imported', array( 'attachment_id' => (string) $imported['attachment_id'] ) );
				}
				return $imported;

			case 'set_draft_seo_meta':
				$post_id = $this->post_id_argument( $arguments );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				$seo = $this->draft_manager->set_seo_meta(
					$post_id,
					isset( $arguments['meta_title'] ) && is_scalar( $arguments['meta_title'] ) ? (string) $arguments['meta_title'] : '',
					isset( $arguments['meta_description'] ) && is_scalar( $arguments['meta_description'] ) ? (string) $arguments['meta_description'] : ''
				);
				if ( ! is_wp_error( $seo ) ) {
					$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'set_draft_seo_meta', 'updated', array( 'post_id' => (string) $post_id ) );
				}
				return is_wp_error( $seo ) ? $seo : array_merge( array( 'post_id' => $post_id ), $seo );

			case 'set_draft_custom_css':
				$post_id = $this->post_id_argument( $arguments );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				$stored = $this->draft_manager->set_custom_css( $post_id, isset( $arguments['css'] ) && is_scalar( $arguments['css'] ) ? (string) $arguments['css'] : '' );
				if ( ! is_wp_error( $stored ) ) {
					$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'set_draft_custom_css', 'updated', array( 'post_id' => (string) $post_id ) );
				}
				return is_wp_error( $stored ) ? $stored : array_merge( array( 'post_id' => $post_id ), $stored );

			case 'save_draft_as_template':
				$post_id = $this->post_id_argument( $arguments );
				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				$saved = $this->saved_templates->save_from_draft( $post_id, isset( $arguments['name'] ) && is_scalar( $arguments['name'] ) ? (string) $arguments['name'] : '' );
				if ( ! is_wp_error( $saved ) ) {
					$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'save_draft_as_template', 'saved', array( 'post_id' => (string) $post_id ) );
				}
				return $saved;

			case 'list_saved_templates':
				return array( 'saved_templates' => $this->saved_templates->list_summaries() );

			case 'get_atomic_status':
				return Atomic_Builder::status();

			case 'list_brand_kits':
				return array(
					'brand_kits' => $this->brand_kits->list_public(),
					'note'       => __( 'Brand kits are applied by the site administrator from the dashboard; this list is read-only for AI clients.', 'ai-builder-connector' ),
				);
		}

		return new \WP_Error( 'aibc_mcp_unknown_tool', __( 'Unknown MCP tool.', 'ai-builder-connector' ), array( 'status' => 400 ) );
	}

	/**
	 * Gets tool descriptions.
	 *
	 * @return array<string,string>
	 */
	private function tool_descriptions(): array {
		return array(
			'ping'                       => __( 'Checks whether AI Builder Connector MCP is reachable.', 'ai-builder-connector' ),
			'get_site_context'           => __( 'Returns safe, non-sensitive WordPress site context.', 'ai-builder-connector' ),
			'get_builder_status'         => __( 'Returns Elementor availability and initialization status.', 'ai-builder-connector' ),
			'get_connection_permissions' => __( 'Returns permissions for the authenticated MCP connection.', 'ai-builder-connector' ),
			'get_design_system'          => __( 'Returns the active design-system tokens used by generated drafts.', 'ai-builder-connector' ),
			'list_widget_definitions'    => __( 'Lists Phase 11 verified Elementor Core widget definitions and runtime status.', 'ai-builder-connector' ),
			'get_widget_definition'      => __( 'Returns one Phase 11 verified Elementor widget definition.', 'ai-builder-connector' ),
			'list_page_templates'        => __( 'Lists Phase 9 draft-safe page and section templates.', 'ai-builder-connector' ),
			'get_page_template'          => __( 'Returns one Phase 9 draft-safe page template.', 'ai-builder-connector' ),
			'list_allowed_addons'        => __( 'Lists detected Elementor addon sources allowed for this connection.', 'ai-builder-connector' ),
			'list_allowed_widgets'       => __( 'Lists currently registered Elementor widgets allowed by AIBC permissions.', 'ai-builder-connector' ),
			'list_ai_drafts'             => __( 'Lists draft pages created and tracked by AI Builder Connector.', 'ai-builder-connector' ),
			'get_ai_draft'               => __( 'Gets one AIBC-owned draft review record.', 'ai-builder-connector' ),
			'create_page_plan'           => __( 'Creates a validated draft page plan from a brief, optionally with AI-authored sections and content, without saving anything.', 'ai-builder-connector' ),
			'validate_page_plan'         => __( 'Validates a draft page plan against current Elementor widgets and AIBC permissions.', 'ai-builder-connector' ),
			'create_elementor_draft'     => __( 'Creates an AIBC-owned Elementor draft page from a brief, optionally with AI-authored sections, title, and sanitized content, using only validated allowed widgets.', 'ai-builder-connector' ),
			'revise_elementor_draft'     => __( 'Revises an existing AIBC-owned draft with new AI-authored sections and optional title. The previous version is snapshotted for rollback and the draft returns to Needs Review. Published pages are excluded until an administrator unpublishes them.', 'ai-builder-connector' ),
			'validate_elementor_draft'   => __( 'Validates an existing AIBC-owned Elementor draft page.', 'ai-builder-connector' ),
			'get_preview_link'           => __( 'Returns a preview link for an AIBC-owned draft page.', 'ai-builder-connector' ),
			'list_ai_actions'            => __( 'Lists recent MCP actions tracked by AI Builder Connector.', 'ai-builder-connector' ),
			'get_action_details'         => __( 'Gets one tracked MCP action by ID.', 'ai-builder-connector' ),
			'approve_ai_draft'           => __( 'Marks an AIBC draft approved without publishing it.', 'ai-builder-connector' ),
			'reject_ai_draft'            => __( 'Marks an AIBC draft rejected without deleting it.', 'ai-builder-connector' ),
			'rollback_action'            => __( 'Rolls back a tracked AIBC draft creation by moving the draft to trash. Published pages are excluded until an administrator unpublishes them.', 'ai-builder-connector' ),
			'delete_ai_draft'            => __( 'Permanently deletes an AIBC-owned draft or rollback record. Published pages are excluded until an administrator unpublishes them.', 'ai-builder-connector' ),
			'search_stock_images'        => __( 'Searches Openverse for openly licensed (commercial-use) stock images. Returns ids, thumbnails, and attribution.', 'ai-builder-connector' ),
			'import_stock_image'         => __( 'Imports one Openverse image (by id from search_stock_images) into the media library and returns its attachment url for use in image widget content.', 'ai-builder-connector' ),
			'set_draft_seo_meta'         => __( 'Sets the SEO meta title and meta description on an AIBC page (feeds Yoast SEO when installed).', 'ai-builder-connector' ),
			'set_draft_custom_css'       => __( 'Stores sanitized page-scoped custom CSS on an AIBC page. CSS only: no JavaScript, HTML, or imports can pass.', 'ai-builder-connector' ),
			'save_draft_as_template'     => __( 'Saves an AIBC draft\'s section plan as a reusable named template.', 'ai-builder-connector' ),
			'list_saved_templates'       => __( 'Lists saved templates. Pass a slug as saved_template to create_elementor_draft to start from one.', 'ai-builder-connector' ),
			'list_brand_kits'            => __( 'Lists curated brand kits (colors + fonts). Read-only; an administrator applies kits from the dashboard.', 'ai-builder-connector' ),
			'get_atomic_status'          => __( 'Reports whether Elementor 4 atomic elements are available and which widgets the atomic engine supports. Call before using engine:"atomic".', 'ai-builder-connector' ),
		);
	}

	/**
	 * Gets a simple MCP input schema for a tool.
	 *
	 * @return array<string,mixed>
	 */
	private function input_schema( string $tool_name ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);

		if ( in_array( $tool_name, array( 'create_page_plan', 'create_elementor_draft' ), true ) ) {
			$schema['properties']['brief'] = array(
				'type'        => 'string',
				'description' => __( 'Website brief for the draft.', 'ai-builder-connector' ),
			);
			$schema['properties']['template'] = array(
				'type'        => 'string',
				'description' => __( 'Optional page template slug, such as homepage or services-page.', 'ai-builder-connector' ),
			);
		}

		if ( in_array( $tool_name, array( 'create_page_plan', 'create_elementor_draft', 'revise_elementor_draft' ), true ) ) {
			$schema['properties']['sections'] = array(
				'type'        => 'array',
				'description' => __( 'Optional AI-authored sections (max 20). Each item: { "widget": "heading", "label": "Hero", "section_style": "gradient", "description": "Purpose", "content": { "title": "Your headline" } }. Consecutive items with the same label are grouped into one visual section (own background + padding); repeated card widgets like icon-box render side by side in a row. Use section_style to design each section (see its field). Content keys must be AI-authorable settings from get_widget_definition (ai_content_settings). Unsupported or unsafe values are dropped and reported in blocked_widgets.', 'ai-builder-connector' ),
				'items'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'widget'        => array(
							'type'        => 'string',
							'description' => __( 'Verified widget identifier, such as heading, text-editor, or button.', 'ai-builder-connector' ),
						),
						'label'         => array(
							'type'        => 'string',
							'description' => __( 'Short section label. Items sharing a label form one visual section, so give every widget in the same block the same label.', 'ai-builder-connector' ),
						),
						'section_style' => array(
							'type'        => 'string',
							'enum'        => array( 'light', 'muted', 'dark', 'brand', 'gradient' ),
							'description' => __( 'Optional design style for this section, taken from the first item of the group. "light" = white, "muted" = light grey, "dark" = dark panel with light text, "brand" = primary-color panel with light text, "gradient" = primary-to-secondary gradient with light text. Text color flips automatically on dark/brand/gradient. Use dark/brand/gradient for hero and call-to-action blocks; use light/muted for feature and content blocks. If omitted, sections alternate light and muted automatically.', 'ai-builder-connector' ),
						),
						'description'   => array(
							'type'        => 'string',
							'description' => __( 'One-line purpose of this section.', 'ai-builder-connector' ),
						),
						'content'       => array(
							'type'        => 'object',
							'description' => __( 'AI-authored content values keyed by setting name, such as title, editor, text, link, caption.', 'ai-builder-connector' ),
						),
					),
					'required'             => array( 'widget' ),
					'additionalProperties' => false,
				),
			);

			if ( 'create_page_plan' === $tool_name ) {
				$schema['required'] = array( 'brief' );
			}
		}

		if ( 'create_elementor_draft' === $tool_name ) {
			$schema['properties']['plan'] = array(
				'type'        => 'object',
				'description' => __( 'Optional: the plan object returned by create_page_plan. Its brief, template, and sections are used unless the top-level fields override them. Pass either this plan or a brief.', 'ai-builder-connector' ),
			);
			$schema['properties']['saved_template'] = array(
				'type'        => 'string',
				'description' => __( 'Optional: slug of a saved template (see list_saved_templates). Its brief, template, and sections are used unless overridden.', 'ai-builder-connector' ),
			);
			$schema['properties']['engine'] = array(
				'type'        => 'string',
				'enum'        => array( 'legacy', 'atomic' ),
				'description' => __( 'Optional build engine. "atomic" uses Elementor 4 atomic elements (lighter, faster pages; supports heading, text-editor, button, image, divider only — check get_atomic_status first). Default "legacy" supports all allowed widgets.', 'ai-builder-connector' ),
			);
		}

		if ( 'search_stock_images' === $tool_name ) {
			$schema['properties']['query'] = array( 'type' => 'string', 'description' => __( 'Image search terms.', 'ai-builder-connector' ) );
			$schema['properties']['page']  = array( 'type' => 'integer', 'description' => __( 'Result page, starting at 1.', 'ai-builder-connector' ) );
			$schema['required']            = array( 'query' );
		}

		if ( 'import_stock_image' === $tool_name ) {
			$schema['properties']['image_id'] = array( 'type' => 'string', 'description' => __( 'Openverse image id from search_stock_images.', 'ai-builder-connector' ) );
			$schema['required']               = array( 'image_id' );
		}

		if ( 'set_draft_seo_meta' === $tool_name ) {
			$schema['properties']['post_id']          = array( 'type' => 'integer', 'description' => __( 'AIBC page ID.', 'ai-builder-connector' ) );
			$schema['properties']['meta_title']       = array( 'type' => 'string', 'description' => __( 'SEO meta title (aim for under 60 characters).', 'ai-builder-connector' ) );
			$schema['properties']['meta_description'] = array( 'type' => 'string', 'description' => __( 'SEO meta description (aim for under 160 characters).', 'ai-builder-connector' ) );
			$schema['required']                       = array( 'post_id' );
		}

		if ( 'set_draft_custom_css' === $tool_name ) {
			$schema['properties']['post_id'] = array( 'type' => 'integer', 'description' => __( 'AIBC page ID.', 'ai-builder-connector' ) );
			$schema['properties']['css']     = array( 'type' => 'string', 'description' => __( 'Plain CSS, printed only on this page. Selectors are not rewritten; scope them yourself. No @import, JavaScript, or HTML.', 'ai-builder-connector' ) );
			$schema['required']              = array( 'post_id', 'css' );
		}

		if ( 'save_draft_as_template' === $tool_name ) {
			$schema['properties']['post_id'] = array( 'type' => 'integer', 'description' => __( 'AIBC draft ID whose plan to save.', 'ai-builder-connector' ) );
			$schema['properties']['name']    = array( 'type' => 'string', 'description' => __( 'Template display name.', 'ai-builder-connector' ) );
			$schema['required']              = array( 'post_id', 'name' );
		}

		if ( in_array( $tool_name, array( 'create_elementor_draft', 'revise_elementor_draft' ), true ) ) {
			$schema['properties']['title'] = array(
				'type'        => 'string',
				'description' => __( 'Optional page title. The AIBC Draft prefix is always added for review safety.', 'ai-builder-connector' ),
			);
		}

		if ( 'revise_elementor_draft' === $tool_name ) {
			$schema['properties']['post_id'] = array(
				'type'        => 'integer',
				'description' => __( 'AIBC-owned draft page ID to revise.', 'ai-builder-connector' ),
			);
			$schema['required'] = array( 'post_id', 'sections' );
		}

		if ( 'get_page_template' === $tool_name ) {
			$schema['properties']['template'] = array(
				'type'        => 'string',
				'description' => __( 'Page template slug.', 'ai-builder-connector' ),
			);
			$schema['required'] = array( 'template' );
		}

		if ( 'get_widget_definition' === $tool_name ) {
			$schema['properties']['widget'] = array(
				'type'        => 'string',
				'description' => __( 'Elementor widget identifier, such as heading or button.', 'ai-builder-connector' ),
			);
			$schema['required'] = array( 'widget' );
		}

		if ( 'validate_page_plan' === $tool_name ) {
			$schema['properties']['plan'] = array(
				'type'        => 'object',
				'description' => __( 'A page plan previously returned by create_page_plan.', 'ai-builder-connector' ),
			);
			$schema['properties']['brief'] = array(
				'type'        => 'string',
				'description' => __( 'Optional brief used to build and validate a fresh plan.', 'ai-builder-connector' ),
			);
			$schema['properties']['template'] = array(
				'type'        => 'string',
				'description' => __( 'Optional page template slug used when generating a fresh plan.', 'ai-builder-connector' ),
			);
		}

		if ( in_array( $tool_name, array( 'get_ai_draft', 'validate_elementor_draft', 'get_preview_link', 'approve_ai_draft', 'reject_ai_draft', 'delete_ai_draft' ), true ) ) {
			$schema['properties']['post_id'] = array(
				'type'        => 'integer',
				'description' => __( 'AIBC-owned draft page ID.', 'ai-builder-connector' ),
			);
			$schema['required'] = array( 'post_id' );
		}

		if ( in_array( $tool_name, array( 'approve_ai_draft', 'reject_ai_draft' ), true ) ) {
			$schema['properties']['note'] = array(
				'type'        => 'string',
				'description' => __( 'Optional review note.', 'ai-builder-connector' ),
			);
		}

		if ( 'get_action_details' === $tool_name ) {
			$schema['properties']['action_id'] = array(
				'type'        => 'string',
				'description' => __( 'Tracked MCP action ID.', 'ai-builder-connector' ),
			);
			$schema['required'] = array( 'action_id' );
		}

		if ( 'rollback_action' === $tool_name ) {
			$schema['properties']['action_id'] = array(
				'type'        => 'string',
				'description' => __( 'Tracked create_elementor_draft action ID.', 'ai-builder-connector' ),
			);
			$schema['properties']['post_id'] = array(
				'type'        => 'integer',
				'description' => __( 'AIBC-owned draft page ID.', 'ai-builder-connector' ),
			);
		}

		// JSON Schema "properties" must be an object. A parameterless tool would
		// otherwise serialize it as [] (empty PHP array) and fail strict MCP
		// client validation, breaking tools/list.
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}

		return $schema;
	}

	/**
	 * Gets safe site context.
	 *
	 * @return array<string,mixed>
	 */
	private function get_site_context(): array {
		return array(
			'name'          => sanitize_text_field( get_bloginfo( 'name' ) ),
			'url'           => esc_url_raw( home_url( '/' ) ),
			'wp_version'    => sanitize_text_field( get_bloginfo( 'version' ) ),
			'php_version'   => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'plugin_version' => AIBC_VERSION,
			'locale'        => sanitize_text_field( get_locale() ),
		);
	}

	/**
	 * Lists allowed addon sources.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function list_allowed_addons(): array {
		$allowed = $this->permission_manager->get_allowed_addons();
		$sources = $this->addon_detector->get_detected_sources();
		$rows    = array();

		foreach ( $sources as $source ) {
			$slug = sanitize_key( (string) ( $source['slug'] ?? '' ) );

			if ( '' === $slug || ! in_array( $slug, $allowed, true ) || empty( $source['active'] ) ) {
				continue;
			}

			$rows[] = array(
				'slug'     => $slug,
				'name'     => sanitize_text_field( (string) ( $source['name'] ?? '' ) ),
				'version'  => sanitize_text_field( (string) ( $source['version'] ?? '' ) ),
				'verified' => ! empty( $source['verified'] ),
			);
		}

		return $rows;
	}

	/**
	 * Lists verified widget definitions with current runtime status.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function list_widget_definitions(): array {
		return $this->widget_definitions->runtime_rows( $this->current_widgets(), $this->permission_manager );
	}

	/**
	 * Gets one verified widget definition.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_widget_definition( array $arguments ): array|\WP_Error {
		$widget_name = isset( $arguments['widget'] ) && is_scalar( $arguments['widget'] ) ? sanitize_text_field( (string) $arguments['widget'] ) : '';

		if ( '' === $widget_name ) {
			return new \WP_Error( 'aibc_mcp_missing_widget', __( 'A widget identifier is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		$definition = $this->widget_definitions->public_definition( $widget_name );

		if ( null === $definition ) {
			return new \WP_Error( 'aibc_mcp_unknown_widget_definition', __( 'The requested widget is not in the verified widget pack.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		return array( 'widget_definition' => $definition );
	}

	/**
	 * Lists allowed widgets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function list_allowed_widgets(): array {
		$widgets = $this->detector->is_initialized() ? $this->source_resolver->resolve_widgets( $this->scanner->get_widgets() ) : array();
		$rows    = array();

		foreach ( $widgets as $widget ) {
			if ( ! $this->permission_manager->is_widget_allowed( $widget ) ) {
				continue;
			}

			$rows[] = array(
				'name'           => sanitize_text_field( (string) ( $widget['name'] ?? '' ) ),
				'title'          => sanitize_text_field( (string) ( $widget['title'] ?? '' ) ),
				'categories'     => array_values( array_map( 'sanitize_text_field', (array) ( $widget['categories'] ?? array() ) ) ),
				'source_slug'    => sanitize_key( (string) ( $widget['source_slug'] ?? Addon_Detector::UNKNOWN_SOURCE ) ),
				'source_name'    => sanitize_text_field( (string) ( $widget['source_name'] ?? '' ) ),
				'support_status' => null === $this->widget_definitions->get_definition( (string) ( $widget['name'] ?? '' ) ) ? Widget_Definition_Registry::STATUS_UNSUPPORTED : Widget_Definition_Registry::STATUS_VERIFIED,
			);
		}

		return $rows;
	}

	/**
	 * Lists AIBC-owned drafts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function list_ai_drafts(): array {
		$rows = array();

		foreach ( $this->draft_manager->get_created_drafts() as $draft ) {
			$rows[] = $this->public_draft( $draft );
		}

		return $rows;
	}

	/**
	 * Gets one AIBC-owned draft review record.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_ai_draft( array $arguments ): array|\WP_Error {
		$post_id = $this->post_id_argument( $arguments );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$draft = $this->draft_manager->get_aibc_page( $post_id );

		if ( null === $draft ) {
			return new \WP_Error( 'aibc_mcp_invalid_draft_record', __( 'Only AIBC-owned draft pages can be inspected through MCP.', 'ai-builder-connector' ), array( 'status' => 403 ) );
		}

		return array( 'draft' => $this->public_draft( $draft ) );
	}

	/**
	 * Gets one page template.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_page_template( array $arguments ): array|\WP_Error {
		$template_slug = isset( $arguments['template'] ) && is_scalar( $arguments['template'] ) ? sanitize_key( (string) $arguments['template'] ) : '';

		if ( '' === $template_slug ) {
			return new \WP_Error( 'aibc_mcp_missing_template', __( 'A page template slug is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		$template = $this->template_registry->get_page_template( $template_slug );

		if ( null === $template ) {
			return new \WP_Error( 'aibc_mcp_unknown_template', __( 'The requested page template is not available.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		return array( 'page_template' => $template );
	}

	/**
	 * Builds a draft page plan from a brief.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function create_page_plan( array $arguments, array $connection ): array|\WP_Error {
		$brief = $this->sanitize_brief_argument( $arguments );

		if ( is_wp_error( $brief ) ) {
			return $brief;
		}

		$widgets = $this->current_widgets();
		$template_slug = $this->template_argument( $arguments );
		$plan    = $this->page_plan_builder->build_plan( $brief, $widgets, $template_slug, $this->sections_argument( $arguments ) );
		$result  = $this->validator->validate_plan( $plan, $widgets );
		$status  = $result->is_valid() ? 'validated' : 'validation_failed';

		$action_id = $this->action_log->record(
			(string) ( $connection['id'] ?? '' ),
			'create_page_plan',
			$status,
			array(
				'template'     => (string) ( $plan['template'] ?? '' ),
				'used_widgets' => (array) ( $plan['used_widgets'] ?? array() ),
			)
		);

		return array(
			'action_id' => $action_id,
			'valid'     => $result->is_valid(),
			'errors'    => $result->get_errors(),
			'plan'      => $this->public_plan( $plan ),
		);
	}

	/**
	 * Validates a supplied or generated page plan.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_page_plan( array $arguments ): array|\WP_Error {
		$widgets = $this->current_widgets();
		$plan    = array();

		if ( isset( $arguments['plan'] ) && is_array( $arguments['plan'] ) ) {
			$plan = $arguments['plan'];
		} else {
			$brief = $this->sanitize_brief_argument( $arguments );

			if ( is_wp_error( $brief ) ) {
				return $brief;
			}

			$plan = $this->page_plan_builder->build_plan( $brief, $widgets, $this->template_argument( $arguments ), $this->sections_argument( $arguments ) );
		}

		$result = $this->validator->validate_plan( $plan, $widgets );

		return array(
			'valid'  => $result->is_valid(),
			'errors' => $result->get_errors(),
			'plan'   => $this->public_plan( $plan ),
		);
	}

	/**
	 * Creates an AIBC-owned Elementor draft from a brief.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function create_elementor_draft( array $arguments, array $connection ): array|\WP_Error {
		if ( ( ! isset( $arguments['plan'] ) || ! is_array( $arguments['plan'] ) ) && isset( $arguments['saved_template'] ) && is_scalar( $arguments['saved_template'] ) ) {
			$saved = $this->saved_templates->get( (string) $arguments['saved_template'] );

			if ( null === $saved ) {
				return new \WP_Error( 'aibc_unknown_saved_template', __( 'Unknown saved template slug. Use list_saved_templates.', 'ai-builder-connector' ), array( 'status' => 404 ) );
			}

			$arguments['plan'] = array(
				'brief'    => (string) ( $saved['brief'] ?? '' ),
				'template' => (string) ( $saved['template'] ?? '' ),
				'sections' => is_array( $saved['sections'] ?? null ) ? $saved['sections'] : array(),
			);
		}

		$arguments = $this->apply_plan_argument( $arguments );
		$brief     = $this->sanitize_brief_argument( $arguments );

		if ( is_wp_error( $brief ) ) {
			$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'create_elementor_draft', 'rejected' );
			return $brief;
		}

		$widgets = $this->current_widgets();
		$template_slug = $this->template_argument( $arguments );
		$engine  = isset( $arguments['engine'] ) && is_scalar( $arguments['engine'] ) ? sanitize_key( (string) $arguments['engine'] ) : '';
		$post_id = $this->draft_manager->create_draft( $brief, $widgets, $template_slug, $this->sections_argument( $arguments ), $this->title_argument( $arguments ), $engine );

		if ( is_wp_error( $post_id ) ) {
			$action_id = $this->action_log->record(
				(string) ( $connection['id'] ?? '' ),
				'create_elementor_draft',
				'failed',
				array( 'error' => $post_id->get_error_message() )
			);

			$error_data = $post_id->get_error_data();
			$blocked    = is_array( $error_data ) && isset( $error_data['blocked_widgets'] ) ? $error_data['blocked_widgets'] : array();

			return new \WP_Error( $post_id->get_error_code(), $post_id->get_error_message(), array( 'status' => 400, 'action_id' => $action_id, 'blocked_widgets' => $blocked ) );
		}

		$action_id = $this->action_log->record(
			(string) ( $connection['id'] ?? '' ),
			'create_elementor_draft',
			'created',
			array(
				'post_id'      => (string) $post_id,
				'template'     => $template_slug,
				'used_widgets' => $this->draft_manager->get_used_widgets( (int) $post_id ),
			)
		);

		$stored_plan = $this->draft_manager->get_plan( (int) $post_id );

		return array(
			'action_id'         => $action_id,
			'post_id'           => (int) $post_id,
			'status'            => 'draft',
			'template'          => $template_slug,
			'content_mode'      => sanitize_key( (string) ( $stored_plan['content_mode'] ?? 'template' ) ),
			'validation_status' => $this->draft_manager->get_validation_status( (int) $post_id ),
			'blocked_widgets'   => $this->draft_manager->get_blocked_widgets( (int) $post_id ),
			'preview_url'       => $this->preview_url( (int) $post_id ),
			'edit_url'          => esc_url_raw( get_edit_post_link( (int) $post_id, '' ) ),
			'widgets_used'      => $this->draft_manager->get_used_widgets( (int) $post_id ),
		);
	}

	/**
	 * Revises an AIBC-owned draft with new AI-authored sections.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function revise_elementor_draft( array $arguments, array $connection ): array|\WP_Error {
		$post_id = $this->post_id_argument( $arguments );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$sections = $this->sections_argument( $arguments );

		if ( empty( $sections ) ) {
			return new \WP_Error( 'aibc_mcp_missing_sections', __( 'At least one AI-authored section is required to revise a draft.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		$result = $this->draft_manager->revise_draft( $post_id, $this->current_widgets(), $sections, $this->title_argument( $arguments ) );

		if ( is_wp_error( $result ) ) {
			$action_id = $this->action_log->record(
				(string) ( $connection['id'] ?? '' ),
				'revise_elementor_draft',
				'failed',
				array(
					'post_id' => (string) $post_id,
					'error'   => $result->get_error_message(),
				)
			);

			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400, 'action_id' => $action_id ) );
		}

		$action_id = $this->action_log->record(
			(string) ( $connection['id'] ?? '' ),
			'revise_elementor_draft',
			'revised',
			array(
				'post_id'      => (string) $post_id,
				'used_widgets' => $this->draft_manager->get_used_widgets( $post_id ),
			)
		);

		$stored_plan = $this->draft_manager->get_plan( $post_id );

		return array(
			'action_id'         => $action_id,
			'post_id'           => $post_id,
			'status'            => 'revised',
			'content_mode'      => sanitize_key( (string) ( $stored_plan['content_mode'] ?? 'template' ) ),
			'validation_status' => $this->draft_manager->get_validation_status( $post_id ),
			'blocked_widgets'   => $this->draft_manager->get_blocked_widgets( $post_id ),
			'revisions'         => $this->draft_manager->get_revision_count( $post_id ),
			'review'            => $this->review_workflow->get_state( $post_id ),
			'preview_url'       => $this->preview_url( $post_id ),
			'widgets_used'      => $this->draft_manager->get_used_widgets( $post_id ),
		);
	}

	/**
	 * Validates an AIBC-owned Elementor draft.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_elementor_draft( array $arguments ): array|\WP_Error {
		$post_id = $this->post_id_argument( $arguments );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $this->draft_manager->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_mcp_invalid_draft', __( 'Only AIBC-owned draft pages can be validated through MCP.', 'ai-builder-connector' ), array( 'status' => 403 ) );
		}

		$result = $this->draft_manager->validate_draft_again( $post_id, $this->current_widgets() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post_id' => $post_id,
			'valid'   => $result->is_valid(),
			'errors'  => $result->get_errors(),
		);
	}

	/**
	 * Gets a preview URL for an AIBC-owned draft.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_preview_link( array $arguments ): array|\WP_Error {
		$post_id = $this->post_id_argument( $arguments );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $this->draft_manager->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_mcp_invalid_preview_draft', __( 'Only AIBC-owned draft pages can receive MCP preview links.', 'ai-builder-connector' ), array( 'status' => 403 ) );
		}

		return array(
			'post_id'     => $post_id,
			'status'      => 'preview_ready',
			'preview_url' => $this->preview_url( $post_id ),
		);
	}

	/**
	 * Lists tracked MCP actions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function list_ai_actions(): array {
		$actions = array();

		foreach ( $this->action_log->get_actions() as $action ) {
			$actions[] = $this->public_action( $action );
		}

		return $actions;
	}

	/**
	 * Gets one action detail row.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_action_details( array $arguments ): array|\WP_Error {
		$action_id = $this->action_id_argument( $arguments );

		if ( is_wp_error( $action_id ) ) {
			return $action_id;
		}

		$action = $this->action_log->find_action( $action_id );

		if ( null === $action ) {
			return new \WP_Error( 'aibc_mcp_unknown_action', __( 'The requested AI action was not found.', 'ai-builder-connector' ), array( 'status' => 404 ) );
		}

		return array( 'action' => $this->public_action( $action ) );
	}

	/**
	 * Approves or rejects an AIBC draft without publishing.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function review_ai_draft( array $arguments, array $connection, string $decision ): array|\WP_Error {
		$post_id = $this->post_id_argument( $arguments );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $this->draft_manager->is_aibc_draft( $post_id ) ) {
			return new \WP_Error( 'aibc_mcp_invalid_review_draft', __( 'Only active AIBC-owned draft pages can be reviewed through MCP.', 'ai-builder-connector' ), array( 'status' => 403 ) );
		}

		$note = $this->note_argument( $arguments );
		$result = 'approve' === $decision ? $this->review_workflow->approve( $post_id, $note ) : $this->review_workflow->reject( $post_id, $note );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = 'approve' === $decision ? Review_Workflow::STATUS_APPROVED : Review_Workflow::STATUS_REJECTED;
		$action_id = $this->action_log->record(
			(string) ( $connection['id'] ?? '' ),
			'approve' === $decision ? 'approve_ai_draft' : 'reject_ai_draft',
			$status,
			array( 'post_id' => (string) $post_id )
		);

		return array(
			'action_id' => $action_id,
			'post_id'   => $post_id,
			'review'    => $this->review_workflow->get_state( $post_id ),
			'published' => false,
		);
	}

	/**
	 * Rolls back a tracked AIBC draft by moving it to trash.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function rollback_action( array $arguments, array $connection ): array|\WP_Error {
		$post_id   = 0;
		$tool      = 'create_elementor_draft';
		$action_id = isset( $arguments['action_id'] ) ? sanitize_text_field( (string) $arguments['action_id'] ) : '';

		if ( '' !== $action_id ) {
			$action = $this->action_log->find_action( $action_id );
			$tool   = null !== $action ? (string) ( $action['tool'] ?? '' ) : '';

			if ( null === $action || ! in_array( $tool, array( 'create_elementor_draft', 'revise_elementor_draft' ), true ) ) {
				return new \WP_Error( 'aibc_mcp_invalid_action', __( 'Only tracked draft creation or revision actions can be rolled back.', 'ai-builder-connector' ), array( 'status' => 403 ) );
			}

			$context = is_array( $action['context'] ?? null ) ? $action['context'] : array();
			$post_id = absint( $context['post_id'] ?? 0 );
		} else {
			$post_id = $this->post_id_argument( $arguments );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$tracked_action = $this->find_create_action_by_post_id( $post_id );

			if ( null === $tracked_action ) {
				return new \WP_Error( 'aibc_mcp_untracked_rollback', __( 'MCP rollback is limited to tracked MCP draft creation actions.', 'ai-builder-connector' ), array( 'status' => 403 ) );
			}

			$action_id = sanitize_text_field( (string) ( $tracked_action['id'] ?? '' ) );
		}

		if ( 'revise_elementor_draft' === $tool ) {
			$result = $this->draft_manager->restore_last_revision( $post_id, __( 'The previous revision was restored through MCP rollback.', 'ai-builder-connector' ) );

			if ( is_wp_error( $result ) ) {
				$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'rollback_action', 'failed', array( 'post_id' => (string) $post_id, 'error' => $result->get_error_message() ) );
				return $result;
			}

			$rollback_id = $this->action_log->record( (string) ( $connection['id'] ?? '' ), 'rollback_action', 'revision_restored', array( 'post_id' => (string) $post_id, 'action_id' => $action_id ) );

			return array(
				'action_id' => $rollback_id,
				'post_id'   => $post_id,
				'status'    => 'revision_restored',
				'revisions' => $this->draft_manager->get_revision_count( $post_id ),
			);
		}

		$result = $this->draft_manager->rollback_draft( $post_id, __( 'MCP rollback from AI Builder Connector.', 'ai-builder-connector' ) );

		if ( is_wp_error( $result ) ) {
			$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'rollback_action', 'failed', array( 'post_id' => (string) $post_id, 'error' => $result->get_error_message() ) );
			return $result;
		}

		$rollback_id = $this->action_log->record( (string) ( $connection['id'] ?? '' ), 'rollback_action', 'rolled_back', array( 'post_id' => (string) $post_id, 'action_id' => $action_id ) );

		return array(
			'action_id' => $rollback_id,
			'post_id'   => $post_id,
			'status'    => 'rolled_back',
		);
	}

	/**
	 * Permanently deletes an AIBC-owned draft or rollback record.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param array<string,mixed> $connection Connection row.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function delete_ai_draft( array $arguments, array $connection ): array|\WP_Error {
		$post_id = $this->post_id_argument( $arguments );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$result = $this->draft_manager->delete_ai_draft( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->action_log->record( (string) ( $connection['id'] ?? '' ), 'delete_ai_draft', 'failed', array( 'post_id' => (string) $post_id, 'error' => $result->get_error_message() ) );
			return $result;
		}

		$action_id = $this->action_log->record( (string) ( $connection['id'] ?? '' ), 'delete_ai_draft', 'deleted', array( 'post_id' => (string) $post_id ) );

		return array(
			'action_id' => $action_id,
			'post_id'   => $post_id,
			'status'    => 'deleted',
		);
	}

	/**
	 * Gets current resolved widgets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function current_widgets(): array {
		return $this->detector->is_initialized() ? $this->source_resolver->resolve_widgets( $this->scanner->get_widgets() ) : array();
	}

	/**
	 * Sanitizes a brief argument.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return string|\WP_Error
	 */
	/**
	 * Hoists brief, template, and sections out of a supplied plan object.
	 *
	 * Lets clients chain create_page_plan straight into create_elementor_draft
	 * by passing the returned plan, without re-authoring every field.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<string,mixed>
	 */
	private function apply_plan_argument( array $arguments ): array {
		if ( ! isset( $arguments['plan'] ) || ! is_array( $arguments['plan'] ) ) {
			return $arguments;
		}

		$plan = $arguments['plan'];

		if ( ( ! isset( $arguments['brief'] ) || ! is_scalar( $arguments['brief'] ) || '' === trim( (string) $arguments['brief'] ) ) && isset( $plan['brief'] ) && is_scalar( $plan['brief'] ) ) {
			$arguments['brief'] = (string) $plan['brief'];
		}

		if ( ( ! isset( $arguments['template'] ) || ! is_scalar( $arguments['template'] ) || '' === trim( (string) $arguments['template'] ) ) && isset( $plan['template'] ) && is_scalar( $plan['template'] ) ) {
			$arguments['template'] = (string) $plan['template'];
		}

		if ( ( empty( $arguments['sections'] ) || ! is_array( $arguments['sections'] ) ) && ! empty( $plan['sections'] ) && is_array( $plan['sections'] ) ) {
			$sections = array();

			foreach ( $plan['sections'] as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}

				$content = array();

				if ( isset( $section['content'] ) && is_array( $section['content'] ) ) {
					$content = $section['content'];
				} elseif ( isset( $section['settings'] ) && is_array( $section['settings'] ) ) {
					$content = $section['settings'];
				}

				$sections[] = array(
					'widget'        => isset( $section['widget'] ) && is_scalar( $section['widget'] ) ? (string) $section['widget'] : '',
					'label'         => isset( $section['label'] ) && is_scalar( $section['label'] ) ? (string) $section['label'] : '',
					'section_style' => isset( $section['section_style'] ) && is_scalar( $section['section_style'] ) ? (string) $section['section_style'] : '',
					'description'   => isset( $section['description'] ) && is_scalar( $section['description'] ) ? (string) $section['description'] : '',
					'content'       => $content,
				);
			}

			$arguments['sections'] = $sections;
		}

		return $arguments;
	}

	private function sanitize_brief_argument( array $arguments ): string|\WP_Error {
		$brief = isset( $arguments['brief'] ) && is_scalar( $arguments['brief'] ) ? sanitize_textarea_field( (string) $arguments['brief'] ) : '';

		if ( '' === trim( $brief ) ) {
			return new \WP_Error( 'aibc_mcp_missing_brief', __( 'A website brief is required. Pass "brief" directly, or pass the "plan" object returned by create_page_plan.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		if ( strlen( $brief ) > 3000 ) {
			return new \WP_Error( 'aibc_mcp_brief_too_long', __( 'The website brief is too long for this phase.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		return $brief;
	}

	/**
	 * Gets an optional page template argument.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 */
	private function template_argument( array $arguments ): string {
		$template = isset( $arguments['template'] ) && is_scalar( $arguments['template'] ) ? sanitize_key( (string) $arguments['template'] ) : '';

		return $this->template_registry->resolve_page_template_slug( $template );
	}

	/**
	 * Gets optional AI-authored sections. Structure only; content values are
	 * sanitized by Widget_Content_Sanitizer inside the plan builder.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private function sections_argument( array $arguments ): array {
		if ( ! isset( $arguments['sections'] ) || ! is_array( $arguments['sections'] ) ) {
			return array();
		}

		$sections = array();

		foreach ( $arguments['sections'] as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$sections[] = array(
				'widget'        => isset( $section['widget'] ) && is_scalar( $section['widget'] ) ? sanitize_text_field( (string) $section['widget'] ) : '',
				'label'         => isset( $section['label'] ) && is_scalar( $section['label'] ) ? sanitize_text_field( (string) $section['label'] ) : '',
				'section_style' => isset( $section['section_style'] ) && is_scalar( $section['section_style'] ) ? sanitize_key( (string) $section['section_style'] ) : '',
				'description'   => isset( $section['description'] ) && is_scalar( $section['description'] ) ? sanitize_text_field( (string) $section['description'] ) : '',
				'content'       => isset( $section['content'] ) && is_array( $section['content'] ) ? $section['content'] : array(),
			);

			if ( count( $sections ) >= 20 ) {
				break;
			}
		}

		return $sections;
	}

	/**
	 * Gets an optional AI-supplied page title argument.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 */
	private function title_argument( array $arguments ): string {
		$title = isset( $arguments['title'] ) && is_scalar( $arguments['title'] ) ? sanitize_text_field( (string) $arguments['title'] ) : '';

		return wp_trim_words( $title, 12, '' );
	}

	/**
	 * Gets a post ID argument.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return int|\WP_Error
	 */
	private function post_id_argument( array $arguments ): int|\WP_Error {
		$post_id = absint( $arguments['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'aibc_mcp_missing_post_id', __( 'A valid post_id is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		return $post_id;
	}

	/**
	 * Gets an action ID argument.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @return string|\WP_Error
	 */
	private function action_id_argument( array $arguments ): string|\WP_Error {
		$action_id = isset( $arguments['action_id'] ) && is_scalar( $arguments['action_id'] ) ? sanitize_text_field( (string) $arguments['action_id'] ) : '';

		if ( '' === $action_id ) {
			return new \WP_Error( 'aibc_mcp_missing_action_id', __( 'A valid action_id is required.', 'ai-builder-connector' ), array( 'status' => 400 ) );
		}

		return $action_id;
	}

	/**
	 * Gets an optional review note argument.
	 *
	 * @param array<string,mixed> $arguments Tool arguments.
	 */
	private function note_argument( array $arguments ): string {
		return isset( $arguments['note'] ) && is_scalar( $arguments['note'] ) ? sanitize_textarea_field( (string) $arguments['note'] ) : '';
	}

	/**
	 * Builds a safe public plan response.
	 *
	 * @param array<string,mixed> $plan Raw plan.
	 * @return array<string,mixed>
	 */
	private function public_plan( array $plan ): array {
		$sections = array();

		foreach ( (array) ( $plan['sections'] ?? array() ) as $section ) {
			if ( is_array( $section ) ) {
				$row = array(
					'label'         => sanitize_text_field( (string) ( $section['label'] ?? '' ) ),
					'widget'        => sanitize_text_field( (string) ( $section['widget'] ?? '' ) ),
					'source'        => sanitize_text_field( (string) ( $section['source'] ?? '' ) ),
					'section_style' => sanitize_key( (string) ( $section['section_style'] ?? '' ) ),
					'description'   => sanitize_text_field( (string) ( $section['description'] ?? '' ) ),
				);

				if ( isset( $section['settings'] ) && is_array( $section['settings'] ) ) {
					$row['settings'] = $this->public_section_settings( $section['settings'] );
				}

				$sections[] = $row;
			}
		}

		return array(
			'brief'           => sanitize_textarea_field( (string) ( $plan['brief'] ?? '' ) ),
			'created_at'      => sanitize_text_field( (string) ( $plan['created_at'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $plan['status'] ?? '' ) ),
			'content_mode'    => sanitize_key( (string) ( $plan['content_mode'] ?? 'template' ) ),
			'template'        => sanitize_key( (string) ( $plan['template'] ?? '' ) ),
			'template_title'  => sanitize_text_field( (string) ( $plan['template_title'] ?? '' ) ),
			'design_system'   => $this->public_design_system( (array) ( $plan['design_system'] ?? array() ) ),
			'sections'        => $sections,
			'used_widgets'    => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $plan['used_widgets'] ?? array() ) ) ) ),
			'blocked_widgets' => $this->public_blocked_widgets( (array) ( $plan['blocked_widgets'] ?? array() ) ),
		);
	}

	/**
	 * Builds safe public section settings for plan responses.
	 *
	 * @param array<string,mixed> $settings Accepted section settings.
	 * @return array<string,mixed>
	 */
	private function public_section_settings( array $settings ): array {
		$public = array();

		foreach ( $settings as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( 'editor' === $key && is_string( $value ) ) {
				$public[ $key ] = wp_kses_post( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$public[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$nested = array();

				foreach ( $value as $nested_key => $nested_value ) {
					$nested_key = sanitize_key( (string) $nested_key );

					if ( '' !== $nested_key && ( is_scalar( $nested_value ) || null === $nested_value ) ) {
						$nested[ $nested_key ] = sanitize_text_field( (string) $nested_value );
					}
				}

				$public[ $key ] = $nested;
			}
		}

		return $public;
	}

	/**
	 * Builds a safe public draft record.
	 *
	 * @return array<string,mixed>
	 */
	private function public_draft( \WP_Post $draft ): array {
		$plan = $this->draft_manager->get_plan( $draft->ID );

		return array(
			'id'                => (int) $draft->ID,
			'title'             => sanitize_text_field( get_the_title( $draft ) ),
			'status'            => sanitize_key( $draft->post_status ),
			'post_status'       => sanitize_key( $draft->post_status ),
			'created_at'        => sanitize_text_field( get_post_meta( $draft->ID, Draft_Manager::META_CREATED_AT, true ) ),
			'review'            => $this->review_workflow->get_state( $draft->ID ),
			'validation_status' => $this->draft_manager->get_validation_status( $draft->ID ),
			'validation_errors' => $this->draft_manager->get_validation_error_count( $draft->ID ),
			'template'          => sanitize_key( (string) ( $plan['template'] ?? '' ) ),
			'template_title'    => sanitize_text_field( (string) ( $plan['template_title'] ?? '' ) ),
			'planned_sections'  => is_array( $plan['sections'] ?? null ) ? count( (array) $plan['sections'] ) : 0,
			'content_mode'      => sanitize_key( (string) ( $plan['content_mode'] ?? 'template' ) ),
			'revisions'         => $this->draft_manager->get_revision_count( $draft->ID ),
			'widgets_used'      => $this->draft_manager->get_used_widgets( $draft->ID ),
			'design_system'     => $this->draft_manager->get_design_system( $draft->ID ),
			'published'         => 'publish' === $draft->post_status,
			'published_at'      => $this->draft_manager->get_published_at( $draft->ID ),
		);
	}

	/**
	 * Builds a safe public action record.
	 *
	 * @param array<string,mixed> $action Raw action.
	 * @return array<string,mixed>
	 */
	private function public_action( array $action ): array {
		return array(
			'action_id'  => sanitize_text_field( (string) ( $action['id'] ?? '' ) ),
			'tool_name'  => sanitize_key( (string) ( $action['tool'] ?? '' ) ),
			'status'     => sanitize_key( (string) ( $action['status'] ?? '' ) ),
			'context'    => is_array( $action['context'] ?? null ) ? $action['context'] : array(),
			'created_at' => sanitize_text_field( (string) ( $action['created_at'] ?? '' ) ),
		);
	}

	/**
	 * Finds a tracked create action by post ID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_create_action_by_post_id( int $post_id ): ?array {
		foreach ( $this->action_log->get_actions() as $action ) {
			$context = is_array( $action['context'] ?? null ) ? $action['context'] : array();

			if (
				'create_elementor_draft' === (string) ( $action['tool'] ?? '' )
				&& (string) $post_id === (string) ( $context['post_id'] ?? '' )
			) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * Sanitizes blocked widget rows for public responses.
	 *
	 * @param array<int,mixed> $blocked_widgets Raw blocked widgets.
	 * @return array<int,array{widget:string,reason:string}>
	 */
	private function public_blocked_widgets( array $blocked_widgets ): array {
		$rows = array();

		foreach ( $blocked_widgets as $item ) {
			if ( is_array( $item ) ) {
				$rows[] = array(
					'widget' => sanitize_text_field( (string) ( $item['widget'] ?? '' ) ),
					'reason' => sanitize_text_field( (string) ( $item['reason'] ?? '' ) ),
				);
			}
		}

		return $rows;
	}

	/**
	 * Sanitizes design-system tokens for public MCP responses.
	 *
	 * @param array<string,mixed> $tokens Raw tokens.
	 * @return array<string,mixed>
	 */
	private function public_design_system( array $tokens ): array {
		if ( empty( $tokens ) ) {
			return $this->design_system->get_tokens();
		}

		$rows = array();

		foreach ( $tokens as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				$rows[ $key ] = (int) $value;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$rows[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( is_array( $value ) ) {
				$nested = array();

				foreach ( $value as $nested_key => $nested_value ) {
					$nested_key = sanitize_key( (string) $nested_key );

					if ( '' === $nested_key ) {
						continue;
					}

					if ( is_numeric( $nested_value ) ) {
						$nested[ $nested_key ] = (int) $nested_value;
					} elseif ( is_scalar( $nested_value ) || null === $nested_value ) {
						$nested[ $nested_key ] = sanitize_text_field( (string) $nested_value );
					} elseif ( is_array( $nested_value ) ) {
						$nested[ $nested_key ] = array_values( array_filter( array_map( 'absint', $nested_value ) ) );
					}
				}

				$rows[ $key ] = $nested;
			}
		}

		return $rows;
	}

	/**
	 * Gets a preview URL.
	 */
	private function preview_url( int $post_id ): string {
		$url = get_preview_post_link( $post_id );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}
}
