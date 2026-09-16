<?php
/**
 * Signed, asynchronous Stripe webhook reconciliation.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

// Reconciliation deliberately throws so Action Scheduler retries transient errors.
// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionCommentThrowTag.Missing,Generic.Commenting.DocComment.MissingShort,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Webhook ingress and Action Scheduler worker.
 */
final class Webhook_Handler {

	const ACTION = 'chicago_reader_donation_stripe_process_event';
	const GROUP  = 'chicago-reader-donation-stripe';

	/** @var string[] */
	private static $allowed_events = array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'payment_intent.canceled',
		'setup_intent.succeeded',
		'setup_intent.setup_failed',
		'refund.created',
		'refund.updated',
		'charge.dispute.created',
		'charge.dispute.updated',
		'charge.dispute.closed',
		'payment_method.automatically_updated',
	);

	/** Register the queue worker independently of gateway availability. */
	public static function init() {
		add_action( self::ACTION, array( __CLASS__, 'process' ) );
	}

	/**
	 * Verify a webhook and durably enqueue only its event ID.
	 */
	public static function receive() {
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;
		if ( $length > 1048576 || ! Configuration::is_complete() ) {
			self::respond( 400 );
		}
		$payload   = file_get_contents( 'php://input', false, null, 0, 1048577 ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsRemoteFile -- Stripe signature validation requires the exact raw HTTP body.
		$signature = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';
		if ( ! is_string( $payload ) || '' === $payload || strlen( $payload ) > 1048576 || '' === $signature ) {
			self::respond( 400 );
		}
		try {
			$event = \Stripe\Webhook::constructEvent( $payload, $signature, Configuration::webhook_secret(), 300 );
		} catch ( \Throwable $error ) {
			self::respond( 400 );
		}

		$expected_live = ! Configuration::is_test_mode();
		if ( (bool) $event->livemode !== $expected_live || ! in_array( (string) $event->type, self::$allowed_events, true ) ) {
			self::respond( 200 );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::respond( 503 );
		}
		$action_id = as_enqueue_async_action( self::ACTION, array( 'event_id' => (string) $event->id ), self::GROUP, true );
		if ( ! $action_id ) {
			self::respond( 503 );
		}
		update_option( '_chicago_reader_donation_stripe_last_webhook_received', time(), false );
		self::respond( 200 );
	}

	/**
	 * Retrieve current provider state and reconcile an event once.
	 *
	 * @param string $event_id Stripe event ID.
	 */
	public static function process( $event_id ) {
		if ( ! Configuration::verify_account() || ! preg_match( '/^evt_[A-Za-z0-9]+$/', (string) $event_id ) ) {
			throw new \RuntimeException( 'Donation Stripe event cannot be securely retrieved.' );
		}
		$event = Configuration::client()->events->retrieve( $event_id );
		if ( ! in_array( (string) $event->type, self::$allowed_events, true ) || (bool) $event->livemode !== ! Configuration::is_test_mode() ) {
			return;
		}

		$order = self::resolve_order( $event );
		if ( ! $order ) {
			if ( 0 === strpos( (string) $event->type, 'setup_intent.' ) || 'payment_method.automatically_updated' === $event->type ) {
				$lock = Lock::acquire( 'webhook_event_' . $event->id );
				if ( ! $lock ) {
					throw new \RuntimeException( 'Donation payment-method event is already being reconciled.' );
				}
				try {
					if ( ! get_option( '_chicago_reader_donation_stripe_event_' . $event->id ) ) {
						self::reconcile_without_order( $event );
						add_option( '_chicago_reader_donation_stripe_event_' . $event->id, time(), '', false );
					}
				} finally {
						Lock::release( 'webhook_event_' . $event->id, $lock );
				}
				update_option( '_chicago_reader_donation_stripe_last_webhook_processed', time(), false );
				return;
			}
			throw new \UnexpectedValueException( 'Stripe event has no matching Donation Stripe order.' );
		}

		$lock_name = 'webhook_order_' . $order->get_id();
		$lock      = Lock::acquire( $lock_name );
		if ( ! $lock ) {
			throw new \RuntimeException( 'Donation order is already being reconciled.' );
		}
		try {
			$processed = $order->get_meta( '_chicago_reader_donation_stripe_processed_events' );
			$processed = is_array( $processed ) ? $processed : array();
			if ( in_array( (string) $event->id, $processed, true ) ) {
				return;
			}
			self::reconcile( $event, $order );
			$processed[] = (string) $event->id;
			$order->update_meta_data( '_chicago_reader_donation_stripe_processed_events', array_slice( array_values( array_unique( $processed ) ), -100 ) );
			$order->save();
			update_option( '_chicago_reader_donation_stripe_last_webhook_processed', time(), false );
		} finally {
			Lock::release( $lock_name, $lock );
		}
	}

	/** Resolve an event to an order using provider metadata, never request data. */
	private static function resolve_order( $event ) {
		$object   = $event->data->object;
		$order_id = isset( $object->metadata->order_id ) ? absint( $object->metadata->order_id ) : 0;
		if ( ! $order_id && 'refund' === (string) $object->object && ! empty( $object->payment_intent ) ) {
			$intent   = Configuration::client()->paymentIntents->retrieve( Gateway::provider_id( $object->payment_intent ) );
			$order_id = isset( $intent->metadata->order_id ) ? absint( $intent->metadata->order_id ) : 0;
		}
		if ( ! $order_id && 'dispute' === (string) $object->object && ! empty( $object->payment_intent ) ) {
			$intent   = Configuration::client()->paymentIntents->retrieve( Gateway::provider_id( $object->payment_intent ) );
			$order_id = isset( $intent->metadata->order_id ) ? absint( $intent->metadata->order_id ) : 0;
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		return $order && in_array( $order->get_payment_method(), array( Gateway::ID, Legacy_Gateway::ID ), true ) ? $order : false;
	}

	/** Apply current provider state for one verified event. */
	private static function reconcile( $event, $order ) {
		$gateway = self::gateway();
		$object  = $event->data->object;
		if ( 0 === strpos( (string) $event->type, 'payment_intent.' ) ) {
			$intent = Configuration::client()->paymentIntents->retrieve( $object->id );
			if ( ! $gateway->payment_intent_matches_order( $intent, $order ) ) {
				throw new \UnexpectedValueException( 'PaymentIntent facts do not match the Woo order.' );
			}
			if ( 'succeeded' === $intent->status ) {
				$gateway->complete_order_from_intent( $order, $intent );
			} elseif ( in_array( $intent->status, array( 'canceled', 'requires_payment_method' ), true ) ) {
				$gateway->mark_payment_failed( $order );
			}
			return;
		}
		if ( 0 === strpos( (string) $event->type, 'setup_intent.' ) ) {
			$intent = Configuration::client()->setupIntents->retrieve( $object->id );
			if ( Gateway::ID !== $order->get_payment_method() || (string) $order->get_meta( Gateway::SETUP_INTENT_META ) !== (string) $intent->id ) {
				throw new \UnexpectedValueException( 'SetupIntent does not match the donation order.' );
			}
			if ( 'succeeded' === $intent->status && 0 >= (float) $order->get_total() ) {
				$gateway->complete_zero_setup_order( $order, $intent );
			}
			return;
		}
		if ( 0 === strpos( (string) $event->type, 'refund.' ) ) {
			self::reconcile_refund( Configuration::client()->refunds->retrieve( $object->id ), $order );
			return;
		}
		if ( 0 === strpos( (string) $event->type, 'charge.dispute.' ) ) {
			$status = sanitize_key( (string) $object->status );
			$order->update_meta_data( '_chicago_reader_donation_stripe_dispute_id', sanitize_text_field( (string) $object->id ) );
			$order->update_meta_data( '_chicago_reader_donation_stripe_dispute_status', $status );
			$order->add_order_note( sprintf( /* translators: %s: Stripe dispute status */ __( 'Stripe dispute status: %s. Review this dispute in the donation Stripe account.', 'chicago-reader' ), $status ) );
		}
	}

	/** Reconcile account-level SetupIntents and automatic card updates. */
	private static function reconcile_without_order( $event ) {
		$object = $event->data->object;
		if ( 0 === strpos( (string) $event->type, 'setup_intent.' ) ) {
			$intent = Configuration::client()->setupIntents->retrieve( $object->id );
			if ( 'succeeded' !== (string) $intent->status ) {
				return;
			}
			$user_id = isset( $intent->metadata->wordpress_user_id ) ? absint( $intent->metadata->wordpress_user_id ) : 0;
			$method_id = Gateway::provider_id( $intent->payment_method );
			$customer_id = Gateway::provider_id( $intent->customer );
			if ( ! $user_id || ! $method_id || ! $customer_id ) {
				throw new \UnexpectedValueException( 'SetupIntent is missing customer or method identity.' );
			}
			self::verify_customer( $user_id, $customer_id );
			$method = Configuration::client()->paymentMethods->retrieve( $method_id );
			if ( ! hash_equals( $customer_id, Gateway::provider_id( $method->customer ) ) ) {
				throw new \UnexpectedValueException( 'SetupIntent payment method belongs to another customer.' );
			}
			Token_Manager::upsert( $user_id, $method );
			return;
		}
		$method = Configuration::client()->paymentMethods->retrieve( $object->id );
		$customer_id = Gateway::provider_id( $method->customer );
		if ( ! $customer_id ) {
			return;
		}
		$customer = Configuration::client()->customers->retrieve( $customer_id );
		$user_id = isset( $customer->metadata->wordpress_user_id ) ? absint( $customer->metadata->wordpress_user_id ) : 0;
		if ( ! $user_id ) {
			throw new \UnexpectedValueException( 'Updated card has no matching WordPress customer.' );
		}
		self::verify_customer( $user_id, $customer_id );
		if ( Token_Manager::find_by_payment_method( $user_id, (string) $method->id ) ) {
			Token_Manager::upsert( $user_id, $method );
		}
	}

	/** Match a Stripe Customer to the current mode's Woo user mapping. */
	private static function verify_customer( $user_id, $customer_id ) {
		$key = '_chicago_reader_donation_stripe_customer_id_' . ( Configuration::is_test_mode() ? 'test' : 'live' );
		if ( ! get_user_by( 'id', $user_id ) || ! hash_equals( (string) get_user_meta( $user_id, $key, true ), (string) $customer_id ) ) {
			throw new \UnexpectedValueException( 'Stripe customer does not match the mode-scoped donor.' );
		}
	}

	/** Create a Woo refund for a completed Dashboard-originated Stripe refund. */
	private static function reconcile_refund( $refund, $order ) {
		if ( 'succeeded' !== (string) $refund->status ) {
			return;
		}
		$gateway_refunds = $order->get_meta( '_chicago_reader_donation_stripe_refund_id', false );
		if ( in_array( (string) $refund->id, array_map( 'strval', is_array( $gateway_refunds ) ? $gateway_refunds : array() ), true ) ) {
			// WooCommerce creates its own WC_Order_Refund immediately after the
			// gateway returns true. Never create a second one from its webhook.
			return;
		}
		foreach ( $order->get_refunds() as $woo_refund ) {
			if ( hash_equals( (string) $refund->id, (string) $woo_refund->get_meta( '_chicago_reader_donation_stripe_refund_id' ) ) ) {
				return;
			}
		}
		$amount = (float) $refund->amount / ( in_array( strtolower( $order->get_currency() ), array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ), true ) ? 1 : 100 );
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => __( 'Refund recorded by Donation Stripe', 'chicago-reader' ),
				'refund_payment' => false,
			)
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'WooCommerce could not reconcile the Stripe refund.' );
		}
		$result->update_meta_data( '_chicago_reader_donation_stripe_refund_id', sanitize_text_field( (string) $refund->id ) );
		$result->save();
		$order->add_order_note( __( 'A refund made in the donation Stripe Dashboard was reconciled into WooCommerce.', 'chicago-reader' ) );
	}

	/** Get the registered gateway or a local reconciliation instance. */
	private static function gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ Gateway::ID ] ) ? $gateways[ Gateway::ID ] : new Gateway();
	}

	/** Emit an empty HTTP response and stop WordPress rendering. */
	private static function respond( $status ) {
		status_header( absint( $status ) );
		exit;
	}
}
