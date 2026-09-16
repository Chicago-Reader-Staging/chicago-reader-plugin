<?php
/** Donation routing and currency policy tests. */

use ChicagoReader\Modules\DonationStripe\Gateway;
use ChicagoReader\Modules\DonationStripe\Routing;
use PHPUnit\Framework\TestCase;

/** Tests deterministic policy that does not need a WordPress database. */
final class DonationStripePolicyTest extends TestCase {

	protected function setUp(): void {
		\Newspack\Donations::$donation_ids = array( 101, 102, 103 );
	}

	public function test_only_newspack_donations_route_to_donation_stripe(): void {
		$this->assertSame( Routing::ALL, Routing::classify_product_ids( array( 101, 102 ) ) );
	}

	public function test_normal_products_stay_on_store_gateways(): void {
		$this->assertSame( Routing::NONE, Routing::classify_product_ids( array( 201, 202 ) ) );
	}

	public function test_mixed_cart_is_detected(): void {
		$this->assertSame( Routing::MIXED, Routing::classify_product_ids( array( 101, 201 ) ) );
	}

	public function test_empty_cart_is_not_a_donation_cart(): void {
		$this->assertSame( Routing::NONE, Routing::classify_product_ids( array() ) );
	}

	public function test_usd_uses_minor_units(): void {
		$this->assertSame( 1235, Gateway::to_minor_units( '12.345', 'USD' ) );
	}

	public function test_zero_decimal_currency_is_not_multiplied(): void {
		$this->assertSame( 1235, Gateway::to_minor_units( '1234.6', 'JPY' ) );
	}

	public function test_gateway_uses_semantic_identity_and_customer_label(): void {
		$gateway = new Gateway();
		$this->assertSame( 'chicago_reader_donation_stripe', $gateway->id );
		$this->assertSame( 'Donation Stripe', $gateway->method_title );
		$this->assertSame( 'Credit or debit card', $gateway->title );
	}

	public function test_gateway_advertises_complete_subscription_contract(): void {
		$gateway = new Gateway();
		$expected = array(
			'tokenization',
			'add_payment_method',
			'refunds',
			'subscriptions',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);
		foreach ( $expected as $feature ) {
			$this->assertContains( $feature, $gateway->supports );
		}
	}
}
