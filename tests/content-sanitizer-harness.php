<?php
/**
 * Standalone harness for Widget_Content_Sanitizer logic.
 * Stubs the WordPress functions used, then runs adversarial inputs.
 * Not shipped with the plugin. Run: php tests/content-sanitizer-harness.php
 */

define( 'ABSPATH', __DIR__ );

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = wp_strip_all_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ) {
	return trim( wp_strip_all_tags( (string) $str ) );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_strip_all_tags( $string ) {
	$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
	return strip_tags( $string );
}
function wp_kses( $string, $allowed ) {
	// Real wp_kses strips disallowed TAGS but keeps their inner text — do the same.
	$tags = '<' . implode( '><', array_keys( $allowed ) ) . '>';
	$string = strip_tags( (string) $string, $tags );
	// crude attribute filter: drop on* and javascript: attributes.
	$string = preg_replace( '/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $string );
	$string = preg_replace( '/href\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')/i', 'href="#"', $string );
	return $string;
}
function wpautop( $pee ) {
	return '<p>' . trim( (string) $pee ) . "</p>\n";
}
function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	$parsed = parse_url( $url );
	if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), (array) $protocols, true ) ) {
		return '';
	}
	return $url;
}
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

require __DIR__ . '/../includes/class-elementor-detector.php';
require __DIR__ . '/../includes/class-addon-detector.php';
require __DIR__ . '/../includes/class-widget-definition-registry.php';
require __DIR__ . '/../includes/class-widget-content-sanitizer.php';

$registry  = new AIBC\Widget_Definition_Registry();
$sanitizer = new AIBC\Widget_Content_Sanitizer( $registry );

$pass = 0;
$fail = 0;

function check( string $name, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "PASS  $name\n";
	} else {
		$fail++;
		echo "FAIL  $name\n";
	}
}

// 1. Clean heading content is accepted.
$r = $sanitizer->sanitize_content( 'heading', array( 'title' => 'Fast WordPress Care Plans', 'header_size' => 'h1' ) );
check( 'heading: clean title accepted', ( $r['settings']['title'] ?? '' ) === 'Fast WordPress Care Plans' );
check( 'heading: h1 accepted', ( $r['settings']['header_size'] ?? '' ) === 'h1' );
check( 'heading: nothing rejected', empty( $r['rejected'] ) );

// 2. Script tag in title is stripped.
$r = $sanitizer->sanitize_content( 'heading', array( 'title' => 'Hello <script>alert(1)</script>World' ) );
check( 'heading: script stripped from title', false === strpos( (string) ( $r['settings']['title'] ?? '' ), 'script' ) );

// 3. Invalid heading level rejected.
$r = $sanitizer->sanitize_content( 'heading', array( 'header_size' => 'h7' ) );
check( 'heading: h7 rejected', ! isset( $r['settings']['header_size'] ) && 1 === count( $r['rejected'] ) );

// 4. Unknown content key rejected.
$r = $sanitizer->sanitize_content( 'heading', array( 'title_color' => '#ff0000' ) );
check( 'heading: styling key title_color rejected as content', ! isset( $r['settings']['title_color'] ) && 1 === count( $r['rejected'] ) );

// 5. Rich text keeps allowed tags, strips script.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => '<p>Good <strong>copy</strong></p><script>alert(1)</script>' ) );
$editor = (string) ( $r['settings']['editor'] ?? '' );
check( 'text-editor: strong kept', false !== strpos( $editor, '<strong>' ) );
check( 'text-editor: script removed', false === strpos( $editor, '<script' ) );

// 6. Plain text without <p> gets wrapped.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => 'Simple paragraph copy.' ) );
check( 'text-editor: plain text wrapped in p', false !== strpos( (string) ( $r['settings']['editor'] ?? '' ), '<p>' ) );

// 7. Button link with javascript: URL rejected.
$r = $sanitizer->sanitize_content( 'button', array( 'text' => 'Click', 'link' => array( 'url' => 'javascript:alert(1)' ) ) );
check( 'button: javascript link rejected', ! isset( $r['settings']['link'] ) && 1 === count( $r['rejected'] ) );
check( 'button: text still accepted', ( $r['settings']['text'] ?? '' ) === 'Click' );

// 8. Button https link accepted; string form allowed.
$r = $sanitizer->sanitize_content( 'button', array( 'link' => 'https://fixmywp.com/contact' ) );
check( 'button: https string link accepted', ( $r['settings']['link']['url'] ?? '' ) === 'https://fixmywp.com/contact' );

// 9. Relative and anchor links accepted, protocol-relative rejected.
$r = $sanitizer->sanitize_content( 'button', array( 'link' => array( 'url' => '/contact' ) ) );
check( 'button: relative link accepted', ( $r['settings']['link']['url'] ?? '' ) === '/contact' );
$r = $sanitizer->sanitize_content( 'button', array( 'link' => array( 'url' => '//evil.example.com' ) ) );
check( 'button: protocol-relative link rejected', ! isset( $r['settings']['link'] ) );

// 10. Video choice enum enforced.
$r = $sanitizer->sanitize_content( 'video', array( 'video_type' => 'dailymotion' ) );
check( 'video: unknown video_type rejected', ! isset( $r['settings']['video_type'] ) );
$r = $sanitizer->sanitize_content( 'video', array( 'video_type' => 'youtube', 'youtube_url' => 'https://www.youtube.com/watch?v=abc123' ) );
check( 'video: youtube accepted', ( $r['settings']['video_type'] ?? '' ) === 'youtube' && '' !== ( $r['settings']['youtube_url'] ?? '' ) );

// 11. Widgets with no content rules reject everything.
$r = $sanitizer->sanitize_content( 'spacer', array( 'space' => array( 'unit' => 'px', 'size' => 9999 ) ) );
check( 'spacer: content not authorable', empty( $r['settings'] ) && 1 === count( $r['rejected'] ) );

// 12. Long text capped at 300 chars.
$r = $sanitizer->sanitize_content( 'heading', array( 'title' => str_repeat( 'a', 500 ) ) );
check( 'heading: long title capped', mb_strlen( (string) ( $r['settings']['title'] ?? '' ) ) === 300 );

// 13. Non-scalar where scalar expected rejected.
$r = $sanitizer->sanitize_content( 'heading', array( 'title' => array( 'nested' => 'array' ) ) );
check( 'heading: array title rejected', ! isset( $r['settings']['title'] ) );

// 14. icon-box text fields accepted.
$r = $sanitizer->sanitize_content( 'icon-box', array( 'title_text' => 'Speed', 'description_text' => 'We make it fast.' ) );
check( 'icon-box: both text fields accepted', 2 === count( $r['settings'] ) );

// 15. Registry exposes ai_content_settings publicly.
$def = $registry->public_definition( 'button' );
check( 'registry: button exposes ai content settings', isset( $def['ai_content_settings']['text']['type'] ) && 'text' === $def['ai_content_settings']['text']['type'] );
$def = $registry->public_definition( 'divider' );
check( 'registry: divider has no ai content settings', empty( $def['ai_content_settings'] ) );

// 16. Phase 14: script blocks removed INCLUDING inner text from rich text.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => '<p>Before.</p><script>alert(1)</script><p>After.</p>' ) );
check( 'rich_text: script inner text removed', false === strpos( (string) ( $r['settings']['editor'] ?? '' ), 'alert(1)' ) && false !== strpos( (string) ( $r['settings']['editor'] ?? '' ), 'After.' ) );

// 17. Phase 14: style blocks removed including inner text; mixed case and attributes.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => '<p>Keep.</p><STYLE type="text/css">body{display:none}</STYLE>' ) );
check( 'rich_text: style inner text removed', false === strpos( (string) ( $r['settings']['editor'] ?? '' ), 'display:none' ) );

// 18. Phase 14: multiple script blocks all removed.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => '<script>one()</script><p>Mid.</p><script src="x">two()</script>' ) );
$v = (string) ( $r['settings']['editor'] ?? '' );
check( 'rich_text: multiple scripts removed', false === strpos( $v, 'one()' ) && false === strpos( $v, 'two()' ) && false !== strpos( $v, 'Mid.' ) );

// 19. Phase 14: unclosed script block dropped to end of value.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => '<p>Safe.</p><script>evil(' ) );
$v = (string) ( $r['settings']['editor'] ?? '' );
check( 'rich_text: unclosed script dropped', false === strpos( $v, 'evil(' ) && false !== strpos( $v, 'Safe.' ) );

// 20. Phase 14: plain text type also drops script inner text.
$r = $sanitizer->sanitize_content( 'heading', array( 'title' => 'Real Title <script>alert(2)</script>' ) );
check( 'text: script inner text removed from heading', false === strpos( (string) ( $r['settings']['title'] ?? '' ), 'alert(2)' ) );

// 21. Phase 14: content that is only a script block rejects to null.
$r = $sanitizer->sanitize_content( 'text-editor', array( 'editor' => '<script>alert(3)</script>' ) );
check( 'rich_text: script-only content rejected', ! isset( $r['settings']['editor'] ) && 1 === count( $r['rejected'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
