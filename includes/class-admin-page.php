<?php
/**
 * Admin page renderer.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Tools admin page.
 */
final class Admin_Page {
	private const MENU_SLUG = 'ai-builder-connector';

	private Elementor_Detector $detector;
	private Widget_Scanner $scanner;
	private Addon_Detector $addon_detector;
	private Widget_Source_Resolver $source_resolver;
	private Permission_Manager $permission_manager;
	private Settings $settings;
	private Design_System $design_system;
	private Template_Registry $template_registry;
	private Widget_Definition_Registry $widget_definitions;
	private Page_Plan_Builder $page_plan_builder;
	private Draft_Manager $draft_manager;
	private Review_Workflow $review_workflow;
	private MCP_Action_Log $mcp_action_log;

	/**
	 * MCP connection manager.
	 *
	 * @var MCP_Connection_Manager
	 */
	private MCP_Connection_Manager $mcp_connection_manager;
	private string $hook_suffix = '';

	/**
	 * Admin page constructor.
	 */
	public function __construct(
		Elementor_Detector $detector,
		Widget_Scanner $scanner,
		Addon_Detector $addon_detector,
		Widget_Source_Resolver $source_resolver,
		Permission_Manager $permission_manager,
		Settings $settings,
		Design_System $design_system,
		Template_Registry $template_registry,
		Widget_Definition_Registry $widget_definitions,
		Page_Plan_Builder $page_plan_builder,
		Draft_Manager $draft_manager,
		Review_Workflow $review_workflow,
		MCP_Connection_Manager $mcp_connection_manager,
		MCP_Action_Log $mcp_action_log
	) {
		$this->detector           = $detector;
		$this->scanner            = $scanner;
		$this->addon_detector     = $addon_detector;
		$this->source_resolver    = $source_resolver;
		$this->permission_manager = $permission_manager;
		$this->settings           = $settings;
		$this->design_system      = $design_system;
		$this->template_registry  = $template_registry;
		$this->widget_definitions = $widget_definitions;
		$this->page_plan_builder  = $page_plan_builder;
		$this->draft_manager      = $draft_manager;
		$this->review_workflow    = $review_workflow;
		$this->mcp_connection_manager = $mcp_connection_manager;
		$this->mcp_action_log     = $mcp_action_log;
	}

	/**
	 * Registers the Tools page.
	 */
	public function register_menu(): void {
		$this->hook_suffix = add_menu_page(
			__( 'AI Builder Connector', 'ai-builder-connector' ),
			__( 'AI Builder', 'ai-builder-connector' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-layout',
			58.7
		);

		// Flyout submenu: one entry per dashboard tab, plus the setup wizard.
		add_submenu_page( self::MENU_SLUG, __( 'AI Builder Connector', 'ai-builder-connector' ), __( 'Overview', 'ai-builder-connector' ), 'manage_options', self::MENU_SLUG, array( $this, 'render' ) );

		foreach ( array(
			'drafts'      => __( 'Drafts', 'ai-builder-connector' ),
			'connections' => __( 'Connections', 'ai-builder-connector' ),
			'settings'    => __( 'Permissions & Design', 'ai-builder-connector' ),
			'reference'   => __( 'Reference', 'ai-builder-connector' ),
		) as $tab => $label ) {
			add_submenu_page(
				self::MENU_SLUG,
				$label,
				$label,
				'manage_options',
				'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab
			);
		}

		add_submenu_page( self::MENU_SLUG, __( 'Setup Wizard', 'ai-builder-connector' ), __( 'Setup Wizard', 'ai-builder-connector' ), 'manage_options', 'admin.php?page=aibc-setup&step=1' );
	}

	/**
	 * Highlights the submenu entry matching the active dashboard tab.
	 */
	public function highlight_submenu( $submenu_file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only menu highlight.
		if ( isset( $_GET['page'] ) && self::MENU_SLUG === $_GET['page'] && isset( $_GET['tab'] ) ) {
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( in_array( $tab, array( 'drafts', 'connections', 'settings', 'reference' ), true ) ) {
				return 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab;
			}
		}

		return $submenu_file;
	}

	/**
	 * Registers settings.
	 */
	public function register_settings(): void {
		$this->settings->register();
	}

	/**
	 * Handles manual widget rescans.
	 */
	public function handle_rescan(): void {
		$this->settings->handle_rescan();
	}

	/**
	 * Handles draft creation.
	 */
	public function handle_create_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to create drafts.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_create_draft' );

		$brief   = isset( $_POST['aibc_brief'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aibc_brief'] ) ) : '';
		$template = isset( $_POST['aibc_template'] ) ? sanitize_key( wp_unslash( $_POST['aibc_template'] ) ) : '';
		$widgets = $this->detector->is_initialized() ? $this->source_resolver->resolve_widgets( $this->scanner->get_widgets() ) : array();
		$result  = $this->draft_manager->create_draft( $brief, $widgets, $template );
		$args    = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_draft_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aibc_draft_created'] = (string) (int) $result;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles rollback for plugin-created drafts.
	 */
	public function handle_rollback_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to roll back drafts.', 'ai-builder-connector' ) );
		}

		$post_id = isset( $_POST['aibc_post_id'] ) ? absint( wp_unslash( $_POST['aibc_post_id'] ) ) : 0;

		check_admin_referer( 'aibc_rollback_draft_' . $post_id );

		$result = $this->draft_manager->rollback_draft( $post_id );
		$args   = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_rollback_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aibc_draft_rolled_back'] = (string) $post_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles review-time validation.
	 */
	public function handle_validate_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to validate drafts.', 'ai-builder-connector' ) );
		}

		$post_id = isset( $_POST['aibc_post_id'] ) ? absint( wp_unslash( $_POST['aibc_post_id'] ) ) : 0;

		check_admin_referer( 'aibc_validate_draft_' . $post_id );

		$widgets = $this->detector->is_initialized() ? $this->source_resolver->resolve_widgets( $this->scanner->get_widgets() ) : array();
		$result  = $this->draft_manager->validate_draft_again( $post_id, $widgets );
		$args    = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_review_error'] = rawurlencode( $result->get_error_message() );
		} elseif ( $result->is_valid() ) {
			$args['aibc_draft_validated'] = (string) $post_id;
		} else {
			$args['aibc_review_error'] = rawurlencode( __( 'Draft validation failed. Review the validation status for details.', 'ai-builder-connector' ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles approval and rejection transitions.
	 */
	public function handle_review_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to review drafts.', 'ai-builder-connector' ) );
		}

		$post_id = isset( $_POST['aibc_post_id'] ) ? absint( wp_unslash( $_POST['aibc_post_id'] ) ) : 0;
		$decision = isset( $_POST['aibc_review_decision'] ) ? sanitize_key( wp_unslash( $_POST['aibc_review_decision'] ) ) : '';
		$note = isset( $_POST['aibc_review_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aibc_review_note'] ) ) : '';

		check_admin_referer( 'aibc_review_draft_' . $post_id );

		if ( 'approve' === $decision ) {
			$result = $this->review_workflow->approve( $post_id, '' !== $note ? $note : __( 'Approved for future publishing review. No publishing was performed.', 'ai-builder-connector' ) );
			$success_key = 'aibc_draft_approved';
		} elseif ( 'reject' === $decision ) {
			$result = $this->review_workflow->reject( $post_id, '' !== $note ? $note : __( 'Rejected during administrator review.', 'ai-builder-connector' ) );
			$success_key = 'aibc_draft_rejected';
		} else {
			$result = new \WP_Error( 'aibc_invalid_review_decision', __( 'Unknown review decision.', 'ai-builder-connector' ) );
			$success_key = '';
		}

		$args = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_review_error'] = rawurlencode( $result->get_error_message() );
		} elseif ( '' !== $success_key ) {
			$args[ $success_key ] = (string) $post_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles admin-gated publishing for approved AIBC drafts.
	 */
	public function handle_publish_draft(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to publish AI drafts.', 'ai-builder-connector' ) );
		}

		$post_id = isset( $_POST['aibc_post_id'] ) ? absint( wp_unslash( $_POST['aibc_post_id'] ) ) : 0;

		check_admin_referer( 'aibc_publish_draft_' . $post_id );

		$result = $this->draft_manager->publish_draft( $post_id );
		$args   = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_review_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aibc_draft_published'] = (string) $post_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles unpublishing an AIBC-published page back to draft.
	 */
	public function handle_unpublish_draft(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to unpublish AI pages.', 'ai-builder-connector' ) );
		}

		$post_id = isset( $_POST['aibc_post_id'] ) ? absint( wp_unslash( $_POST['aibc_post_id'] ) ) : 0;

		check_admin_referer( 'aibc_unpublish_draft_' . $post_id );

		$result = $this->draft_manager->unpublish_page( $post_id );
		$args   = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_review_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aibc_draft_unpublished'] = (string) $post_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles permanent deletion for AIBC-owned drafts.
	 */
	public function handle_delete_draft(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete AI drafts.', 'ai-builder-connector' ) );
		}

		$post_id = isset( $_POST['aibc_post_id'] ) ? absint( wp_unslash( $_POST['aibc_post_id'] ) ) : 0;

		check_admin_referer( 'aibc_delete_draft_' . $post_id );

		$result = $this->draft_manager->delete_ai_draft( $post_id );
		$args   = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_review_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aibc_draft_deleted'] = (string) $post_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Brand kits service (set after construction).
	 */
	private ?Brand_Kits $brand_kits = null;

	/**
	 * Injects the brand kits service.
	 */
	public function set_brand_kits( Brand_Kits $brand_kits ): void {
		$this->brand_kits = $brand_kits;
	}

	/**
	 * Applies a brand kit to the AIBC design system.
	 */
	public function handle_apply_brand_kit(): void {
		if ( ! current_user_can( 'manage_options' ) || null === $this->brand_kits ) {
			wp_die( esc_html__( 'You do not have permission to apply brand kits.', 'ai-builder-connector' ) );
		}

		$slug = isset( $_POST['aibc_kit'] ) ? sanitize_key( wp_unslash( $_POST['aibc_kit'] ) ) : '';

		check_admin_referer( 'aibc_apply_brand_kit_' . $slug );

		$result = $this->brand_kits->apply( $slug );
		$args   = array( 'page' => 'ai-builder-connector', 'tab' => 'settings' );

		if ( is_wp_error( $result ) ) {
			$args['aibc_review_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['aibc_kit_applied'] = rawurlencode( (string) $result['name'] );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Restores the design saved before the last brand kit apply.
	 */
	public function handle_restore_design(): void {
		if ( ! current_user_can( 'manage_options' ) || null === $this->brand_kits ) {
			wp_die( esc_html__( 'You do not have permission to restore the design.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_restore_design' );

		$restored = $this->brand_kits->restore_backup();
		$args     = array( 'page' => 'ai-builder-connector', 'tab' => 'settings' );

		if ( $restored ) {
			$args['aibc_design_restored'] = '1';
		} else {
			$args['aibc_review_error'] = rawurlencode( __( 'No design backup found to restore.', 'ai-builder-connector' ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the brand kit picker (settings tab, outside the settings form).
	 */
	private function render_brand_kits(): void {
		if ( null === $this->brand_kits ) {
			return;
		}

		$current = $this->design_system->get_config();

		echo '<section class="aibc-panel" aria-labelledby="aibc-brand-kits">';
		echo '<h2 id="aibc-brand-kits">' . esc_html__( 'Brand Kits', 'ai-builder-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'One-click color and typography presets for AI-built pages. Applying a kit only changes the AI design system above — never your live theme. Your previous design is backed up automatically.', 'ai-builder-connector' ) . '</p>';

		if ( $this->brand_kits->has_backup() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aibc-inline-form">';
			wp_nonce_field( 'aibc_restore_design' );
			echo '<input type="hidden" name="action" value="aibc_restore_design">';
			echo '<button type="submit" class="button">' . esc_html__( 'Restore previous design', 'ai-builder-connector' ) . '</button>';
			echo '</form>';
		}

		echo '<div class="aibc-kit-grid">';

		foreach ( $this->brand_kits->get_kits() as $slug => $kit ) {
			$is_current = ( $current['primary_color'] ?? '' ) === $kit['primary_color'] && ( $current['heading_font_family'] ?? '' ) === $kit['heading_font_family'];

			echo '<div class="aibc-kit-card' . ( $is_current ? ' is-current' : '' ) . '">';
			echo '<div class="aibc-kit-swatches">';
			foreach ( array( 'primary_color', 'secondary_color', 'accent_color' ) as $color_key ) {
				echo '<span style="background:' . esc_attr( $kit[ $color_key ] ) . '"></span>';
			}
			echo '</div>';
			echo '<div class="aibc-kit-name">' . esc_html( $kit['name'] ) . '</div>';
			echo '<div class="aibc-kit-fonts">' . esc_html( $kit['heading_font_family'] . ' + ' . $kit['font_family'] ) . '</div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'aibc_apply_brand_kit_' . $slug );
			echo '<input type="hidden" name="action" value="aibc_apply_brand_kit">';
			echo '<input type="hidden" name="aibc_kit" value="' . esc_attr( $slug ) . '">';
			echo '<button type="submit" class="button' . ( $is_current ? '' : ' button-primary' ) . '">' . ( $is_current ? esc_html__( 'Applied', 'ai-builder-connector' ) : esc_html__( 'Apply kit', 'ai-builder-connector' ) ) . '</button>';
			echo '</form>';
			echo '</div>';
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Enables Elementor's atomic elements experiment (admin action).
	 */
	public function handle_enable_atomic(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Elementor features.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_enable_atomic' );

		update_option( 'elementor_experiment-e_atomic_elements', 'active', false );
		update_option( 'elementor_experiment-atomic_widgets', 'active', false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'ai-builder-connector', 'aibc_atomic_enabled' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles bulk reject/delete for AIBC drafts.
	 */
	public function handle_bulk_drafts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage AI drafts.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_bulk_drafts' );

		$bulk_action = isset( $_POST['aibc_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['aibc_bulk_action'] ) ) : '';
		$post_ids    = isset( $_POST['aibc_post_ids'] ) && is_array( $_POST['aibc_post_ids'] ) ? array_filter( array_map( 'absint', wp_unslash( $_POST['aibc_post_ids'] ) ) ) : array();
		$args        = array( 'page' => 'ai-builder-connector', 'tab' => 'drafts' );

		if ( ! in_array( $bulk_action, array( 'reject', 'delete' ), true ) || empty( $post_ids ) ) {
			$args['aibc_review_error'] = rawurlencode( __( 'Pick a bulk action and at least one draft.', 'ai-builder-connector' ) );
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$done   = 0;
		$failed = 0;

		foreach ( $post_ids as $post_id ) {
			if ( 'reject' === $bulk_action ) {
				$result = $this->review_workflow->reject( $post_id, __( 'Rejected in bulk cleanup.', 'ai-builder-connector' ) );
			} else {
				$result = $this->draft_manager->delete_ai_draft( $post_id );
			}

			if ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$done;
			}
		}

		$args['aibc_bulk_action'] = $bulk_action;
		$args['aibc_bulk_done']   = (string) $done;
		$args['aibc_bulk_failed'] = (string) $failed;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles MCP connection creation.
	 */
	public function handle_create_mcp_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to create MCP connections.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_create_mcp_connection' );

		$label   = isset( $_POST['aibc_mcp_label'] ) ? sanitize_text_field( wp_unslash( $_POST['aibc_mcp_label'] ) ) : '';
		$expires = isset( $_POST['aibc_mcp_expires'] ) ? absint( wp_unslash( $_POST['aibc_mcp_expires'] ) ) : 30;
		$created = $this->mcp_connection_manager->create_connection( $label, $expires );
		$id      = (string) $created['connection']['id'];

		$this->mcp_connection_manager->store_one_time_token( $id, $created['token'] );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => 'ai-builder-connector',
					'tab'                     => 'connections',
					'aibc_mcp_created'        => rawurlencode( $id ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles MCP connection revocation.
	 */
	public function handle_revoke_mcp_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke MCP connections.', 'ai-builder-connector' ) );
		}

		$connection_id = isset( $_POST['aibc_connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['aibc_connection_id'] ) ) : '';

		check_admin_referer( 'aibc_revoke_mcp_connection_' . $connection_id );

		$this->mcp_connection_manager->revoke_connection( $connection_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'ai-builder-connector',
					'tab'              => 'connections',
					'aibc_mcp_revoked' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the MCP emergency disable switch.
	 */
	public function handle_toggle_mcp(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change MCP access.', 'ai-builder-connector' ) );
		}

		check_admin_referer( 'aibc_toggle_mcp' );

		$disabled = isset( $_POST['aibc_mcp_disabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['aibc_mcp_disabled'] ) );
		$this->mcp_connection_manager->set_disabled( $disabled );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'ai-builder-connector',
					'tab'              => 'connections',
					'aibc_mcp_updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Enqueues admin assets for this page only.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'aibc-admin',
			AIBC_PLUGIN_URL . 'assets/admin.css',
			array(),
			AIBC_VERSION
		);
	}

	/**
	 * Renders the admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-builder-connector' ) );
		}

		$status  = $this->detector->get_status();
		$widgets = $status['initialized'] ? $this->source_resolver->resolve_widgets( $this->scanner->get_widgets() ) : array();
		$sources = $this->prepare_sources( $widgets );
		$groups  = $this->group_widgets( $widgets );
		$summary = $this->build_current_summary( $widgets, $sources );

		$tabs = array(
			'overview'    => __( 'Overview', 'ai-builder-connector' ),
			'drafts'      => __( 'Drafts', 'ai-builder-connector' ),
			'connections' => __( 'Connections', 'ai-builder-connector' ),
			'settings'    => __( 'Permissions & Design', 'ai-builder-connector' ),
			'reference'   => __( 'Reference', 'ai-builder-connector' ),
		);

		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'overview';
		}

		echo '<div class="wrap aibc-admin">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'AI Builder Connector', 'ai-builder-connector' ) . '</h1>';

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI Builder Connector permissions saved.', 'ai-builder-connector' ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_rescanned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Elementor widgets rescanned and snapshot saved.', 'ai-builder-connector' ) . '</p></div>';
		}

		$this->render_draft_notices();

		$endpoint_on    = ! $this->mcp_connection_manager->is_disabled();
		$elementor_cell = ! empty( $status['active'] )
			? ( defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : __( 'Active', 'ai-builder-connector' ) )
			: __( 'Not active', 'ai-builder-connector' );

		echo '<header class="aibc-masthead">';
		echo '<div class="aibc-masthead-top">';
		echo '<div class="aibc-mark">';
		echo '<span class="aibc-mark-name">' . esc_html__( 'AI Builder Connector', 'ai-builder-connector' ) . '</span>';
		echo '<p class="aibc-mark-line">' . esc_html__( 'Your AI drafts pages here. Nothing goes live until you approve it.', 'ai-builder-connector' ) . '</p>';
		echo '</div>';
		echo '<div class="aibc-masthead-actions">';
		echo '<a class="aibc-act" href="' . esc_url( add_query_arg( array( 'page' => 'aibc-setup', 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Setup wizard', 'ai-builder-connector' ) . '</a>';
		echo '<a class="aibc-act aibc-act-ink" href="' . esc_url( $this->tab_url( 'connections' ) ) . '">' . esc_html__( 'Connect an AI', 'ai-builder-connector' ) . '</a>';
		echo '</div>';
		echo '</div>';

		// Title block: the drawing-sheet metadata for this install.
		$cells = array(
			array( __( 'Plugin', 'ai-builder-connector' ), 'v' . AIBC_VERSION, '' ),
			array( __( 'Elementor', 'ai-builder-connector' ), $elementor_cell, empty( $status['active'] ) ? 'is-off' : '' ),
			array( __( 'AI endpoint', 'ai-builder-connector' ), $endpoint_on ? __( 'On', 'ai-builder-connector' ) : __( 'Off', 'ai-builder-connector' ), $endpoint_on ? 'is-on' : 'is-off' ),
			array( __( 'Widgets it may use', 'ai-builder-connector' ), (string) (int) $summary['widget_count'], '' ),
		);

		echo '<dl class="aibc-titleblock">';
		foreach ( $cells as $cell ) {
			echo '<div class="aibc-tb-cell">';
			echo '<dt>' . esc_html( (string) $cell[0] ) . '</dt>';
			echo '<dd class="' . esc_attr( (string) $cell[2] ) . '">' . esc_html( (string) $cell[1] ) . '</dd>';
			echo '</div>';
		}
		echo '</dl>';
		echo '</header>';

		echo '<nav class="nav-tab-wrapper aibc-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->tab_url( $key ) ),
				$key === $active ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div class="aibc-tab-content">';

		switch ( $active ) {
			case 'drafts':
				$this->render_draft_sandbox( $widgets );
				$this->render_created_drafts();
				$this->render_ai_actions();
				break;

			case 'connections':
				$this->render_mcp_connections();
				break;

			case 'settings':
				echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
				settings_fields( 'aibc_permissions' );
				$this->render_addon_permissions( $sources );
				$this->render_widget_permissions( $groups );
				$this->render_design_system_settings();
				submit_button( __( 'Save Permissions and Design System', 'ai-builder-connector' ) );
				echo '</form>';
				$this->render_brand_kits();
				break;

			case 'reference':
				$this->render_verified_widget_pack( $widgets );
				$this->render_template_library();
				$this->render_sample_plan_preview( $widgets );
				$this->render_grouped_widgets( $groups );
				$this->render_unknown_widgets( $groups );
				$this->render_scan_summary( $summary );
				$this->render_rescan_form();
				break;

			case 'overview':
			default:
				$this->render_overview( $status, $summary );
				break;
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Builds a URL to a dashboard tab.
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders the Overview tab: status strip, review queue, and quick actions.
	 *
	 * @param array<string,mixed> $status  Elementor status.
	 * @param array<string,mixed> $summary Scan summary.
	 */
	private function render_overview( array $status, array $summary ): void {
		$connections = $this->mcp_connection_manager->get_connections();
		$active_conns = 0;

		foreach ( $connections as $connection ) {
			if ( '' === (string) ( $connection['revoked_at'] ?? '' ) ) {
				$active_conns++;
			}
		}

		$mcp_on = ! $this->mcp_connection_manager->is_disabled();
		$drafts = $this->draft_manager->get_created_drafts();
		$needs  = array();

		foreach ( $drafts as $draft ) {
			$state = $this->review_workflow->get_state( $draft->ID );

			if ( 'needs_review' === (string) $state['status'] ) {
				$needs[] = array( 'draft' => $draft, 'state' => $state );
			}
		}

		$waiting = count( $needs );

		// The hero states the reviewer's job, not the machine's statistics.
		echo '<section class="aibc-hero">';
		echo '<p class="aibc-eyebrow">' . esc_html__( 'On your desk', 'ai-builder-connector' ) . '</p>';

		if ( $waiting > 0 ) {
			printf(
				'<h2 class="aibc-hero-line">%1$s <span class="aibc-hero-mark">%2$s</span> %3$s</h2>',
				esc_html__( 'You have', 'ai-builder-connector' ),
				esc_html( sprintf(
					/* translators: %d: number of drafts awaiting review. */
					_n( '%d draft', '%d drafts', $waiting, 'ai-builder-connector' ),
					$waiting
				) ),
				esc_html__( 'to review.', 'ai-builder-connector' )
			);
			echo '<p class="aibc-hero-sub">' . esc_html__( 'Read each one, then approve it to publish or reject it to send it back.', 'ai-builder-connector' ) . '</p>';
			echo '<div class="aibc-hero-actions"><a class="aibc-act aibc-act-ink" href="' . esc_url( $this->tab_url( 'drafts' ) ) . '">' . esc_html__( 'Open the review queue', 'ai-builder-connector' ) . '</a></div>';
		} elseif ( $active_conns < 1 ) {
			echo '<h2 class="aibc-hero-line">' . esc_html__( 'No AI is connected yet.', 'ai-builder-connector' ) . '</h2>';
			echo '<p class="aibc-hero-sub">' . esc_html__( 'Create a connection token, paste it into your AI tool, and ask it to build a page.', 'ai-builder-connector' ) . '</p>';
			echo '<div class="aibc-hero-actions"><a class="aibc-act aibc-act-ink" href="' . esc_url( $this->tab_url( 'connections' ) ) . '">' . esc_html__( 'Connect an AI', 'ai-builder-connector' ) . '</a></div>';
		} else {
			echo '<h2 class="aibc-hero-line">' . esc_html__( 'Your desk is clear.', 'ai-builder-connector' ) . '</h2>';
			echo '<p class="aibc-hero-sub">' . esc_html__( 'Ask your AI to build a page and it lands here for review.', 'ai-builder-connector' ) . '</p>';
			echo '<div class="aibc-hero-actions"><a class="aibc-act aibc-act-ink" href="' . esc_url( $this->tab_url( 'drafts' ) ) . '">' . esc_html__( 'Write a brief yourself', 'ai-builder-connector' ) . '</a></div>';
		}

		echo '</section>';

		if ( ! empty( $needs ) ) {
			echo '<section class="aibc-panel aibc-card">';
			echo '<h2>' . esc_html__( 'Waiting for a decision', 'ai-builder-connector' ) . '</h2>';
			echo '<ul class="aibc-review-list">';

			foreach ( $needs as $item ) {
				$draft = $item['draft'];
				// Every row here is by definition awaiting review, so a status
				// stamp on each one would repeat the panel heading and carry no
				// information. The date does carry some: what has been sitting longest.
				echo '<li>';
				echo '<a class="aibc-review-title" href="' . esc_url( (string) get_edit_post_link( $draft->ID, '' ) ) . '">' . esc_html( get_the_title( $draft ) ) . '</a>';
				echo '<span class="aibc-review-age">' . esc_html( sprintf(
					/* translators: %s: human-readable time difference, e.g. "2 hours". */
					__( '%s ago', 'ai-builder-connector' ),
					human_time_diff( (int) get_post_timestamp( $draft ), time() )
				) ) . '</span>';
				echo '<a class="button button-small" href="' . esc_url( $this->tab_url( 'drafts' ) ) . '">' . esc_html__( 'Review', 'ai-builder-connector' ) . '</a>';
				echo '</li>';
			}

			echo '</ul>';
			echo '</section>';
		}

		echo '<section class="aibc-panel aibc-card">';
		echo '<h2>' . esc_html__( 'Quick actions', 'ai-builder-connector' ) . '</h2>';
		echo '<div class="aibc-actions-row">';
		echo '<a class="button button-primary" href="' . esc_url( $this->tab_url( 'drafts' ) ) . '">' . esc_html__( 'Create a draft', 'ai-builder-connector' ) . '</a>';
		echo '<a class="button" href="' . esc_url( $this->tab_url( 'connections' ) ) . '">' . esc_html__( 'Add an AI connection', 'ai-builder-connector' ) . '</a>';
		echo '<a class="button" href="' . esc_url( $this->tab_url( 'settings' ) ) . '">' . esc_html__( 'Permissions & design', 'ai-builder-connector' ) . '</a>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aibc-inline-form">';
		wp_nonce_field( 'aibc_rescan_widgets' );
		echo '<input type="hidden" name="action" value="aibc_rescan_widgets">';
		echo '<button type="submit" class="button">' . esc_html__( 'Rescan widgets', 'ai-builder-connector' ) . '</button>';
		echo '</form>';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'aibc-setup', 'step' => 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Run setup wizard', 'ai-builder-connector' ) . '</a>';

		if ( ! Atomic_Builder::is_supported() && defined( 'ELEMENTOR_VERSION' ) && version_compare( (string) ELEMENTOR_VERSION, '4.0.0', '>=' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aibc-inline-form">';
			wp_nonce_field( 'aibc_enable_atomic' );
			echo '<input type="hidden" name="action" value="aibc_enable_atomic">';
			echo '<button type="submit" class="button">' . esc_html__( 'Enable atomic elements (Elementor 4 experiment)', 'ai-builder-connector' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
		echo '</section>';

		$this->render_status_panel( $status );
	}

	/**
	 * Renders draft action notices.
	 */
	private function render_draft_notices(): void {
		if ( isset( $_GET['aibc_draft_created'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_created'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'Draft page created for review. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_rolled_back'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_rolled_back'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'Draft page moved to trash. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['aibc_draft_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_rollback_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['aibc_rollback_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_validated'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_validated'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'Draft validation passed. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_approved'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_approved'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'Draft approved for review workflow. Page ID: %d. Publishing remains disabled.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_rejected'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_rejected'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'Draft rejected. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_deleted'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_deleted'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'AI draft permanently deleted. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_published'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_published'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'AI page published by administrator. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_draft_unpublished'] ) ) {
			$post_id = absint( wp_unslash( $_GET['aibc_draft_unpublished'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: WordPress post ID. */
				__( 'AI page unpublished and returned to draft. Page ID: %d.', 'ai-builder-connector' ),
				$post_id
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_review_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['aibc_review_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_atomic_enabled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Elementor atomic elements experiment activated. The AI can now build with engine "atomic".', 'ai-builder-connector' ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_kit_applied'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %s: brand kit name. */
				__( 'Brand kit "%s" applied to the AI design system. Your previous design was backed up.', 'ai-builder-connector' ),
				sanitize_text_field( wp_unslash( $_GET['aibc_kit_applied'] ) )
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_design_restored'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Previous design restored.', 'ai-builder-connector' ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_bulk_done'] ) ) {
			$done        = absint( wp_unslash( $_GET['aibc_bulk_done'] ) );
			$failed      = isset( $_GET['aibc_bulk_failed'] ) ? absint( wp_unslash( $_GET['aibc_bulk_failed'] ) ) : 0;
			$bulk_action = isset( $_GET['aibc_bulk_action'] ) ? sanitize_key( wp_unslash( $_GET['aibc_bulk_action'] ) ) : '';
			$verb        = 'delete' === $bulk_action ? __( 'deleted', 'ai-builder-connector' ) : __( 'rejected', 'ai-builder-connector' );
			$class       = $failed > 0 ? 'notice-warning' : 'notice-success';

			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( sprintf(
				/* translators: 1: number of drafts processed, 2: action verb, 3: number skipped. */
				__( 'Bulk action finished: %1$d draft(s) %2$s, %3$d skipped.', 'ai-builder-connector' ),
				$done,
				$verb,
				$failed
			) ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_mcp_created'] ) ) {
			$connection_id = sanitize_text_field( wp_unslash( $_GET['aibc_mcp_created'] ) );
			$token         = $this->mcp_connection_manager->consume_one_time_token( $connection_id );

			if ( '' !== $token ) {
				$endpoint     = rest_url( 'ai-builder-connector/v1/mcp' );
				$cli_snippet  = sprintf(
					'claude mcp add ai-builder --transport http %1$s --header "Authorization: Bearer %2$s"',
					$endpoint,
					$token
				);
				$json_snippet = wp_json_encode(
					array(
						'mcpServers' => array(
							'ai-builder' => array(
								'url'     => $endpoint,
								'headers' => array( 'Authorization' => 'Bearer ' . $token ),
							),
						),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				);

				echo '<div class="notice notice-success aibc-token-notice"><p><strong>' . esc_html__( 'MCP connection created. Copy this token now; it will not be shown again.', 'ai-builder-connector' ) . '</strong></p>';
				echo '<p><textarea id="aibc-new-token" class="large-text code" rows="2" readonly>' . esc_textarea( $token ) . '</textarea></p>';
				echo '<p><button type="button" class="button button-primary" data-aibc-copy="aibc-new-token">' . esc_html__( 'Copy token', 'ai-builder-connector' ) . '</button></p>';
				echo '<details class="aibc-connect-help"><summary>' . esc_html__( 'Connect your AI tool (ready-made setup)', 'ai-builder-connector' ) . '</summary>';
				echo '<p>' . esc_html__( 'Claude Code — run this one command:', 'ai-builder-connector' ) . '</p>';
				echo '<p><textarea id="aibc-snippet-cli" class="large-text code" rows="3" readonly>' . esc_textarea( $cli_snippet ) . '</textarea></p>';
				echo '<p><button type="button" class="button" data-aibc-copy="aibc-snippet-cli">' . esc_html__( 'Copy command', 'ai-builder-connector' ) . '</button></p>';
				echo '<p>' . esc_html__( 'Cursor / Claude Desktop / other MCP clients — add this to the MCP config file:', 'ai-builder-connector' ) . '</p>';
				echo '<p><textarea id="aibc-snippet-json" class="large-text code" rows="9" readonly>' . esc_textarea( (string) $json_snippet ) . '</textarea></p>';
				echo '<p><button type="button" class="button" data-aibc-copy="aibc-snippet-json">' . esc_html__( 'Copy config', 'ai-builder-connector' ) . '</button></p>';
				echo '</details>';
				echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-aibc-copy]");if(!b){return;}var t=document.getElementById(b.getAttribute("data-aibc-copy"));if(!t){return;}t.select();try{document.execCommand("copy");}catch(o){}if(navigator.clipboard){navigator.clipboard.writeText(t.value);}var l=b.textContent;b.textContent="' . esc_js( __( 'Copied!', 'ai-builder-connector' ) ) . '";setTimeout(function(){b.textContent=l;},1500);});</script>';
				echo '</div>';
			}
		}

		if ( isset( $_GET['aibc_mcp_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MCP connection revoked.', 'ai-builder-connector' ) . '</p></div>';
		}

		if ( isset( $_GET['aibc_mcp_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MCP access setting updated.', 'ai-builder-connector' ) . '</p></div>';
		}
	}

	/**
	 * Renders Elementor status.
	 *
	 * @param array{installed: bool, active: bool, initialized: bool, version: string} $status Elementor status.
	 */
	private function render_status_panel( array $status ): void {
		?>
		<section class="aibc-panel" aria-labelledby="aibc-elementor-status">
			<h2 id="aibc-elementor-status"><?php echo esc_html__( 'Elementor Status', 'ai-builder-connector' ); ?></h2>
			<table class="widefat striped aibc-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Installed', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( $status['installed'] ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Active', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( $status['active'] ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Version', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( '' !== $status['version'] ? $status['version'] : __( 'Unavailable', 'ai-builder-connector' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Initialized', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( $status['initialized'] ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders scan summary.
	 *
	 * @param array<string, mixed> $summary Scan summary.
	 */
	private function render_scan_summary( array $summary ): void {
		?>
		<section class="aibc-panel" aria-labelledby="aibc-scan-summary">
			<h2 id="aibc-scan-summary"><?php echo esc_html__( 'Scan Summary', 'ai-builder-connector' ); ?></h2>
			<table class="widefat striped aibc-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current widgets', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( (string) $summary['widget_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Sources found', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( (string) $summary['source_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Unknown widgets', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( (string) $summary['unknown_widget_count'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last saved scan', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( $this->format_saved_scan_time() ); ?></td>
					</tr>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders manual rescan form.
	 */
	private function render_rescan_form(): void {
		?>
		<section class="aibc-panel" aria-labelledby="aibc-rescan">
			<h2 id="aibc-rescan"><?php echo esc_html__( 'Manual Rescan', 'ai-builder-connector' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aibc_rescan_widgets' ); ?>
				<input type="hidden" name="action" value="aibc_rescan_widgets">
				<?php submit_button( __( 'Rescan Widgets', 'ai-builder-connector' ), 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Renders MCP connection controls.
	 */
	private function render_mcp_connections(): void {
		$connections = $this->mcp_connection_manager->get_connections();
		$endpoint    = rest_url( 'ai-builder-connector/v1/mcp' );

		?>
		<section class="aibc-panel" aria-labelledby="aibc-mcp-connections">
			<h2 id="aibc-mcp-connections"><?php echo esc_html__( 'MCP Connections', 'ai-builder-connector' ); ?></h2>
			<table class="widefat striped aibc-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Endpoint', 'ai-builder-connector' ); ?></th>
						<td><code><?php echo esc_html( $endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mode', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html__( 'Draft builder', 'ai-builder-connector' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'MCP writes', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html__( 'AIBC-owned drafts only', 'ai-builder-connector' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Publishing', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html__( 'Administrator only. No MCP publish tool exists.', 'ai-builder-connector' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Emergency disable', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html( $this->mcp_connection_manager->is_disabled() ? __( 'On', 'ai-builder-connector' ) : __( 'Off', 'ai-builder-connector' ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aibc-inline-form">
				<?php wp_nonce_field( 'aibc_toggle_mcp' ); ?>
				<input type="hidden" name="action" value="aibc_toggle_mcp">
				<input type="hidden" name="aibc_mcp_disabled" value="<?php echo esc_attr( $this->mcp_connection_manager->is_disabled() ? '0' : '1' ); ?>">
				<?php submit_button( $this->mcp_connection_manager->is_disabled() ? __( 'Enable MCP', 'ai-builder-connector' ) : __( 'Disable MCP', 'ai-builder-connector' ), 'secondary', 'submit', false ); ?>
			</form>

			<h3><?php echo esc_html__( 'Create Draft-builder Connection', 'ai-builder-connector' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aibc-draft-form">
				<?php wp_nonce_field( 'aibc_create_mcp_connection' ); ?>
				<input type="hidden" name="action" value="aibc_create_mcp_connection">
				<label for="aibc-mcp-label"><strong><?php echo esc_html__( 'Connection label', 'ai-builder-connector' ); ?></strong></label>
				<input id="aibc-mcp-label" name="aibc_mcp_label" type="text" class="regular-text" value="">
				<label for="aibc-mcp-expires"><strong><?php echo esc_html__( 'Expires in days', 'ai-builder-connector' ); ?></strong></label>
				<input id="aibc-mcp-expires" name="aibc_mcp_expires" type="number" min="1" max="365" value="30">
				<?php submit_button( __( 'Create MCP Connection', 'ai-builder-connector' ), 'primary', 'submit', false ); ?>
			</form>

			<h3><?php echo esc_html__( 'Existing Connections', 'ai-builder-connector' ); ?></h3>
			<?php if ( empty( $connections ) ) : ?>
				<p><?php echo esc_html__( 'No MCP connections have been created yet.', 'ai-builder-connector' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Label', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Token', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Permissions', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Created', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Expires', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Last used', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Actions', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $connections as $connection ) : ?>
						<?php $public = $this->mcp_connection_manager->public_connection( $connection ); ?>
						<tr>
							<td><?php echo esc_html( (string) $public['label'] ); ?></td>
							<td><code><?php echo esc_html( '...' . (string) $public['token_last4'] ); ?></code></td>
							<td><?php echo esc_html( implode( ', ', (array) $public['permissions'] ) ); ?></td>
							<td><?php echo esc_html( '' !== (string) $public['created_at'] ? (string) $public['created_at'] : __( 'Unknown', 'ai-builder-connector' ) ); ?></td>
							<td><?php echo esc_html( '' !== (string) $public['expires_at'] ? (string) $public['expires_at'] : __( 'Never', 'ai-builder-connector' ) ); ?></td>
							<td><?php echo esc_html( '' !== (string) $public['last_used_at'] ? (string) $public['last_used_at'] : __( 'Never', 'ai-builder-connector' ) ); ?></td>
							<td>
								<?php
								if ( '' !== (string) $public['revoked_at'] ) {
									echo esc_html__( 'Revoked', 'ai-builder-connector' );
								} elseif ( ! empty( $public['expired'] ) ) {
									echo esc_html__( 'Expired', 'ai-builder-connector' );
								} else {
									echo esc_html__( 'Active', 'ai-builder-connector' );
								}
								?>
							</td>
							<td>
								<?php if ( '' === (string) $public['revoked_at'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'aibc_revoke_mcp_connection_' . (string) $public['id'] ); ?>
										<input type="hidden" name="action" value="aibc_revoke_mcp_connection">
										<input type="hidden" name="aibc_connection_id" value="<?php echo esc_attr( (string) $public['id'] ); ?>">
										<?php submit_button( __( 'Revoke', 'ai-builder-connector' ), 'secondary', 'submit', false ); ?>
									</form>
								<?php else : ?>
									<?php echo esc_html__( 'No actions', 'ai-builder-connector' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders the draft sandbox controls and plan preview.
	 *
	 * @param array<int, array<string,mixed>> $widgets Current widgets.
	 */
	private function render_draft_sandbox( array $widgets ): void {
		unset( $widgets );
		?>
		<section class="aibc-panel" aria-labelledby="aibc-draft-sandbox">
			<h2 id="aibc-draft-sandbox"><?php echo esc_html__( 'Draft Sandbox', 'ai-builder-connector' ); ?></h2>
			<table class="widefat striped aibc-status-table">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mode', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html__( 'Draft only', 'ai-builder-connector' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Publishing', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html__( 'Administrator publish action for approved, validation-passed drafts only.', 'ai-builder-connector' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Existing pages', 'ai-builder-connector' ); ?></th>
						<td><?php echo esc_html__( 'Never edited by this phase', 'ai-builder-connector' ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aibc-draft-form">
				<?php wp_nonce_field( 'aibc_create_draft' ); ?>
				<input type="hidden" name="action" value="aibc_create_draft">
				<label for="aibc-brief"><strong><?php echo esc_html__( 'Website brief', 'ai-builder-connector' ); ?></strong></label>
				<textarea id="aibc-brief" name="aibc_brief" rows="5" class="large-text" required></textarea>
				<label for="aibc-template"><strong><?php echo esc_html__( 'Page template', 'ai-builder-connector' ); ?></strong></label>
				<select id="aibc-template" name="aibc_template">
					<?php foreach ( $this->template_registry->list_page_templates() as $template ) : ?>
						<option value="<?php echo esc_attr( (string) $template['slug'] ); ?>"><?php echo esc_html( (string) $template['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Create Review Draft', 'ai-builder-connector' ), 'primary', 'submit', false ); ?>
			</form>
			<p><small><?php echo esc_html__( 'Looking for the dry-run plan preview? It now lives on the Reference tab.', 'ai-builder-connector' ); ?></small></p>
		</section>
		<?php
	}

	/**
	 * Renders the sample dry-run plan preview on the Reference tab.
	 *
	 * @param array<int,array<string,mixed>> $widgets Current resolved widgets.
	 */
	private function render_sample_plan_preview( array $widgets ): void {
		$sample_brief = __( 'Create a service landing page draft with a clear headline, short intro copy, a primary call to action, and a placeholder for one image.', 'ai-builder-connector' );
		$plan         = $this->page_plan_builder->build_plan( $sample_brief, $widgets, $this->template_registry->default_page_template() );

		?>
		<section class="aibc-panel" aria-labelledby="aibc-sample-plan">
			<h2 id="aibc-sample-plan"><?php echo esc_html__( 'Dry-run Plan Preview', 'ai-builder-connector' ); ?></h2>
			<p><?php echo esc_html__( 'A sample plan built from the current permissions, so you can see which widgets a draft would use.', 'ai-builder-connector' ); ?></p>
			<?php $this->render_plan_preview( $plan ); ?>
		</section>
		<?php
	}

	/**
	 * Renders available page and section templates.
	 */
	private function render_template_library(): void {
		$templates = $this->template_registry->list_page_templates();

		?>
		<section class="aibc-panel" aria-labelledby="aibc-template-library">
			<h2 id="aibc-template-library"><?php echo esc_html__( 'Template Library', 'ai-builder-connector' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Template', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Purpose', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Sections', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Widgets', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Responsive', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $templates as $template ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $template['slug'] ); ?></code><br><?php echo esc_html( (string) $template['title'] ); ?></td>
							<td><?php echo esc_html( (string) $template['description'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', $this->template_section_titles( (array) $template['sections'] ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $template['recommended_widgets'] ) ); ?></td>
							<td><?php echo esc_html( (string) $template['responsive_behavior'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders Phase 11 verified Elementor widget definitions.
	 *
	 * @param array<int,array<string,mixed>> $widgets Current resolved widgets.
	 */
	private function render_verified_widget_pack( array $widgets ): void {
		$rows = $this->widget_definitions->runtime_rows( $widgets, $this->permission_manager );

		?>
		<section class="aibc-panel" aria-labelledby="aibc-verified-widget-pack">
			<h2 id="aibc-verified-widget-pack"><?php echo esc_html__( 'Verified Widget Pack', 'ai-builder-connector' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Widget', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Source', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Runtime status', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Detected', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Allowed', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Required settings', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Safe settings', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'AI content fields', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Tested Elementor', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Responsive', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Limitations', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $row['identifier'] ); ?></code><br><?php echo esc_html( (string) $row['title'] ); ?></td>
							<td><?php echo esc_html( (string) $row['source_plugin'] ); ?></td>
							<td><span class="aibc-review-status aibc-review-status-<?php echo esc_attr( (string) $row['runtime_status'] ); ?>"><?php echo esc_html( $this->widget_status_label( (string) $row['runtime_status'] ) ); ?></span></td>
							<td><?php echo esc_html( ! empty( $row['detected'] ) ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
							<td><?php echo esc_html( ! empty( $row['allowed'] ) ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
							<td><?php echo esc_html( empty( $row['required_settings'] ) ? __( 'None', 'ai-builder-connector' ) : implode( ', ', (array) $row['required_settings'] ) ); ?></td>
							<td><?php echo esc_html( (string) count( (array) $row['supported_settings'] ) ); ?></td>
							<td><?php echo esc_html( empty( $row['ai_content_settings'] ) ? __( 'None', 'ai-builder-connector' ) : implode( ', ', array_keys( (array) $row['ai_content_settings'] ) ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $row['tested_elementor_versions'] ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $row['responsive_capabilities'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['known_limitations'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Gets an admin label for a widget runtime status.
	 */
	private function widget_status_label( string $status ): string {
		switch ( sanitize_key( $status ) ) {
			case Widget_Definition_Registry::STATUS_DETECTED:
				return __( 'Detected', 'ai-builder-connector' );
			case Widget_Definition_Registry::STATUS_ALLOWED:
				return __( 'Allowed', 'ai-builder-connector' );
			case Widget_Definition_Registry::STATUS_VERIFIED:
				return __( 'Verified', 'ai-builder-connector' );
			case Widget_Definition_Registry::STATUS_NEEDS_REVIEW:
				return __( 'Needs Review', 'ai-builder-connector' );
			case Widget_Definition_Registry::STATUS_UNSUPPORTED:
			default:
				return __( 'Unsupported', 'ai-builder-connector' );
		}
	}

	/**
	 * Gets section titles from public template rows.
	 *
	 * @param array<int,mixed> $sections Section rows.
	 * @return array<int,string>
	 */
	private function template_section_titles( array $sections ): array {
		$titles = array();

		foreach ( $sections as $section ) {
			if ( is_array( $section ) ) {
				$titles[] = sanitize_text_field( (string) ( $section['title'] ?? '' ) );
			}
		}

		return array_values( array_filter( $titles ) );
	}

	/**
	 * Renders design-system controls.
	 */
	private function render_design_system_settings(): void {
		$config = $this->design_system->get_config();
		$tokens = $this->design_system->get_tokens();
		$option = Design_System::OPTION_DESIGN_SYSTEM;

		?>
		<section class="aibc-panel" aria-labelledby="aibc-design-system">
			<h2 id="aibc-design-system"><?php echo esc_html__( 'Design System', 'ai-builder-connector' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="aibc-design-preset"><?php echo esc_html__( 'Preset', 'ai-builder-connector' ); ?></label></th>
						<td>
							<select id="aibc-design-preset" name="<?php echo esc_attr( $option ); ?>[preset]">
								<?php foreach ( $this->design_system->get_preset_labels() as $preset => $label ) : ?>
									<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( (string) $config['preset'], $preset ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php
					$this->render_color_setting( $option, 'primary_color', __( 'Primary colour', 'ai-builder-connector' ), (string) $config['primary_color'] );
					$this->render_color_setting( $option, 'secondary_color', __( 'Secondary colour', 'ai-builder-connector' ), (string) $config['secondary_color'] );
					$this->render_color_setting( $option, 'accent_color', __( 'Accent colour', 'ai-builder-connector' ), (string) $config['accent_color'] );
					$this->render_color_setting( $option, 'text_color', __( 'Text colour', 'ai-builder-connector' ), (string) $config['text_color'] );
					$this->render_color_setting( $option, 'background_color', __( 'Background colour', 'ai-builder-connector' ), (string) $config['background_color'] );
					?>
					<tr>
						<th scope="row"><label for="aibc-font-family"><?php echo esc_html__( 'Body font', 'ai-builder-connector' ); ?></label></th>
						<td><input id="aibc-font-family" type="text" name="<?php echo esc_attr( $option ); ?>[font_family]" value="<?php echo esc_attr( (string) $config['font_family'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aibc-heading-font-family"><?php echo esc_html__( 'Heading font', 'ai-builder-connector' ); ?></label></th>
						<td><input id="aibc-heading-font-family" type="text" name="<?php echo esc_attr( $option ); ?>[heading_font_family]" value="<?php echo esc_attr( (string) $config['heading_font_family'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aibc-container-width"><?php echo esc_html__( 'Container width', 'ai-builder-connector' ); ?></label></th>
						<td><input id="aibc-container-width" type="number" min="720" max="1600" name="<?php echo esc_attr( $option ); ?>[container_width]" value="<?php echo esc_attr( (string) $config['container_width'] ); ?>"> px</td>
					</tr>
					<tr>
						<th scope="row"><label for="aibc-button-radius"><?php echo esc_html__( 'Button radius', 'ai-builder-connector' ); ?></label></th>
						<td><input id="aibc-button-radius" type="number" min="0" max="32" name="<?php echo esc_attr( $option ); ?>[button_radius]" value="<?php echo esc_attr( (string) $config['button_radius'] ); ?>"> px</td>
					</tr>
				</tbody>
			</table>
			<div class="aibc-token-preview">
				<h3><?php echo esc_html__( 'Current Tokens', 'ai-builder-connector' ); ?></h3>
				<p><?php echo esc_html( sprintf(
					/* translators: 1: spacing scale, 2: section gap. */
					__( 'Spacing scale: %1$s. Section gap: %2$dpx.', 'ai-builder-connector' ),
					implode( ', ', array_map( 'strval', (array) ( $tokens['spacing']['scale'] ?? array() ) ) ),
					(int) ( $tokens['spacing']['section_gap'] ?? 0 )
				) ); ?></p>
				<p><?php echo esc_html( sprintf(
					/* translators: 1: mobile breakpoint, 2: tablet breakpoint. */
					__( 'Responsive defaults: mobile %1$dpx, tablet %2$dpx.', 'ai-builder-connector' ),
					(int) ( $tokens['responsive']['mobile_breakpoint'] ?? 0 ),
					(int) ( $tokens['responsive']['tablet_breakpoint'] ?? 0 )
				) ); ?></p>
				<div class="aibc-swatches" aria-hidden="true">
					<?php foreach ( (array) ( $tokens['colors'] ?? array() ) as $name => $color ) : ?>
						<span title="<?php echo esc_attr( (string) $name ); ?>" style="background: <?php echo esc_attr( (string) $color ); ?>"></span>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders one colour option row.
	 */
	private function render_color_setting( string $option, string $key, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( 'aibc-' . $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input id="<?php echo esc_attr( 'aibc-' . $key ); ?>" type="text" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" pattern="#[a-fA-F0-9]{6}" class="regular-text"></td>
		</tr>
		<?php
	}

	/**
	 * Renders a plan preview table.
	 *
	 * @param array<string,mixed> $plan Page plan.
	 */
	private function render_plan_preview( array $plan ): void {
		$sections = isset( $plan['sections'] ) && is_array( $plan['sections'] ) ? $plan['sections'] : array();
		$blocked  = isset( $plan['blocked_widgets'] ) && is_array( $plan['blocked_widgets'] ) ? $plan['blocked_widgets'] : array();

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Section', 'ai-builder-connector' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Widget', 'ai-builder-connector' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Source', 'ai-builder-connector' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Plan', 'ai-builder-connector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sections as $section ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $section['label'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $section['widget'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( (string) ( $section['source'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $section['description'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $sections ) ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'No allowed draft widgets are available in the current permissions.', 'ai-builder-connector' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $blocked ) ) : ?>
			<h4><?php echo esc_html__( 'Blocked or unavailable plan items', 'ai-builder-connector' ); ?></h4>
			<ul class="aibc-plain-list">
				<?php foreach ( $blocked as $item ) : ?>
					<li><code><?php echo esc_html( (string) ( $item['widget'] ?? '' ) ); ?></code> - <?php echo esc_html( (string) ( $item['reason'] ?? '' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders plugin-created drafts and rollback controls.
	 */
	private function render_created_drafts(): void {
		$drafts = $this->draft_manager->get_created_drafts();

		?>
		<section class="aibc-panel" aria-labelledby="aibc-created-drafts">
			<h2 id="aibc-created-drafts"><?php echo esc_html__( 'Created Drafts', 'ai-builder-connector' ); ?></h2>
			<?php if ( empty( $drafts ) ) : ?>
				<p><?php echo esc_html__( 'No AI Builder Connector draft pages have been created yet.', 'ai-builder-connector' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<form id="aibc-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aibc-bulk-form">
				<?php wp_nonce_field( 'aibc_bulk_drafts' ); ?>
				<input type="hidden" name="action" value="aibc_bulk_drafts">
				<label for="aibc-bulk-action" class="screen-reader-text"><?php echo esc_html__( 'Bulk action', 'ai-builder-connector' ); ?></label>
				<select id="aibc-bulk-action" name="aibc_bulk_action">
					<option value=""><?php echo esc_html__( 'Bulk actions', 'ai-builder-connector' ); ?></option>
					<option value="reject"><?php echo esc_html__( 'Reject selected', 'ai-builder-connector' ); ?></option>
					<option value="delete"><?php echo esc_html__( 'Delete selected (permanent)', 'ai-builder-connector' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'ai-builder-connector' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php echo esc_html__( 'Published pages are skipped; unpublish them first.', 'ai-builder-connector' ); ?></span>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="aibc-bulk-all" aria-label="<?php echo esc_attr__( 'Select all drafts', 'ai-builder-connector' ); ?>" onclick="var v=this.checked;document.querySelectorAll('.aibc-bulk-cb').forEach(function(c){c.checked=v;});"></td>
						<th scope="col"><?php echo esc_html__( 'Page ID', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Title', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Created', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Review state', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Brief', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Template', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Widgets used', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Design preset', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Validation', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Compare Changes', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Actions', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $drafts as $draft ) : ?>
						<?php $review = $this->review_workflow->get_state( $draft->ID ); ?>
						<tr>
							<td class="check-column"><input type="checkbox" class="aibc-bulk-cb" form="aibc-bulk-form" name="aibc_post_ids[]" value="<?php echo esc_attr( (string) $draft->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: post ID. */ __( 'Select draft %d', 'ai-builder-connector' ), $draft->ID ) ); ?>"></td>
							<td><?php echo esc_html( (string) $draft->ID ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $draft->ID, '' ) ); ?>"><?php echo esc_html( get_the_title( $draft ) ); ?></a></td>
							<td><?php echo esc_html( get_the_date( '', $draft ) . ' ' . get_the_time( '', $draft ) ); ?></td>
							<td>
								<span class="aibc-review-status aibc-review-status-<?php echo esc_attr( (string) $review['status'] ); ?>"><?php echo esc_html( (string) $review['label'] ); ?></span>
								<?php if ( '' !== (string) $review['note'] ) : ?>
									<br><small><?php echo esc_html( (string) $review['note'] ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $this->draft_manager->get_brief_excerpt( $draft->ID ) ); ?></td>
							<td><?php echo esc_html( $this->draft_template_label( $draft->ID ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', $this->draft_manager->get_used_widgets( $draft->ID ) ) ); ?></td>
							<td><?php echo esc_html( $this->draft_design_preset( $draft->ID ) ); ?></td>
							<td>
								<?php echo esc_html( ucfirst( $this->draft_manager->get_validation_status( $draft->ID ) ) ); ?>
								<?php if ( $this->draft_manager->get_validation_error_count( $draft->ID ) > 0 ) : ?>
									<?php echo esc_html( sprintf(
										/* translators: %d: validation error count. */
										__( '(%d errors)', 'ai-builder-connector' ),
										$this->draft_manager->get_validation_error_count( $draft->ID )
									) ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $this->draft_compare_summary( $draft->ID ) ); ?></td>
							<td><?php $this->render_draft_action_buttons( $draft ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders recent AI action audit rows.
	 */
	private function render_ai_actions(): void {
		$actions = array_slice( $this->mcp_action_log->get_actions(), 0, 20 );

		?>
		<section class="aibc-panel" aria-labelledby="aibc-ai-actions">
			<h2 id="aibc-ai-actions"><?php echo esc_html__( 'AI Actions', 'ai-builder-connector' ); ?></h2>
			<?php if ( empty( $actions ) ) : ?>
				<p><?php echo esc_html__( 'No MCP actions have been recorded yet.', 'ai-builder-connector' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Action ID', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Tool', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Context', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Created', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $actions as $action ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $action['id'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $action['tool'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $action['status'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $this->compact_context( is_array( $action['context'] ?? null ) ? $action['context'] : array() ) ); ?></td>
							<td><?php echo esc_html( (string) ( $action['created_at'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Gets a draft design preset label.
	 */
	private function draft_design_preset( int $post_id ): string {
		$tokens = $this->draft_manager->get_design_system( $post_id );
		$preset = sanitize_key( (string) ( $tokens['preset'] ?? '' ) );
		$labels = $this->design_system->get_preset_labels();

		return isset( $labels[ $preset ] ) ? $labels[ $preset ] : __( 'Unknown', 'ai-builder-connector' );
	}

	/**
	 * Gets a draft template label.
	 */
	private function draft_template_label( int $post_id ): string {
		$plan = $this->draft_manager->get_plan( $post_id );
		$title = sanitize_text_field( (string) ( $plan['template_title'] ?? '' ) );
		$slug = sanitize_key( (string) ( $plan['template'] ?? '' ) );

		if ( '' !== $title && '' !== $slug ) {
			return $title . ' (' . $slug . ')';
		}

		return '' !== $title ? $title : __( 'Unknown', 'ai-builder-connector' );
	}

	/**
	 * Builds a safe compare summary for review.
	 */
	private function draft_compare_summary( int $post_id ): string {
		$plan = $this->draft_manager->get_plan( $post_id );
		$section_count = is_array( $plan['sections'] ?? null ) ? count( (array) $plan['sections'] ) : 0;

		return sprintf(
			/* translators: 1: planned section count, 2: Elementor data status, 3: blocked warning count, 4: stored revision count. */
			__( '%1$d planned sections; Elementor data: %2$s; blocked warnings: %3$d; revisions: %4$d.', 'ai-builder-connector' ),
			$section_count,
			$this->draft_manager->has_elementor_data( $post_id ) ? __( 'yes', 'ai-builder-connector' ) : __( 'no', 'ai-builder-connector' ),
			$this->draft_manager->get_blocked_widget_count( $post_id ),
			$this->draft_manager->get_revision_count( $post_id )
		);
	}

	/**
	 * Renders review action controls for one draft row.
	 */
	private function render_draft_action_buttons( \WP_Post $draft ): void {
		$post_id = (int) $draft->ID;

		if ( 'publish' === $draft->post_status ) {
			$permalink = get_permalink( $post_id );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				echo '<p><a class="button button-small" href="' . esc_url( $permalink ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Published Page', 'ai-builder-connector' ) . '</a></p>';
			}

			$this->render_post_action_form( 'aibc_unpublish_draft', 'aibc_unpublish_draft_' . $post_id, $post_id, __( 'Unpublish', 'ai-builder-connector' ) );
			echo '<p><small>' . esc_html__( 'Unpublish first to roll back or delete this AI page.', 'ai-builder-connector' ) . '</small></p>';

			return;
		}

		if ( 'draft' === $draft->post_status ) {
			$preview_url = get_preview_post_link( $post_id );

			if ( is_string( $preview_url ) && '' !== $preview_url ) {
				echo '<p><a class="button button-small" href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Preview', 'ai-builder-connector' ) . '</a></p>';
			}

			echo '<p><a class="button button-small" href="' . esc_url( get_edit_post_link( $post_id, '' ) ) . '">' . esc_html__( 'Edit with Elementor', 'ai-builder-connector' ) . '</a></p>';
			$this->render_post_action_form( 'aibc_validate_draft', 'aibc_validate_draft_' . $post_id, $post_id, __( 'Validate Again', 'ai-builder-connector' ) );
			$this->render_review_form( $post_id, 'approve', __( 'Approve', 'ai-builder-connector' ) );
			$this->render_review_form( $post_id, 'reject', __( 'Reject', 'ai-builder-connector' ) );

			if (
				Review_Workflow::STATUS_APPROVED === $this->review_workflow->get_status( $post_id )
				&& 'passed' === $this->draft_manager->get_validation_status( $post_id )
				&& current_user_can( 'publish_pages' )
			) {
				$this->render_post_action_form( 'aibc_publish_draft', 'aibc_publish_draft_' . $post_id, $post_id, __( 'Publish', 'ai-builder-connector' ) );
			}

			$this->render_post_action_form( 'aibc_rollback_draft', 'aibc_rollback_draft_' . $post_id, $post_id, __( 'Roll Back', 'ai-builder-connector' ) );
		}

		$this->render_post_action_form( 'aibc_delete_draft', 'aibc_delete_draft_' . $post_id, $post_id, __( 'Delete AI Draft', 'ai-builder-connector' ) );
	}

	/**
	 * Renders a compact post action form.
	 */
	private function render_post_action_form( string $action, string $nonce_action, int $post_id, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aibc-inline-form">
			<?php wp_nonce_field( $nonce_action ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="aibc_post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<?php submit_button( $label, 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders approve/reject form.
	 */
	private function render_review_form( int $post_id, string $decision, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aibc-inline-form">
			<?php wp_nonce_field( 'aibc_review_draft_' . $post_id ); ?>
			<input type="hidden" name="action" value="aibc_review_draft">
			<input type="hidden" name="aibc_post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
			<input type="hidden" name="aibc_review_decision" value="<?php echo esc_attr( $decision ); ?>">
			<input type="hidden" name="aibc_review_note" value="">
			<?php submit_button( $label, 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Builds compact audit context text.
	 *
	 * @param array<string,mixed> $context Action context.
	 */
	private function compact_context( array $context ): string {
		$parts = array();

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
			}

			if ( is_scalar( $value ) || null === $value ) {
				$parts[] = sanitize_key( (string) $key ) . ': ' . sanitize_text_field( (string) $value );
			}
		}

		return implode( '; ', array_slice( $parts, 0, 5 ) );
	}

	/**
	 * Renders detected addon permissions.
	 *
	 * @param array<string, array<string, mixed>> $sources Source rows.
	 */
	private function render_addon_permissions( array $sources ): void {
		$allowed_addons = $this->permission_manager->get_allowed_addons();
		?>
		<section class="aibc-panel" aria-labelledby="aibc-addon-permissions">
			<h2 id="aibc-addon-permissions"><?php echo esc_html__( 'Addon Permissions', 'ai-builder-connector' ); ?></h2>
			<input type="hidden" name="<?php echo esc_attr( Permission_Manager::OPTION_ALLOWED_ADDONS ); ?>[]" value="">
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Allowed', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Plugin name', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Plugin slug', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Version', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Active', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Widgets', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sources as $source ) : ?>
						<?php
						$slug = (string) $source['slug'];
						if ( Addon_Detector::UNKNOWN_SOURCE === $slug ) {
							continue;
						}
						?>
						<tr>
							<td>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Permission_Manager::OPTION_ALLOWED_ADDONS ); ?>[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $allowed_addons, true ) ); ?>
								>
							</td>
							<td><?php echo esc_html( (string) $source['name'] ); ?></td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo esc_html( '' !== (string) $source['version'] ? (string) $source['version'] : __( 'Unavailable', 'ai-builder-connector' ) ); ?></td>
							<td><?php echo esc_html( ! empty( $source['active'] ) ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
							<td><?php echo esc_html( (string) $source['widget_count'] ); ?></td>
							<td><?php $this->render_source_badges( $source, in_array( $slug, $allowed_addons, true ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders widget permissions grouped by source.
	 *
	 * @param array<string, array{source: array<string, mixed>, widgets: array<int, array<string, mixed>>}> $groups Widget groups.
	 */
	private function render_widget_permissions( array $groups ): void {
		$allowed_widgets = $this->permission_manager->get_allowed_widgets();
		?>
		<section class="aibc-panel" aria-labelledby="aibc-widget-permissions">
			<h2 id="aibc-widget-permissions"><?php echo esc_html__( 'Widget Permissions', 'ai-builder-connector' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Fine-grained control. Allowing an addon above already enables its widgets; open this only to allow or block individual widgets.', 'ai-builder-connector' ); ?></p>
			<input type="hidden" name="<?php echo esc_attr( Permission_Manager::OPTION_ALLOWED_WIDGETS ); ?>[]" value="">
			<details class="aibc-advanced">
				<summary><?php echo esc_html__( 'Show per-widget permissions', 'ai-builder-connector' ); ?></summary>
			<?php foreach ( $groups as $group ) : ?>
				<?php $source = $group['source']; ?>
				<div class="aibc-widget-group">
					<h3><?php echo esc_html( (string) $source['name'] ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Allowed', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Internal name', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Title', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Confidence', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Detection status', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Effective status', 'ai-builder-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $group['widgets'] as $widget ) : ?>
								<?php
								$name = (string) $widget['name'];
								?>
								<tr>
									<td>
										<input
											type="checkbox"
											name="<?php echo esc_attr( Permission_Manager::OPTION_ALLOWED_WIDGETS ); ?>[]"
											value="<?php echo esc_attr( $name ); ?>"
											<?php checked( in_array( $name, $allowed_widgets, true ) || ( Addon_Detector::ELEMENTOR_CORE === (string) $widget['source_slug'] && empty( $allowed_widgets ) ) ); ?>
										>
									</td>
									<td><code><?php echo esc_html( $name ); ?></code></td>
									<td><?php echo esc_html( (string) $widget['title'] ); ?></td>
									<td><?php echo esc_html( (string) $widget['confidence'] ); ?></td>
									<td><?php echo esc_html( (string) $widget['detection_status'] ); ?></td>
									<td><?php echo esc_html( $this->permission_manager->is_widget_allowed( $widget ) ? __( 'Allowed', 'ai-builder-connector' ) : __( 'Blocked', 'ai-builder-connector' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
			</details>
		</section>
		<?php
	}

	/**
	 * Renders unknown-source widgets separately.
	 *
	 * @param array<string, array{source: array<string, mixed>, widgets: array<int, array<string, mixed>>}> $groups Widget groups.
	 */
	private function render_unknown_widgets( array $groups ): void {
		$unknown_widgets = $groups[ Addon_Detector::UNKNOWN_SOURCE ]['widgets'] ?? array();

		?>
		<section class="aibc-panel" aria-labelledby="aibc-unknown-widgets">
			<h2 id="aibc-unknown-widgets"><?php echo esc_html__( 'Unknown-source Widgets', 'ai-builder-connector' ); ?></h2>
			<?php if ( empty( $unknown_widgets ) ) : ?>
				<p><?php echo esc_html__( 'No unknown-source widgets were found in the current scan.', 'ai-builder-connector' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped aibc-widget-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Internal Name', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Title', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Categories', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'PHP Class Name', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Detection Confidence', 'ai-builder-connector' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Detection Status', 'ai-builder-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $unknown_widgets as $widget ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $widget['name'] ); ?></code></td>
							<td><?php echo esc_html( (string) $widget['title'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $widget['categories'] ) ); ?></td>
							<td><code><?php echo esc_html( (string) $widget['class_name'] ); ?></code></td>
							<td><?php echo esc_html( (string) $widget['confidence'] ); ?></td>
							<td><?php echo esc_html( (string) $widget['detection_status'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders grouped widget scan details.
	 *
	 * @param array<string, array{source: array<string, mixed>, widgets: array<int, array<string, mixed>>}> $groups Widget groups.
	 */
	private function render_grouped_widgets( array $groups ): void {
		?>
		<section class="aibc-panel" aria-labelledby="aibc-grouped-widgets">
			<h2 id="aibc-grouped-widgets"><?php echo esc_html__( 'Widgets Grouped by Source', 'ai-builder-connector' ); ?></h2>
			<?php if ( empty( $groups ) ) : ?>
				<p><?php echo esc_html__( 'No registered Elementor widgets are available for this request.', 'ai-builder-connector' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php foreach ( $groups as $group ) : ?>
				<div class="aibc-widget-group">
					<h3><?php echo esc_html( (string) $group['source']['name'] ); ?></h3>
					<table class="widefat striped aibc-widget-table">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Internal Name', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Title', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Categories', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'PHP Class Name', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Source Plugin', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Detection Confidence', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Detection Status', 'ai-builder-connector' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Allowed', 'ai-builder-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $group['widgets'] as $widget ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $widget['name'] ); ?></code></td>
									<td><?php echo esc_html( (string) $widget['title'] ); ?></td>
									<td><?php echo esc_html( implode( ', ', (array) $widget['categories'] ) ); ?></td>
									<td><code><?php echo esc_html( (string) $widget['class_name'] ); ?></code></td>
									<td><?php echo esc_html( (string) $widget['source_name'] ); ?></td>
									<td><?php echo esc_html( (string) $widget['confidence'] ); ?></td>
									<td><?php echo esc_html( (string) $widget['detection_status'] ); ?></td>
									<td><?php echo esc_html( $this->permission_manager->is_widget_allowed( $widget ) ? __( 'Yes', 'ai-builder-connector' ) : __( 'No', 'ai-builder-connector' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Prepares source rows with widget counts.
	 *
	 * @param array<int, array<string, mixed>> $widgets Widget rows.
	 * @return array<string, array<string, mixed>>
	 */
	private function prepare_sources( array $widgets ): array {
		$sources = $this->addon_detector->get_detected_sources();

		foreach ( $widgets as $widget ) {
			$source_slug = sanitize_key( (string) ( $widget['source_slug'] ?? Addon_Detector::UNKNOWN_SOURCE ) );

			if ( ! isset( $sources[ $source_slug ] ) ) {
				$sources[ $source_slug ] = array(
					'slug'         => $source_slug,
					'name'         => sanitize_text_field( (string) ( $widget['source_name'] ?? __( 'Unknown Source', 'ai-builder-connector' ) ) ),
					'plugin_file'  => '',
					'plugin_dir'   => '',
					'version'      => '',
					'active'       => true,
					'detected'     => true,
					'verified'     => false,
					'detection'    => 'detected',
					'widget_count' => 0,
				);
			}

			$sources[ $source_slug ]['widget_count']++;
		}

		uasort(
			$sources,
			static function ( array $first, array $second ): int {
				return strcasecmp( (string) $first['name'], (string) $second['name'] );
			}
		);

		return $sources;
	}

	/**
	 * Groups widgets by source slug.
	 *
	 * @param array<int, array<string, mixed>> $widgets Widget rows.
	 * @return array<string, array{source: array<string, mixed>, widgets: array<int, array<string, mixed>>}>
	 */
	private function group_widgets( array $widgets ): array {
		$sources = $this->prepare_sources( $widgets );
		$groups  = array();

		foreach ( $widgets as $widget ) {
			$source_slug = sanitize_key( (string) ( $widget['source_slug'] ?? Addon_Detector::UNKNOWN_SOURCE ) );

			if ( ! isset( $groups[ $source_slug ] ) ) {
				$groups[ $source_slug ] = array(
					'source'  => $sources[ $source_slug ],
					'widgets' => array(),
				);
			}

			$groups[ $source_slug ]['widgets'][] = $widget;
		}

		return $groups;
	}

	/**
	 * Builds current request summary.
	 *
	 * @param array<int, array<string, mixed>>    $widgets Widget rows.
	 * @param array<string, array<string, mixed>> $sources Source rows.
	 * @return array<string, int>
	 */
	private function build_current_summary( array $widgets, array $sources ): array {
		$unknown = 0;

		foreach ( $widgets as $widget ) {
			if ( Addon_Detector::UNKNOWN_SOURCE === (string) ( $widget['source_slug'] ?? '' ) ) {
				$unknown++;
			}
		}

		return array(
			'widget_count'         => count( $widgets ),
			'source_count'         => count( array_filter( $sources, static fn( array $source ): bool => (int) $source['widget_count'] > 0 ) ),
			'unknown_widget_count' => $unknown,
		);
	}

	/**
	 * Formats saved scan time.
	 */
	private function format_saved_scan_time(): string {
		$summary = $this->permission_manager->get_scan_summary();
		$time    = isset( $summary['scanned_at'] ) ? (string) $summary['scanned_at'] : '';

		if ( '' === $time ) {
			return __( 'Never', 'ai-builder-connector' );
		}

		$timestamp = strtotime( $time );

		if ( false === $timestamp ) {
			return __( 'Unavailable', 'ai-builder-connector' );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Renders source status badges.
	 *
	 * @param array<string, mixed> $source Source row.
	 * @param bool                $allowed Whether source is allowed.
	 */
	private function render_source_badges( array $source, bool $allowed ): void {
		$badges = array( __( 'Detected', 'ai-builder-connector' ) );

		if ( $allowed ) {
			$badges[] = __( 'Allowed', 'ai-builder-connector' );
		}

		if ( ! empty( $source['verified'] ) ) {
			$badges[] = __( 'Verified', 'ai-builder-connector' );
		}

		foreach ( $badges as $badge ) {
			echo '<span class="aibc-badge">' . esc_html( $badge ) . '</span> ';
		}
	}
}
