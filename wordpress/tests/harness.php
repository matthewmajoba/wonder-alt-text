<?php
/**
 * Runs the plugin's generator outside WordPress with minimal stubs.
 *
 *   php wordpress/tests/harness.php prompts            -> JSON of every rendered system prompt
 *   php wordpress/tests/harness.php clean "<text>"     -> cleaned alt-text
 *   php wordpress/tests/harness.php generate <image>   -> full request to WONDER_ALT_API_URL (use cli/tests/mock_api.py)
 *
 * Used by scripts/test.sh to check the PHP and Python implementations agree.
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code, $msg, $data;
	public function __construct( $code = '', $msg = '', $data = null ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
}
class WP_Post { public $ID = 1, $post_type = 'attachment', $post_title = '', $post_excerpt = '', $post_content = '', $post_parent = 0; }

$GLOBALS['stub'] = array( 'options' => array(), 'locale' => 'en_US', 'file' => '', 'mime' => 'image/jpeg', 'title' => '' );

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'http://x/'; }
function plugin_basename( $f ) { return basename( $f ); }
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function __( $s ) { return $s; }
function get_option( $k, $d = false ) { return $GLOBALS['stub']['options'] ?: $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_locale() { return $GLOBALS['stub']['locale']; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
function get_bloginfo() { return 'Test Lodge'; }
function wp_basename( $p ) { return basename( $p ); }
function wp_trim_words( $s ) { return $s; }
function get_the_title() { return ''; }
function get_post( $id ) { $p = new WP_Post(); $p->ID = $id; $p->post_title = $GLOBALS['stub']['title']; return $p; }
function get_post_mime_type() { return $GLOBALS['stub']['mime']; }
function get_attached_file() { return $GLOBALS['stub']['file']; }
function wp_get_image_editor() { return new WP_Error( 'no_editor', 'none' ); }
function wp_tempnam() { return tempnam( sys_get_temp_dir(), 'w' ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header() { return ''; }
function wp_remote_post( $url, $args ) {
	$base = rtrim( getenv( 'WONDER_ALT_API_URL' ) ?: 'https://api.anthropic.com', '/' );
	$url  = str_replace( 'https://api.anthropic.com', $base, $url );
	$h    = array();
	foreach ( $args['headers'] as $k => $v ) { $h[] = "$k: $v"; }
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $args['body'], CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '127.0.0.1,localhost' ) );
	$body = curl_exec( $ch );
	if ( false === $body ) { return new WP_Error( 'http_request_failed', curl_error( $ch ) ); }
	return array( 'code' => curl_getinfo( $ch, CURLINFO_HTTP_CODE ), 'body' => $body );
}

$root = dirname( __DIR__ ) . '/wonder-alt-text/';
define( 'WONDER_ALT_DIR', $root );
require $root . 'includes/class-personas.php';
require $root . 'includes/class-settings.php';
require $root . 'includes/class-api-client.php';
require $root . 'includes/class-generator.php';

$cmd = $argv[1] ?? '';

if ( 'prompts' === $cmd ) {
	$out = array();
	foreach ( array( 'auto', 'plain', 'story' ) as $mode ) {
		foreach ( array_merge( array( 'auto' ), Wonder_Alt_Personas::slugs() ) as $persona ) {
			foreach ( array( 'short', 'medium', 'long' ) as $len ) {
				foreach ( array( 'en_US', 'es_ES' ) as $locale ) {
					$GLOBALS['stub']['options'] = array( 'story_length' => $len );
					$GLOBALS['stub']['locale']  = $locale;
					$out[ "$mode|$persona|$len|$locale" ] = Wonder_Alt_Generator::system_prompt( $mode, $persona );
				}
			}
		}
	}
	$out['__tool'] = Wonder_Alt_Generator::tool();
	echo json_encode( $out );
} elseif ( 'clean' === $cmd ) {
	echo Wonder_Alt_Generator::clean( $argv[2] );
} elseif ( 'generate' === $cmd ) {
	putenv( 'X=1' );
	$GLOBALS['stub']['options'] = array( 'api_key' => 'test-key' );
	$GLOBALS['stub']['file']    = $argv[2];
	$GLOBALS['stub']['title']   = 'Lake at dawn';
	$GLOBALS['stub']['mime']    = $argv[3] ?? 'image/jpeg';
	$r = Wonder_Alt_Generator::generate( 1, array( 'persona' => $argv[4] ?? 'auto' ) );
	echo json_encode( is_wp_error( $r ) ? array( 'error' => $r->get_error_message(), 'code' => $r->get_error_code() ) : $r );
} else {
	fwrite( STDERR, "usage: harness.php prompts|clean|generate\n" );
	exit( 2 );
}
