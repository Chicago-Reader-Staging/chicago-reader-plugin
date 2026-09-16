<?php
/** PaymentIntent-to-order verification tests. */

use ChicagoReader\Modules\DonationStripe\Gateway;
use ChicagoReader\Modules\DonationStripe\Legacy_Gateway;
use PHPUnit\Framework\TestCase;

/** Verify that webhook/payment facts cannot be applied to another order. */
final class DonationStripeIntentVerificationTest extends TestCase {

	private function order( $overrides = array() ) {
		$values = array_merge(
			array(
				'id'       => 55,
				'currency' => 'USD',
				'gateway'  => Gateway::ID,
				'total'    => '12.50',
				'meta'     => array( Gateway::PAYMENT_INTENT_META => 'pi_expected' ),
			),
			$overrides
		);
		return new class( $values ) {
			private $values;

			public function __construct( $values ) {
				$this->values = $values;
			}

			public function get_id() {
				return $this->values['id'];
			}

			public function get_currency() {
				return $this->values['currency'];
			}

			public function get_payment_method() {
				return $this->values['gateway'];
			}

			public function get_total() {
				return $this->values['total'];
			}

			public function get_meta( $key ) {
				return $this->values['meta'][ $key ] ?? '';
			}
		};
	}

	private function intent( $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'       => 'pi_expected',
				'currency' => 'usd',
				'amount'   => 1250,
				'metadata' => (object) array( 'order_id' => '55' ),
			),
			$overrides
		);
	}

	public function test_matching_intent_is_accepted(): void {
		$this->assertTrue( ( new Gateway() )->payment_intent_matches_order( $this->intent(), $this->order() ) );
	}

	public function test_other_order_id_is_rejected(): void {
		$this->assertFalse( ( new Gateway() )->payment_intent_matches_order( $this->intent( array( 'metadata' => (object) array( 'order_id' => '56' ) ) ), $this->order() ) );
	}

	public function test_other_currency_is_rejected(): void {
		$this->assertFalse( ( new Gateway() )->payment_intent_matches_order( $this->intent( array( 'currency' => 'eur' ) ), $this->order() ) );
	}

	public function test_other_amount_is_rejected(): void {
		$this->assertFalse( ( new Gateway() )->payment_intent_matches_order( $this->intent( array( 'amount' => 1251 ) ), $this->order() ) );
	}

	public function test_other_gateway_is_rejected(): void {
		$this->assertFalse( ( new Gateway() )->payment_intent_matches_order( $this->intent(), $this->order( array( 'gateway' => 'stripe' ) ) ) );
	}

	public function test_different_stored_intent_is_rejected(): void {
		$this->assertFalse( ( new Gateway() )->payment_intent_matches_order( $this->intent( array( 'id' => 'pi_other' ) ), $this->order() ) );
	}

	public function test_legacy_intent_meta_is_accepted_only_for_legacy_gateway(): void {
		$order = $this->order(
			array(
				'gateway' => Legacy_Gateway::ID,
				'meta'    => array( '_chicago_reader_account_b_payment_intent_id' => 'pi_expected' ),
			)
		);
		$this->assertTrue( ( new Gateway() )->payment_intent_matches_order( $this->intent(), $order ) );
	}

	public function test_missing_metadata_is_rejected(): void {
		$this->assertFalse( ( new Gateway() )->payment_intent_matches_order( $this->intent( array( 'metadata' => (object) array() ) ), $this->order() ) );
	}
}
