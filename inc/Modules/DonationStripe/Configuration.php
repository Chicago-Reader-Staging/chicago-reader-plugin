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
			&& 0 === strpos( $publishable, 'pk' . $prefix )
			&& 0 === strpos( $secret, 'sk' . $prefix )
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
					'api_key'             => self::secret_key(),
					'stripe_version'      => self::API_VERSION,
					'max_network_retries' => 2,
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
		if ( ! self::is_complete() ) {
			return '<strong style="color:#b32d2e">' . esc_html__( 'Not ready: required host-injected configuration is missing or mode-invalid.', 'chicago-reader' ) . '</strong>';
		}
		$status = self::verify_account() ? __( 'Connected to the expected Stripe account.', 'chicago-reader' ) : __( 'Blocked: the API key could not be verified against the expected Stripe account.', 'chicago-reader' );
		$color  = self::verify_account() ? '#008a20' : '#b32d2e';
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
