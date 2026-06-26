<?php
/**
 * Bootstrap dos testes de regressão (requisito 8).
 *
 * Para manter os testes leves e executáveis em CI sem subir uma instância WP
 * completa, definimos stubs mínimos das funções do WordPress usadas pelas
 * classes sob teste. Os fluxos críticos testados são reais (conversão de
 * imagem com GD, formato do log JSON, escopo de detecção).
 *
 * @package WebPProductCards
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPC_VERSION', '2.0.0-test' );
define( 'WPC_SLUG', 'webp-product-cards' );
define( 'WPC_REST_NS', 'webp-product-cards/v1' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__wpc_options']    = array();
$GLOBALS['__wpc_transients'] = array();
$GLOBALS['__wpc_meta']       = array();

// --- Stubs do WordPress -----------------------------------------------------
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__wpc_options'] ) ? $GLOBALS['__wpc_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) {
	$GLOBALS['__wpc_options'][ $k ] = $v; return true; }
function add_option( $k, $v = '' ) {
	if ( ! isset( $GLOBALS['__wpc_options'][ $k ] ) ) {
		$GLOBALS['__wpc_options'][ $k ] = $v; }
	return true; }
function delete_option( $k ) {
	unset( $GLOBALS['__wpc_options'][ $k ] ); return true; }
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__wpc_transients'] ) ? $GLOBALS['__wpc_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) {
	$GLOBALS['__wpc_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) {
	unset( $GLOBALS['__wpc_transients'][ $k ] ); return true; }
function update_post_meta( $id, $k, $v ) {
	$GLOBALS['__wpc_meta'][ $id ][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) {
	return isset( $GLOBALS['__wpc_meta'][ $id ][ $k ] ) ? $GLOBALS['__wpc_meta'][ $id ][ $k ] : ''; }
function trailingslashit( $s ) {
	return rtrim( $s, "/\\" ) . '/'; }
function wp_mkdir_p( $d ) {
	return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_is_writable( $d ) {
	return is_writable( $d ); }
function wp_get_upload_dir() {
	$b = sys_get_temp_dir() . '/wpc-test-uploads';
	if ( ! is_dir( $b ) ) {
		mkdir( $b, 0777, true ); }
	return array( 'basedir' => $b, 'baseurl' => 'http://example.test/uploads', 'error' => false ); }
function wp_json_encode( $d, $o = 0, $depth = 512 ) {
	return json_encode( $d, $o, $depth ); }
function do_action() {}
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) {
	return $v; }
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max ? $max : PHP_INT_MAX ); }
function sanitize_text_field( $s ) {
	return is_string( $s ) ? trim( $s ) : $s; }
function wp_unslash( $s ) {
	return $s; }
function is_email( $e ) {
	return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_http_validate_url( $u ) {
	return (bool) filter_var( $u, FILTER_VALIDATE_URL ); }
function home_url() {
	return 'http://example.test'; }
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( $u, $c ); }
function wp_parse_args( $a, $d ) {
	return array_merge( $d, (array) $a ); }
function wp_mail() {
	return true; }
function wp_remote_post() {
	return array(); }
function is_wp_error( $t ) {
	return $t instanceof WP_Error; }
function class_exists_wc() {
	return false; }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() {
			return $this->message; }
	}
}

// --- Autoloader do plugin ---------------------------------------------------
spl_autoload_register(
	function ( $class ) {
		$prefix = 'WebPProductCards\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$rel  = substr( $class, strlen( $prefix ) );
		$path = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', $rel ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);
