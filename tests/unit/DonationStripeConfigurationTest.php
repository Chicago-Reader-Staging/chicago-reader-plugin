<?php
/** Unit coverage for the fail-closed account-isolation gate. */

use ChicagoReader\Modules\DonationStripe\Configuration;
use PHPUnit\Framework\TestCase;

final class DonationStripeConfigurationTest extends TestCase {
	private $original_account_id;

	protected function setUp(): void {
		$this->original_account_id = WC_Stripe::get_instance()->account->id;
	}

	protected function tearDown(): void {
		WC_Stripe::get_instance()->account->id = $this->original_account_id;
		unset( $GLOBALS['cr_test_options']['_chicago_reader_donation_stripe_last_scheduler_canary'] );
	}

	public function test_donation_account_must_differ_from_official_stripe_account(): void {
		WC_Stripe::get_instance()->account->id = Configuration::APPROVED_TEST_ACCOUNT_ID;
		$this->assertFalse( Configuration::has_distinct_store_account() );
	}

	public function test_deployment_account_is_independently_pinned_to_nonprofit(): void {
		$this->assertSame( 'acct_1F24k4LAprqx9n8w', Configuration::APPROVED_TEST_ACCOUNT_ID );
		$this->assertTrue( Configuration::account_id_is_approved() );
		$this->assertNotSame( WC_Stripe::get_instance()->account->id, Configuration::APPROVED_TEST_ACCOUNT_ID );
		$this->assertFalse( Configuration::is_approved_account_id( 'acct_1TqEqILJyj1mjPmo', true ) );
		$this->assertFalse( Configuration::is_approved_account_id( 'acct_another_distinct_account', true ) );
		$this->assertFalse( Configuration::is_approved_account_id( Configuration::APPROVED_TEST_ACCOUNT_ID, false ) );
	}

	public function test_unknown_official_stripe_account_fails_closed(): void {
		WC_Stripe::get_instance()->account->id = '';
		$this->assertFalse( Configuration::has_distinct_store_account() );
	}

	public function test_separate_donation_account_passes_isolation_gate(): void {
		WC_Stripe::get_instance()->account->id = 'acct_store_test';
		$this->assertTrue( Configuration::has_distinct_store_account() );
		$this->assertSame( 'test', WC_Stripe::get_instance()->account->last_mode );
	}

	public function test_scheduler_requires_recent_canary(): void {
		$this->assertFalse( Configuration::scheduler_healthy() );
		$GLOBALS['cr_test_options']['_chicago_reader_donation_stripe_last_scheduler_canary'] = time() - DAY_IN_SECONDS - 1;
		$this->assertFalse( Configuration::scheduler_healthy() );
		$GLOBALS['cr_test_options']['_chicago_reader_donation_stripe_last_scheduler_canary'] = time();
		$this->assertTrue( Configuration::scheduler_healthy() );
	}

	public function test_restricted_and_standard_secret_key_formats_are_mode_scoped(): void {
		$this->assertTrue( Configuration::valid_secret_key_format( 'rk_test_example', true ) );
		$this->assertTrue( Configuration::valid_secret_key_format( 'sk_test_example', true ) );
		$this->assertFalse( Configuration::valid_secret_key_format( 'rk_live_example', true ) );
		$this->assertTrue( Configuration::valid_secret_key_format( 'rk_live_example', false ) );
		$this->assertFalse( Configuration::valid_secret_key_format( 'pk_test_example', true ) );
	}
}
