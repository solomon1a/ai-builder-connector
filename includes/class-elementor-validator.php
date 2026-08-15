<?php
/**
 * Elementor validation service.
 *
 * @package AIBuilderConnector
 */

namespace AIBC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates page plans and generated Elementor document data.
 */
final class Elementor_Validator {
	/**
	 * Permission manager.
	 *
	 * @var Permission_Manager
	 */
	private Permission_Manager $permission_manager;

	/**
	 * Verified widget definitions.
	 *
	 * @var Widget_Definition_Registry
	 */
	private Widget_Definition_Registry $widget_definitions;

	/**
	 * Validator constructor.
	 */
	public function __construct( Permission_Manager $permission_manager, Widget_Definition_Registry $widget_definitions ) {
		$this->permission_manager = $permission_manager;
		$this->widget_definitions = $widget_definitions;
	}

	/**
	 * Validates the generated page plan before any post is created.
	 *
	 * @param array<string,mixed>              $plan Page plan.
	 * @param array<int,array<string,mixed>> $widgets Current widgets.
	 */
	public function validate_plan( array $plan, array $widgets ): Validation_Result {
		$result     = new Validation_Result();
		$widget_map = $this->index_widgets( $widgets );

		if ( 'ready' !== sanitize_key( (string) ( $plan['status'] ?? '' ) ) ) {
			$result->add_error( 'plan_not_ready', __( 'The page plan is not ready for draft creation.', 'ai-builder-connector' ) );
		}

		$sections = $plan['sections'] ?? null;

		if ( ! is_array( $sections ) || empty( $sections ) ) {
			$result->add_error( 'plan_sections_empty', __( 'The page plan must include at least one section.', 'ai-builder-connector' ) );
			return $result;
		}

		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				$result->add_error( 'plan_section_invalid', __( 'Each page plan section must be an object.', 'ai-builder-connector' ), array( 'section_index' => $index ) );
				continue;
			}

			$widget_name = sanitize_text_field( (string) ( $section['widget'] ?? '' ) );
			$source_slug = isset( $widget_map[ $widget_name ]['source_slug'] ) ? sanitize_key( (string) $widget_map[ $widget_name ]['source_slug'] ) : '';

			foreach ( array( 'label', 'widget', 'source', 'description' ) as $required_key ) {
				if ( '' === sanitize_text_field( (string) ( $section[ $required_key ] ?? '' ) ) ) {
					$result->add_error(
						'plan_section_missing_field',
						sprintf(
							/* translators: %s: missing field name. */
							__( 'A page plan section is missing the required "%s" field.', 'ai-builder-connector' ),
							$required_key
						),
						array( 'section_index' => $index )
					);
				}
			}

			if ( '' === $widget_name || ! isset( $widget_map[ $widget_name ] ) ) {
				$result->add_error( 'plan_widget_unregistered', __( 'The page plan contains a widget that is not registered in Elementor.', 'ai-builder-connector' ), array( 'widget' => $widget_name ) );
				continue;
			}

			if ( Addon_Detector::UNKNOWN_SOURCE === $source_slug ) {
				$result->add_error( 'plan_widget_unknown_source', __( 'The page plan contains a widget from an unknown source.', 'ai-builder-connector' ), array( 'widget' => $widget_name ) );
			}

			if ( ! $this->permission_manager->is_addon_allowed( $source_slug ) ) {
				$result->add_error( 'plan_addon_blocked', __( 'The page plan contains a widget from a blocked addon.', 'ai-builder-connector' ), array( 'widget' => $widget_name ) );
			}

			if ( ! $this->permission_manager->is_widget_allowed( $widget_map[ $widget_name ] ) ) {
				$result->add_error( 'plan_widget_blocked', __( 'The page plan contains a blocked widget.', 'ai-builder-connector' ), array( 'widget' => $widget_name ) );
			}

			if ( null === $this->widget_definitions->get_definition( $widget_name ) ) {
				$result->add_error( 'plan_widget_unsupported', __( 'The page plan uses a widget that is not supported by the verified widget pack.', 'ai-builder-connector' ), array( 'widget' => $widget_name ) );
				continue;
			}

			if ( array_key_exists( 'settings', $section ) ) {
				if ( ! is_array( $section['settings'] ) ) {
					$result->add_error( 'plan_widget_settings_invalid', __( 'Page plan widget settings must be an object when supplied.', 'ai-builder-connector' ), array( 'widget' => $widget_name ) );
				} else {
					$this->validate_settings_keys( $widget_name, $section['settings'], $result );
				}
			}
		}

		return $result;
	}

	/**
	 * Validates generated Elementor document data before and after saving.
	 *
	 * @param array<int,array<string,mixed>> $elementor_data Elementor data.
	 * @param array<int,array<string,mixed>> $widgets Current widgets.
	 */
	public function validate_elementor_data( array $elementor_data, array $widgets ): Validation_Result {
		$result     = new Validation_Result();
		$widget_map = $this->index_widgets( $widgets );
		$ids        = array();

		if ( empty( $elementor_data ) ) {
			$result->add_error( 'elementor_data_empty', __( 'Elementor data must not be empty.', 'ai-builder-connector' ) );
			return $result;
		}

		foreach ( $elementor_data as $index => $node ) {
			if ( ! is_array( $node ) ) {
				$result->add_error( 'elementor_node_invalid', __( 'Each Elementor root element must be an object.', 'ai-builder-connector' ), array( 'node_index' => $index ) );
				continue;
			}

			$this->validate_node( $node, 'root', $widget_map, $ids, $result );
		}

		return $result;
	}

	/**
	 * Verifies that saved draft metadata is complete and valid.
	 *
	 * @param int                         $post_id Post ID.
	 * @param array<int,array<string,mixed>> $widgets Current widgets.
	 */
	public function verify_saved_draft( int $post_id, array $widgets ): Validation_Result {
		$result = new Validation_Result();
		$post   = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'draft' !== $post->post_status ) {
			$result->add_error( 'saved_post_invalid', __( 'The saved item is not a draft page.', 'ai-builder-connector' ) );
		}

		if ( '1' !== get_post_meta( $post_id, Draft_Manager::META_CREATED, true ) ) {
			$result->add_error( 'saved_missing_aibc_owner', __( 'The saved draft is missing AI Builder Connector ownership metadata.', 'ai-builder-connector' ) );
		}

		if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			$result->add_error( 'saved_missing_edit_mode', __( 'The saved draft is missing Elementor builder edit mode metadata.', 'ai-builder-connector' ) );
		}

		if ( 'wp-page' !== get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			$result->add_error( 'saved_missing_template_type', __( 'The saved draft is missing Elementor page template metadata.', 'ai-builder-connector' ) );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			$result->add_error( 'saved_missing_elementor_data', __( 'The saved draft is missing Elementor data.', 'ai-builder-connector' ) );
			return $result;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$data = json_decode( wp_unslash( $raw ), true );
		}

		if ( ! is_array( $data ) ) {
			$result->add_error( 'saved_elementor_data_invalid_json', __( 'The saved Elementor data is not valid JSON.', 'ai-builder-connector' ) );
			return $result;
		}

		$result->merge( $this->validate_elementor_data( $data, $widgets ) );

		return $result;
	}

	/**
	 * Validates one Elementor node recursively.
	 *
	 * @param array<string,mixed>              $node Elementor node.
	 * @param string                          $parent_type Parent element type.
	 * @param array<string,array<string,mixed>> $widget_map Registered widgets.
	 * @param array<string,bool>              $ids Seen IDs.
	 * @param Validation_Result               $result Result collector.
	 */
	private function validate_node( array $node, string $parent_type, array $widget_map, array &$ids, Validation_Result $result ): void {
		$id      = sanitize_text_field( (string) ( $node['id'] ?? '' ) );
		$el_type = sanitize_key( (string) ( $node['elType'] ?? '' ) );

		if ( ! preg_match( '/^[a-f0-9]{7}$/', $id ) ) {
			$result->add_error( 'elementor_id_invalid', __( 'An Elementor element has an invalid ID.', 'ai-builder-connector' ), array( 'id' => $id ) );
		} elseif ( isset( $ids[ $id ] ) ) {
			$result->add_error( 'elementor_id_duplicate', __( 'Elementor data contains a duplicate element ID.', 'ai-builder-connector' ), array( 'id' => $id ) );
		} else {
			$ids[ $id ] = true;
		}

		if ( ! in_array( $el_type, array( 'section', 'column', 'container', 'widget' ), true ) ) {
			$result->add_error( 'elementor_type_invalid', __( 'Elementor data contains an unsupported element type.', 'ai-builder-connector' ), array( 'el_type' => $el_type ) );
			return;
		}

		if ( ! $this->is_allowed_child( $parent_type, $el_type ) ) {
			$result->add_error( 'elementor_hierarchy_invalid', __( 'Elementor data contains an invalid parent-child hierarchy.', 'ai-builder-connector' ), array( 'parent' => $parent_type, 'child' => $el_type ) );
		}

		if ( 'widget' === $el_type ) {
			$this->validate_widget_node( $node, $widget_map, $result );

			if ( ! empty( $node['elements'] ) ) {
				$result->add_error( 'elementor_widget_children_invalid', __( 'Elementor widget nodes must not contain child elements.', 'ai-builder-connector' ), array( 'id' => $id ) );
			}

			return;
		}

		if ( 'container' === $el_type ) {
			if ( ! $this->permission_manager->is_addon_allowed( Addon_Detector::ELEMENTOR_CORE ) ) {
				$result->add_error( 'elementor_container_blocked', __( 'Elementor container layout is blocked because Elementor Core is not allowed.', 'ai-builder-connector' ), array( 'id' => $id ) );
			}

			$settings = $node['settings'] ?? array();

			if ( ! is_array( $settings ) ) {
				$result->add_error( 'elementor_container_settings_invalid', __( 'Elementor container settings must be an object.', 'ai-builder-connector' ), array( 'id' => $id ) );
			} else {
				$this->validate_required_settings( 'container', $settings, $result );
				$this->validate_settings_keys( 'container', $settings, $result );
			}
		}

		$children = $node['elements'] ?? array();

		if ( ! is_array( $children ) ) {
			$result->add_error( 'elementor_children_invalid', __( 'Elementor container elements must include an elements array.', 'ai-builder-connector' ), array( 'id' => $id ) );
			return;
		}

		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$this->validate_node( $child, $el_type, $widget_map, $ids, $result );
			} else {
				$result->add_error( 'elementor_child_invalid', __( 'Elementor child elements must be objects.', 'ai-builder-connector' ), array( 'parent_id' => $id ) );
			}
		}
	}

	/**
	 * Validates one Elementor widget node.
	 *
	 * @param array<string,mixed>                $node Elementor node.
	 * @param array<string,array<string,mixed>> $widget_map Registered widgets.
	 * @param Validation_Result                 $result Result collector.
	 */
	private function validate_widget_node( array $node, array $widget_map, Validation_Result $result ): void {
		$widget_type = sanitize_text_field( (string) ( $node['widgetType'] ?? '' ) );

		if ( '' === $widget_type || ! isset( $widget_map[ $widget_type ] ) ) {
			$result->add_error( 'elementor_widget_unregistered', __( 'Elementor data contains a widget that is not registered.', 'ai-builder-connector' ), array( 'widget' => $widget_type ) );
			return;
		}

		if ( ! $this->permission_manager->is_widget_allowed( $widget_map[ $widget_type ] ) ) {
			$result->add_error( 'elementor_widget_blocked', __( 'Elementor data contains a widget that is not allowed.', 'ai-builder-connector' ), array( 'widget' => $widget_type ) );
		}

		if ( null === $this->widget_definitions->get_definition( $widget_type ) ) {
			$result->add_error( 'elementor_widget_unsupported', __( 'Elementor data contains a widget that is not supported by the verified widget pack.', 'ai-builder-connector' ), array( 'widget' => $widget_type ) );
			return;
		}

		$settings = $node['settings'] ?? array();

		if ( ! is_array( $settings ) ) {
			$result->add_error( 'elementor_widget_settings_invalid', __( 'Elementor widget settings must be an object.', 'ai-builder-connector' ), array( 'widget' => $widget_type ) );
			return;
		}

		$this->validate_required_settings( $widget_type, $settings, $result );
		$this->validate_settings_keys( $widget_type, $settings, $result );
	}

	/**
	 * Validates required widget settings against the verified definition.
	 *
	 * @param string              $widget_type Elementor widget type.
	 * @param array<string,mixed> $settings Widget settings.
	 * @param Validation_Result   $result Result collector.
	 */
	private function validate_required_settings( string $widget_type, array $settings, Validation_Result $result ): void {
		foreach ( $this->widget_definitions->required_setting_keys( $widget_type ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $settings ) ) {
				$result->add_error( 'elementor_setting_required_missing', __( 'Elementor data is missing a required widget setting.', 'ai-builder-connector' ), array( 'widget' => $widget_type, 'setting' => $required_key ) );
			}
		}
	}

	/**
	 * Validates widget setting keys against the verified definition.
	 *
	 * @param string              $widget_type Elementor widget type.
	 * @param array<string,mixed> $settings Widget settings.
	 * @param Validation_Result   $result Result collector.
	 */
	private function validate_settings_keys( string $widget_type, array $settings, Validation_Result $result ): void {
		$allowed = $this->widget_definitions->supported_setting_keys( $widget_type );

		foreach ( array_keys( $settings ) as $setting_key ) {
			$setting_key = sanitize_key( (string) $setting_key );

			if ( ! in_array( $setting_key, $allowed, true ) ) {
				$result->add_error( 'elementor_setting_unsupported', __( 'Elementor data contains an unsupported widget setting.', 'ai-builder-connector' ), array( 'widget' => $widget_type, 'setting' => $setting_key ) );
			}
		}
	}

	/**
	 * Checks allowed Elementor parent-child relationships.
	 */
	private function is_allowed_child( string $parent_type, string $child_type ): bool {
		if ( 'root' === $parent_type ) {
			return in_array( $child_type, array( 'section', 'container' ), true );
		}

		if ( 'section' === $parent_type ) {
			return in_array( $child_type, array( 'column', 'container' ), true );
		}

		if ( in_array( $parent_type, array( 'column', 'container' ), true ) ) {
			// Columns may hold widgets, nested containers, or inner sections
			// (the classic Elementor multi-column-row pattern).
			return in_array( $child_type, array( 'container', 'widget', 'section' ), true );
		}

		return false;
	}

	/**
	 * Indexes registered widgets by internal name.
	 *
	 * @param array<int,array<string,mixed>> $widgets Current widgets.
	 * @return array<string,array<string,mixed>>
	 */
	private function index_widgets( array $widgets ): array {
		$map = array();

		foreach ( $widgets as $widget ) {
			if ( ! is_array( $widget ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $widget['name'] ?? '' ) );

			if ( '' !== $name ) {
				$map[ $name ] = $widget;
			}
		}

		return $map;
	}
}
