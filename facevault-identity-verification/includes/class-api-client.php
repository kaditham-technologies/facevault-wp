<?php
/**
 * Thin HTTP client for the FaceVault API. The API key lives server-side
 * only; nothing in this class ever reaches the browser.
 *
 * @package FaceVault
 */

namespace FaceVault\WP;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps wp_remote_* with FaceVault auth and maps HTTP failures onto
 * stable WP_Error codes the callers can branch on.
 */
class Api_Client {

	/**
	 * Mint a single-use widget session token.
	 *
	 * @param string      $external_user_id Stable per-user identifier.
	 * @param string|null $return_url       Same-origin URL to return to.
	 * @return array|WP_Error Response array on 201.
	 */
	public function create_widget_session( $external_user_id, $return_url = null ) {
		$body = array(
			'site_id'          => Settings::get( 'site_id' ),
			'external_user_id' => $external_user_id,
		);
		if ( null !== $return_url && '' !== $return_url ) {
			$body['return_url'] = $return_url;
		}

		$result = $this->request( 'POST', '/widget_sessions', $body, 201 );

		if ( is_wp_error( $result ) ) {
			if ( 'facevault_quota' === $result->get_error_code() ) {
				do_action( 'facevault_api_quota_exceeded', $result->get_error_message() );
			}
		} else {
			do_action( 'facevault_api_ok' );
		}

		return $result;
	}

	/**
	 * Fetch session status (poll fallback).
	 *
	 * @param string $session_id Session id.
	 * @return array|WP_Error
	 */
	public function get_session( $session_id ) {
		return $this->request( 'GET', '/sessions/' . rawurlencode( $session_id ), null, 200 );
	}

	/**
	 * Perform a request and normalize errors.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $path     Path under the API base.
	 * @param array|null $body     JSON body for POST.
	 * @param int        $expected Expected success status code.
	 * @return array|WP_Error Decoded JSON on success.
	 */
	private function request( $method, $path, $body, $expected ) {
		$api_key = Settings::get( 'api_key' );
		if ( '' === $api_key || '' === Settings::get( 'site_id' ) ) {
			return new WP_Error( 'facevault_not_configured', __( 'FaceVault is not configured yet.', 'facevault-identity-verification' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'X-FaceVault-Api-Key' => $api_key,
				'Content-Type'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( Settings::get( 'api_base' ) . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'facevault_unreachable', $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail  = is_array( $decoded ) && isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) ? $decoded['detail'] : '';

		if ( $code === $expected ) {
			return is_array( $decoded ) ? $decoded : array();
		}

		switch ( $code ) {
			case 401:
				return new WP_Error( 'facevault_auth', $detail ? $detail : __( 'API key rejected.', 'facevault-identity-verification' ) );
			case 403:
				return new WP_Error( 'facevault_scope', $detail ? $detail : __( 'API key lacks the required scope or belongs to a different site.', 'facevault-identity-verification' ) );
			case 402:
				return new WP_Error( 'facevault_quota', $detail ? $detail : __( 'FaceVault plan limit reached.', 'facevault-identity-verification' ) );
			case 404:
				return new WP_Error( 'facevault_not_found', __( 'Not found.', 'facevault-identity-verification' ) );
			case 429:
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				return new WP_Error(
					'facevault_rate_limited',
					__( 'FaceVault rate limit reached.', 'facevault-identity-verification' ),
					array( 'retry_after' => $retry_after > 0 ? $retry_after : 600 )
				);
			default:
				return new WP_Error(
					'facevault_bad_response',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Unexpected response from FaceVault (HTTP %d).', 'facevault-identity-verification' ),
						$code
					)
				);
		}
	}
}
