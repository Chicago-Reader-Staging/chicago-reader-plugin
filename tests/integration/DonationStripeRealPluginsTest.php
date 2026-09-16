<?php
/** Assertions against real plugin classes and the local site's donation products. */

use ChicagoReader\Modules\DonationStripe\Gateway;
use PHPUnit\Framework\TestCase;

final class DonationStripeRealPluginsTest extends TestCase {
	public function test_newspack_generated_products_are_classified_by_real_code(): void {
		$product_ids = \Newspack\Donations::get_donation_product_child_products_ids();
		$this->assertNotEmpty( array_filter( $product_ids ), 'BLOCKED: configure Newspack donation products in the local installation.' );
		foreach ( array_filter( $product_ids ) as $product_id ) {
			$this->assertTrue( \Newspack\Donations::is_donation_product( $product_id ), 'A generated product must be recognized as a donation.' );
		}
	}

	public function test_gateway_is_registered_alongside_official_stripe(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( Gateway::ID, $gateways );
		$this->assertArrayHasKey( 'stripe', $gateways, 'BLOCKED: activate the official WooCommerce Stripe plugin.' );
	}

	public function test_subscriptions_uses_donation_gateway_renewal_hook(): void {
		$this->assertTrue( function_exists( 'wcs_create_subscription' ) );
		$this->assertNotFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . Gateway::ID ) );
	}

	public function test_scheduler_canary_has_a_callback_and_completion_observer(): void {
		$this->assertNotFalse( has_action( 'chicago_reader_donation_stripe_scheduler_canary_recurring', 'ChicagoReader\\Modules\\DonationStripe\\action_scheduler_canary' ) );
		$this->assertNotFalse( has_action( 'action_scheduler_after_execute', 'ChicagoReader\\Modules\\DonationStripe\\record_action_scheduler_canary' ) );
	}
}
