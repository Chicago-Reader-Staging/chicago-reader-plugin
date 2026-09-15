<?php
/** Minimal bootstrap for payment-policy unit tests. */

namespace {
	define( 'ABSPATH', __DIR__ );

	function absint( $value ) {
		return abs( (int) $value );
	}

	function get_option( $key, $default = false ) {
		return $default;
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
}
