<?php
/**
 * WooCommerce token integration for Donation Stripe.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

/**
 * Stores reusable Stripe PaymentMethod references in WooCommerce.
 */
final class Token_Manager {

	const SUBSCRIPTION_TOKEN_META = '_chicago_reader_donation_stripe_token_id';
	const DEFAULT_TOKEN_META      = '_chicago_reader_donation_stripe_default_token_id';

	/** Initialize My Account token controls. */
	public static function init() {
		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'account_list_item' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_default_request' ), 1 );
		add_action( 'wp', array( __CLASS__, 'guard_core_token_actions' ), 19 );
		add_action( 'woocommerce_payment_token_deleted', array( __CLASS__, 'detach_deleted_method' ), 10, 2 );
	}

	/**
	 * Create or update a Woo token from a verified Stripe PaymentMethod.
	 *
	 * @param int                   $user_id        User ID.
	 * @param \Stripe\PaymentMethod $payment_method Stripe PaymentMethod.
	 * @return \WC_Payment_Token_CC
	 * @throws \UnexpectedValueException Invalid card method.
	 */
	public static function upsert( $user_id, $payment_method ) {
		if ( ! $user_id || 'card' !== (string) $payment_method->type || empty( $payment_method->card ) ) {
			throw new \UnexpectedValueException( 'A reusable card payment method was not returned.' );
		}

		$token = self::find_by_payment_method( $user_id, (string) $payment_method->id );
		if ( ! $token ) {
			$token = new \WC_Payment_Token_CC();
			$token->set_user_id( $user_id );
			$token->set_gateway_id( Gateway::ID );
			$token->set_token( (string) $payment_method->id );
		}

		$token->set_card_type( sanitize_key( (string) $payment_method->card->brand ) );
		$token->set_last4( (string) $payment_method->card->last4 );
		$token->set_expiry_month( str_pad( (string) $payment_method->card->exp_month, 2, '0', STR_PAD_LEFT ) );
		$token->set_expiry_year( (string) $payment_method->card->exp_year );
		$wallet_type = ! empty( $payment_method->card->wallet->type ) ? sanitize_key( (string) $payment_method->card->wallet->type ) : '';
		$token->update_meta_data( '_chicago_reader_donation_stripe_wallet_type', $wallet_type );
		$token->update_meta_data( '_chicago_reader_donation_stripe_mode', Configuration::is_test_mode() ? 'test' : 'live' );
		$token->update_meta_data( '_chicago_reader_donation_stripe_account_id', Configuration::expected_account_id() );
		$token->save();

		if ( ! get_user_meta( $user_id, self::DEFAULT_TOKEN_META, true ) ) {
			self::set_default( $user_id, $token->get_id() );
		}
		return $token;
	}

	/**
	 * Find a user's Donation Stripe token by provider ID.
	 *
	 * @param int    $user_id           User ID.
	 * @param string $payment_method_id Stripe PaymentMethod ID.
	 * @return \WC_Payment_Token_CC|false
	 */
	public static function find_by_payment_method( $user_id, $payment_method_id ) {
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id, Gateway::ID ) as $token ) {
			if ( hash_equals( (string) $token->get_token(), (string) $payment_method_id ) ) {
				return $token;
			}
		}
		return false;
	}

	/**
	 * Get and strictly validate a token for the current account/mode.
	 *
	 * @param int $token_id Token ID.
	 * @param int $user_id  Expected owner.
	 * @return \WC_Payment_Token_CC|false
	 */
	public static function get_valid( $token_id, $user_id ) {
		$token = \WC_Payment_Tokens::get( absint( $token_id ) );
		if ( ! $token || Gateway::ID !== $token->get_gateway_id() || absint( $user_id ) !== absint( $token->get_user_id() ) ) {
			return false;
		}
		$mode = Configuration::is_test_mode() ? 'test' : 'live';
		if ( $mode !== $token->get_meta( '_chicago_reader_donation_stripe_mode' ) ) {
			return false;
		}
		if ( ! hash_equals( Configuration::expected_account_id(), (string) $token->get_meta( '_chicago_reader_donation_stripe_account_id' ) ) ) {
			return false;
		}
		return $token;
	}

	/**
	 * Set the module-specific default without changing another gateway's default.
	 *
	 * @param int $user_id  User ID.
	 * @param int $token_id Token ID.
	 * @return bool
	 */
	public static function set_default( $user_id, $token_id ) {
		if ( ! self::get_valid( $token_id, $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, self::DEFAULT_TOKEN_META, absint( $token_id ) );
		return true;
	}

	/**
	 * Label and control a Donation Stripe token on My Account.
	 *
	 * @param array             $item  Payment-method row.
	 * @param \WC_Payment_Token $token Token object.
	 * @return array
	 */
	public static function account_list_item( $item, $token ) {
		if ( Gateway::ID !== $token->get_gateway_id() ) {
			return $item;
		}
		$default_id         = absint( get_user_meta( $token->get_user_id(), self::DEFAULT_TOKEN_META, true ) );
		$item['is_default'] = $default_id === $token->get_id();
		$item['method']['gateway'] = Gateway::ID;
		if ( ! $item['is_default'] ) {
			$url = add_query_arg(
				'donation-stripe-default',
				$token->get_id(),
				wc_get_account_endpoint_url( 'payment-methods' )
			);
			$item['actions']['default'] = array(
				'url'  => wp_nonce_url( $url, 'donation-stripe-default-' . $token->get_id() ),
				'name' => __( 'Default for donations', 'chicago-reader' ),
			);
		} else {
			unset( $item['actions']['default'] );
		}
		if ( self::active_subscriptions_using_token( $token->get_user_id(), $token->get_id() ) ) {
			unset( $item['actions']['delete'] );
		}
		return $item;
	}

	/** Process the module-specific default action without touching store defaults. */
	public static function handle_default_request() {
		if ( empty( $_GET['donation-stripe-default'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token_id = absint( $_GET['donation-stripe-default'] );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'donation-stripe-default-' . $token_id ) || ! self::set_default( get_current_user_id(), $token_id ) ) {
			wc_add_notice( __( 'The default donation payment method could not be changed.', 'chicago-reader' ), 'error' );
		} else {
			wc_add_notice( __( 'Default donation payment method updated.', 'chicago-reader' ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
		exit;
	}

	/** Prevent deletion in use and keep Woo's generic default account-specific. */
	public static function guard_core_token_actions() {
		global $wp;
		if ( ! is_user_logged_in() || empty( $wp->query_vars ) ) {
			return;
		}

		if ( isset( $wp->query_vars['delete-payment-method'] ) ) {
			$token_id = absint( $wp->query_vars['delete-payment-method'] );
			$token    = \WC_Payment_Tokens::get( $token_id );
			$owned    = $token && Gateway::ID === $token->get_gateway_id() && get_current_user_id() === absint( $token->get_user_id() );
			if ( $owned && self::active_subscriptions_using_token( get_current_user_id(), $token_id ) ) {
				wc_add_notice( __( 'This payment method is still used by an active donation subscription. Choose a replacement before deleting it.', 'chicago-reader' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
				exit;
			}
		}

		if ( isset( $wp->query_vars['set-default-payment-method'] ) ) {
			$token_id = absint( $wp->query_vars['set-default-payment-method'] );
			$nonce    = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, 'set-default-payment-method-' . $token_id ) && self::get_valid( $token_id, get_current_user_id() ) ) {
				self::set_default( get_current_user_id(), $token_id );
				wc_add_notice( __( 'Default donation payment method updated.', 'chicago-reader' ) );
				wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
				exit;
			}
		}
	}

	/**
	 * Update all active donation subscriptions for one customer.
	 *
	 * @param int $user_id  User ID.
	 * @param int $token_id Token ID.
	 */
	public static function update_active_donation_subscriptions( $user_id, $token_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) || ! self::get_valid( $token_id, $user_id ) ) {
			return;
		}
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			if ( ! $subscription->has_status( array( 'active', 'on-hold', 'pending-cancel' ) ) || ! Routing::is_donation_order( $subscription ) ) {
				continue;
			}
			$subscription->set_payment_method( Gateway::ID );
			$subscription->update_meta_data( self::SUBSCRIPTION_TOKEN_META, absint( $token_id ) );
			$subscription->save();
		}
	}

	/**
	 * Whether an active donation subscription still depends on a token.
	 *
	 * @param int $user_id  User ID.
	 * @param int $token_id Token ID.
	 * @return bool
	 */
	private static function active_subscriptions_using_token( $user_id, $token_id ) {
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return false;
		}
		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			if ( $subscription->has_status( array( 'active', 'on-hold', 'pending-cancel' ) ) && absint( $subscription->get_meta( self::SUBSCRIPTION_TOKEN_META ) ) === absint( $token_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detach a deleted, unused method from the donation Stripe customer.
	 *
	 * @param int               $token_id Deleted token ID.
	 * @param \WC_Payment_Token $token    Deleted token object.
	 */
	public static function detach_deleted_method( $token_id, $token ) {
		if ( Gateway::ID !== $token->get_gateway_id() || self::active_subscriptions_using_token( $token->get_user_id(), $token_id ) || ! Configuration::verify_account() ) {
			return;
		}
		try {
			Configuration::client()->paymentMethods->detach(
				$token->get_token(),
				array(),
				array( 'idempotency_key' => 'cr-ds-detach-' . absint( $token_id ) )
			);
		} catch ( \Throwable $error ) {
			// The local token is already gone; webhook/reconciliation monitoring
			// surfaces a provider detach failure without exposing details here.
			update_option( '_chicago_reader_donation_stripe_last_token_detach_failure', time(), false );
		}
	}

	/**
	 * Store a token reference on all subscriptions created by an order.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $token_id Token ID.
	 */
	public static function attach_to_order_subscriptions( $order, $token_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}
		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
			$subscription->set_payment_method( Gateway::ID );
			$subscription->update_meta_data( self::SUBSCRIPTION_TOKEN_META, absint( $token_id ) );
			$subscription->save();
		}
	}

	/**
	 * Render a safe payment-method label for Newspack/WCS account screens.
	 *
	 * @param string           $label Existing label.
	 * @param \WC_Subscription $subscription Subscription.
	 * @return string
	 */
	public static function subscription_label( $label, $subscription ) {
		if ( Gateway::ID !== $subscription->get_payment_method() ) {
			return $label;
		}
		$token = self::get_valid( $subscription->get_meta( self::SUBSCRIPTION_TOKEN_META ), $subscription->get_customer_id() );
		if ( ! $token ) {
			return __( 'Donation payment method unavailable', 'chicago-reader' );
		}
		$wallet = $token->get_meta( '_chicago_reader_donation_stripe_wallet_type' );
		$name   = $wallet ? ucwords( str_replace( '_', ' ', $wallet ) ) : ucfirst( $token->get_card_type() );
		return sprintf( /* translators: 1: card/wallet name, 2: last four digits */ __( 'Donation payment method: %1$s ending in %2$s', 'chicago-reader' ), esc_html( $name ), esc_html( $token->get_last4() ) );
	}
}
