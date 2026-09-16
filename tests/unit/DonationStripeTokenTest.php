<?php
/** Donation payment token ownership and subscription tests. */

use ChicagoReader\Modules\DonationStripe\Gateway;
use ChicagoReader\Modules\DonationStripe\Token_Manager;
use ChicagoReader\Modules\DonationStripe\Compatibility;
use PHPUnit\Framework\TestCase;

/** Exercises token policy without contacting Stripe or mutating a site. */
final class DonationStripeTokenTest extends TestCase {

	protected function setUp(): void {
		\WC_Payment_Tokens::$tokens = array();
		$GLOBALS['cr_test_user_meta'] = array();
		$GLOBALS['cr_test_subscriptions'] = array();
		$GLOBALS['cr_test_user_subscriptions'] = array();
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
		$this->assertSame( 'acct_1F24k4LAprqx9n8w', $token->get_meta( '_chicago_reader_donation_stripe_account_id' ) );
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
			public $manual = true;
			public $payment_tokens = array();
			public function get_customer_id() { return 17; }
			public function get_payment_method() { return $this->gateway; }
			public function get_items() { return array( new class { public function get_variation_id() { return 0; } public function get_product_id() { return 123; } } ); }
			public function get_payment_tokens() { return $this->payment_tokens; }
			public function add_payment_token( $token ) { $this->payment_tokens[] = $token; return $token->get_id(); }
			public function set_payment_method( $value ) { $this->gateway = $value; }
			public function set_requires_manual_renewal( $value ) { $this->manual = $value; }
			public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
			public function save() { $this->saved = true; }
		};
		$order = new class {
			public function get_id() { return 55; }
			public function get_customer_id() { return 17; }
		};
		\Newspack\Donations::$donation_ids = array( 123 );
		$GLOBALS['cr_test_subscriptions'][55] = array( $subscription );
		$this->assertSame( 1, Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() ) );
		$this->assertSame( Gateway::ID, $subscription->gateway );
		$this->assertSame( $token->get_id(), $subscription->meta[ Token_Manager::SUBSCRIPTION_TOKEN_META ] );
		$this->assertTrue( $subscription->saved );
		$this->assertTrue( $subscription->manual, 'Token attachment must not override WooCommerce Subscriptions staging mode.' );
		$this->assertSame( array( $token ), $subscription->payment_tokens );
	}

	public function test_token_attachment_does_not_migrate_an_existing_official_stripe_subscription(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		\Newspack\Donations::$donation_ids = array( 123 );
		$subscription = new class {
			public $gateway = 'stripe';
			public $saved = false;
			public function get_customer_id() { return 17; }
			public function get_payment_method() { return $this->gateway; }
			public function get_items() { return array( new class { public function get_variation_id() { return 0; } public function get_product_id() { return 123; } } ); }
			public function set_payment_method( $value ) { $this->gateway = $value; }
			public function save() { $this->saved = true; }
		};
		$order = new class {
			public function get_id() { return 56; }
			public function get_customer_id() { return 17; }
		};
		$GLOBALS['cr_test_subscriptions'][56] = array( $subscription );
		$this->expectException( \UnexpectedValueException::class );
		try {
			Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
		} finally {
			$this->assertSame( 'stripe', $subscription->gateway );
			$this->assertFalse( $subscription->saved );
		}
	}

	public function test_update_all_does_not_migrate_official_stripe_subscriptions(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		\Newspack\Donations::$donation_ids = array( 123 );
		$make_subscription = static function ( $gateway ) {
			return new class( $gateway ) {
				public $gateway;
				public $meta = array();
				public $saved = false;
				public $payment_tokens = array();
				public function __construct( $gateway ) { $this->gateway = $gateway; }
				public function has_status( $statuses ) { return in_array( 'active', $statuses, true ); }
				public function get_payment_method() { return $this->gateway; }
				public function get_customer_id() { return 17; }
				public function get_items() { return array( new class { public function get_variation_id() { return 0; } public function get_product_id() { return 123; } } ); }
				public function get_payment_tokens() { return $this->payment_tokens; }
				public function add_payment_token( $value ) { $this->payment_tokens[] = $value; return $value->get_id(); }
				public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
				public function save() { $this->saved = true; }
			};
		};
		$official = $make_subscription( 'stripe' );
		$donation = $make_subscription( Gateway::ID );
		$GLOBALS['cr_test_user_subscriptions'][17] = array( $official, $donation );
		Token_Manager::update_active_donation_subscriptions( 17, $token->get_id() );
		$this->assertSame( 'stripe', $official->gateway );
		$this->assertSame( array(), $official->meta );
		$this->assertFalse( $official->saved );
		$this->assertSame( Gateway::ID, $donation->gateway );
		$this->assertSame( $token->get_id(), $donation->meta[ Token_Manager::SUBSCRIPTION_TOKEN_META ] );
		$this->assertTrue( $donation->saved );
	}

	public function test_unverified_modal_does_not_advertise_gateway(): void {
		$this->assertSame( array( 'stripe' ), Compatibility::modal_gateway( array( 'stripe' ) ) );
	}

	public function test_invalid_token_cannot_be_attached_to_subscription(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$order = new class {
			public function get_customer_id() { return 18; }
		};
		$this->expectException( \UnexpectedValueException::class );
		Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
	}

	public function test_failed_native_token_association_does_not_enable_automatic_renewal(): void {
		$token = Token_Manager::upsert( 17, $this->card() );
		$subscription = new class {
			public $manual = true;
			public function get_customer_id() { return 17; }
			public function get_payment_tokens() { return array(); }
			public function add_payment_token( $token ) { return false; }
		};
		try {
			Token_Manager::associate_token( $subscription, $token->get_id() );
			$this->fail( 'Expected native token association to fail.' );
		} catch ( \RuntimeException $error ) {
			$this->assertTrue( $subscription->manual );
		}
	}
}
