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
		$this->supports           = array( 'products', 'refunds', 'subscriptions' );

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
		add_action( 'wp_ajax_chicago_reader_account_b_finalize', array( $this, 'finalize_payment' ) );
		add_action( 'wp_ajax_nopriv_chicago_reader_account_b_finalize', array( $this, 'finalize_payment' ) );
	}

	/**
	 * Check whether the gateway has everything needed to accept payments.
	 *
	 * Donation checkout must fail closed when Account B is not configured;
	 * otherwise WooCommerce can silently fall back to the default account.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		return (bool) ( $this->get_publishable_key() && $this->get_secret_key() && $this->get_webhook_secret() );
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
				'description'       => 'Carts containing only products from these categories route to this Stripe account. Mixed carts are blocked and must be purchased separately.',
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
		return new \Stripe\StripeClient( $this->get_secret_key() );
	}

	/**
	 * Active secret key for the current mode.
	 *
	 * @return string
	 */
	private function get_secret_key() {
		return $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'live_secret_key' );
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
		if ( ! is_checkout() || ! $this->is_available() || ! $this->cart_matches_configured_categories() ) {
			return;
		}

		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedScriptVersion.NotInFooter, WordPress.WP.EnqueuedScriptVersion.NoVersion -- Stripe.js must never be self-hosted or version-pinned; served from js.stripe.com by design.
		$checkout_script_path = __DIR__ . '/assets/checkout.js';
		wp_enqueue_script( 'chicago-reader-account-b-checkout', plugins_url( 'assets/checkout.js', __FILE__ ), array( 'jquery', 'stripe-js' ), (string) filemtime( $checkout_script_path ), true );
		wp_localize_script(
			'chicago-reader-account-b-checkout',
			'chicagoReaderAccountB',
			array(
				'gatewayId'      => $this->id,
				'publishableKey' => $this->get_publishable_key(),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'finalizeNonce'  => wp_create_nonce( 'chicago_reader_account_b_finalize' ),
			)
		);
	}

	/**
	 * Render the inline card form mount point.
	 */
	public function payment_fields() {
		$amount             = WC()->cart ? $this->get_stripe_amount( WC()->cart->get_total( 'edit' ), get_woocommerce_currency() ) : 0;
		$setup_future_usage = class_exists( '\WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ? 'off_session' : '';

		if ( ! empty( $this->description ) ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		?>
		<div
			id="chicago-reader-account-b-payment-element"
			data-amount="<?php echo esc_attr( $amount ); ?>"
			data-currency="<?php echo esc_attr( strtolower( get_woocommerce_currency() ) ); ?>"
			data-setup-future-usage="<?php echo esc_attr( $setup_future_usage ); ?>"
		></div>
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
		if ( ! $order ) {
			wc_add_notice( __( 'Payment could not be processed. Please try again.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$confirmation_token_id = isset( $_POST['chicago_reader_account_b_confirmation_token'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce's own checkout nonce before process_payment runs.
			? sanitize_text_field( wp_unslash( $_POST['chicago_reader_account_b_confirmation_token'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( ! $confirmation_token_id ) {
			wc_add_notice( __( 'Payment could not be processed. Please try again.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$save_payment_method = $this->order_needs_saved_payment_method( $order );
			$stripe              = $this->get_stripe_client();
			$customer_id         = $save_payment_method ? $this->get_or_create_customer_id( $order ) : '';
			$intent              = $this->get_or_create_payment_intent( $order, $customer_id );

			if ( in_array( $intent->status, array( 'requires_payment_method', 'requires_confirmation' ), true ) ) {
				$intent = $stripe->paymentIntents->confirm( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
					$intent->id,
					array(
						'confirmation_token' => $confirmation_token_id,
						'return_url'         => $this->get_return_url( $order ),
					),
					array( 'idempotency_key' => 'chicago-reader-confirm-' . $confirmation_token_id )
				);
			}
		} catch ( \Exception $e ) {
			$this->log_error( 'Initial payment failed', $e, $order->get_id() );
			wc_add_notice( __( 'Payment could not be processed. Please check your payment details and try again.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $intent->id );
		$order->save();

		if ( 'succeeded' === $intent->status ) {
			$this->complete_order_from_intent( $order, $intent );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		if ( 'requires_action' === $intent->status ) {
			return array(
				'result'                      => 'success',
				'redirect'                    => $this->get_return_url( $order ),
				'order_id'                    => $order->get_id(),
				'account_b_requires_action'   => true,
				'account_b_client_secret'     => $intent->client_secret,
				'account_b_payment_intent_id' => $intent->id,
				'account_b_order_key'         => $order->get_order_key(),
			);
		}

		if ( 'processing' === $intent->status ) {
			$order->update_status( 'on-hold', __( 'Stripe is processing the payment.', 'chicago-reader' ) );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		wc_add_notice( __( 'Payment was not completed. Please check your payment details and try again.', 'chicago-reader' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Reuse one PaymentIntent per Woo order, updating its amount before a retry.
	 *
	 * @param \WC_Order $order       WooCommerce order.
	 * @param string    $customer_id Optional Stripe Customer ID.
	 * @return \Stripe\PaymentIntent
	 * @throws \UnexpectedValueException If the order currency changes after payment starts.
	 */
	private function get_or_create_payment_intent( $order, $customer_id = '' ) {
		$stripe            = $this->get_stripe_client();
		$payment_intent_id = $order->get_meta( '_chicago_reader_account_b_payment_intent_id' );
		$amount            = $this->get_stripe_amount( $order->get_total(), $order->get_currency() );
		$currency          = strtolower( $order->get_currency() );

		if ( $payment_intent_id ) {
			$intent = $stripe->paymentIntents->retrieve( $payment_intent_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
			if ( $currency !== strtolower( $intent->currency ) ) {
				throw new \UnexpectedValueException( 'The order currency changed after payment began.' );
			}
			if ( $amount !== (int) $intent->amount && in_array( $intent->status, array( 'requires_payment_method', 'requires_confirmation' ), true ) ) {
				$intent = $stripe->paymentIntents->update( $intent->id, array( 'amount' => $amount ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
			}
			return $intent;
		}

		$intent_args = array(
			'amount'               => $amount,
			'currency'             => $currency,
			'payment_method_types' => array( 'card' ),
			'metadata'             => array( 'order_id' => (string) $order->get_id() ),
		);
		if ( $customer_id ) {
			$intent_args['customer']           = $customer_id;
			$intent_args['setup_future_usage'] = 'off_session';
		}

		$intent = $stripe->paymentIntents->create( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
			$intent_args,
			array( 'idempotency_key' => 'chicago-reader-order-' . $order->get_id() )
		);
		$order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $intent->id );
		$order->save();

		return $intent;
	}

	/**
	 * Find or create a Stripe Customer for the order's account, for future renewal charges.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function get_or_create_customer_id( $order ) {
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
			$subscription  = reset( $subscriptions );
			$existing      = $subscription ? $subscription->get_meta( '_chicago_reader_account_b_customer_id' ) : '';
			if ( $existing ) {
				return $existing;
			}
		}

		$user_id  = $order->get_customer_id();
		$existing = $user_id ? get_user_meta( $user_id, '_chicago_reader_account_b_customer_id', true ) : '';
		if ( $existing ) {
			return $existing;
		}

		$stripe   = $this->get_stripe_client();
		$customer = $stripe->customers->create(
			array( 'email' => $order->get_billing_email() ),
			array( 'idempotency_key' => 'chicago-reader-order-' . $order->get_id() . '-customer' )
		);

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
		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
			$subscription->update_meta_data( '_chicago_reader_account_b_customer_id', $customer_id );
			$subscription->update_meta_data( '_chicago_reader_account_b_payment_method_id', $payment_method_id );
			$subscription->save();
		}
	}

	/**
	 * Whether the order is a subscription signup or renewal payment.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private function order_needs_saved_payment_method( $order ) {
		$contains_subscription = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
		$contains_renewal      = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );

		return $contains_subscription || $contains_renewal;
	}

	/**
	 * Complete an authenticated payment before sending the customer to the
	 * order-received page.
	 */
	public function finalize_payment() {
		check_ajax_referer( 'chicago_reader_account_b_finalize', 'nonce' );

		$order_id          = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key         = isset( $_POST['order_key'] ) ? wc_clean( wp_unslash( $_POST['order_key'] ) ) : '';
		$payment_intent_id = isset( $_POST['payment_intent_id'] ) ? wc_clean( wp_unslash( $_POST['payment_intent_id'] ) ) : '';
		$order             = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'The order could not be verified.', 'chicago-reader' ) ), 403 );
		}

		if ( $this->id !== $order->get_payment_method() || $payment_intent_id !== $order->get_meta( '_chicago_reader_account_b_payment_intent_id' ) ) {
			wp_send_json_error( array( 'message' => __( 'The payment could not be verified.', 'chicago-reader' ) ), 403 );
		}

		try {
			$intent = $this->get_stripe_client()->paymentIntents->retrieve( $payment_intent_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
		} catch ( \Exception $e ) {
			$this->log_error( 'Payment finalization failed', $e, $order_id );
			wp_send_json_error( array( 'message' => __( 'The payment status could not be verified.', 'chicago-reader' ) ), 502 );
		}

		if ( ! $this->payment_intent_matches_order( $intent, $order ) || 'succeeded' !== $intent->status ) {
			wp_send_json_error( array( 'message' => __( 'Payment has not completed.', 'chicago-reader' ) ), 409 );
		}

		$this->complete_order_from_intent( $order, $intent );

		wp_send_json_success( array( 'redirect' => $this->get_return_url( $order ) ) );
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
		} elseif ( 'payment_intent.payment_failed' === $event->type ) {
			$this->fail_payment_intent( $event->data->object );
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
		if ( ! $order || ! $this->payment_intent_matches_order( $payment_intent, $order ) ) {
			return;
		}

		$this->complete_order_from_intent( $order, $payment_intent );
	}

	/**
	 * Verify that a Stripe PaymentIntent belongs to the expected Woo order.
	 *
	 * @param \Stripe\PaymentIntent $payment_intent Stripe PaymentIntent.
	 * @param \WC_Order             $order          WooCommerce order.
	 * @return bool
	 */
	private function payment_intent_matches_order( $payment_intent, $order ) {
		$metadata_order_id = isset( $payment_intent->metadata->order_id ) ? absint( $payment_intent->metadata->order_id ) : 0;

		return $this->id === $order->get_payment_method()
			&& $order->get_id() === $metadata_order_id
			&& $payment_intent->id === $order->get_meta( '_chicago_reader_account_b_payment_intent_id' )
			&& strtolower( $order->get_currency() ) === strtolower( $payment_intent->currency )
			&& $this->get_stripe_amount( $order->get_total(), $order->get_currency() ) === (int) $payment_intent->amount;
	}

	/**
	 * Mark a verified PaymentIntent paid and persist renewal details.
	 *
	 * @param \WC_Order             $order          WooCommerce order.
	 * @param \Stripe\PaymentIntent $payment_intent Stripe PaymentIntent.
	 */
	private function complete_order_from_intent( $order, $payment_intent ) {
		if ( ! $order->is_paid() ) {
			$order->payment_complete( $payment_intent->id );
		}
		$order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $payment_intent->id );
		$order->save();

		if ( $this->order_needs_saved_payment_method( $order ) && $payment_intent->customer && $payment_intent->payment_method ) {
			$this->store_subscription_payment_method( $order, $payment_intent->customer, $payment_intent->payment_method );
		}
	}

	/**
	 * Record an asynchronous failure without disrupting an in-checkout retry.
	 *
	 * @param \Stripe\PaymentIntent $payment_intent Stripe PaymentIntent.
	 */
	private function fail_payment_intent( $payment_intent ) {
		$order_id = isset( $payment_intent->metadata->order_id ) ? absint( $payment_intent->metadata->order_id ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! $this->payment_intent_matches_order( $payment_intent, $order ) ) {
			return;
		}

		$order->add_order_note( __( 'Stripe reported that the payment attempt failed.', 'chicago-reader' ) );
		if ( $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'failed' );
		}
	}

	/**
	 * Add an order note when Stripe reports a refund made directly in the Dashboard.
	 *
	 * @param \Stripe\Charge $charge Stripe Charge.
	 */
	private function note_refund( $charge ) {
		$order_id = isset( $charge->metadata->order_id ) ? absint( $charge->metadata->order_id ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order && $this->id === $order->get_payment_method() && $charge->payment_intent === $order->get_meta( '_chicago_reader_account_b_payment_intent_id' ) ) {
			$order->add_order_note( __( 'Stripe reported a refund for this order.', 'chicago-reader' ) );
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
			$renewal_order->add_order_note( __( 'No saved payment method was found for this renewal.', 'chicago-reader' ) );
			\WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $renewal_order );
			return;
		}

		$stripe = $this->get_stripe_client();

		try {
			$intent = $stripe->paymentIntents->create( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Stripe SDK's own camelCase property.
				array(
					'amount'               => $this->get_stripe_amount( $amount_to_charge, $renewal_order->get_currency() ),
					'currency'             => strtolower( $renewal_order->get_currency() ),
					'customer'             => $customer_id,
					'payment_method'       => $payment_method_id,
					'off_session'          => true,
					'confirm'              => true,
					'payment_method_types' => array( 'card' ),
					'metadata'             => array( 'order_id' => (string) $renewal_order->get_id() ),
				),
				array( 'idempotency_key' => 'chicago-reader-renewal-' . $renewal_order->get_id() )
			);
			$renewal_order->update_meta_data( '_chicago_reader_account_b_payment_intent_id', $intent->id );
			$renewal_order->save();
			if ( 'succeeded' !== $intent->status ) {
				$renewal_order->add_order_note( __( 'Stripe did not complete the automatic renewal payment.', 'chicago-reader' ) );
				\WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $renewal_order );
				return;
			}
			\WC_Subscriptions_Manager::process_subscription_payments_on_order( $renewal_order );
		} catch ( \Exception $e ) {
			$this->log_error( 'Renewal payment failed', $e, $renewal_order->get_id() );
			$renewal_order->add_order_note( __( 'The automatic renewal payment failed.', 'chicago-reader' ) );
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
		if ( ! $order ) {
			return new \WP_Error( 'chicago_reader_account_b_refund', __( 'The order could not be found.', 'chicago-reader' ) );
		}
		$payment_intent_id  = $order->get_meta( '_chicago_reader_account_b_payment_intent_id' );
		if ( ! $payment_intent_id ) {
			return new \WP_Error( 'chicago_reader_account_b_refund', __( 'No Stripe payment was recorded for this order.', 'chicago-reader' ) );
		}

		$stripe      = $this->get_stripe_client();
		$refund_args = array( 'payment_intent' => $payment_intent_id );
		if ( null !== $amount ) {
			$refund_args['amount'] = $this->get_stripe_amount( $amount, $order->get_currency() );
		}
		try {
			$refund_total = $this->get_stripe_amount( $order->get_total_refunded(), $order->get_currency() );
			$stripe->refunds->create(
				$refund_args,
				array( 'idempotency_key' => 'chicago-reader-refund-' . $order_id . '-' . absint( $refund_total ) )
			);
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Refund failed', $e, $order_id );
			return new \WP_Error( 'chicago_reader_account_b_refund', __( 'Stripe could not process the refund.', 'chicago-reader' ) );
		}
	}

	/**
	 * Convert a WooCommerce amount to Stripe's integer minor units.
	 *
	 * The Chicago Reader store uses USD. Keeping this conversion centralized
	 * makes the supported-currency assumption explicit and testable.
	 *
	 * @param float|string $amount   WooCommerce amount.
	 * @param string       $currency Currency code.
	 * @return int
	 */
	private function get_stripe_amount( $amount, $currency ) {
		$zero_decimal_currencies = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
		$multiplier              = in_array( strtolower( $currency ), $zero_decimal_currencies, true ) ? 1 : 100;

		return (int) round( (float) $amount * $multiplier );
	}

	/**
	 * Log a gateway error without exposing provider details to a customer.
	 *
	 * @param string     $message  Safe log message.
	 * @param \Throwable $error    Caught error.
	 * @param int        $order_id Order ID.
	 */
	private function log_error( $message, $error, $order_id ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				$message . ': ' . $error->getMessage(),
				array(
					'source'   => $this->id,
					'order_id' => $order_id,
				)
			);
		}
	}
}
