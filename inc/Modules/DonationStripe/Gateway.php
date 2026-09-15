<?php
/**
 * Newspack-compatible gateway for the independent donation Stripe account.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

// Stripe SDK service properties intentionally use the SDK's camelCase API.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionCommentThrowTag.Missing,Generic.Commenting.DocComment.MissingShort

/**
 * Donation card and wallet gateway.
 */
class Gateway extends \WC_Payment_Gateway_CC {

	const ID                 = 'chicago_reader_donation_stripe';
	const PAYMENT_INTENT_META = '_chicago_reader_donation_stripe_payment_intent_id';
	const SETUP_INTENT_META   = '_chicago_reader_donation_stripe_setup_intent_id';
	const ACCOUNT_META        = '_chicago_reader_donation_stripe_account_id';
	const MODE_META           = '_chicago_reader_donation_stripe_mode';
	const REVISION_META       = '_chicago_reader_donation_stripe_operation_revision';

	/** @var bool */
	protected $testmode;

	/** @var bool */
	protected $legacy = false;

	/**
	 * Register gateway behavior.
	 *
	 * @param bool $legacy Build the hidden compatibility gateway.
	 */
	public function __construct( $legacy = false ) {
		$this->legacy             = (bool) $legacy;
		$this->id                 = $legacy ? Legacy_Gateway::ID : self::ID;
		$this->method_title       = $legacy ? __( 'Legacy Donation Stripe', 'chicago-reader' ) : __( 'Donation Stripe', 'chicago-reader' );
		$this->method_description = $legacy
			? __( 'Hidden compatibility handler for refunds on old staging orders.', 'chicago-reader' )
			: __( 'Accepts Newspack donations in the independent donation Stripe account. Other WooCommerce products continue using the store gateway.', 'chicago-reader' );
		$this->has_fields         = ! $legacy;
		$this->supports           = $legacy ? array( 'refunds' ) : array(
			'products',
			'refunds',
			'tokenization',
			'add_payment_method',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);

		$this->init_form_fields();
		$this->init_settings();
		$this->enabled     = $legacy ? 'no' : $this->get_option( 'enabled', 'no' );
		$this->title       = $legacy ? __( 'Legacy Donation Stripe', 'chicago-reader' ) : $this->get_option( 'title', __( 'Credit or debit card', 'chicago-reader' ) );
		$this->description = $legacy ? '' : $this->get_option( 'description', '' );
		$this->testmode    = Configuration::is_test_mode();

		if ( $legacy ) {
			return;
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'woocommerce_api_' . $this->id, array( Webhook_Handler::class, 'receive' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );
		add_filter( 'woocommerce_my_subscriptions_payment_method', array( Token_Manager::class, 'subscription_label' ), 10, 2 );
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
		add_action( 'wp_ajax_chicago_reader_donation_stripe_finalize', array( $this, 'finalize_payment' ) );
		add_action( 'wp_ajax_nopriv_chicago_reader_donation_stripe_finalize', array( $this, 'finalize_payment' ) );
		add_action( 'wp_ajax_chicago_reader_donation_stripe_setup', array( $this, 'create_setup_intent' ) );
		add_action( 'profile_update', array( $this, 'sync_customer_email' ), 10, 2 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'sync_billing_email' ), 10, 2 );
	}

	/** {@inheritDoc} */
	public function is_available() {
		if ( $this->legacy || ! parent::is_available() || ! Configuration::is_complete() ) {
			return false;
		}
		if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
			return is_user_logged_in() && 'yes' === $this->get_option( 'saved_methods', 'yes' );
		}
		if ( function_exists( 'wcs_is_payment_change' ) && wcs_is_payment_change() ) {
			return is_user_logged_in();
		}
		return Routing::ALL === Routing::request_state();
	}

	/** {@inheritDoc} */
	public function init_form_fields() {
		if ( $this->legacy ) {
			$this->form_fields = array();
			return;
		}
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'chicago-reader' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Donation Stripe for Newspack donations', 'chicago-reader' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Checkout title', 'chicago-reader' ),
				'type'        => 'text',
				'default'     => __( 'Credit or debit card', 'chicago-reader' ),
				'description' => __( 'The payment method name shown to donors.', 'chicago-reader' ),
			),
			'description'       => array(
				'title'   => __( 'Description', 'chicago-reader' ),
				'type'    => 'textarea',
				'default' => '',
			),
			'testmode'          => array(
				'title'   => __( 'Test mode', 'chicago-reader' ),
				'type'    => 'checkbox',
				'label'   => __( 'Use host-injected test credentials', 'chicago-reader' ),
				'default' => 'yes',
			),
			'saved_methods'     => array(
				'title'   => __( 'Saved methods', 'chicago-reader' ),
				'type'    => 'checkbox',
				'label'   => __( 'Allow donors to save and manage cards', 'chicago-reader' ),
				'default' => 'yes',
			),
			'wallets'           => array(
				'title'   => __( 'Wallets', 'chicago-reader' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer Apple Pay and Google Pay when Stripe reports them available', 'chicago-reader' ),
				'default' => 'yes',
			),
			'logging'           => array(
				'title'   => __( 'Diagnostics', 'chicago-reader' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable sanitized diagnostic logging', 'chicago-reader' ),
				'default' => 'no',
			),
			'connection_status' => array(
				'title'       => __( 'Connection readiness', 'chicago-reader' ),
				'type'        => 'title',
				'description' => function_exists( 'is_admin' ) && is_admin() ? Configuration::status_html() : '',
			),
			'webhook_url'       => array(
				'title'       => __( 'Webhook URL', 'chicago-reader' ),
				'type'        => 'title',
				'description' => '<code>' . esc_html( function_exists( 'WC' ) && WC() ? WC()->api_request_url( self::ID ) : home_url( '/?wc-api=' . self::ID ) ) . '</code>',
			),
		);
	}

	/**
	 * Enqueue Stripe.js on Newspack modal/classic checkout and account forms.
	 */
	public function payment_scripts() {
		$account_page = function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page();
		$checkout     = function_exists( 'is_checkout' ) && is_checkout();
		if ( ! ( $account_page || $checkout ) || ! Configuration::is_complete() ) {
			return;
		}
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe requires loading its unversioned hosted SDK directly.
		$path = __DIR__ . '/assets/checkout.js';
		wp_enqueue_script( 'stripe-chicago-reader-donation', plugins_url( 'assets/checkout.js', __FILE__ ), array( 'jquery', 'stripe-js' ), (string) filemtime( $path ), true );
		wp_localize_script(
			'stripe-chicago-reader-donation',
			'chicagoReaderDonationStripe',
			array(
				'gatewayId'      => self::ID,
				'publishableKey' => Configuration::publishable_key(),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'finalizeNonce'  => wp_create_nonce( 'chicago_reader_donation_stripe_finalize' ),
				'setupNonce'     => wp_create_nonce( 'chicago_reader_donation_stripe_setup' ),
				'walletsEnabled' => 'yes' === $this->get_option( 'wallets', 'yes' ),
				'accountUrl'     => wc_get_account_endpoint_url( 'payment-methods' ),
				'genericError'   => __( 'Payment could not be completed. Please check your details and try again.', 'chicago-reader' ),
			)
		);
	}

	/** {@inheritDoc} */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		if ( is_user_logged_in() && 'yes' === $this->get_option( 'saved_methods', 'yes' ) ) {
			$this->saved_payment_methods();
		}
		$amount        = WC()->cart ? self::to_minor_units( WC()->cart->get_total( 'edit' ), get_woocommerce_currency() ) : 0;
		$future        = class_exists( '\WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription();
		$setup_context = ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) || ( function_exists( 'wcs_is_payment_change' ) && wcs_is_payment_change() );
		$element_context = $setup_context ? 'setup' : ( $future && 0 >= $amount ? 'deferred-setup' : 'payment' );
		$recurring     = $future ? $this->recurring_cart_details() : array();
		?>
		<div class="chicago-reader-donation-stripe-new-method">
			<div id="chicago-reader-donation-stripe-express"
				aria-label="<?php esc_attr_e( 'Express donation payment methods', 'chicago-reader' ); ?>"
				data-recurring-unit="<?php echo esc_attr( $recurring['unit'] ?? '' ); ?>"
				data-recurring-interval="<?php echo esc_attr( $recurring['interval'] ?? '' ); ?>"
				data-recurring-amount="<?php echo esc_attr( $recurring['amount'] ?? '' ); ?>"></div>
			<div id="chicago-reader-donation-stripe-payment-element"
				data-amount="<?php echo esc_attr( $amount ); ?>"
				data-currency="<?php echo esc_attr( strtolower( get_woocommerce_currency() ) ); ?>"
				data-future-usage="<?php echo esc_attr( $future ? 'off_session' : '' ); ?>"
				data-context="<?php echo esc_attr( $element_context ); ?>"></div>
			<?php if ( $future ) : ?>
				<p class="form-row"><small><?php esc_html_e( 'By donating, you authorize Chicago Reader to charge this payment method for future scheduled donations until you cancel.', 'chicago-reader' ); ?></small></p>
			<?php endif; ?>
			<div id="chicago-reader-donation-stripe-errors" role="alert" aria-live="polite"></div>
		</div>
		<input type="hidden" name="chicago_reader_donation_stripe_confirmation_token" id="chicago-reader-donation-stripe-confirmation-token" />
		<input type="hidden" name="chicago_reader_donation_stripe_setup_intent" id="chicago-reader-donation-stripe-setup-intent" />
		<?php
		if ( $setup_context && function_exists( 'wcs_get_users_subscriptions' ) && wcs_get_users_subscriptions( get_current_user_id() ) ) {
			woocommerce_form_field(
				'chicago_reader_donation_stripe_update_all_subscriptions',
				array(
					'type'  => 'checkbox',
					'label' => __( 'Use this method for all of my active donation subscriptions', 'chicago-reader' ),
				)
			);
		}
		if ( is_user_logged_in() && ! $future && 'yes' === $this->get_option( 'saved_methods', 'yes' ) ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Select the donation-specific default without changing another gateway.
	 *
	 * @param \WC_Payment_Token $token Payment token.
	 * @return string
	 */
	public function get_saved_payment_method_option_html( $token ) {
		$default_id = absint( get_user_meta( get_current_user_id(), Token_Manager::DEFAULT_TOKEN_META, true ) );
		$html       = sprintf(
			'<li class="woocommerce-SavedPaymentMethods-token"><input id="wc-%1$s-payment-token-%2$s" type="radio" name="wc-%1$s-payment-token" value="%2$s" style="width:auto" class="woocommerce-SavedPaymentMethods-tokenInput" %4$s /><label for="wc-%1$s-payment-token-%2$s">%3$s</label></li>',
			esc_attr( $this->id ),
			esc_attr( $token->get_id() ),
			esc_html( $token->get_display_name() ),
			checked( $default_id, $token->get_id(), false )
		);
		return apply_filters( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', $html, $token, $this );
	}

	/** {@inheritDoc} */
	public function validate_fields() {
		if ( ! Configuration::verify_account() ) {
			wc_add_notice( __( 'Donation payments are temporarily unavailable because the payment account could not be verified.', 'chicago-reader' ), 'error' );
			return false;
		}
		return true;
	}

	/** {@inheritDoc} */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Routing::is_donation_order( $order ) || ! Configuration::verify_account() ) {
			wc_add_notice( __( 'The donation could not be securely routed. No charge was attempted.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$lock_name = 'payment_' . $order->get_id();
		$lock      = Lock::acquire( $lock_name );
		if ( ! $lock ) {
			wc_add_notice( __( 'This donation is already being processed. Please wait before trying again.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			if ( self::ID !== $order->get_payment_method() ) {
				throw new \UnexpectedValueException( 'Order gateway mismatch.' );
			}
			$setup_intent_id = $this->posted_value( 'chicago_reader_donation_stripe_setup_intent' );
			if ( $setup_intent_id ) {
				return $this->apply_setup_intent_to_order( $order, $setup_intent_id );
			}

			$token = $this->posted_saved_token( $order->get_customer_id() );
			if ( $token ) {
				$payment_method_id = $token->get_token();
				$order->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, $token->get_id() );
				$order->save();
			} else {
				$confirmation_token = $this->posted_value( 'chicago_reader_donation_stripe_confirmation_token' );
				if ( ! $confirmation_token ) {
					throw new \UnexpectedValueException( 'Confirmation token missing.' );
				}
			}

			if ( 0 >= (float) $order->get_total() && $token ) {
				$order->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, $token->get_id() );
				if ( is_a( $order, 'WC_Subscription' ) ) {
					$order->set_payment_method( self::ID );
					$order->save();
				} else {
					Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
					$order->payment_complete();
					$order->save();
				}
				if ( $this->posted_value( 'chicago_reader_donation_stripe_update_all_subscriptions' ) ) {
					Token_Manager::update_active_donation_subscriptions( $order->get_customer_id(), $token->get_id() );
				}
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			if ( 0 >= (float) $order->get_total() ) {
				return $this->setup_zero_total_order( $order, $confirmation_token ?? '' );
			}

			$customer_id = ( $token || $this->order_needs_token( $order ) ) ? $this->get_or_create_customer_id( $order ) : '';
			$intent      = $this->get_or_create_payment_intent( $order, $customer_id );
			if ( in_array( $intent->status, array( 'requires_payment_method', 'requires_confirmation' ), true ) ) {
				$args = array( 'return_url' => $this->get_return_url( $order ) );
				if ( ! empty( $payment_method_id ) ) {
					$args['payment_method'] = $payment_method_id;
				} else {
					$args['confirmation_token'] = $confirmation_token;
				}
				$intent = Configuration::client()->paymentIntents->confirm(
					$intent->id,
					$args,
					array( 'idempotency_key' => $this->idempotency_key( 'confirm', $order, $payment_method_id ?? $confirmation_token ) )
				);
			}
			$order->update_meta_data( self::PAYMENT_INTENT_META, $intent->id );
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
					'result'                            => 'success',
					'redirect'                          => $this->get_return_url( $order ),
					'order_id'                          => $order->get_id(),
					'donation_stripe_requires_action'   => true,
					'donation_stripe_client_secret'     => $intent->client_secret,
					'donation_stripe_payment_intent_id' => $intent->id,
					'donation_stripe_order_key'         => $order->get_order_key(),
				);
			}
			if ( 'processing' === $intent->status ) {
				$order->update_status( 'on-hold', __( 'Stripe is processing the donation.', 'chicago-reader' ) );
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
			throw new \RuntimeException( 'Unexpected PaymentIntent status.' );
		} catch ( \Throwable $error ) {
			$this->log_error( 'Initial donation payment failed', $error, $order_id );
			wc_add_notice( __( 'Payment could not be completed. Please check your payment details and try again.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		} finally {
			Lock::release( $lock_name, $lock );
		}
	}

	/**
	 * Create a SetupIntent for a logged-in My Account/payment-change form.
	 */
	public function create_setup_intent() {
		check_ajax_referer( 'chicago_reader_donation_stripe_setup', 'nonce' );
		if ( ! is_user_logged_in() || ! Configuration::verify_account() ) {
			wp_send_json_error( array( 'message' => __( 'The payment account could not be verified.', 'chicago-reader' ) ), 403 );
		}
		try {
			$user = wp_get_current_user();
			if ( ! $this->within_rate_limit( 'setup-' . $user->ID, 20, HOUR_IN_SECONDS ) ) {
				wp_send_json_error( array( 'message' => __( 'Too many payment-method attempts. Please wait and try again.', 'chicago-reader' ) ), 429 );
			}
			$customer = $this->get_or_create_customer_for_user( $user );
			$operation = $this->posted_value( 'operation' );
			if ( ! preg_match( '/^[a-f0-9-]{20,64}$/i', $operation ) ) {
				throw new \UnexpectedValueException( 'Invalid setup operation identifier.' );
			}
			$intent = Configuration::client()->setupIntents->create(
				array(
					'customer'             => $customer,
					'payment_method_types' => array( 'card' ),
					'usage'                => 'off_session',
					'metadata'             => array( 'wordpress_user_id' => (string) $user->ID ),
				),
				array( 'idempotency_key' => 'cr-ds-setup-user-' . $user->ID . '-' . strtolower( $operation ) )
			);
			wp_send_json_success( array( 'clientSecret' => $intent->client_secret ) );
		} catch ( \Throwable $error ) {
			$this->log_error( 'SetupIntent creation failed', $error, 0 );
			wp_send_json_error( array( 'message' => __( 'A secure payment form could not be started.', 'chicago-reader' ) ), 502 );
		}
	}

	/** {@inheritDoc} */
	public function add_payment_method() {
		if ( ! is_user_logged_in() || ! Configuration::verify_account() ) {
			return array( 'result' => 'failure' );
		}
		try {
			$setup_intent_id = $this->posted_value( 'chicago_reader_donation_stripe_setup_intent' );
			$intent          = Configuration::client()->setupIntents->retrieve( $setup_intent_id );
			$user            = wp_get_current_user();
			if ( 'succeeded' !== $intent->status || ! hash_equals( (string) $user->ID, (string) $intent->metadata->wordpress_user_id ) ) {
				throw new \UnexpectedValueException( 'SetupIntent mismatch.' );
			}
			$expected_customer = $this->get_or_create_customer_for_user( $user );
			if ( ! hash_equals( $expected_customer, self::provider_id( $intent->customer ) ) ) {
				throw new \UnexpectedValueException( 'SetupIntent customer mismatch.' );
			}
			$method = Configuration::client()->paymentMethods->retrieve( self::provider_id( $intent->payment_method ) );
			$token = Token_Manager::upsert( $user->ID, $method );
			if ( $this->posted_value( 'chicago_reader_donation_stripe_update_all_subscriptions' ) ) {
				Token_Manager::update_active_donation_subscriptions( $user->ID, $token->get_id() );
			}
			return array(
				'result'   => 'success',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		} catch ( \Throwable $error ) {
			$this->log_error( 'Adding donation payment method failed', $error, 0 );
			wc_add_notice( __( 'The donation payment method could not be saved.', 'chicago-reader' ), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Verify an authenticated client-side payment and finish its order.
	 */
	public function finalize_payment() {
		check_ajax_referer( 'chicago_reader_donation_stripe_finalize', 'nonce' );
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = $this->posted_value( 'order_key' );
		$intent_id = $this->posted_value( 'payment_intent_id' );
		$setup_id  = $this->posted_value( 'setup_intent_id' );
		$order     = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'The donation could not be verified.', 'chicago-reader' ) ), 403 );
		}
		if ( is_user_logged_in() ) {
			$owns_order = get_current_user_id() === absint( $order->get_customer_id() ) || current_user_can( 'manage_woocommerce' );
		} else {
			$posted_email = sanitize_email( $this->posted_value( 'billing_email' ) );
			$owns_order   = $posted_email && hash_equals( strtolower( (string) $order->get_billing_email() ), strtolower( $posted_email ) );
		}
		if ( ! $owns_order || ! Configuration::verify_account() ) {
			wp_send_json_error( array( 'message' => __( 'The donation could not be verified.', 'chicago-reader' ) ), 403 );
		}
		$provider_match = $intent_id
			? hash_equals( (string) $order->get_meta( self::PAYMENT_INTENT_META ), $intent_id )
			: hash_equals( (string) $order->get_meta( self::SETUP_INTENT_META ), $setup_id );
		if ( ! $provider_match || ( ! $intent_id && ! $setup_id ) ) {
			wp_send_json_error( array( 'message' => __( 'The donation could not be verified.', 'chicago-reader' ) ), 403 );
		}
		if ( ! $this->within_rate_limit( 'finalize-' . $order_id, 10, 10 * MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many verification attempts. Please wait and try again.', 'chicago-reader' ) ), 429 );
		}
		try {
			if ( $setup_id ) {
				$setup_intent = Configuration::client()->setupIntents->retrieve( $setup_id );
				$this->complete_zero_setup_order( $order, $setup_intent );
				wp_send_json_success( array( 'redirect' => $this->get_return_url( $order ) ) );
			}
			$intent = Configuration::client()->paymentIntents->retrieve( $intent_id );
			if ( 'succeeded' !== $intent->status || ! $this->payment_intent_matches_order( $intent, $order ) ) {
				wp_send_json_error( array( 'message' => __( 'The payment has not completed.', 'chicago-reader' ) ), 409 );
			}
			$this->complete_order_from_intent( $order, $intent );
			wp_send_json_success( array( 'redirect' => $this->get_return_url( $order ) ) );
		} catch ( \Throwable $error ) {
			$this->log_error( 'Donation finalization failed', $error, $order_id );
			wp_send_json_error( array( 'message' => __( 'The payment status could not be verified.', 'chicago-reader' ) ), 502 );
		}
	}

	/**
	 * Scheduled off-session WCS renewal.
	 *
	 * @param float     $amount Renewal amount.
	 * @param \WC_Order $order  Renewal order.
	 */
	public function process_subscription_payment( $amount, $order ) {
		$lock_name = 'renewal_' . $order->get_id();
		$lock      = Lock::acquire( $lock_name );
		if ( ! $lock ) {
			$order->add_order_note( __( 'A duplicate donation renewal attempt was blocked.', 'chicago-reader' ) );
			return;
		}
		try {
			if ( ! Configuration::verify_account() ) {
				throw new \RuntimeException( 'Stripe account verification failed.' );
			}
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
			$subscription  = reset( $subscriptions );
			if ( ! $subscription || self::ID !== $subscription->get_payment_method() ) {
				throw new \UnexpectedValueException( 'Renewal subscription gateway mismatch.' );
			}
			$token = Token_Manager::get_valid( $subscription->get_meta( Token_Manager::SUBSCRIPTION_TOKEN_META ), $subscription->get_customer_id() );
			if ( ! $token ) {
				throw new \UnexpectedValueException( 'No valid donation payment token.' );
			}
			$customer_id = $this->customer_id_for_user( $subscription->get_customer_id() );
			if ( ! $customer_id ) {
				throw new \UnexpectedValueException( 'No donation Stripe customer is stored for the subscriber.' );
			}
			$intent      = Configuration::client()->paymentIntents->create(
				array(
					'amount'               => self::to_minor_units( $amount, $order->get_currency() ),
					'currency'             => strtolower( $order->get_currency() ),
					'customer'             => $customer_id,
					'payment_method'       => $token->get_token(),
					'payment_method_types' => array( 'card' ),
					'off_session'          => true,
					'confirm'              => true,
					'metadata'             => $this->metadata_for_order( $order ),
				),
				array( 'idempotency_key' => $this->idempotency_key( 'renewal', $order, $token->get_token() ) )
			);
			$order->update_meta_data( self::PAYMENT_INTENT_META, $intent->id );
			$order->update_meta_data( self::ACCOUNT_META, Configuration::expected_account_id() );
			$order->update_meta_data( self::MODE_META, Configuration::is_test_mode() ? 'test' : 'live' );
			$order->save();
			if ( 'succeeded' !== $intent->status ) {
				throw new \RuntimeException( 'Renewal did not reach succeeded state.' );
			}
			\WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
		} catch ( \Throwable $error ) {
			$stripe_error = method_exists( $error, 'getError' ) ? $error->getError() : null;
			$failed       = $stripe_error && ! empty( $stripe_error->payment_intent ) ? $stripe_error->payment_intent : null;
			$failed_id    = is_object( $failed ) ? (string) $failed->id : (string) $failed;
			if ( $failed_id ) {
				$failed_intent = is_object( $failed ) ? $failed : Configuration::client()->paymentIntents->retrieve( $failed_id );
				$order->update_meta_data( self::PAYMENT_INTENT_META, $failed_id );
				if ( 'requires_action' === (string) $failed_intent->status ) {
					$order->update_meta_data( '_chicago_reader_donation_stripe_authentication_required', 'yes' );
				} elseif ( 'requires_payment_method' === (string) $failed_intent->status ) {
					$order->update_meta_data( self::REVISION_META, max( 1, absint( $order->get_meta( self::REVISION_META ) ) ) + 1 );
				}
				$order->save();
			}
			$this->log_error( 'Donation renewal failed', $error, $order->get_id() );
			$order->add_order_note( __( 'The automatic donation renewal failed. The donor can update the donation payment method in My Account.', 'chicago-reader' ) );
			\WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order );
		} finally {
			Lock::release( $lock_name, $lock );
		}
	}

	/**
	 * Copy a replacement token from a successful retry order.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param \WC_Order        $renewal_order Retry order.
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {
		$token_id = $renewal_order->get_meta( Token_Manager::SUBSCRIPTION_TOKEN_META );
		if ( Token_Manager::get_valid( $token_id, $subscription->get_customer_id() ) ) {
			$subscription->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, absint( $token_id ) );
			$subscription->save();
		}
	}

	/**
	 * Add editable admin subscription payment metadata.
	 *
	 * @param array            $payment_meta Existing metadata.
	 * @param \WC_Subscription $subscription Subscription.
	 * @return array
	 */
	public function subscription_payment_meta( $payment_meta, $subscription ) {
		$payment_meta[ self::ID ] = array(
			'post_meta' => array(
				Token_Manager::SUBSCRIPTION_TOKEN_META => array(
					'value' => $subscription->get_meta( Token_Manager::SUBSCRIPTION_TOKEN_META ),
					'label' => __( 'Donation payment token ID', 'chicago-reader' ),
				),
			),
		);
		return $payment_meta;
	}

	/**
	 * Validate an admin-entered subscription token.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param array  $payment_meta Submitted metadata.
	 * @throws \InvalidArgumentException Invalid token.
	 */
	public function validate_subscription_payment_meta( $gateway_id, $payment_meta ) {
		if ( self::ID !== $gateway_id || empty( $payment_meta['post_meta'][ Token_Manager::SUBSCRIPTION_TOKEN_META ]['value'] ) ) {
			return;
		}
		// Classic subscription screens submit post_ID; HPOS screens submit id.
		// WCS verifies the enclosing admin nonce before running this validation hook.
		$subscription_id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $subscription_id && isset( $_POST['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$subscription_id = absint( $_POST['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$subscription    = $subscription_id ? wcs_get_subscription( $subscription_id ) : false;
		$token_id        = absint( $payment_meta['post_meta'][ Token_Manager::SUBSCRIPTION_TOKEN_META ]['value'] );
		if ( ! $subscription || ! Token_Manager::get_valid( $token_id, $subscription->get_customer_id() ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The donation payment token is invalid for this subscriber or Stripe account.', 'chicago-reader' ) );
		}
	}

	/** {@inheritDoc} */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! in_array( $order->get_payment_method(), array( self::ID, Legacy_Gateway::ID ), true ) ) {
			return new \WP_Error( 'donation_stripe_refund', __( 'This is not a Donation Stripe order.', 'chicago-reader' ) );
		}
		$intent_id = $order->get_meta( self::PAYMENT_INTENT_META ) ? $order->get_meta( self::PAYMENT_INTENT_META ) : $order->get_meta( '_chicago_reader_account_b_payment_intent_id' );
		if ( ! $intent_id || ! Configuration::verify_account() ) {
			return new \WP_Error( 'donation_stripe_refund', __( 'The Stripe payment or account could not be verified.', 'chicago-reader' ) );
		}
		$minor     = null === $amount ? self::to_minor_units( $order->get_remaining_refund_amount(), $order->get_currency() ) : self::to_minor_units( $amount, $order->get_currency() );
		$lock_name = 'refund_' . $order_id;
		$lock      = Lock::acquire( $lock_name );
		if ( ! $lock ) {
			return new \WP_Error( 'donation_stripe_refund', __( 'Another refund operation is already in progress.', 'chicago-reader' ) );
		}
		try {
			$intent = Configuration::client()->paymentIntents->retrieve( $intent_id );
			if ( ! $this->provider_object_matches_order( $intent, $order, false ) || $minor <= 0 ) {
				throw new \UnexpectedValueException( 'Refund validation failed.' );
			}
			$charge_id = is_object( $intent->latest_charge ) ? (string) $intent->latest_charge->id : (string) $intent->latest_charge;
			$charge    = $charge_id ? Configuration::client()->charges->retrieve( $charge_id ) : null;
			$remaining = $charge ? (int) $charge->amount - (int) $charge->amount_refunded : 0;
			if ( $minor > $remaining ) {
				throw new \UnexpectedValueException( 'Refund exceeds the provider refundable balance.' );
			}
			$refund = Configuration::client()->refunds->create(
				array(
					'payment_intent' => $intent_id,
					'amount'         => $minor,
					'reason'         => 'requested_by_customer',
					'metadata'       => array( 'order_id' => (string) $order_id ),
				),
				array( 'idempotency_key' => 'cr-ds-refund-' . $order_id . '-' . $minor . '-' . self::to_minor_units( $order->get_total_refunded(), $order->get_currency() ) )
			);
			$order->add_meta_data( '_chicago_reader_donation_stripe_refund_id', $refund->id, false );
			$order->save();
			return true;
		} catch ( \Throwable $error ) {
			$this->log_error( 'Donation refund failed', $error, $order_id );
			return new \WP_Error( 'donation_stripe_refund', __( 'Stripe could not process this refund.', 'chicago-reader' ) );
		} finally {
			Lock::release( $lock_name, $lock );
		}
	}

	/**
	 * Complete a verified PaymentIntent.
	 *
	 * @param \WC_Order             $order  Order.
	 * @param \Stripe\PaymentIntent $intent PaymentIntent.
	 */
	public function complete_order_from_intent( $order, $intent ) {
		if ( ! $this->payment_intent_matches_order( $intent, $order ) || 'succeeded' !== $intent->status ) {
			return;
		}
		if ( ! $order->is_paid() ) {
			$order->payment_complete( $intent->id );
		}
		$order->update_meta_data( self::PAYMENT_INTENT_META, $intent->id );
		$order->update_meta_data( self::ACCOUNT_META, Configuration::expected_account_id() );
		$order->update_meta_data( self::MODE_META, Configuration::is_test_mode() ? 'test' : 'live' );
		$order->delete_meta_data( '_chicago_reader_donation_stripe_authentication_required' );
		if ( $this->order_needs_token( $order ) && $intent->payment_method && $order->get_customer_id() ) {
			try {
				$method = Configuration::client()->paymentMethods->retrieve( self::provider_id( $intent->payment_method ) );
				$token  = Token_Manager::upsert( $order->get_customer_id(), $method );
				$order->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, $token->get_id() );
				$order->delete_meta_data( '_chicago_reader_donation_stripe_tokenization_failed' );
				Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
			} catch ( \Throwable $error ) {
				$order->update_meta_data( '_chicago_reader_donation_stripe_tokenization_failed', 'yes' );
				$order->add_order_note( __( 'The donation was paid, but its reusable payment method could not be stored. Review before the next renewal.', 'chicago-reader' ) );
				$this->log_error( 'Donation tokenization after payment failed', $error, $order->get_id() );
			}
		}
		$order->save();
	}

	/**
	 * Match a PaymentIntent to its immutable Woo order facts.
	 *
	 * @param object    $intent Stripe object.
	 * @param \WC_Order $order  Order.
	 * @return bool
	 */
	public function payment_intent_matches_order( $intent, $order ) {
		return $this->provider_object_matches_order( $intent, $order, true )
			&& self::to_minor_units( $order->get_total(), $order->get_currency() ) === (int) $intent->amount;
	}

	/**
	 * Shared provider-object/order checks.
	 *
	 * @param object    $object       Stripe object.
	 * @param \WC_Order $order        Order.
	 * @param bool      $require_meta Require current intent meta.
	 * @return bool
	 */
	public function provider_object_matches_order( $object, $order, $require_meta = true ) {
		$order_id = isset( $object->metadata->order_id ) ? absint( $object->metadata->order_id ) : 0;
		if ( $order->get_id() !== $order_id || strtolower( $order->get_currency() ) !== strtolower( (string) ( $object->currency ?? '' ) ) ) {
			return false;
		}
		if ( ! in_array( $order->get_payment_method(), array( self::ID, Legacy_Gateway::ID ), true ) ) {
			return false;
		}
		$stored_intent = $order->get_meta( self::PAYMENT_INTENT_META );
		if ( ! $stored_intent && Legacy_Gateway::ID === $order->get_payment_method() ) {
			$stored_intent = $order->get_meta( '_chicago_reader_account_b_payment_intent_id' );
		}
		return ! $require_meta || hash_equals( (string) $stored_intent, (string) $object->id );
	}

	/**
	 * Record a failed asynchronous attempt without exposing Stripe error text.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function mark_payment_failed( $order ) {
		if ( ! $order->is_paid() ) {
			$order->add_order_note( __( 'Stripe reported that the donation payment attempt failed.', 'chicago-reader' ) );
			if ( $order->has_status( 'on-hold' ) ) {
				$order->update_status( 'failed' );
			}
		}
	}

	/** Convert currency to Stripe minor units. */
	public static function to_minor_units( $amount, $currency ) {
		$zero_decimal = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
		return (int) round( (float) $amount * ( in_array( strtolower( $currency ), $zero_decimal, true ) ? 1 : 100 ) );
	}

	/** Normalize an expandable Stripe relationship to its provider ID. */
	public static function provider_id( $value ) {
		return is_object( $value ) && isset( $value->id ) ? (string) $value->id : (string) $value;
	}

	/**
	 * Build or reuse one PaymentIntent per order.
	 */
	private function get_or_create_payment_intent( $order, $customer_id ) {
		$client    = Configuration::client();
		$intent_id = $order->get_meta( self::PAYMENT_INTENT_META );
		$amount    = self::to_minor_units( $order->get_total(), $order->get_currency() );
		if ( $intent_id ) {
			$intent = $client->paymentIntents->retrieve( $intent_id );
			if ( ! $this->provider_object_matches_order( $intent, $order, false ) ) {
				throw new \UnexpectedValueException( 'Stored PaymentIntent does not match the order.' );
			}
			if ( in_array( $intent->status, array( 'requires_payment_method', 'requires_confirmation' ), true ) && (int) $intent->amount !== $amount ) {
				$intent = $client->paymentIntents->update(
					$intent->id,
					array( 'amount' => $amount ),
					array( 'idempotency_key' => $this->idempotency_key( 'update-amount', $order, (string) $amount ) )
				);
			}
			return $intent;
		}

		$args = array(
			'amount'               => $amount,
			'currency'             => strtolower( $order->get_currency() ),
			'payment_method_types' => array( 'card' ),
			'metadata'             => $this->metadata_for_order( $order ),
		);
		if ( $customer_id ) {
			$args['customer']           = $customer_id;
			$args['setup_future_usage'] = 'off_session';
		}
		$intent = $client->paymentIntents->create( $args, array( 'idempotency_key' => $this->idempotency_key( 'create', $order ) ) );
		$order->update_meta_data( self::PAYMENT_INTENT_META, $intent->id );
		$order->update_meta_data( self::ACCOUNT_META, Configuration::expected_account_id() );
		$order->update_meta_data( self::MODE_META, Configuration::is_test_mode() ? 'test' : 'live' );
		$order->save();
		return $intent;
	}

	/**
	 * Use a client-confirmed SetupIntent for a payment-method change.
	 */
	private function apply_setup_intent_to_order( $order, $setup_intent_id ) {
		$intent = Configuration::client()->setupIntents->retrieve( $setup_intent_id );
		if ( 'succeeded' !== $intent->status || ! $order->get_customer_id() ) {
			throw new \UnexpectedValueException( 'SetupIntent is incomplete.' );
		}
		if ( ! hash_equals( $this->customer_id_for_user( $order->get_customer_id() ), self::provider_id( $intent->customer ) ) ) {
			throw new \UnexpectedValueException( 'SetupIntent owner mismatch.' );
		}
		$method = Configuration::client()->paymentMethods->retrieve( self::provider_id( $intent->payment_method ) );
		$token  = Token_Manager::upsert( $order->get_customer_id(), $method );
		$order->set_payment_method( self::ID );
		$order->update_meta_data( self::SETUP_INTENT_META, $intent->id );
		$order->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, $token->get_id() );
		$order->save();
		if ( is_a( $order, 'WC_Subscription' ) ) {
			$order->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, $token->get_id() );
			$order->save();
		} else {
			Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
		}
		if ( $this->posted_value( 'chicago_reader_donation_stripe_update_all_subscriptions' ) ) {
			Token_Manager::update_active_donation_subscriptions( $order->get_customer_id(), $token->get_id() );
		}
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Set up a reusable method when an initial order has no amount due.
	 */
	private function setup_zero_total_order( $order, $confirmation_token ) {
		$customer_id = $this->get_or_create_customer_id( $order );
		$intent      = Configuration::client()->setupIntents->create(
			array(
				'customer'             => $customer_id,
				'confirmation_token'   => $confirmation_token,
				'confirm'              => true,
				'payment_method_types' => array( 'card' ),
				'usage'                => 'off_session',
				'metadata'             => $this->metadata_for_order( $order ),
			),
			array( 'idempotency_key' => $this->idempotency_key( 'zero-setup', $order, $confirmation_token ) )
		);
		$order->update_meta_data( self::SETUP_INTENT_META, $intent->id );
		$order->save();
		if ( 'requires_action' === $intent->status ) {
			return array(
				'result'                          => 'success',
				'redirect'                        => $this->get_return_url( $order ),
				'order_id'                        => $order->get_id(),
				'donation_stripe_requires_action' => true,
				'donation_stripe_is_setup'        => true,
				'donation_stripe_client_secret'   => $intent->client_secret,
				'donation_stripe_setup_intent_id' => $intent->id,
				'donation_stripe_order_key'       => $order->get_order_key(),
			);
		}
		if ( 'succeeded' !== $intent->status ) {
			throw new \RuntimeException( 'Zero-total SetupIntent did not succeed.' );
		}
		$this->complete_zero_setup_order( $order, $intent );
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/** Finish a verified zero-total order and store its reusable method. */
	private function complete_zero_setup_order( $order, $intent ) {
		if ( 'succeeded' !== (string) $intent->status || ! hash_equals( (string) $order->get_meta( self::SETUP_INTENT_META ), (string) $intent->id ) ) {
			throw new \UnexpectedValueException( 'SetupIntent does not match the zero-total order.' );
		}
		if ( ! isset( $intent->metadata->order_id ) || absint( $intent->metadata->order_id ) !== $order->get_id() ) {
			throw new \UnexpectedValueException( 'SetupIntent order metadata does not match.' );
		}
		if ( ! hash_equals( $this->customer_id_for_user( $order->get_customer_id() ), self::provider_id( $intent->customer ) ) ) {
			throw new \UnexpectedValueException( 'SetupIntent customer does not match the order.' );
		}
		$method = Configuration::client()->paymentMethods->retrieve( self::provider_id( $intent->payment_method ) );
		$token  = Token_Manager::upsert( $order->get_customer_id(), $method );
		$order->update_meta_data( Token_Manager::SUBSCRIPTION_TOKEN_META, $token->get_id() );
		Token_Manager::attach_to_order_subscriptions( $order, $token->get_id() );
		$order->payment_complete();
		$order->save();
	}

	/** Get/create customer for an order that must have a Woo account. */
	private function get_or_create_customer_id( $order ) {
		if ( ! $order->get_customer_id() ) {
			throw new \UnexpectedValueException( 'Recurring or saved donations require a WooCommerce customer.' );
		}
		$user = get_user_by( 'id', $order->get_customer_id() );
		return $this->get_or_create_customer_for_user( $user, $order->get_billing_email() );
	}

	/** Get/create a mode-scoped Stripe Customer. */
	private function get_or_create_customer_for_user( $user, $email = '' ) {
		if ( ! $user ) {
			throw new \UnexpectedValueException( 'A WordPress customer is required.' );
		}
		$existing = $this->customer_id_for_user( $user->ID );
		if ( $existing ) {
			return $existing;
		}
		$customer = Configuration::client()->customers->create(
			array(
				'email'    => sanitize_email( $email ? $email : $user->user_email ),
				'metadata' => array( 'wordpress_user_id' => (string) $user->ID ),
			),
			array( 'idempotency_key' => 'cr-ds-customer-' . ( Configuration::is_test_mode() ? 'test-' : 'live-' ) . $user->ID )
		);
		update_user_meta( $user->ID, $this->customer_meta_key(), $customer->id );
		return $customer->id;
	}

	/** Get stored mode-scoped Stripe customer. */
	private function customer_id_for_user( $user_id ) {
		return (string) get_user_meta( absint( $user_id ), $this->customer_meta_key(), true );
	}

	/** Mode-scoped customer meta key. */
	private function customer_meta_key() {
		return '_chicago_reader_donation_stripe_customer_id_' . ( Configuration::is_test_mode() ? 'test' : 'live' );
	}

	/** Synchronize a changed WordPress email to this account only. */
	public function sync_customer_email( $user_id, $old_user_data ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || $user->user_email === $old_user_data->user_email || ! Configuration::verify_account() ) {
			return;
		}
		$customer_id = $this->customer_id_for_user( $user_id );
		if ( $customer_id ) {
			try {
				$email = sanitize_email( $user->user_email );
				Configuration::client()->customers->update(
					$customer_id,
					array( 'email' => $email ),
					array( 'idempotency_key' => 'cr-ds-email-' . $user_id . '-' . substr( hash( 'sha256', $email ), 0, 16 ) )
				);
			} catch ( \Throwable $error ) {
				$this->log_error( 'Donation customer email sync failed', $error, 0 );
			}
		}
	}

	/** Synchronize the Woo billing email to the donation customer. */
	public function sync_billing_email( $user_id, $address_type ) {
		if ( 'billing' !== $address_type || ! Configuration::verify_account() ) {
			return;
		}
		$customer_id = $this->customer_id_for_user( $user_id );
		$customer    = new \WC_Customer( $user_id );
		if ( $customer_id && $customer->get_billing_email() ) {
			try {
				$email = sanitize_email( $customer->get_billing_email() );
				Configuration::client()->customers->update(
					$customer_id,
					array( 'email' => $email ),
					array( 'idempotency_key' => 'cr-ds-billing-email-' . $user_id . '-' . substr( hash( 'sha256', $email ), 0, 16 ) )
				);
			} catch ( \Throwable $error ) {
				$this->log_error( 'Donation billing email sync failed', $error, 0 );
			}
		}
	}

	/** Whether order requires a reusable method. */
	private function order_needs_token( $order ) {
		$subscription = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
		$renewal      = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
		$save         = is_user_logged_in() && $this->posted_value( 'wc-' . self::ID . '-new-payment-method' );
		return $subscription || $renewal || $save;
	}

	/** Return Apple Pay merchant-token recurrence details for the donation cart. */
	private function recurring_cart_details() {
		if ( ! WC()->cart || ! class_exists( '\WC_Subscriptions_Product' ) ) {
			return array();
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? false;
			if ( $product && \WC_Subscriptions_Product::is_subscription( $product ) ) {
				return array(
					'unit'     => \WC_Subscriptions_Product::get_period( $product ),
					'interval' => max( 1, absint( \WC_Subscriptions_Product::get_interval( $product ) ) ),
					'amount'   => self::to_minor_units( WC()->cart->get_total( 'edit' ), get_woocommerce_currency() ),
				);
			}
		}
		return array();
	}

	/** Retrieve a selected, owned saved token. */
	private function posted_saved_token( $user_id ) {
		$value = $this->posted_value( 'wc-' . self::ID . '-payment-token' );
		return $value && 'new' !== $value ? Token_Manager::get_valid( absint( $value ), $user_id ) : false;
	}

	/** Sanitize a posted scalar after Woo's checkout nonce verification. */
	private function posted_value( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The dynamic scalar is sanitized immediately; Woo verifies the parent form nonce.
	}

	/** Apply a privacy-preserving, best-effort request rate limit. */
	private function within_rate_limit( $operation, $limit, $window ) {
		$ip  = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
		$ip  = is_string( $ip ) ? $ip : '';
		$key = 'cr_ds_rate_' . md5( wp_salt( 'nonce' ) . '|' . $operation . '|' . $ip );
		$hits = absint( get_transient( $key ) ) + 1;
		set_transient( $key, $hits, absint( $window ) );
		return $hits <= absint( $limit );
	}

	/** Build stable idempotency keys without placing PII in headers. */
	private function idempotency_key( $operation, $order, $detail = '' ) {
		$revision = max( 1, absint( $order->get_meta( self::REVISION_META ) ) );
		if ( ! $order->get_meta( self::REVISION_META ) ) {
			$order->update_meta_data( self::REVISION_META, $revision );
			$order->save();
		}
		return 'cr-ds-' . sanitize_key( $operation ) . '-' . $order->get_id() . '-' . $revision . '-' . substr( hash( 'sha256', (string) $detail ), 0, 16 );
	}

	/** Preserve Newspack transaction metadata where its adapter is available. */
	private function metadata_for_order( $order ) {
		$metadata = array( 'order_id' => (string) $order->get_id() );
		if ( class_exists( '\Newspack\WooCommerce_Gateway_Stripe' ) && method_exists( '\Newspack\WooCommerce_Gateway_Stripe', 'add_intent_metadata' ) ) {
			$metadata = \Newspack\WooCommerce_Gateway_Stripe::add_intent_metadata( $metadata, $order );
		}
		return array_map( 'strval', array_slice( $metadata, 0, 50, true ) );
	}

	/** Log an opaque exception class/code only when diagnostics are enabled. */
	private function log_error( $message, $error, $order_id ) {
		if ( 'yes' !== $this->get_option( 'logging', 'no' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->error(
			$message,
			array(
				'source'      => self::ID,
				'order_id'    => absint( $order_id ),
				'error_class' => get_class( $error ),
				'error_code'  => (int) $error->getCode(),
			)
		);
	}
}
