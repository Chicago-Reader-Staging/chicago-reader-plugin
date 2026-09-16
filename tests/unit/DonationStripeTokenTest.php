<?php
/** Donation payment token ownership and subscription tests. */

use ChicagoReader\Modules\DonationStripe\Gateway;
use ChicagoReader\Modules\DonationStripe\Token_Manager;
use PHPUnit\Framework\TestCase;

/** Exercises token policy without contacting Stripe or mutating a site. */
final class DonationStripeTokenTest extends TestCase {

	protected function setUp(): void {
		\WC_Payment_Tokens::$tokens = array();
		$GLOBALS['cr_test_user_meta'] = array();
		$GLOBALS['cr_test_subscriptions'] = array();
		$GLOBALS['cr_test_options'] = array();
	}

	private function card( $id = 'pm_one' ) {
		return (object) array(
			'id'   => $id,
			'type' => 'card',
			'card' => (object) array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2034,
			),
		);
	}

	public function test_card_is_saved_for_the_donation_gateway_and_account(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$this->assertSame( Gateway::ID, $token->get_gateway_id() );
		$this->assertSame( 17, $token->get_user_id() );
		$this->assertSame( 'pm_one', $token->get_token() );
		$this->assertSame( 'acct_donation_test', $token->get_meta( '_chicago_reader_donation_stripe_account_id' ) );
		$this->assertSame( 'test', $token->get_meta( '_chicago_reader_donation_stripe_mode' ) );
		$this->assertSame( $token, Token_Manager::get_valid( $token->get_id(), 17 ) );
	}

	public function test_wrong_user_cannot_reuse_a_donation_token(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$this->assertFalse( Token_Manager::get_valid( $token->get_id(), 18 ) );
	}

	public function test_wrong_account_cannot_reuse_a_donation_token(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$token->update_meta_data( '_chicago_reader_donation_stripe_account_id', 'acct_other' );
		$this->assertFalse( Token_Manager::get_valid( $token->get_id(), 17 ) );
	}

	public function test_wrong_mode_cannot_reuse_a_donation_token(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$token->update_meta_data( '_chicago_reader_donation_stripe_mode', 'live' );
		$this->assertFalse( Token_Manager::get_valid( $token->get_id(), 17 ) );
	}

	public function test_store_stripe_token_cannot_be_used_as_donation_token(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$token->set_gateway_id( 'stripe' );
		$this->assertFalse( Token_Manager::get_valid( $token->get_id(), 17 ) );
	}

	public function test_repeated_payment_method_upsert_reuses_same_token(): void {
		$first = Token_Manager::upsert( 17, $this->card() );
		$second = Token_Manager::upsert( 17, $this->card() );
		$this->assertSame( $first->get_id(), $second->get_id() );
		$this->assertCount( 1, \WC_Payment_Tokens::$tokens );
	}

	public function test_default_is_isolated_to_donation_gateway(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$this->assertSame( $token->get_id(), get_user_meta( 17, Token_Manager::DEFAULT_TOKEN_META ) );
		$this->assertSame( '', get_user_meta( 17, 'woocommerce_default_payment_token' ) );
		$this->assertFalse( Token_Manager::set_default( 18, $token->get_id() ) );
	}

	public function test_attaching_token_marks_subscription_for_donation_gateway(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$subscription = new class {
			public $gateway = '';
			public $meta = array();
			public $saved = false;
			public function set_payment_method( $value ) { $this->gateway = $value; }
			public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
			public function save() { $this->saved = true; }
		};
		$order = new class {
			public function get_id() { return 55; }
		};
		$GLOBALS['cr_test_subscriptions'][55] = array( $subscription );
		Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
		$this->assertSame( Gateway::ID, $subscription->gateway );
		$this->assertSame( $token->get_id(), $subscription->meta[ Token_Manager::SUBSCRIPTION_TOKEN_META ] );
		$this->assertTrue( $subscription->saved );
	}
}
