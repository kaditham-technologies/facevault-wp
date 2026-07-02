<?php
/**
 * Api_Client error mapping: every FaceVault failure mode lands on a stable
 * WP_Error code, and the quota path raises the admin-notice action.
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use PHPUnit\Framework\TestCase;

final class ApiClientErrorMapTest extends TestCase {

	/**
	 * @var Api_Client
	 */
	private $client;

	protected function setUp(): void {
		FV_Test_State::reset();
		FV_Test_State::$options['facevault_settings'] = array(
			'api_key' => 'fv_live_test',
			'site_id' => 'fvs_pk_abc',
		);
		$this->client = new Api_Client();
	}

	private function queue( $code, $body = array(), $headers = array() ) {
		FV_Test_State::$http_queue[] = array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
			'headers'  => $headers,
		);
	}

	private function action_fired( $tag ) {
		foreach ( FV_Test_State::$actions as $action ) {
			if ( $action[0] === $tag ) {
				return $action;
			}
		}
		return null;
	}

	public function test_successful_mint_returns_payload_and_fires_ok(): void {
		$this->queue(
			201,
			array(
				'session_id'   => 'sess_1',
				'widget_token' => 'tok',
			)
		);

		$result = $this->client->create_widget_session( '42', 'https://shop.example/verify' );

		$this->assertIsArray( $result );
		$this->assertSame( 'sess_1', $result['session_id'] );
		$this->assertNotNull( $this->action_fired( 'facevault_api_ok' ) );

		$request = FV_Test_State::$http_log[0];
		$this->assertSame( 'https://api.facevault.id/api/v1/widget_sessions', $request['url'] );
		$this->assertSame( 'fv_live_test', $request['args']['headers']['X-FaceVault-Api-Key'] );
		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( '42', $body['external_user_id'] );
		$this->assertSame( 'fvs_pk_abc', $body['site_id'] );
	}

	public function test_quota_402_maps_and_fires_notice_action(): void {
		$this->queue( 402, array( 'detail' => 'Free plan limit reached (50 checks/month). Upgrade at https://devdash.facevault.id' ) );

		$result = $this->client->create_widget_session( '42' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'facevault_quota', $result->get_error_code() );
		$this->assertStringContainsString( 'Free plan limit reached', $result->get_error_message() );

		$action = $this->action_fired( 'facevault_api_quota_exceeded' );
		$this->assertNotNull( $action );
		$this->assertStringContainsString( 'Upgrade at', $action[1] );
	}

	public function test_401_maps_to_auth(): void {
		$this->queue( 401, array( 'detail' => 'invalid api key' ) );

		$result = $this->client->create_widget_session( '42' );

		$this->assertSame( 'facevault_auth', $result->get_error_code() );
	}

	public function test_403_maps_to_scope(): void {
		$this->queue( 403, array( 'detail' => 'API key missing required scope: sessions:create' ) );

		$result = $this->client->create_widget_session( '42' );

		$this->assertSame( 'facevault_scope', $result->get_error_code() );
	}

	public function test_429_maps_with_retry_after(): void {
		$this->queue( 429, array( 'error' => 'Rate limit exceeded: 60 per 1 hour' ), array( 'retry-after' => '120' ) );

		$result = $this->client->create_widget_session( '42' );

		$this->assertSame( 'facevault_rate_limited', $result->get_error_code() );
		$this->assertSame( 120, $result->get_error_data()['retry_after'] );
	}

	public function test_5xx_maps_to_bad_response(): void {
		$this->queue( 500, array() );

		$result = $this->client->create_widget_session( '42' );

		$this->assertSame( 'facevault_bad_response', $result->get_error_code() );
	}

	public function test_transport_error_maps_to_unreachable(): void {
		FV_Test_State::$http_queue[] = new WP_Error( 'http_request_failed', 'could not resolve host' );

		$result = $this->client->create_widget_session( '42' );

		$this->assertSame( 'facevault_unreachable', $result->get_error_code() );
	}

	public function test_unconfigured_short_circuits_without_http(): void {
		FV_Test_State::$options['facevault_settings'] = array();

		$result = $this->client->create_widget_session( '42' );

		$this->assertSame( 'facevault_not_configured', $result->get_error_code() );
		$this->assertCount( 0, FV_Test_State::$http_log );
	}

	public function test_get_session_hits_expected_url(): void {
		$this->queue(
			200,
			array(
				'session_id' => 'sess_1',
				'status'     => 'passed',
			)
		);

		$result = $this->client->get_session( 'sess_1' );

		$this->assertSame( 'passed', $result['status'] );
		$this->assertSame( 'https://api.facevault.id/api/v1/sessions/sess_1', FV_Test_State::$http_log[0]['url'] );
	}
}
