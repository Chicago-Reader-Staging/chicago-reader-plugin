<?php
/**
 * This file will be automatically loaded when the module is active.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\StripeAccountB;

/**
 * Register the gateway with WooCommerce.
 *
 * @param array $gateways Registered gateway classes.
 * @return array
 */
function register_gateway( $gateways ) {
	$gateways[] = Gateway::class;
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', __NAMESPACE__ . '\register_gateway' );

/**
 * Restrict available gateways to a single match for the cart's category.
 *
 * At checkout, show only this gateway when the cart matches its configured
 * categories, and hide it entirely otherwise. One order still settles
 * through exactly one gateway — a cart mixing this gateway's categories
 * with anything else is prevented upstream (Subscriptions' mixed-checkout
 * setting, and Newspack's own donation flow emptying the cart first).
 *
 * @param array $available_gateways Gateways available for the current cart.
 * @return array
 */
function filter_available_gateways( $available_gateways ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $available_gateways;
	}
	if ( ! isset( $available_gateways['chicago_reader_account_b'] ) ) {
		return $available_gateways;
	}

	$gateway = $available_gateways['chicago_reader_account_b'];

	if ( ! $gateway->cart_matches_configured_categories() ) {
		unset( $available_gateways['chicago_reader_account_b'] );
		return $available_gateways;
	}

	return array( 'chicago_reader_account_b' => $gateway );
}
add_filter( 'woocommerce_available_payment_gateways', __NAMESPACE__ . '\filter_available_gateways' );
