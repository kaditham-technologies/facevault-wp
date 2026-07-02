<?php
/**
 * Unit-test bootstrap: minimal in-memory WordPress shims. The classes under
 * test only touch options, user meta, transients, hooks, and wp_remote_*,
 * so a mocking framework would be heavier than these ~100 lines.
 *
 * @package FaceVault
 */

require __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Shared mutable test state, reset per test.
 */
final class FV_Test_State {
	public static $options    = array();
	public static $user_meta  = array();
	public static $transients = array();
	public static $actions    = array();
	public static $filters    = array();
	public static $http_queue = array();
	public static $http_log   = array();

	public static function reset() {
		self::$options    = array();
		self::$user_meta  = array();
		self::$transients = array();
		self::$actions    = array();
		self::$filters    = array();
		self::$http_queue = array();
		self::$http_log   = array();
	}
}

// ---------------------------------------------------------------- WP_Error.
class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// ------------------------------------------------------------ REST shims.
class WP_REST_Response {
	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

/**
 * Minimal stand-in for WP_REST_Request: raw body + headers, the only
 * surface the webhook controller touches.
 */
class FV_Test_Request {
	private $body;
	private $headers;

	public function __construct( $body, array $headers = array() ) {
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	public function get_body() {
		return $this->body;
	}

	public function get_header( $name ) {
		$name = strtolower( $name );
		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
	}

	public function get_json_params() {
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}

// ----------------------------------------------------------------- Options.
function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, FV_Test_State::$options ) ? FV_Test_State::$options[ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	FV_Test_State::$options[ $name ] = $value;
	return true;
}

function add_option( $name, $value = '' ) {
	if ( array_key_exists( $name, FV_Test_State::$options ) ) {
		return false;
	}
	FV_Test_State::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( FV_Test_State::$options[ $name ] );
	return true;
}

// --------------------------------------------------------------- User meta.
function get_user_meta( $user_id, $key, $single = false ) {
	if ( isset( FV_Test_State::$user_meta[ $user_id ][ $key ] ) ) {
		return FV_Test_State::$user_meta[ $user_id ][ $key ];
	}
	return $single ? '' : array();
}

function update_user_meta( $user_id, $key, $value ) {
	FV_Test_State::$user_meta[ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( FV_Test_State::$user_meta[ $user_id ][ $key ] );
	return true;
}

/**
 * Supports exactly the shape User_Status::find_user_by_session() uses:
 * an OR meta_query of key/value pairs, fields=ID, number=1.
 */
function get_users( $args ) {
	$matches = array();
	foreach ( FV_Test_State::$user_meta as $user_id => $meta ) {
		foreach ( $args['meta_query'] as $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
				continue;
			}
			if ( isset( $meta[ $clause['key'] ] ) && $meta[ $clause['key'] ] === $clause['value'] ) {
				$matches[] = $user_id;
				break;
			}
		}
	}
	return array_slice( $matches, 0, isset( $args['number'] ) ? (int) $args['number'] : 10 );
}

// -------------------------------------------------------------- Transients.
function get_transient( $key ) {
	return array_key_exists( $key, FV_Test_State::$transients ) ? FV_Test_State::$transients[ $key ] : false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	FV_Test_State::$transients[ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( FV_Test_State::$transients[ $key ] );
	return true;
}

// ------------------------------------------------------------------- Hooks.
function do_action( $tag, ...$args ) {
	FV_Test_State::$actions[] = array_merge( array( $tag ), $args );
}

function apply_filters( $tag, $value, ...$args ) {
	if ( isset( FV_Test_State::$filters[ $tag ] ) ) {
		return call_user_func( FV_Test_State::$filters[ $tag ], $value, ...$args );
	}
	return $value;
}

// ---------------------------------------------------------- Misc utilities.
function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function wp_json_encode( $data ) {
	return json_encode( $data ); // phpcs:ignore
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ); // phpcs:ignore
}

function esc_url_raw( $url, $protocols = null ) {
	return (string) $url;
}

// ------------------------------------------------- Users / render helpers.
function is_user_logged_in() {
	return ! empty( FV_Test_State::$options['_test_current_user'] );
}

function get_current_user_id() {
	return (int) ( FV_Test_State::$options['_test_current_user'] ?? 0 );
}

function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default_value;
	}
	return $out;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function get_permalink() {
	return 'https://example.test/verify-page/';
}

function wp_login_url( $redirect = '' ) {
	return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( $redirect );
}

function wp_validate_redirect( $location, $fallback = '' ) {
	$host = parse_url( $location, PHP_URL_HOST ); // phpcs:ignore
	if ( null === $host || 'example.test' === $host ) {
		return $location;
	}
	return $fallback;
}

function wp_date( $format, $timestamp ) {
	return gmdate( $format, $timestamp );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . $path;
}

function wp_create_nonce( $action = -1 ) {
	return 'test-nonce';
}

function add_shortcode( $tag, $callback ) {}
function add_action( $tag, $callback, $priority = 10, $args = 1 ) {}
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {}
function wp_register_script( ...$args ) {}
function wp_register_style( ...$args ) {}

function wp_enqueue_script( $handle ) {
	FV_Test_State::$options['_test_enqueued'][] = $handle;
}

function wp_enqueue_style( $handle ) {
	FV_Test_State::$options['_test_enqueued'][] = $handle;
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	FV_Test_State::$options['_test_localized'] = $l10n;
}

// -------------------------------------------------------------------- HTTP.
/**
 * wp_remote_request shim: shift the next queued response (an array shaped
 * like a WP HTTP response, or a WP_Error) and log the request.
 */
function wp_remote_request( $url, $args = array() ) {
	FV_Test_State::$http_log[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( empty( FV_Test_State::$http_queue ) ) {
		return new WP_Error( 'http_request_failed', 'no queued response' );
	}
	return array_shift( FV_Test_State::$http_queue );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return is_array( $response ) && isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

// --------------------------------------------------- Load classes under test.
require __DIR__ . '/../facevault-identity-verification/includes/class-settings.php';
require __DIR__ . '/../facevault-identity-verification/includes/class-api-client.php';
require __DIR__ . '/../facevault-identity-verification/includes/class-user-status.php';
require __DIR__ . '/../facevault-identity-verification/includes/class-webhook-controller.php';

define( 'FACEVAULT_VERSION', '0.0.0-test' );
define( 'FACEVAULT_PLUGIN_URL', 'https://example.test/wp-content/plugins/facevault-identity-verification/' );
define( 'FACEVAULT_PLUGIN_DIR', __DIR__ . '/../facevault-identity-verification/' );
require __DIR__ . '/../facevault-identity-verification/includes/class-render.php';
