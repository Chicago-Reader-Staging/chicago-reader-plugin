<?php
/**
 * Generate the deployment-only Donation Stripe runtime configuration.
 *
 * @package ChicagoReader
 */

$stripe_config_mode = strtoupper( (string) getenv( 'DONATION_STRIPE_CONFIG_MODE' ) );
$output             = $argv[1] ?? dirname( __DIR__ ) . '/inc/Modules/DonationStripe/runtime-config.php';

if ( ! in_array( $stripe_config_mode, array( 'TEST', 'LIVE' ), true ) ) {
	// An entirely unconfigured deployment keeps the safe empty placeholder.
	if ( '' === $stripe_config_mode ) {
		exit( 0 );
	}
	throw new RuntimeException( 'DONATION_STRIPE_CONFIG_MODE must be TEST or LIVE.' );
}

$stripe_value_names = array( 'PUBLISHABLE_KEY', 'SECRET_KEY', 'WEBHOOK_SECRET', 'ACCOUNT_ID' );
$values             = array();
foreach ( $stripe_value_names as $name ) {
	$values[ $name ] = trim( (string) getenv( 'DONATION_STRIPE_' . $name ) );
}

if ( ! array_filter( $values ) ) {
	exit( 0 );
}
if ( count( array_filter( $values ) ) !== count( $values ) ) {
	throw new RuntimeException( 'Donation Stripe deployment secrets are only partially configured.' );
}

$expected_prefixes = array(
	'PUBLISHABLE_KEY' => 'pk_' . strtolower( $stripe_config_mode ) . '_',
	'SECRET_KEY'      => 'sk_' . strtolower( $stripe_config_mode ) . '_',
	'WEBHOOK_SECRET'  => 'whsec_',
	'ACCOUNT_ID'      => 'acct_',
);
foreach ( $expected_prefixes as $name => $prefix ) {
	$valid = 0 === strpos( $values[ $name ], $prefix );
	if ( 'SECRET_KEY' === $name ) {
		$valid = $valid || 0 === strpos( $values[ $name ], 'rk_' . strtolower( $stripe_config_mode ) . '_' );
	}
	if ( ! $valid ) {
		throw new RuntimeException( 'Donation Stripe deployment value has the wrong mode or prefix: ' . $name );
	}
}

$php = "<?php\n/** Generated at deployment; never commit populated output. */\n";
$php .= "defined( 'ABSPATH' ) || exit;\n\n";
foreach ( $values as $name => $value ) {
	$php .= 'define( ' . var_export( 'CHICAGO_READER_DONATION_STRIPE_' . $stripe_config_mode . '_' . $name, true ) . ', ' . var_export( $value, true ) . ");\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Safely quotes a deployment secret into generated PHP; nothing is logged.
}
if ( 'LIVE' === $stripe_config_mode ) {
	$php .= "define( 'CHICAGO_READER_DONATION_STRIPE_LIVE_ALLOWED', true );\n";
}

if ( false === file_put_contents( $output, $php, LOCK_EX ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Build-time generator, never a WordPress runtime write.
	throw new RuntimeException( 'Donation Stripe runtime configuration could not be written.' );
}
chmod( $output, 0600 ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.chmod_chmod -- Build-time secret file, not a WordPress runtime write.
