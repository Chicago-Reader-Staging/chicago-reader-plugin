<?php
/**
 * Donation Stripe module bootstrap.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

/**
 * Register current and one-release legacy gateway classes.
 *
 * @param array $gateways Gateway classes.
 * @return array
 */
function register_gateways( $gateways ) {
	$gateways[] = Gateway::class;
	$gateways[] = Legacy_Gateway::class;
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', __NAMESPACE__ . '\register_gateways' );

/** Copy non-secret operational settings from the retired experiment once. */
function migrate_settings() {
	$new_key = 'woocommerce_' . Gateway::ID . '_settings';
	$old_key = 'woocommerce_' . Legacy_Gateway::ID . '_settings';
	$old     = get_option( $old_key, array() );
	if ( false === get_option( $new_key, false ) ) {
		update_option(
			$new_key,
			array(
				// The new gateway must be opted into explicitly after account and
				// scheduler verification; an enabled prototype is not approval.
				'enabled'       => 'no',
				'testmode'      => $old['testmode'] ?? 'yes',
				'title'         => __( 'Credit or debit card', 'chicago-reader' ),
				'description'   => '',
				'saved_methods' => 'yes',
				'wallets'       => 'yes',
				'logging'       => 'no',
			),
			false
		);
	}

	// Secrets from the prototype must not remain readable through WordPress
	// options or authenticated REST settings responses.
	foreach ( array( 'test_publishable_key', 'test_secret_key', 'test_webhook_secret', 'live_publishable_key', 'live_secret_key', 'live_webhook_secret', 'categories' ) as $retired_key ) {
		unset( $old[ $retired_key ] );
	}
	if ( get_option( $old_key, array() ) !== $old ) {
		update_option( $old_key, $old, false );
	}
}
migrate_settings();

add_action( 'woocommerce_check_cart_items', array( Routing::class, 'validate_cart' ) );
add_filter( 'woocommerce_available_payment_gateways', array( Routing::class, 'filter_gateways' ), 100 );

// The legacy endpoint remains only long enough to reconcile old staging data.
add_action( 'woocommerce_api_' . Legacy_Gateway::ID, array( Webhook_Handler::class, 'receive' ) );

Webhook_Handler::init();
Compatibility::init();
Token_Manager::init();

/** Keep a recurring canary so readiness reflects an operating host runner. */
function schedule_action_scheduler_canary() {
	$hook = 'chicago_reader_donation_stripe_scheduler_canary_recurring';
	if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
		return;
	}
	if ( as_has_scheduled_action( $hook, array(), Webhook_Handler::GROUP ) ) {
		return;
	}
	as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 12 * HOUR_IN_SECONDS, $hook, array(), Webhook_Handler::GROUP, true );
}
add_action( 'admin_init', __NAMESPACE__ . '\schedule_action_scheduler_canary' );
add_action( 'action_scheduler_ensure_recurring_actions', __NAMESPACE__ . '\schedule_action_scheduler_canary' );

/** Record successful execution without storing request or customer data. */
function record_action_scheduler_canary() {
	update_option( '_chicago_reader_donation_stripe_last_scheduler_canary', time(), false );
}
add_action( 'chicago_reader_donation_stripe_scheduler_canary_recurring', __NAMESPACE__ . '\record_action_scheduler_canary' );
