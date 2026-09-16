<?php
/** Minimal bootstrap for payment-policy unit tests. */

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['cr_test_options'] = array();
	$GLOBALS['cr_test_notices'] = array();
	$GLOBALS['cr_test_is_admin'] = false;
	$GLOBALS['cr_test_ajax'] = false;
	$GLOBALS['cr_test_wc'] = null;
	$GLOBALS['cr_test_orders'] = array();
	$GLOBALS['cr_test_user_meta'] = array();
	$GLOBALS['cr_test_subscriptions'] = array();
	define( 'CHICAGO_READER_DONATION_STRIPE_TEST_ACCOUNT_ID', 'acct_1F24k4LAprqx9n8w' );

	class WC_Stripe_Account_Test_Double {
		public $id = 'acct_store_test';
		public $last_mode = null;

		public function get_cached_account_data( $mode = null ) {
			$this->last_mode = $mode;
			return $this->id ? array( 'id' => $this->id ) : array();
		}
	}

	class WC_Stripe {
		public $account;
		private static $instance;

		private function __construct() {
			$this->account = new WC_Stripe_Account_Test_Double();
		}

		public static function get_instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function get_option( $key, $default = false ) {
		return $GLOBALS['cr_test_options'][ $key ] ?? $default;
	}

	function add_option( $key, $value ) {
		if ( array_key_exists( $key, $GLOBALS['cr_test_options'] ) ) {
			return false;
		}
		$GLOBALS['cr_test_options'][ $key ] = $value;
		return true;
	}

	function delete_option( $key ) {
		unset( $GLOBALS['cr_test_options'][ $key ] );
		return true;
	}

	function wp_generate_uuid4() {
		return bin2hex( random_bytes( 16 ) );
	}

	function is_admin() {
		return $GLOBALS['cr_test_is_admin'];
	}

	function wp_doing_ajax() {
		return $GLOBALS['cr_test_ajax'];
	}

	function WC() {
		return $GLOBALS['cr_test_wc'];
	}

	function wc_has_notice( $message, $type ) {
		return in_array( array( $message, $type ), $GLOBALS['cr_test_notices'], true );
	}

	function wc_add_notice( $message, $type ) {
		$GLOBALS['cr_test_notices'][] = array( $message, $type );
	}

	function wc_get_order( $id ) {
		return $GLOBALS['cr_test_orders'][ $id ] ?? false;
	}

	function get_user_meta( $user_id, $key ) {
		return $GLOBALS['cr_test_user_meta'][ $user_id ][ $key ] ?? '';
	}

	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['cr_test_user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}

	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
	}

	function wcs_get_subscriptions_for_order( $order ) {
		return $GLOBALS['cr_test_subscriptions'][ $order->get_id() ] ?? array();
	}

	function wcs_get_users_subscriptions( $user_id ) {
		return $GLOBALS['cr_test_user_subscriptions'][ $user_id ] ?? array();
	}

	function is_wc_endpoint_url( $endpoint ) {
		return 'order-pay' === $endpoint && ! empty( $GLOBALS['cr_test_order_pay'] );
	}

	function add_action() {}

	function add_filter() {}

	function as_enqueue_async_action() {
		return 1;
	}

	function __( $text ) {
		return $text;
	}

	function esc_html( $text ) {
		return $text;
	}

	function esc_html__( $text ) {
		return $text;
	}

	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}

	#[\AllowDynamicProperties]
	class WC_Payment_Gateway_CC {
		public $settings = array();

		public function init_settings() {
			$this->settings = array();
		}

		public function get_option( $key, $default = '' ) {
			return $default;
		}
	}

	class WC_Payment_Token_CC {
		private static $next_id = 1;
		private $id = 0;
		private $user_id = 0;
		private $gateway_id = '';
		private $token = '';
		private $card_type = '';
		private $last4 = '';
		private $meta = array();

		public function set_user_id( $value ) { $this->user_id = $value; }
		public function get_user_id() { return $this->user_id; }
		public function set_gateway_id( $value ) { $this->gateway_id = $value; }
		public function get_gateway_id() { return $this->gateway_id; }
		public function set_token( $value ) { $this->token = $value; }
		public function get_token() { return $this->token; }
		public function set_card_type( $value ) { $this->card_type = $value; }
		public function get_card_type() { return $this->card_type; }
		public function set_last4( $value ) { $this->last4 = $value; }
		public function get_last4() { return $this->last4; }
		public function set_expiry_month( $value ) { $this->meta['expiry_month'] = $value; }
		public function set_expiry_year( $value ) { $this->meta['expiry_year'] = $value; }
		public function get_id() { return $this->id; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function save() {
			if ( ! $this->id ) { $this->id = self::$next_id++; }
			WC_Payment_Tokens::$tokens[ $this->id ] = $this;
		}
	}

	class WC_Payment_Tokens {
		public static $tokens = array();
		public static function get( $id ) { return self::$tokens[ $id ] ?? false; }
		public static function get_customer_tokens( $user_id, $gateway_id ) {
			return array_filter( self::$tokens, static function ( $token ) use ( $user_id, $gateway_id ) {
				return $token->get_user_id() === $user_id && $token->get_gateway_id() === $gateway_id;
			} );
		}
	}
}

namespace Newspack {
	/** Controllable stand-in for Newspack's canonical classifier. */
	class Donations {
		public static $donation_ids = array();

		public static function is_donation_product( $product_id ) {
			return in_array( (int) $product_id, self::$donation_ids, true );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Routing.php';
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Configuration.php';
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Gateway.php';
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Legacy_Gateway.php';
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Lock.php';
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Token_Manager.php';
	require_once dirname( __DIR__ ) . '/inc/Modules/DonationStripe/Compatibility.php';
}
