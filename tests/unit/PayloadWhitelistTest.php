<?php
/**
 * Privacy invariant: raw verification scores present in webhook payloads are
 * never persisted anywhere — not in user meta, not in options/logs.
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use FaceVault\WP\User_Status;
use FaceVault\WP\Webhook_Controller;
use PHPUnit\Framework\TestCase;

final class PayloadWhitelistTest extends TestCase {

	/**
	 * @var Webhook_Controller
	 */
	private $controller;

	/**
	 * @var User_Status
	 */
	private $status;

	protected function setUp(): void {
		FV_Test_State::reset();
		FV_Test_State::$options['facevault_settings'] = array( 'webhook_secret' => str_repeat( 'b', 64 ) );
		$this->status     = new User_Status( new Api_Client() );
		$this->controller = new Webhook_Controller( $this->status );
	}

	public function test_scores_never_reach_storage(): void {
		$this->status->record_mint( 7, 'sess_scores' );

		// A realistic payload including every score field the backend
		// currently ships. The plugin must apply the decision and drop
		// everything else.
		$payload = array(
			'event'               => 'verification.completed',
			'signed_at'           => '2026-07-02T10:00:00Z',
			'session_id'          => 'sess_scores',
			'status'              => 'passed',
			'external_user_id'    => '7',
			'trust_decision'      => 'accept',
			'trust_score'         => 87.5,
			'face_match_score'    => 0.31,
			'face_match_passed'   => true,
			'anti_spoofing_score' => 0.91,
			'anti_spoofing_passed' => true,
			'confirmed_data'      => array( 'name' => 'TEST PERSON' ),
		);
		$body    = wp_json_encode( $payload );
		$request = new FV_Test_Request( $body );

		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'applied', $response->get_data()['result'] );
		$this->assertSame( 'verified', $this->status->get_status( 7 ) );

		// Nothing persisted anywhere may contain a score key or value.
		$persisted = wp_json_encode(
			array(
				'user_meta' => FV_Test_State::$user_meta,
				'options'   => FV_Test_State::$options,
			)
		);
		foreach ( array( 'trust_score', 'face_match', 'anti_spoofing', '87.5', '0.31', '0.91', 'confirmed_data', 'TEST PERSON' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $persisted, "Persisted state leaked: {$forbidden}" );
		}
	}

	public function test_unknown_event_type_is_acknowledged_not_processed(): void {
		$request = new FV_Test_Request(
			wp_json_encode(
				array(
					'event'      => 'verification.started',
					'session_id' => 's9',
				)
			)
		);

		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ignored_event', $response->get_data()['result'] );
	}

	public function test_malformed_json_is_a_400(): void {
		$request = new FV_Test_Request( '{not json' );

		$response = $this->controller->handle( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_unknown_session_returns_200_so_facevault_stops_retrying(): void {
		$request = new FV_Test_Request(
			wp_json_encode(
				array(
					'event'          => 'verification.completed',
					'session_id'     => 'sess_unknown',
					'status'         => 'passed',
					'trust_decision' => 'accept',
				)
			)
		);

		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unknown_session', $response->get_data()['result'] );
	}
}
