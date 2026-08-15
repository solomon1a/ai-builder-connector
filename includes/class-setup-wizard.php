<?php
/**
 * Setup wizard.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided onboarding flow that wraps the MCP connection, addon allowlist, and
 * design-system settings into a stepped setup experience.
 */
final class Setup_Wizard {
	public const PAGE_SLUG          = 'aibc-setup';
	public const OPTION_COMPLETED   = 'aibc_wizard_completed';
	public const OPTION_REDIRECT    = 'aibc_wizard_redirect';
	private const MAIN_PAGE_SLUG    = 'ai-builder-connector';
	private const TOTAL_STEPS       = 5;

	private Elementor_Detector $detector;
	private Widget_Scanner $scanner;
	private Widget_Source_Resolver $source_resolver;
	private Permission_Manager $permission_manager;
	private Design_System $design_system;
	private MCP_Connection_Manager $connection_manager;

	/**
	 * Wizard constructor.
	 */
	public function __construct(
		Elementor_Detector $detector,
		Widget_Scanner $scanner,
		Widget_Source_Resolver $source_resolver,
		Permission_Manager $permission_manager,
		Design_System $design_system,
		MCP_Connection_Manager $connection_manager
	) {
		$this->detector           = $detector;
		$this->scanner            = $scanner;
		$this->source_resolver    = $source_resolver;
		$this->permission_manager = $permission_manager;
		$this->design_system      = $design_system;
		$this->connection_manager = $connection_manager;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_post_aibc_wizard_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_aibc_wizard_addons', array( $this, 'handle_addons' ) );
		add_action( 'admin_post_aibc_wizard_design', array( $this, 'handle_design' ) );
		add_action( 'admin_post_aibc_wizard_finish', array( $this, 'handle_finish' ) );
	}

	/**
	 * Registers a hidden submenu page for the wizard under the plugin menu.
	 */
	public function register_page(): void {
		// Empty parent registers a hidden-but-accessible admin page (admin.php?page=aibc-setup).
		add_submenu_page(
			'',
			__( 'AI Builder Setup', 'ai-builder-connector' ),
			__( 'AI Builder Setup', 'ai-builder-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Redirects to the wizard once after activation.
	 */
	public function maybe_redirect(): void {
		if ( '1' !== (string) get_option( self::OPTION_REDIRECT, '' ) ) {
			return;
		}

		delete_option( self::OPTION_REDIRECT );

		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Skip during bulk or network activation.
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( $this->is_completed() ) {
			return;
		}

		wp_safe_redirect( $this->step_url( 1 ) );
		exit;
	}

	/**
	 * Shows a setup notice on the main page until the wizard is completed.
	 */
	public function maybe_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || $this->is_completed() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) ? (string) $screen->id : '';

		if ( false === strpos( $id, self::MAIN_PAGE_SLUG ) && false === strpos( $id, self::PAGE_SLUG ) ) {
			return;
		}

		if ( false !== strpos( $id, self::PAGE_SLUG ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button button-primary" style="margin-left:8px;">%3$s</a></p></div>',
			esc_html__( 'Finish setting up AI Builder Connector.', 'ai-builder-connector' ),
			esc_url( $this->step_url( 1 ) ),
			esc_html__( 'Run setup wizard', 'ai-builder-connector' )
		);
	}

	/**
	 * Renders the current wizard step.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the setup wizard.', 'ai-builder-connector' ) );
		}

		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = max( 1, min( self::TOTAL_STEPS, $step ) );

		echo '<div class="wrap aibc-wizard">';
		$this->render_styles();
		$this->render_header( $step );

		switch ( $step ) {
			case 2:
				$this->render_connect_step();
				break;
			case 3:
				$this->render_addons_step();
				break;
			case 4:
				$this->render_design_step();
				break;
			case 5:
				$this->render_done_step();
				break;
			default:
				$this->render_welcome_step();
				break;
		}

		echo '</div>';
	}

	/**
	 * Handles step 2: create an MCP connection.
	 */
	public function handle_connect(): void {
		$this->guard( 'aibc_wizard_connect' );

		$label   = isset( $_POST['aibc_mcp_label'] ) ? sanitize_text_field( wp_unslash( $_POST['aibc_mcp_label'] ) ) : __( 'AI Connection', 'ai-builder-connector' );
		$expires = isset( $_POST['aibc_mcp_expires'] ) ? absint( wp_unslash( $_POST['aibc_mcp_expires'] ) ) : 0;

		$created = $this->connection_manager->create_connection( $label, $expires );
		$id      = (string) $created['connection']['id'];
		$this->connection_manager->store_one_time_token( $id, $created['token'] );

		wp_safe_redirect( add_query_arg( 'aibc_created', rawurlencode( $id ), $this->step_url( 2 ) ) );
		exit;
	}

	/**
	 * Handles step 3: save allowed addons and sync their widgets.
	 */
	public function handle_addons(): void {
		$this->guard( 'aibc_wizard_addons' );

		$checked = isset( $_POST['aibc_allowed_addons'] ) && is_array( $_POST['aibc_allowed_addons'] )
			? $this->permission_manager->sanitize_slug_list( wp_unslash( $_POST['aibc_allowed_addons'] ) )
			: array();

		$allowed_addons = array_values( array_unique( array_merge( array( Addon_Detector::ELEMENTOR_CORE ), $checked ) ) );

		// Sync allowed widgets: every widget whose source is core or an allowed addon.
		$allowed_widgets = array();

		foreach ( $this->resolved_widgets() as $widget ) {
			$source = sanitize_key( (string) ( $widget['source_slug'] ?? '' ) );
			$name   = sanitize_text_field( (string) ( $widget['name'] ?? '' ) );

			if ( '' !== $name && in_array( $source, $allowed_addons, true ) ) {
				$allowed_widgets[] = $name;
			}
		}

		update_option( Permission_Manager::OPTION_ALLOWED_ADDONS, $allowed_addons, false );
		update_option( Permission_Manager::OPTION_ALLOWED_WIDGETS, array_values( array_unique( $allowed_widgets ) ), false );

		wp_safe_redirect( $this->step_url( 4 ) );
		exit;
	}

	/**
	 * Handles step 4: save design defaults.
	 */
	public function handle_design(): void {
		$this->guard( 'aibc_wizard_design' );

		$raw = array(
			'preset'              => isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : 'professional',
			'primary_color'       => isset( $_POST['primary_color'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_color'] ) ) : '',
			'secondary_color'     => isset( $_POST['secondary_color'] ) ? sanitize_text_field( wp_unslash( $_POST['secondary_color'] ) ) : '',
			'accent_color'        => isset( $_POST['accent_color'] ) ? sanitize_text_field( wp_unslash( $_POST['accent_color'] ) ) : '',
			'font_family'         => isset( $_POST['font_family'] ) ? sanitize_text_field( wp_unslash( $_POST['font_family'] ) ) : '',
			'heading_font_family' => isset( $_POST['heading_font_family'] ) ? sanitize_text_field( wp_unslash( $_POST['heading_font_family'] ) ) : '',
		);

		$current = $this->design_system->get_config();
		$merged  = array_merge( $current, array_filter( $raw, static fn( $v ) => '' !== $v ) );

		update_option( Design_System::OPTION_DESIGN_SYSTEM, $this->design_system->sanitize_config( $merged ), false );

		wp_safe_redirect( $this->step_url( 5 ) );
		exit;
	}

	/**
	 * Handles the final step: mark the wizard completed.
	 */
	public function handle_finish(): void {
		$this->guard( 'aibc_wizard_finish' );

		update_option( self::OPTION_COMPLETED, '1', false );

		wp_safe_redirect( add_query_arg( 'page', self::MAIN_PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders step 1: welcome and Elementor status.
	 */
	private function render_welcome_step(): void {
		$status = $this->detector->get_status();
		$ready  = ! empty( $status['active'] );

		echo '<div class="aibc-card">';
		echo '<p class="aibc-lead">' . esc_html__( 'This wizard connects your site to a coding AI so it can design Elementor pages for you — safely. The AI can only create drafts; you always review and publish.', 'ai-builder-connector' ) . '</p>';

		echo '<h2>' . esc_html__( 'Elementor status', 'ai-builder-connector' ) . '</h2>';
		echo '<ul class="aibc-status">';
		$this->status_row( __( 'Elementor installed', 'ai-builder-connector' ), ! empty( $status['installed'] ) );
		$this->status_row( __( 'Elementor active', 'ai-builder-connector' ), ! empty( $status['active'] ) );
		$this->status_row( __( 'Elementor initialized', 'ai-builder-connector' ), ! empty( $status['initialized'] ) );
		echo '</ul>';

		if ( ! empty( $status['version'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: %s: version */ __( 'Detected Elementor version %s.', 'ai-builder-connector' ), (string) $status['version'] ) ) . '</p>';
		}

		if ( $ready ) {
			echo '<p><a class="button button-primary button-hero" href="' . esc_url( $this->step_url( 2 ) ) . '">' . esc_html__( 'Start setup', 'ai-builder-connector' ) . '</a></p>';
		} else {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Elementor is not active. Install and activate Elementor, then return to this wizard.', 'ai-builder-connector' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * Renders step 2: connect AI.
	 */
	private function render_connect_step(): void {
		$created_id = isset( $_GET['aibc_created'] ) ? sanitize_text_field( wp_unslash( $_GET['aibc_created'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token      = '' !== $created_id ? $this->connection_manager->consume_one_time_token( $created_id ) : '';
		$endpoint   = rest_url( 'ai-builder-connector/v1/mcp' );

		echo '<div class="aibc-card">';

		if ( '' !== $token ) {
			echo '<h2>' . esc_html__( 'Your AI connection is ready', 'ai-builder-connector' ) . '</h2>';
			echo '<p>' . esc_html__( 'Copy the token now. It will not be shown again. Paste the endpoint and token into your AI coding tool as an MCP server with a Bearer token.', 'ai-builder-connector' ) . '</p>';

			echo '<p><label class="aibc-field-label">' . esc_html__( 'MCP endpoint', 'ai-builder-connector' ) . '</label>';
			echo '<input type="text" class="large-text code" readonly value="' . esc_attr( $endpoint ) . '" onclick="this.select()"></p>';

			echo '<p><label class="aibc-field-label">' . esc_html__( 'Bearer token', 'ai-builder-connector' ) . '</label>';
			echo '<input type="text" class="large-text code" readonly value="' . esc_attr( $token ) . '" onclick="this.select()"></p>';

			$this->render_connection_instructions( $endpoint, $token );

			echo '<p><a class="button button-primary" href="' . esc_url( $this->step_url( 3 ) ) . '">' . esc_html__( 'Next: choose addons', 'ai-builder-connector' ) . '</a></p>';
		} else {
			echo '<h2>' . esc_html__( 'Connect your AI', 'ai-builder-connector' ) . '</h2>';
			echo '<p>' . esc_html__( 'Create a secure connection token for your AI coding tool. The token is hashed and shown only once.', 'ai-builder-connector' ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'aibc_wizard_connect' );
			echo '<input type="hidden" name="action" value="aibc_wizard_connect">';
			echo '<p><label class="aibc-field-label">' . esc_html__( 'Connection name', 'ai-builder-connector' ) . '</label>';
			echo '<input type="text" name="aibc_mcp_label" class="regular-text" value="' . esc_attr__( 'My AI Tool', 'ai-builder-connector' ) . '"></p>';
			echo '<p><label class="aibc-field-label">' . esc_html__( 'Expires in days (0 = never)', 'ai-builder-connector' ) . '</label>';
			echo '<input type="number" name="aibc_mcp_expires" class="small-text" min="0" max="3650" value="0"></p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create connection', 'ai-builder-connector' ) . '</button>';
			echo ' <a class="button-link" href="' . esc_url( $this->step_url( 3 ) ) . '">' . esc_html__( 'Skip for now', 'ai-builder-connector' ) . '</a></p>';
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Renders tool-specific connection instructions for the created token.
	 */
	private function render_connection_instructions( string $endpoint, string $token ): void {
		$auth = 'Authorization: Bearer ' . $token;

		$claude_cmd = 'claude mcp add --transport http aibc "' . $endpoint . '" --header "' . $auth . '"';

		$cursor_json = "{\n  \"mcpServers\": {\n    \"aibc\": {\n      \"url\": \"" . $endpoint . "\",\n      \"headers\": { \"Authorization\": \"Bearer " . $token . "\" }\n    }\n  }\n}";

		$codex_toml = "[mcp_servers.aibc]\ncommand = \"npx\"\nargs = [\"-y\", \"mcp-remote\", \"" . $endpoint . "\", \"--header\", \"" . $auth . "\"]";

		$manual_json = "{\n  \"mcpServers\": {\n    \"aibc\": {\n      \"command\": \"npx\",\n      \"args\": [\"-y\", \"mcp-remote\", \"" . $endpoint . "\", \"--header\", \"" . $auth . "\"]\n    }\n  }\n}";

		$tools = array(
			'claude' => array(
				'label' => __( 'Claude Code', 'ai-builder-connector' ),
				'note'  => __( 'Run this in your terminal, inside your project folder. It adds the site as an MCP server.', 'ai-builder-connector' ),
				'code'  => $claude_cmd,
				'rows'  => 3,
			),
			'cursor' => array(
				'label' => __( 'Cursor', 'ai-builder-connector' ),
				'note'  => __( 'Add this to your Cursor MCP config (Settings → MCP, or ~/.cursor/mcp.json).', 'ai-builder-connector' ),
				'code'  => $cursor_json,
				'rows'  => 8,
			),
			'codex'  => array(
				'label' => __( 'Codex CLI', 'ai-builder-connector' ),
				'note'  => __( 'Add this block to ~/.codex/config.toml. It bridges to the site through mcp-remote (needs Node.js).', 'ai-builder-connector' ),
				'code'  => $codex_toml,
				'rows'  => 4,
			),
			'manual' => array(
				'label' => __( 'Other / manual', 'ai-builder-connector' ),
				'note'  => __( 'Works with most MCP clients (Claude Desktop, Windsurf, Cline). Add it to that tool\'s MCP servers config. Needs Node.js for mcp-remote.', 'ai-builder-connector' ),
				'code'  => $manual_json,
				'rows'  => 8,
			),
		);

		echo '<div class="aibc-connect-help">';
		echo '<h3>' . esc_html__( 'Connect your AI tool', 'ai-builder-connector' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Pick the tool you use. Paste the setup below into it once. After that, open your AI tool and ask it to build a page — it will create drafts on this site for you to review.', 'ai-builder-connector' ) . '</p>';

		echo '<p><label class="aibc-field-label">' . esc_html__( 'Your AI tool', 'ai-builder-connector' ) . '</label>';
		echo '<select id="aibc-tool-select">';
		foreach ( $tools as $key => $tool ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $tool['label'] ) . '</option>';
		}
		echo '</select></p>';

		foreach ( $tools as $key => $tool ) {
			echo '<div class="aibc-conn-block" data-tool="' . esc_attr( $key ) . '"' . ( 'claude' === $key ? '' : ' style="display:none"' ) . '>';
			echo '<p class="description">' . esc_html( $tool['note'] ) . '</p>';
			echo '<textarea class="large-text code" readonly rows="' . esc_attr( (string) $tool['rows'] ) . '" onclick="this.select()">' . esc_textarea( $tool['code'] ) . '</textarea>';
			echo '</div>';
		}

		echo '<p class="description aibc-connect-foot">' . esc_html__( 'Tip: MCP client setup changes between tool versions. If a tool does not connect, check its current docs for adding a remote MCP server with a Bearer token, using the endpoint and token above.', 'ai-builder-connector' ) . '</p>';

		echo '<script>(function(){var s=document.getElementById("aibc-tool-select");if(!s)return;s.addEventListener("change",function(){var v=s.value;document.querySelectorAll(".aibc-conn-block").forEach(function(b){b.style.display=(b.getAttribute("data-tool")===v)?"block":"none";});});})();</script>';
		echo '</div>';
	}

	/**
	 * Renders step 3: choose addons.
	 */
	private function render_addons_step(): void {
		$sources        = $this->detected_addon_sources();
		$allowed_addons = $this->permission_manager->get_allowed_addons();

		echo '<div class="aibc-card">';
		echo '<h2>' . esc_html__( 'Which addons can the AI use?', 'ai-builder-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'Allowing an addon lets the AI build with its widgets. Elementor Core is always on. You can change this later.', 'ai-builder-connector' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aibc_wizard_addons' );
		echo '<input type="hidden" name="action" value="aibc_wizard_addons">';
		echo '<ul class="aibc-addons">';

		foreach ( $sources as $source ) {
			$slug     = (string) $source['slug'];
			$is_core  = Addon_Detector::ELEMENTOR_CORE === $slug;
			$checked  = $is_core || in_array( $slug, $allowed_addons, true );
			$disabled = $is_core ? ' disabled' : '';

			echo '<li><label>';
			echo '<input type="checkbox" name="aibc_allowed_addons[]" value="' . esc_attr( $slug ) . '"' . ( $checked ? ' checked' : '' ) . $disabled . '>';
			if ( $is_core ) {
				echo '<input type="hidden" name="aibc_allowed_addons[]" value="' . esc_attr( $slug ) . '">';
			}
			echo ' <strong>' . esc_html( (string) $source['name'] ) . '</strong> ';
			echo '<span class="description">' . esc_html( sprintf( /* translators: %d: widget count */ _n( '%d widget', '%d widgets', (int) $source['count'], 'ai-builder-connector' ), (int) $source['count'] ) ) . '</span>';
			echo '</label></li>';
		}

		echo '</ul>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save and continue', 'ai-builder-connector' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders step 4: design defaults.
	 */
	private function render_design_step(): void {
		$config = $this->design_system->get_config();
		$labels = $this->design_system->get_preset_labels();

		echo '<div class="aibc-card">';
		echo '<h2>' . esc_html__( 'Design defaults', 'ai-builder-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'These colors and fonts are applied to the pages the AI builds. Defaults are fine — change them anytime.', 'ai-builder-connector' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aibc_wizard_design' );
		echo '<input type="hidden" name="action" value="aibc_wizard_design">';

		$brand = $this->design_system->get_site_brand();

		if ( ! empty( $brand ) ) {
			echo '<p class="aibc-brand-row">';
			echo '<button type="button" class="button" id="aibc-use-brand" data-brand="' . esc_attr( (string) wp_json_encode( $brand ) ) . '">' . esc_html__( "Use my site's brand colors & fonts", 'ai-builder-connector' ) . '</button> ';
			echo '<span class="description">' . esc_html__( 'Pulled from your Elementor global colors and fonts.', 'ai-builder-connector' ) . '</span>';
			echo '</p>';
		}

		echo '<p><label class="aibc-field-label">' . esc_html__( 'Style preset', 'ai-builder-connector' ) . '</label><select name="preset">';
		foreach ( $labels as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $config['preset'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		$this->color_field( 'primary_color', __( 'Primary color', 'ai-builder-connector' ), (string) $config['primary_color'] );
		$this->color_field( 'secondary_color', __( 'Secondary color', 'ai-builder-connector' ), (string) $config['secondary_color'] );
		$this->color_field( 'accent_color', __( 'Accent color', 'ai-builder-connector' ), (string) $config['accent_color'] );

		echo '<p><label class="aibc-field-label">' . esc_html__( 'Heading font', 'ai-builder-connector' ) . '</label>';
		echo '<input type="text" name="heading_font_family" class="regular-text" value="' . esc_attr( (string) $config['heading_font_family'] ) . '"></p>';
		echo '<p><label class="aibc-field-label">' . esc_html__( 'Body font', 'ai-builder-connector' ) . '</label>';
		echo '<input type="text" name="font_family" class="regular-text" value="' . esc_attr( (string) $config['font_family'] ) . '"></p>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save and continue', 'ai-builder-connector' ) . '</button></p>';
		echo '</form>';

		if ( ! empty( $brand ) ) {
			echo '<script>(function(){var b=document.getElementById("aibc-use-brand");if(!b)return;b.addEventListener("click",function(){var d;try{d=JSON.parse(b.getAttribute("data-brand"));}catch(e){return;}Object.keys(d).forEach(function(k){var el=document.querySelector(\'[name="\'+k+\'"]\');if(el){el.value=d[k];var code=el.parentNode.querySelector("code");if(code){code.textContent=d[k];}}});});})();</script>';
		}

		echo '</div>';
	}

	/**
	 * Renders the final step.
	 */
	private function render_done_step(): void {
		echo '<div class="aibc-card">';
		echo '<h2>' . esc_html__( 'You are all set', 'ai-builder-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'Now open your AI tool (the one you connected in step 2) and ask it to build a page, for example: "Build a landing page for my service." It will create an Elementor draft on this site. Every page starts as a draft, and you approve and publish it yourself.', 'ai-builder-connector' ) . '</p>';

		echo '<ul class="aibc-status">';
		$this->status_row( __( 'AI can create drafts', 'ai-builder-connector' ), true );
		$this->status_row( __( 'AI cannot publish', 'ai-builder-connector' ), true );
		$this->status_row( __( 'You review every page', 'ai-builder-connector' ), true );
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aibc_wizard_finish' );
		echo '<input type="hidden" name="action" value="aibc_wizard_finish">';
		echo '<p><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Finish and open the dashboard', 'ai-builder-connector' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the step header and progress bar.
	 */
	private function render_header( int $step ): void {
		$titles = array(
			1 => __( 'Welcome', 'ai-builder-connector' ),
			2 => __( 'Connect AI', 'ai-builder-connector' ),
			3 => __( 'Choose addons', 'ai-builder-connector' ),
			4 => __( 'Design', 'ai-builder-connector' ),
			5 => __( 'Done', 'ai-builder-connector' ),
		);

		echo '<h1>' . esc_html__( 'AI Builder Connector setup', 'ai-builder-connector' ) . '</h1>';
		echo '<ol class="aibc-steps">';

		foreach ( $titles as $number => $title ) {
			$class = $number === $step ? 'current' : ( $number < $step ? 'done' : '' );
			echo '<li class="' . esc_attr( $class ) . '"><span class="aibc-step-num">' . esc_html( (string) $number ) . '</span> ' . esc_html( $title ) . '</li>';
		}

		echo '</ol>';
	}

	/**
	 * Renders a status row.
	 */
	private function status_row( string $label, bool $ok ): void {
		echo '<li class="' . ( $ok ? 'ok' : 'no' ) . '">' . ( $ok ? '&#10003;' : '&#10007;' ) . ' ' . esc_html( $label ) . '</li>';
	}

	/**
	 * Renders a labelled color input.
	 */
	private function color_field( string $name, string $label, string $value ): void {
		echo '<p><label class="aibc-field-label">' . esc_html( $label ) . '</label>';
		echo '<input type="color" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"> <code>' . esc_html( $value ) . '</code></p>';
	}

	/**
	 * Detected addon sources with widget counts, core first.
	 *
	 * @return array<int,array{slug:string,name:string,count:int}>
	 */
	private function detected_addon_sources(): array {
		$counts = array();
		$names  = array();

		foreach ( $this->resolved_widgets() as $widget ) {
			$slug = sanitize_key( (string) ( $widget['source_slug'] ?? '' ) );

			if ( '' === $slug || Addon_Detector::UNKNOWN_SOURCE === $slug ) {
				continue;
			}

			$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
			$names[ $slug ]  = sanitize_text_field( (string) ( $widget['source_name'] ?? $slug ) );
		}

		if ( ! isset( $counts[ Addon_Detector::ELEMENTOR_CORE ] ) ) {
			$counts[ Addon_Detector::ELEMENTOR_CORE ] = 0;
			$names[ Addon_Detector::ELEMENTOR_CORE ]  = __( 'Elementor Core', 'ai-builder-connector' );
		}

		$sources = array();

		foreach ( $counts as $slug => $count ) {
			$sources[] = array(
				'slug'  => $slug,
				'name'  => $names[ $slug ] ?? $slug,
				'count' => (int) $count,
			);
		}

		usort(
			$sources,
			static function ( array $a, array $b ): int {
				if ( Addon_Detector::ELEMENTOR_CORE === $a['slug'] ) {
					return -1;
				}
				if ( Addon_Detector::ELEMENTOR_CORE === $b['slug'] ) {
					return 1;
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $sources;
	}

	/**
	 * Resolved current widgets with source metadata.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function resolved_widgets(): array {
		return $this->source_resolver->resolve_widgets( $this->scanner->get_widgets() );
	}

	/**
	 * Checks whether the wizard has been completed.
	 */
	private function is_completed(): bool {
		return '1' === (string) get_option( self::OPTION_COMPLETED, '' );
	}

	/**
	 * Builds a wizard step URL.
	 */
	private function step_url( int $step ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'step' => $step,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Shared capability and nonce guard for handlers.
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the setup wizard.', 'ai-builder-connector' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Prints wizard styles inline.
	 */
	private function render_styles(): void {
		echo '<style>
			.aibc-wizard{max-width:760px}
			.aibc-wizard h1{margin-bottom:16px}
			.aibc-steps{display:flex;gap:8px;list-style:none;margin:0 0 20px;padding:0;flex-wrap:wrap}
			.aibc-steps li{flex:1;min-width:110px;padding:8px 10px;border:1px solid #dcdcde;border-radius:8px;font-size:12px;color:#646970;background:#fff}
			.aibc-steps li.current{border-color:#2271b1;color:#2271b1;font-weight:600}
			.aibc-steps li.done{border-color:#00a32a;color:#00a32a}
			.aibc-step-num{display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:50%;background:#f0f0f1;margin-right:4px;font-weight:700}
			.aibc-steps li.current .aibc-step-num{background:#2271b1;color:#fff}
			.aibc-steps li.done .aibc-step-num{background:#00a32a;color:#fff}
			.aibc-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px 28px}
			.aibc-lead{font-size:15px;color:#1d2327}
			.aibc-field-label{display:block;font-weight:600;margin-bottom:4px}
			.aibc-status{list-style:none;margin:8px 0 16px;padding:0}
			.aibc-status li{padding:4px 0}
			.aibc-status li.ok{color:#00733a}
			.aibc-status li.no{color:#b32d2e}
			.aibc-addons{list-style:none;margin:0 0 16px;padding:0}
			.aibc-addons li{padding:10px 12px;border:1px solid #e6e8ec;border-radius:8px;margin-bottom:8px}
			.aibc-connect-help{margin-top:20px;padding-top:16px;border-top:1px solid #e6e8ec}
			.aibc-conn-block textarea{margin-top:6px;font-size:12px;white-space:pre}
			.aibc-connect-foot{margin-top:12px}
			.aibc-brand-row{margin-bottom:14px}
		</style>';
	}
}
