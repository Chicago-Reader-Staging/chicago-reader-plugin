<?php
/** Minimal bootstrap for payment-policy unit tests. */

namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['cr_test_options'] = array();
	$GLOBALS['cr_test_notices'] = array();
	$GLOBALS['cr_test_is_admin'] = false;
	$GLOBALS['cr_test_ajax'] = false;
	$GLOBALS['cr_test_wc'] = null;
	$GLOBALS['cr_test_orders'] = array();

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

	function is_wc_endpoint_url( $endpoint ) {
		return 'order-pay' === $endpoint && ! empty( $GLOBALS['cr_test_order_pay'] );
	}

	function add_action() {}

	function add_filter() {}

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
}
