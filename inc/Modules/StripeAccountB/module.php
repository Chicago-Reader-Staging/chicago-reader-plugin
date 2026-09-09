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
 * Classify the current cart for Account B routing.
 *
 * @return string One of: none, all, mixed.
 */
function get_cart_routing_state() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 'none';
	}

	$settings   = get_option( 'woocommerce_chicago_reader_account_b_settings', array() );
	$configured = isset( $settings['categories'] ) && is_array( $settings['categories'] ) ? array_map( 'strval', $settings['categories'] ) : array();
	$matched    = 0;
	$unmatched  = 0;

	if ( empty( $configured ) ) {
		return 'none';
	}

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
		$category_ids = array_map( 'strval', wc_get_product_term_ids( $product_id, 'product_cat' ) );
		if ( array_intersect( $configured, $category_ids ) ) {
			++$matched;
		} else {
			++$unmatched;
		}
	}

	if ( $matched && $unmatched ) {
		return 'mixed';
	}

	return $matched ? 'all' : 'none';
}

/**
 * Tell the customer why a cart cannot proceed instead of misrouting it.
 */
function validate_cart_routing() {
	if ( 'mixed' === get_cart_routing_state() ) {
		wc_add_notice( __( 'Donation products must be purchased separately from other products.', 'chicago-reader' ), 'error' );
	}
}
add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\validate_cart_routing' );

/**
 * Restrict available gateways to a single match for the cart's category.
 *
 * At checkout, show only this gateway when every cart item matches its
 * configured categories, and hide it entirely otherwise. Mixed carts are
 * blocked here so they cannot silently settle into the wrong account.
 *
 * @param array $available_gateways Gateways available for the current cart.
 * @return array
 */
function filter_available_gateways( $available_gateways ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $available_gateways;
	}
	$routing_state = get_cart_routing_state();

	if ( 'none' === $routing_state ) {
		unset( $available_gateways['chicago_reader_account_b'] );
		return $available_gateways;
	}

	if ( 'mixed' === $routing_state ) {
		return array();
	}

	// Fail closed: never let a donation cart fall through to Account A when
	// Account B is disabled, incomplete, or otherwise unavailable.
	if ( ! isset( $available_gateways['chicago_reader_account_b'] ) ) {
		$message = __( 'Donation checkout is temporarily unavailable. Please try again later.', 'chicago-reader' );
		if ( ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}
		return array();
	}

	return array( 'chicago_reader_account_b' => $available_gateways['chicago_reader_account_b'] );
}
add_filter( 'woocommerce_available_payment_gateways', __NAMESPACE__ . '\filter_available_gateways' );
