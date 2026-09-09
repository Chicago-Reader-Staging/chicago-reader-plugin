<?php
/**
 * Account B WooCommerce payment gateway. Routes to a second, independent
 * Stripe account via an embedded Stripe Payment Element on the checkout
 * page itself — the same on-page pattern the default Stripe gateway uses,
 * confirmed against Stripe's deferred-intent integration docs.
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
		$this->method_description = 'Routes checkout to a second, independent Stripe account for the product categories selected below. Card fields render inline on the checkout page, same as the default gateway.';
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'subscriptions' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->testmode    = 'yes' === $this->get_option( 'testmode' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
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
			$product_id    = $cart_item['product_id'];
			$category_ids  = wc_get_product_term_ids( $product_id, 'product_cat' );
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
	 * Active publishable key for the current mode.
	 *
	 * @return string
	 */
	private function get_publishable_key() {
		return $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'live_publishable_key' );
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
	 * Enqueue Stripe.js and the checkout script on the checkout page.
	 */
	public function payment_scripts() {
		if ( ! is_checkout() || 'yes' !== $this->enabled ) {
			return;
		}

		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedScriptVersion.NotInFooter, WordPress.WP.EnqueuedScriptVersion.NoVersion -- Stripe.js must never be self-hosted or version-pinned; served from js.stripe.com by design.
		wp_enqueue_script( 'chicago-reader-account-b-checkout', plugins_url( 'assets/checkout.js', __FILE__ ), array( 'jquery', 'stripe-js' ), '1.0.0', true );
		wp_localize_script(
			'chicago-reader-account-b-checkout',
			'chicagoReaderAccountB',
			array(
				'gatewayId'      => $this->id,
				'publishableKey' => $this->get_publishable_key(),
				'amount'         => (int) round( WC()->cart->get_total( 'edit' ) * 100 ),
				'currency'       => strtolower( get_woocommerce_currency() ),
			)
		);
	}

	/**
	 * Render the inline card form mount point.
	 */
	public function payment_fields() {
		if ( ! empty( $this->description ) ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		?>
		<div id="chicago-reader-account-b-payment-element"></div>
		<div id="chicago-reader-account-b-errors" role="alert"></div>
		<input type="hidden" name="chicago_reader_account_b_confirmation_token" id="chicago-reader-account-b-confirmation-token" />
		<?php
	}

	/**
	 * Charge the order using the confirmation token collected on the checkout page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$confirmation_token_id = isset( $_POST['chicago_reader_account_b_confirmation_token'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce's own checkout nonce before process_payment runs.
			? sanitize_text_field( wp_unslash( $_POST['chicago_reader_account_b_confirmation_token'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( ! $confirmation_token_id ) {
			wc_add_notice( 'Payment could not be processed. Please try again.', 'error' );
			return array( 'result' => 'failure' );
		}

		$is_renewal_setup = $this->order_contains_subscription( $order );
		$stripe           = $this->get_stripe_client();

		$intent_args = array(
			'amount'             => (int) round( $order->get_total() * 100 ),
			'currency'           => strtolower( $order->get_currency() ),
			'confirmation_token' => $confirmation_token_id,
			'confirm'            => true,
			'return_url'         => $this->get_return_url( $order ),
			'metadata'           => array( 'order_id' => (string) $order->get_id() ),
		);

		if ( $is_renewal_setup ) {
			$intent_args['customer']            = $this->get_or_create_customer_id( $order );
			$intent_args['setup_future_usage']  = 'off_session';
		}

		try {
			$intent = $stripe->paymentIntents->create( $intent_args ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'succeeded' === $intent->status ) {
			$order->payment_complete( $intent->id );
			$order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $intent->id );
			$order->save();

			if ( $is_renewal_setup ) {
				$this->store_subscription_payment_method( $order, $intent_args['customer'], $intent->payment_method );
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// A card requiring 3D Secure or similar returns `requires_action` here.
		// Known gap: this gateway does not yet drive that challenge client-side.
		$order->update_status( 'on-hold', 'Stripe requires additional card authentication that this gateway does not yet handle.' );
		wc_add_notice( 'Your bank requires additional verification for this card. Please try a different card.', 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Find or create a Stripe Customer for the order's account, for future renewal charges.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function get_or_create_customer_id( $order ) {
		$user_id  = $order->get_customer_id();
		$existing = $user_id ? get_user_meta( $user_id, '_chicago_reader_account_b_customer_id', true ) : '';
		if ( $existing ) {
			return $existing;
		}

		$stripe   = $this->get_stripe_client();
		$customer = $stripe->customers->create( array( 'email' => $order->get_billing_email() ) );

		if ( $user_id ) {
			update_user_meta( $user_id, '_chicago_reader_account_b_customer_id', $customer->id );
		}

		return $customer->id;
	}

	/**
	 * Record the Stripe customer/payment method on the order's subscription(s) for future renewals.
	 *
	 * @param \WC_Order $order             Order.
	 * @param string    $customer_id       Stripe Customer ID.
	 * @param string    $payment_method_id Stripe PaymentMethod ID.
	 */
	private function store_subscription_payment_method( $order, $customer_id, $payment_method_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}
		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
			$subscription->update_meta_data( '_chicago_reader_account_b_customer_id', $customer_id );
			$subscription->update_meta_data( '_chicago_reader_account_b_payment_method_id', $payment_method_id );
			$subscription->save();
		}
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
	 *
	 * Backstop only — process_payment() already completes the order synchronously.
	 * This exists because Stripe's own guidance is to never rely solely on the
	 * client-side callback, since a customer can close the tab mid-confirmation.
	 */
	public function handle_webhook() {
		$payload    = @file_get_contents( 'php://input' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $sig_header, $this->get_webhook_secret() );
		} catch ( \Exception $e ) {
			status_header( 400 );
			exit;
		}

		if ( 'payment_intent.succeeded' === $event->type ) {
			$this->fulfill_payment_intent( $event->data->object );
		} elseif ( 'charge.refunded' === $event->type ) {
			$this->note_refund( $event->data->object );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Mark the order paid, if it isn't already.
	 *
	 * @param \Stripe\PaymentIntent $payment_intent Stripe PaymentIntent.
	 */
	private function fulfill_payment_intent( $payment_intent ) {
		$order_id = isset( $payment_intent->metadata->order_id ) ? absint( $payment_intent->metadata->order_id ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$order->payment_complete( $payment_intent->id );
		$order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $payment_intent->id );
		$order->save();
	}

	/**
	 * Add an order note when Stripe reports a refund made directly in the Dashboard.
	 *
	 * @param \Stripe\Charge $charge Stripe Charge.
	 */
	private function note_refund( $charge ) {
		$order_id = isset( $charge->metadata->order_id ) ? absint( $charge->metadata->order_id ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			$order->add_order_note( 'Stripe reported a refund on this order\'s Account B charge.' );
		}
	}

	/**
	 * Charge a subscription renewal off-session against the saved payment method.
	 *
	 * @param float     $amount_to_charge Renewal amount.
	 * @param \WC_Order $renewal_order    Renewal order.
	 */
	public function process_subscription_payment( $amount_to_charge, $renewal_order ) {
		$subscriptions      = wcs_get_subscriptions_for_order( $renewal_order, array( 'order_type' => 'any' ) );
		$subscription       = reset( $subscriptions );
		$customer_id        = $subscription ? $subscription->get_meta( '_chicago_reader_account_b_customer_id' ) : '';
		$payment_method_id  = $subscription ? $subscription->get_meta( '_chicago_reader_account_b_payment_method_id' ) : '';

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
		$order              = wc_get_order( $order_id );
		$payment_intent_id  = $order->get_meta( '_chicago_reader_account_b_payment_intent_id' );
		if ( ! $payment_intent_id ) {
			return new \WP_Error( 'chicago_reader_account_b_refund', 'No Account B payment intent recorded on this order.' );
		}

		$stripe      = $this->get_stripe_client();
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
