<?php
/** Boot an isolated, real WordPress installation; never the stand-in unit bootstrap. */

$wp_root = getenv( 'DONATION_INTEGRATION_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'BLOCKED: set DONATION_INTEGRATION_WP_ROOT to a local WordPress installation with the required real plugins.' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $wp_root . '/wp-load.php';

$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
if ( ! in_array( $site_host, array( 'localhost', '127.0.0.1', 'wordpress', 'host.docker.internal' ), true ) ) {
	throw new RuntimeException( 'BLOCKED: integration tests run only against an isolated local WordPress host.' );
}

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'Newspack\\Donations' ) || ! class_exists( 'WC_Subscriptions' ) || ! class_exists( 'WC_Name_Your_Price' ) ) {
	throw new RuntimeException( 'BLOCKED: WooCommerce, Newspack, WooCommerce Subscriptions, and Name Your Price must all be active.' );
}

if ( ! class_exists( 'ChicagoReader\\Modules\\DonationStripe\\Gateway' ) ) {
	throw new RuntimeException( 'BLOCKED: activate the Chicago Reader plugin and DonationStripe module in the local WordPress installation.' );
}
