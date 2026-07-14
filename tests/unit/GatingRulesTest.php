<?php
/**
 * Gating resolution: product flag, category flag (with ancestors), global
 * toggle, and the shopper verdict.
 *
 * @package FaceVault
 */

use FaceVault\WP\Api_Client;
use FaceVault\WP\Gating_Rules;
use FaceVault\WP\User_Status;
use PHPUnit\Framework\TestCase;

final class GatingRulesTest extends TestCase {

	/**
	 * @var Gating_Rules
	 */
	private $rules;

	/**
	 * @var User_Status
	 */
	private $status;

	protected function setUp(): void {
		FV_Test_State::reset();
		$this->status = new User_Status( new Api_Client() );
		$this->rules  = new Gating_Rules( $this->status );
	}

	public function test_product_flag_gates_cart(): void {
		FV_Test_State::$post_meta[10][ Gating_Rules::META_KEY ] = 'yes';

		$this->assertTrue( $this->rules->cart_requires_verification( array( 10, 11 ) ) );
		$this->assertFalse( $this->rules->cart_requires_verification( array( 11 ) ) );
	}

	public function test_category_flag_gates_cart(): void {
		FV_Test_State::$product_terms[12]                      = array( 5 );
		FV_Test_State::$term_meta[5][ Gating_Rules::META_KEY ] = 'yes';

		$this->assertTrue( $this->rules->cart_requires_verification( array( 12 ) ) );
	}

	public function test_ancestor_category_flag_gates_cart(): void {
		// Product is in child category 7; only parent category 6 is flagged.
		FV_Test_State::$product_terms[13]                      = array( 7 );
		FV_Test_State::$term_ancestors[7]                      = array( 6 );
		FV_Test_State::$term_meta[6][ Gating_Rules::META_KEY ] = 'yes';

		$this->assertTrue( $this->rules->cart_requires_verification( array( 13 ) ) );
	}

	public function test_global_toggle_gates_everything_except_empty_carts(): void {
		FV_Test_State::$options['facevault_settings'] = array( 'gate_all_purchases' => true );

		$this->assertTrue( $this->rules->cart_requires_verification( array( 11 ) ) );
		$this->assertFalse( $this->rules->cart_requires_verification( array() ) );
	}

	public function test_user_may_checkout_verdicts(): void {
		$this->assertSame( Gating_Rules::VERDICT_GUEST, $this->rules->user_may_checkout( 0 ) );

		$this->assertSame( Gating_Rules::VERDICT_BLOCKED, $this->rules->user_may_checkout( 1 ) );

		update_user_meta( 1, User_Status::META_STATUS, User_Status::STATUS_VERIFIED );
		$this->assertSame( Gating_Rules::VERDICT_PASS, $this->rules->user_may_checkout( 1 ) );

		update_user_meta( 1, User_Status::META_STATUS, User_Status::STATUS_REVIEW );
		$this->assertSame( Gating_Rules::VERDICT_REVIEW, $this->rules->user_may_checkout( 1 ) );

		update_user_meta( 1, User_Status::META_STATUS, User_Status::STATUS_REJECTED );
		$this->assertSame( Gating_Rules::VERDICT_BLOCKED, $this->rules->user_may_checkout( 1 ) );

		update_user_meta( 1, User_Status::META_STATUS, User_Status::STATUS_PENDING );
		$this->assertSame( Gating_Rules::VERDICT_BLOCKED, $this->rules->user_may_checkout( 1 ) );
	}
}
