<?php
/**
 * Standalone harness for Widget_Introspector control-mapping logic.
 * Stubs Elementor with a fake widgets manager and fake widgets, then asserts
 * that control types map to the correct sanitizer rule types.
 * Not shipped with the plugin. Run: php tests/introspector-harness.php
 */

namespace Elementor {
	class Plugin { public static $instance; public $widgets_manager; }
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'ELEMENTOR_VERSION', '4.2.0' );

	function sanitize_text_field( $s ) { $s = preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $s ) ); return trim( $s ); }
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
	function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
	function __( $t, $d = null ) { return $t; }
	function did_action( $h ) { return 1; }
	function is_plugin_active( $f ) { return true; }

	class Fake_Widget {
		private $name; private $title; private $controls;
		public function __construct( $name, $title, $controls ) { $this->name = $name; $this->title = $title; $this->controls = $controls; }
		public function get_name() { return $this->name; }
		public function get_title() { return $this->title; }
		public function get_controls() { return $this->controls; }
	}
	class Fake_Widgets_Manager {
		private $types;
		public function __construct( $types ) { $this->types = $types; }
		public function get_widget_types() { return $this->types; }
	}

	require __DIR__ . '/../includes/class-elementor-detector.php';
	require __DIR__ . '/../includes/class-widget-introspector.php';

	$controls = array(
		'eael_title'      => array( 'type' => 'text', 'tab' => 'content', 'default' => 'Default Title' ),
		'eael_body'       => array( 'type' => 'wysiwyg', 'tab' => 'content', 'default' => '<p>Hi</p>' ),
		'eael_desc'       => array( 'type' => 'textarea', 'tab' => 'content', 'default' => 'Desc' ),
		'eael_link'       => array( 'type' => 'url', 'tab' => 'content', 'default' => array( 'url' => 'https://x.com', 'is_external' => 'on' ) ),
		'eael_layout'     => array( 'type' => 'select', 'tab' => 'content', 'default' => 'grid', 'options' => array( 'grid' => 'Grid', 'list' => 'List' ) ),
		'eael_text_align' => array( 'type' => 'select', 'tab' => 'content', 'default' => 'left', 'options' => array( 'left' => 'Left', 'center' => 'Center' ) ),
		'title_typo_font_weight' => array( 'type' => 'select', 'tab' => 'content', 'default' => '400', 'options' => array( '400' => 'Normal', '700' => 'Bold' ) ),
		'eael_count'      => array( 'type' => 'number', 'tab' => 'content', 'default' => 3 ),
		'eael_color'      => array( 'type' => 'color', 'tab' => 'style', 'default' => '#fff' ),
		'eael_typography' => array( 'type' => 'typography', 'tab' => 'style' ),
		'eael_padding'    => array( 'type' => 'dimensions', 'tab' => 'advanced' ),
		'eael_media'      => array( 'type' => 'media', 'tab' => 'content' ),
		'eael_repeater'   => array( 'type' => 'repeater', 'tab' => 'content' ),
		'_margin'         => array( 'type' => 'dimensions', 'tab' => 'advanced' ),
		'eael_style_text' => array( 'type' => 'text', 'tab' => 'style', 'default' => 'nope' ),
		'eael_section'    => array( 'type' => 'section' ),
	);

	$fake_widget  = new Fake_Widget( 'eael-fancy-heading', 'EA Fancy Heading', $controls );
	$empty_widget = new Fake_Widget( 'eael-empty', 'EA Empty', array( 'x' => array( 'type' => 'color', 'tab' => 'style' ) ) );

	\Elementor\Plugin::$instance = new \Elementor\Plugin();
	\Elementor\Plugin::$instance->widgets_manager = new Fake_Widgets_Manager( array(
		'eael-fancy-heading' => $fake_widget,
		'eael-empty'         => $empty_widget,
	) );

	$detector     = new \AIBC\Elementor_Detector();
	$introspector = new \AIBC\Widget_Introspector( $detector );

	$pass = 0; $fail = 0;
	function check( $name, $cond ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name\n"; } }

	$def = $introspector->build_definition( 'eael-fancy-heading' );
	$cs  = is_array( $def ) ? $def['content_settings'] : array();

	check( 'definition built for addon widget', is_array( $def ) );
	check( 'support_status introspected', ( $def['support_status'] ?? '' ) === 'introspected' );
	check( 'title read from widget', ( $def['title'] ?? '' ) === 'EA Fancy Heading' );
	check( 'text control -> text', ( $cs['eael_title']['type'] ?? '' ) === 'text' );
	check( 'wysiwyg control -> rich_text', ( $cs['eael_body']['type'] ?? '' ) === 'rich_text' );
	check( 'textarea control -> text', ( $cs['eael_desc']['type'] ?? '' ) === 'text' );
	check( 'url control -> link', ( $cs['eael_link']['type'] ?? '' ) === 'link' );
	check( 'select control -> choice', ( $cs['eael_layout']['type'] ?? '' ) === 'choice' );
	check( 'select choices from option keys', ( $cs['eael_layout']['choices'] ?? array() ) === array( 'grid', 'list' ) );
	check( 'number control -> text', ( $cs['eael_count']['type'] ?? '' ) === 'text' );

	check( 'color control skipped', ! isset( $cs['eael_color'] ) );
	check( 'typography control skipped', ! isset( $cs['eael_typography'] ) );
	check( 'media control skipped', ! isset( $cs['eael_media'] ) );
	check( 'repeater control skipped', ! isset( $cs['eael_repeater'] ) );
	check( 'underscore control skipped', ! isset( $cs['_margin'] ) );
	check( 'section control skipped', ! isset( $cs['eael_section'] ) );
	check( 'style-tab text control skipped', ! isset( $cs['eael_style_text'] ) );
	check( 'styling select (text_align) skipped', ! isset( $cs['eael_text_align'] ) );
	check( 'styling select (font_weight) skipped', ! isset( $cs['title_typo_font_weight'] ) );
	check( 'content select (layout) kept', isset( $cs['eael_layout'] ) && $cs['eael_layout']['type'] === 'choice' );

	$supported = $def['supported_settings'] ?? array();
	check( 'supported == content keys', $supported === array_keys( $cs ) );
	$defaults = $def['default_settings'] ?? array();
	check( 'scalar default captured', ( $defaults['eael_title'] ?? '' ) === 'Default Title' );
	check( 'url default kept as link object', is_array( $defaults['eael_link'] ?? null ) && ( $defaults['eael_link']['url'] ?? '' ) === 'https://x.com' );
	check( 'required_settings empty', ( $def['required_settings'] ?? null ) === array() );

	check( 'widget with only style controls -> null', null === $introspector->build_definition( 'eael-empty' ) );
	check( 'unregistered widget -> null', null === $introspector->build_definition( 'does-not-exist' ) );

	echo "\n$pass passed, $fail failed\n";
	exit( $fail > 0 ? 1 : 0 );
}
