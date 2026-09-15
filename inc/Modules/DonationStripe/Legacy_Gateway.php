<?php
/**
 * One-release compatibility gateway for existing Account B staging orders.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

/** Hidden legacy refund handler; never available for new checkout. */
final class Legacy_Gateway extends Gateway {

	const ID = 'chicago_reader_account_b';

	/** Build the disabled compatibility instance. */
	public function __construct() {
		parent::__construct( true );
	}

	/** {@inheritDoc} */
	public function is_available() {
		return false;
	}
}
