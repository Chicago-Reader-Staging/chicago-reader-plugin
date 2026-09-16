<?php
/**
 * Runtime configuration for the Donation Stripe account.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

// The compact accessors below are deliberately self-describing.
// phpcs:disable Generic.Commenting.DocComment.MissingShort

/**
 * Reads payment credentials exclusively from host-injected constants.
 */
final class Configuration {

	const API_VERSION = '2026-08-26.dahlia';
	// Independently verified against the Reader Institute Stripe test account.
	// This must not be sourced from the same deployment secrets as the API key.
	const APPROVED_TEST_ACCOUNT_ID = 'acct_1F24k4LAprqx9n8w';
	// Production remains unavailable until its account identity is reviewed.
	const APPROVED_LIVE_ACCOUNT_ID = '';

	/**
	 * Whether the gateway settings select test mode.
	 *
	 * @return bool
	 */
	public static function is_test_mode() {
		$settings = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
		return 'no' !== ( $settings['testmode'] ?? 'yes' );
	}

	/**
	 * Get an active-mode constant without exposing it through WordPress options.
	 *
	 * @param string $suffix Constant suffix.
	 * @return string
	 */
	private static function get_mode_value( $suffix ) {
		$mode = self::is_test_mode() ? 'TEST' : 'LIVE';
		$name = 'CHICAGO_READER_DONATION_STRIPE_' . $mode . '_' . $suffix;
		return defined( $name ) && is_string( constant( $name ) ) ? trim( constant( $name ) ) : '';
	}

	/** @return string */
	public static function publishable_key() {
		return self::get_mode_value( 'PUBLISHABLE_KEY' );
	}

	/** @return string */
	public static function secret_key() {
		return self::get_mode_value( 'SECRET_KEY' );
	}

	/** @return string */
	public static function webhook_secret() {
		return self::get_mode_value( 'WEBHOOK_SECRET' );
	}

	/** @return string */
	public static function expected_account_id() {
		return self::get_mode_value( 'ACCOUNT_ID' );
	}

	/**
	 * Accept Stripe's standard or least-privilege restricted server keys.
	 *
	 * @param string $key       Candidate secret key.
	 * @param bool   $test_mode Whether test mode is selected.
	 * @return bool
	 */
	public static function valid_secret_key_format( $key, $test_mode ) {
		$prefix = $test_mode ? '_test_' : '_live_';
		return 0 === strpos( (string) $key, 'sk' . $prefix ) || 0 === strpos( (string) $key, 'rk' . $prefix );
	}

	/**
	 * Whether an account ID is independently approved for the selected mode.
	 *
	 * @param string $account_id Stripe account ID.
	 * @param bool   $test_mode  Whether the request uses test mode.
	 * @return bool
	 */
	public static function is_approved_account_id( $account_id, $test_mode ) {
		$approved = $test_mode ? self::APPROVED_TEST_ACCOUNT_ID : self::APPROVED_LIVE_ACCOUNT_ID;
		return '' !== $approved && hash_equals( $approved, (string) $account_id );
	}

	/** The deployment account ID must match an independently approved identity. */
	public static function account_id_is_approved() {
		return self::is_approved_account_id( self::expected_account_id(), self::is_test_mode() );
	}

	/**
	 * Never accept a new donation charge in the store's regular Stripe account.
	 * Use the same account cache that WooCommerce Stripe 11.0.0 uses in its
	 * System Status report. An unknown store account fails closed.
	 *
	 * @return bool
	 */
	public static function has_distinct_store_account() {
		if ( ! self::account_id_is_approved() ) {
			return false;
		}
		if ( ! class_exists( '\\WC_Stripe' ) || ! method_exists( '\\WC_Stripe', 'get_instance' ) ) {
			return false;
		}
		try {
			$stripe = \WC_Stripe::get_instance();
			if ( ! isset( $stripe->account ) || ! method_exists( $stripe->account, 'get_cached_account_data' ) ) {
				return false;
			}
			// Compare like-for-like modes, even when the store gateway's UI is
			// currently switched to a different mode.
			$account = $stripe->account->get_cached_account_data( self::is_test_mode() ? 'test' : 'live' );
			$id      = is_array( $account ) && isset( $account['id'] ) ? (string) $account['id'] : '';
			return 0 === strpos( $id, 'acct_' ) && ! hash_equals( $id, self::expected_account_id() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** @return bool */
	public static function scheduler_healthy() {
		$last_run = absint( get_option( '_chicago_reader_donation_stripe_last_scheduler_canary', 0 ) );
		return function_exists( 'as_enqueue_async_action' )
			&& $last_run > time() - DAY_IN_SECONDS
			&& $last_run <= time();
	}

	/** New payment operations require both account isolation and a working queue. */
	public static function new_payments_ready() {
		return self::is_complete() && self::has_distinct_store_account() && self::scheduler_healthy() && self::verify_account();
	}

	/**
	 * Live payments need an explicit host-side switch and approved hostname.
	 *
	 * @return bool
	 */
	public static function live_mode_allowed() {
		if ( self::is_test_mode() ) {
			return true;
		}

		if ( ! defined( 'CHICAGO_READER_DONATION_STRIPE_LIVE_ALLOWED' ) || true !== CHICAGO_READER_DONATION_STRIPE_LIVE_ALLOWED ) {
			return false;
		}

		$allowed = defined( 'CHICAGO_READER_DONATION_STRIPE_LIVE_HOSTS' )
			? array_filter( array_map( 'trim', explode( ',', (string) CHICAGO_READER_DONATION_STRIPE_LIVE_HOSTS ) ) )
			: array( 'chicagoreader.com', 'www.chicagoreader.com' );
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';

		return in_array( $host, array_map( 'strtolower', $allowed ), true );
	}

	/**
	 * Whether all mandatory credentials are present and mode-consistent.
	 *
	 * @return bool
	 */
	public static function is_complete() {
		$publishable = self::publishable_key();
		$secret      = self::secret_key();
		$webhook     = self::webhook_secret();
		$account_id  = self::expected_account_id();
		$prefix      = self::is_test_mode() ? '_test_' : '_live_';

		return self::live_mode_allowed()
			&& self::account_id_is_approved()
			&& 0 === strpos( $publishable, 'pk' . $prefix )
			&& self::valid_secret_key_format( $secret, self::is_test_mode() )
			&& 0 === strpos( $webhook, 'whsec_' )
			&& 0 === strpos( $account_id, 'acct_' );
	}

	/**
	 * Build an isolated Stripe client with a pinned API version.
	 *
	 * @return \Stripe\StripeClient
	 * @throws \RuntimeException Missing configuration.
	 */
	public static function client() {
		if ( ! self::is_complete() ) {
			throw new \RuntimeException( 'Donation Stripe is not completely configured.' );
		}

		try {
			return new \Stripe\StripeClient(
				array(
					'api_key'        => self::secret_key(),
					'stripe_version' => self::API_VERSION,
				)
			);
		} catch ( \TypeError $error ) {
			/*
			 * Some WordPress payment extensions eagerly load an older global
			 * stripe-php client whose constructor accepts only the API key.
			 * Stripe still applies the account's API version to these requests;
			 * the direct account check above remains explicitly version-pinned.
			 */
			return new \Stripe\StripeClient( self::secret_key() );
		}
	}

	/**
	 * Verify that the key belongs to the explicitly configured account.
	 *
	 * @param bool $force Skip the short-lived cache.
	 * @return bool
	 */
	public static function verify_account( $force = false ) {
		if ( ! self::is_complete() ) {
			return false;
		}

		$cache_key = 'cr_ds_account_' . md5( self::expected_account_id() . self::secret_key() );
		if ( ! $force && 'verified' === get_transient( $cache_key ) ) {
			return true;
		}

		try {
			/*
			 * Verify through WordPress HTTP rather than the global Stripe PHP
			 * namespace. WooCommerce extensions can load another stripe-php
			 * release before this plugin, making a harmless account lookup fail
			 * even though this integration's credentials are valid.
			 */
			$response = wp_remote_get(
				'https://api.stripe.com/v1/account',
				array(
					'headers' => array(
						'Authorization'  => 'Bearer ' . self::secret_key(),
						'Stripe-Version' => self::API_VERSION,
					),
					'timeout' => 3,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			$account = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! is_object( $account ) || empty( $account->id ) || ! hash_equals( self::expected_account_id(), (string) $account->id ) ) {
				return false;
			}
			set_transient( $cache_key, 'verified', 10 * MINUTE_IN_SECONDS );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Non-secret status for the WooCommerce settings screen.
	 *
	 * @return string
	 */
	public static function status_html() {
		if ( ! self::account_id_is_approved() ) {
			return '<strong style="color:#b32d2e">' . esc_html__( 'Blocked: the configured donation Stripe account is not independently approved for this mode.', 'chicago-reader' ) . '</strong>';
		}
		if ( ! self::is_complete() ) {
			return '<strong style="color:#b32d2e">' . esc_html__( 'Not ready: required host-injected configuration is missing or mode-invalid.', 'chicago-reader' ) . '</strong>';
		}
		$account_verified = self::verify_account();
		$distinct         = self::has_distinct_store_account();
		$scheduler_ok     = self::scheduler_healthy();
		if ( ! $account_verified ) {
			$status = __( 'Blocked: the API key could not be verified against the expected Stripe account.', 'chicago-reader' );
		} elseif ( ! $distinct ) {
			$status = __( 'Blocked: the donation account must be different from the regular WooCommerce Stripe account.', 'chicago-reader' );
		} elseif ( ! $scheduler_ok ) {
			$status = __( 'Blocked: the Action Scheduler canary has not run recently.', 'chicago-reader' );
		} else {
			$status = __( 'Ready for new donation payments.', 'chicago-reader' );
		}
		$color = $account_verified && $distinct && $scheduler_ok ? '#008a20' : '#b32d2e';
		$received  = absint( get_option( '_chicago_reader_donation_stripe_last_webhook_received', 0 ) );
		$processed = absint( get_option( '_chicago_reader_donation_stripe_last_webhook_processed', 0 ) );
		$canary    = absint( get_option( '_chicago_reader_donation_stripe_last_scheduler_canary', 0 ) );
		$queue     = function_exists( 'as_enqueue_async_action' ) ? __( 'Action Scheduler available', 'chicago-reader' ) : __( 'Action Scheduler unavailable', 'chicago-reader' );
		$webhooks  = sprintf(
			/* translators: 1: received time, 2: processed time. */
			__( 'Last webhook received: %1$s; processed: %2$s', 'chicago-reader' ),
			$received ? wp_date( 'Y-m-d H:i:s T', $received ) : __( 'never', 'chicago-reader' ),
			$processed ? wp_date( 'Y-m-d H:i:s T', $processed ) : __( 'never', 'chicago-reader' )
		);
		$scheduler = sprintf( /* translators: %s: last canary time. */ __( 'Last scheduler canary: %s', 'chicago-reader' ), $canary ? wp_date( 'Y-m-d H:i:s T', $canary ) : __( 'never', 'chicago-reader' ) );
		return '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</strong><br><code>' . esc_html( self::expected_account_id() ) . '</code> · <code>…' . esc_html( substr( self::publishable_key(), -6 ) ) . '</code><br>' . esc_html( $queue ) . '<br>' . esc_html( $scheduler ) . '<br>' . esc_html( $webhooks );
	}
}
