<?php
/**
 * Short-lived atomic operation locks.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

/**
 * Uses unique options because add_option() is atomic across web workers.
 */
final class Lock {

	/**
	 * Acquire a lock, recovering it only after expiry.
	 *
	 * @param string $operation Stable operation name.
	 * @param int    $ttl       Lifetime in seconds.
	 * @return string|false Lock token or false.
	 */
	public static function acquire( $operation, $ttl = 300 ) {
		$name  = 'cr_ds_lock_' . md5( $operation );
		$token = wp_generate_uuid4() . '|' . ( time() + absint( $ttl ) );
		if ( add_option( $name, $token, '', false ) ) {
			return $token;
		}

		$current = (string) get_option( $name, '' );
		$parts   = explode( '|', $current );
		if ( 2 === count( $parts ) && (int) $parts[1] < time() ) {
			delete_option( $name );
			return add_option( $name, $token, '', false ) ? $token : false;
		}
		return false;
	}

	/**
	 * Release only a lock owned by this caller.
	 *
	 * @param string $operation Stable operation name.
	 * @param string $token     Lock token.
	 */
	public static function release( $operation, $token ) {
		$name = 'cr_ds_lock_' . md5( $operation );
		if ( hash_equals( (string) get_option( $name, '' ), (string) $token ) ) {
			delete_option( $name );
		}
	}
}
