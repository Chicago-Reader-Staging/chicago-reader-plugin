<?php
/**
 * Account B WooCommerce payment gateway. Routes to a second, independent
 * Stripe account via a Stripe Checkout Session (redirect), so no card
 * fields are rendered on the WooCommerce checkout page itself.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\StripeAccountB;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gateway class.
 */
class Gateway extends \WC_Payment_Gateway {

	/**
	 * Whether this gateway is in test mode.
	 *
	 * @var bool
	 */
	protected $testmode;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'chicago_reader_account_b';
		$this->method_title       = 'Chicago Reader — Account B (Stripe)';
		$this->method_description = 'Routes checkout to a second, independent Stripe account for the product categories selected below. Card entry happens on a Stripe-hosted page; no card fields are shown on this site.';
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'subscriptions' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->testmode    = 'yes' === $this->get_option( 'testmode' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_webhook' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_subscription_payment' ), 10, 2 );
	}

	/**
	 * Admin settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable this gateway',
				'default' => 'no',
			),
			'title'                => array(
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'Payment method name the customer sees at checkout.',
				'default'     => 'Credit / Debit Card',
			),
			'description'          => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => '',
			),
			'categories'           => array(
				'title'             => 'Product categories routed here',
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'description'       => 'A cart containing any product from these categories routes to this gateway\'s Stripe account instead of the default gateway.',
				'options'           => $this->get_category_options(),
				'default'           => array(),
				'custom_attributes' => array( 'data-placeholder' => 'Select categories' ),
			),
			'testmode'             => array(
				'title'   => 'Test mode',
				'type'    => 'checkbox',
				'label'   => 'Use test API keys',
				'default' => 'yes',
			),
			'test_publishable_key' => array(
				'title' => 'Test publishable key',
				'type'  => 'text',
			),
			'test_secret_key'      => array(
				'title' => 'Test secret key',
				'type'  => 'password',
			),
			'test_webhook_secret'  => array(
				'title' => 'Test webhook signing secret',
				'type'  => 'password',
			),
			'live_publishable_key' => array(
				'title' => 'Live publishable key',
				'type'  => 'text',
			),
			'live_secret_key'      => array(
				'title' => 'Live secret key',
				'type'  => 'password',
			),
			'live_webhook_secret'  => array(
				'title' => 'Live webhook signing secret',
				'type'  => 'password',
			),
			'webhook_url'          => array(
				'title'       => 'Webhook URL',
				'type'        => 'title',
				'description' => 'Register this exact URL as an endpoint in the Stripe Dashboard for this account: <code>' . esc_url( \WC()->api_request_url( $this->id ) ) . '</code>',
			),
		);
	}

	/**
	 * Product category options for the settings multiselect.
	 *
	 * @return array
	 */
	private function get_category_options() {
		$terms   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$options = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ $term->term_id ] = $term->name;
			}
		}
		return $options;
	}

	/**
	 * Whether the current cart contains a product from this gateway's configured categories.
	 *
	 * @return bool
	 */
	public function cart_matches_configured_categories() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		$configured = $this->get_option( 'categories', array() );
		if ( empty( $configured ) ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id  = $cart_item['product_id'];
			$category_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
			if ( array_intersect( $configured, array_map( 'strval', $category_ids ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stripe API client for the active mode (test or live).
	 *
	 * @return \Stripe\StripeClient
	 */
	private function get_stripe_client() {
		$secret_key = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'live_secret_key' );
		return new \Stripe\StripeClient( $secret_key );
	}

	/**
	 * Active webhook signing secret for the current mode.
	 *
	 * @return string
	 */
	private function get_webhook_secret() {
		return $this->testmode ? $this->get_option( 'test_webhook_secret' ) : $this->get_option( 'live_webhook_secret' );
	}

	/**
	 * Create a Stripe Checkout Session for the order and redirect the customer to it.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order       = wc_get_order( $order_id );
		$stripe      = $this->get_stripe_client();
		$is_renewal_setup = $this->order_contains_subscription( $order );

		$session_args = array(
			'mode'                => 'payment',
			'line_items'          => array(
				array(
					'price_data' => array(
						'currency'     => strtolower( $order->get_currency() ),
						'product_data' => array( 'name' => 'Order #' . $order->get_order_number() ),
						'unit_amount'  => (int) round( $order->get_total() * 100 ),
					),
					'quantity'   => 1,
				),
			),
			'success_url'         => $this->get_return_url( $order ),
			'cancel_url'          => wc_get_checkout_url(),
			'customer_email'      => $order->get_billing_email(),
			'client_reference_id' => (string) $order->get_id(),
			'metadata'            => array( 'order_id' => (string) $order->get_id() ),
		);

		if ( $is_renewal_setup ) {
			$session_args['customer_creation']          = 'always';
			$session_args['payment_intent_data']        = array( 'setup_future_usage' => 'off_session' );
		}

		$session = $stripe->checkout->sessions->create( $session_args );

		$order->update_meta_data( '_chicago_reader_account_b_session_id', $session->id );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $session->url,
		);
	}

	/**
	 * Whether the order contains a WooCommerce Subscriptions product.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function order_contains_subscription( $order ) {
		return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
	}

	/**
	 * Stripe webhook receiver. Registered at ?wc-api=chicago_reader_account_b.
	 */
	public function handle_webhook() {
		$payload     = @file_get_contents( 'php://input' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$sig_header  = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $this->get_webhook_secret() );
		} catch ( \Exception $e ) {
			status_header( 400 );
			exit;
		}

		if ( 'checkout.session.completed' === $event->type || 'checkout.session.async_payment_succeeded' === $event->type ) {
			$this->fulfill_session( $event->data->object );
		} elseif ( 'checkout.session.async_payment_failed' === $event->type ) {
			$this->fail_session( $event->data->object );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Mark the order paid and record the Stripe customer/payment method for future renewals.
	 *
	 * @param \Stripe\Checkout\Session $session Stripe Checkout Session.
	 */
	private function fulfill_session( $session ) {
		$order_id = isset( $session->metadata->order_id ) ? absint( $session->metadata->order_id ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$stripe        = $this->get_stripe_client();
		$payment_intent = $stripe->paymentIntents->retrieve( $session->payment_intent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.

		$order->payment_complete( $payment_intent->id );
		$order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $payment_intent->id );
		$order->save();

		if ( $session->customer && $payment_intent->payment_method && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
				$subscription->update_meta_data( '_chicago_reader_account_b_customer_id', $session->customer );
				$subscription->update_meta_data( '_chicago_reader_account_b_payment_method_id', $payment_intent->payment_method );
				$subscription->save();
			}
		}
	}

	/**
	 * Mark the order failed after an async payment method fails.
	 *
	 * @param \Stripe\Checkout\Session $session Stripe Checkout Session.
	 */
	private function fail_session( $session ) {
		$order_id = isset( $session->metadata->order_id ) ? absint( $session->metadata->order_id ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			$order->update_status( 'failed', 'Stripe reported the payment method failed.' );
		}
	}

	/**
	 * Charge a subscription renewal off-session against the saved payment method.
	 *
	 * @param float     $amount_to_charge Renewal amount.
	 * @param \WC_Order $renewal_order    Renewal order.
	 */
	public function process_subscription_payment( $amount_to_charge, $renewal_order ) {
		$subscriptions = wcs_get_subscriptions_for_order( $renewal_order, array( 'order_type' => 'any' ) );
		$subscription  = reset( $subscriptions );
		$customer_id   = $subscription ? $subscription->get_meta( '_chicago_reader_account_b_customer_id' ) : '';
		$payment_method_id = $subscription ? $subscription->get_meta( '_chicago_reader_account_b_payment_method_id' ) : '';

		if ( ! $customer_id || ! $payment_method_id ) {
			$renewal_order->update_status( 'failed', 'No saved Account B payment method on this subscription.' );
			return;
		}

		$stripe = $this->get_stripe_client();

		try {
			$intent = $stripe->paymentIntents->create( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
				array(
					'amount'         => (int) round( $amount_to_charge * 100 ),
					'currency'       => strtolower( $renewal_order->get_currency() ),
					'customer'       => $customer_id,
					'payment_method' => $payment_method_id,
					'off_session'    => true,
					'confirm'        => true,
				)
			);
			$renewal_order->payment_complete( $intent->id );
		} catch ( \Stripe\Exception\CardException $e ) {
			$renewal_order->add_order_note( 'Account B renewal charge failed: ' . $e->getMessage() );
			\WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $renewal_order );
		}
	}

	/**
	 * Refund a payment through this gateway's Stripe account.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param float  $amount   Amount to refund, or null for a full refund.
	 * @param string $reason   Refund reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order          = wc_get_order( $order_id );
		$payment_intent_id = $order->get_meta( '_chicago_reader_account_b_payment_intent_id' );
		if ( ! $payment_intent_id ) {
			return new \WP_Error( 'chicago_reader_account_b_refund', 'No Account B payment intent recorded on this order.' );
		}

		$stripe    = $this->get_stripe_client();
		$refund_args = array( 'payment_intent' => $payment_intent_id );
		if ( null !== $amount ) {
			$refund_args['amount'] = (int) round( $amount * 100 );
		}

		try {
			$stripe->refunds->create( $refund_args );
			return true;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'chicago_reader_account_b_refund', $e->getMessage() );
		}
	}
}
