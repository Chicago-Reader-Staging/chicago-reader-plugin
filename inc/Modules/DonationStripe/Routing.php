<?php
/**
 * Newspack donation routing policy.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps product classification and payment-account routing in one place.
 */
final class Routing {

	const NONE  = 'none';
	const ALL   = 'all';
	const MIXED = 'mixed';

	/**
	 * Ask Newspack whether a product is a donation.
	 *
	 * @param int $product_id Product ID.
	 * @return bool|null True/false, or null when Newspack cannot classify it.
	 */
	public static function is_donation_product( $product_id ) {
		if ( ! class_exists( '\Newspack\Donations' ) || ! method_exists( '\Newspack\Donations', 'is_donation_product' ) ) {
			return null;
		}
		return (bool) \Newspack\Donations::is_donation_product( absint( $product_id ) );
	}

	/**
	 * Classify an iterable of product IDs.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return string
	 */
	public static function classify_product_ids( $product_ids ) {
		$matched   = 0;
		$unmatched = 0;

		foreach ( $product_ids as $product_id ) {
			$is_donation = self::is_donation_product( $product_id );
			if ( null === $is_donation ) {
				return self::MIXED;
			}
			$is_donation ? ++$matched : ++$unmatched;
		}

		if ( $matched && $unmatched ) {
			return self::MIXED;
		}
		return $matched ? self::ALL : self::NONE;
	}

	/**
	 * Classify the current cart.
	 *
	 * @return string
	 */
	public static function cart_state() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return self::NONE;
		}
		$product_ids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_ids[] = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : absint( $item['product_id'] ?? 0 );
		}
		return self::classify_product_ids( $product_ids );
	}

	/**
	 * Classify the cart or the order being paid from My Account.
	 *
	 * @return string
	 */
	public static function request_state() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			global $wp;
			$order_id = ! empty( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				return self::MIXED;
			}
			if ( Gateway::ID === $order->get_payment_method() ) {
				return self::is_donation_order( $order ) ? self::ALL : self::MIXED;
			}
			return Legacy_Gateway::ID === $order->get_payment_method() ? self::MIXED : self::NONE;
		}
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			return self::cart_state();
		}
		return self::NONE;
	}

	/**
	 * Verify every product on an order is a Newspack donation.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_donation_order( $order ) {
		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		}
		return ! empty( $product_ids ) && self::ALL === self::classify_product_ids( $product_ids );
	}

	/**
	 * Block carts that would require payments to two independent accounts.
	 */
	public static function validate_cart() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( self::MIXED !== self::cart_state() ) {
			return;
		}
		$message = __( 'Donations must be completed separately from merchandise or other purchases. Remove one type of item and complete the two checkouts separately.', 'chicago-reader' );
		if ( ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Select exactly one payment-account family for the current cart.
	 *
	 * @param array $gateways Available gateway objects.
	 * @return array
	 */
	public static function filter_gateways( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}

		$account_add = function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page();
		if ( $account_add ) {
			unset( $gateways[ Legacy_Gateway::ID ] );
			return $gateways;
		}

		$state = self::request_state();
		if ( self::NONE === $state ) {
			unset( $gateways[ Gateway::ID ], $gateways[ Legacy_Gateway::ID ] );
			return $gateways;
		}
		if ( self::MIXED === $state ) {
			return array();
		}
		if ( ! isset( $gateways[ Gateway::ID ] ) ) {
			$message = __( 'Donation checkout is temporarily unavailable. No charge has been attempted.', 'chicago-reader' );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
			return array();
		}
		return array( Gateway::ID => $gateways[ Gateway::ID ] );
	}
}
