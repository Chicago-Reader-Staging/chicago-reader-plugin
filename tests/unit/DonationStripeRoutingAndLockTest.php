<?php
/** Executable isolation, fail-closed, and lock tests. */

use ChicagoReader\Modules\DonationStripe\Gateway;
use ChicagoReader\Modules\DonationStripe\Legacy_Gateway;
use ChicagoReader\Modules\DonationStripe\Lock;
use ChicagoReader\Modules\DonationStripe\Routing;
use PHPUnit\Framework\TestCase;

/** Exercise checkout routing with controlled WooCommerce and Newspack doubles. */
final class DonationStripeRoutingAndLockTest extends TestCase {

	protected function setUp(): void {
		\Newspack\Donations::$donation_ids = array( 101, 102, 103 );
		$GLOBALS['cr_test_options']     = array();
		$GLOBALS['cr_test_notices']     = array();
		$GLOBALS['cr_test_is_admin']    = false;
		$GLOBALS['cr_test_ajax']        = false;
		$GLOBALS['cr_test_orders']      = array();
		$GLOBALS['cr_test_order_pay']   = false;
		$GLOBALS['cr_test_wc']          = (object) array( 'cart' => $this->cart( array() ) );
	}

	private function cart( $items ) {
		return new class( $items ) {
			private $items;

			public function __construct( $items ) {
				$this->items = $items;
			}

			public function get_cart() {
				return $this->items;
			}

			public function is_empty() {
				return empty( $this->items );
			}
		};
	}

	private function item( $product_id, $variation_id = 0 ) {
		return array( 'product_id' => $product_id, 'variation_id' => $variation_id );
	}

	private function gateways() {
		return array(
			Gateway::ID        => (object) array( 'id' => Gateway::ID ),
			Legacy_Gateway::ID => (object) array( 'id' => Legacy_Gateway::ID ),
			'stripe'           => (object) array( 'id' => 'stripe' ),
			'cod'              => (object) array( 'id' => 'cod' ),
		);
	}

	public function test_donation_only_cart_exposes_only_donation_gateway(): void {
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 101 ) ) );
		$this->assertSame( array( Gateway::ID ), array_keys( Routing::filter_gateways( $this->gateways() ) ) );
	}

	public function test_merchandise_cart_keeps_store_gateways_unchanged(): void {
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 201 ) ) );
		$this->assertSame( array( 'stripe', 'cod' ), array_keys( Routing::filter_gateways( $this->gateways() ) ) );
	}

	public function test_variation_id_is_used_for_donation_classification(): void {
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 201, 102 ) ) );
		$this->assertSame( Routing::ALL, Routing::cart_state() );
	}

	public function test_mixed_cart_has_no_payment_gateway_and_one_notice(): void {
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 101 ), $this->item( 201 ) ) );
		$this->assertSame( array(), Routing::filter_gateways( $this->gateways() ) );
		Routing::validate_cart();
		Routing::validate_cart();
		$this->assertCount( 1, $GLOBALS['cr_test_notices'] );
		$this->assertSame( 'error', $GLOBALS['cr_test_notices'][0][1] );
	}

	public function test_missing_donation_gateway_fails_closed(): void {
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 101 ) ) );
		$this->assertSame( array(), Routing::filter_gateways( array( 'stripe' => (object) array( 'id' => 'stripe' ) ) ) );
		$this->assertCount( 1, $GLOBALS['cr_test_notices'] );
	}

	public function test_order_pay_classification_uses_order_not_empty_cart(): void {
		$GLOBALS['cr_test_order_pay'] = true;
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 55 ) );
		$GLOBALS['cr_test_orders'][55] = new class {
			public function get_payment_method() {
				return Gateway::ID;
			}

			public function get_items() {
				return array( new class {
					public function get_variation_id() {
						return 0;
					}

					public function get_product_id() {
						return 101;
					}
				} );
			}
		};
		$this->assertSame( Routing::ALL, Routing::request_state() );
	}

	public function test_order_pay_ignores_a_merchandise_cart_and_keeps_donation_gateway(): void {
		$this->test_order_pay_classification_uses_order_not_empty_cart();
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 201 ) ) );
		$this->assertSame( array( Gateway::ID ), array_keys( Routing::filter_gateways( $this->gateways() ) ) );
	}

	public function test_existing_official_stripe_order_does_not_migrate_to_donation_gateway(): void {
		$GLOBALS['cr_test_order_pay'] = true;
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 56 ) );
		$GLOBALS['cr_test_orders'][56] = new class {
			public function get_payment_method() { return 'stripe'; }
			public function get_items() {
				return array( new class {
					public function get_variation_id() { return 0; }
					public function get_product_id() { return 101; }
				} );
			}
		};
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 101 ) ) );
		$this->assertSame( array( 'stripe', 'cod' ), array_keys( Routing::filter_gateways( $this->gateways() ) ) );
	}

	public function test_unknown_order_pay_fails_closed_even_with_donation_cart(): void {
		$GLOBALS['cr_test_order_pay'] = true;
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-pay' => 57 ) );
		$GLOBALS['cr_test_wc']->cart = $this->cart( array( $this->item( 101 ) ) );
		$this->assertSame( array(), Routing::filter_gateways( $this->gateways() ) );
	}

	public function test_lock_rejects_concurrent_operation_and_non_owner_release(): void {
		$first = Lock::acquire( 'renewal_55' );
		$this->assertIsString( $first );
		$this->assertFalse( Lock::acquire( 'renewal_55' ) );
		Lock::release( 'renewal_55', 'other-owner' );
		$this->assertFalse( Lock::acquire( 'renewal_55' ) );
		Lock::release( 'renewal_55', $first );
		$this->assertIsString( Lock::acquire( 'renewal_55' ) );
	}

	public function test_expired_lock_is_recovered(): void {
		$name = 'cr_ds_lock_' . md5( 'webhook_order_55' );
		$GLOBALS['cr_test_options'][ $name ] = 'old-owner|' . ( time() - 1 );
		$this->assertIsString( Lock::acquire( 'webhook_order_55' ) );
	}
}
