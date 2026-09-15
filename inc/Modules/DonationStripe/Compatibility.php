<?php
/**
 * Narrow Newspack compatibility adapters.
 *
 * @package ChicagoReader
 */

namespace ChicagoReader\Modules\DonationStripe;

defined( 'ABSPATH' ) || exit;

/** Newspack modal and cover-fees integration. */
final class Compatibility {

	/** Initialize adapters. */
	public static function init() {
		add_filter( 'newspack_blocks_modal_checkout_supported_gateways', array( __CLASS__, 'modal_gateway' ) );
		add_action( 'newspack_blocks_after_payment_fields', array( __CLASS__, 'render_cover_fees' ), 11 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'persist_cover_fees' ), 11 );
	}

	/**
	 * Allow the gateway's tested checkout assets inside the native modal.
	 *
	 * @param string[] $gateways Supported gateway IDs.
	 * @return string[]
	 */
	public static function modal_gateway( $gateways ) {
		$gateways[] = Gateway::ID;
		return array_values( array_unique( $gateways ) );
	}

	/**
	 * Render Newspack-equivalent cover-fee input for this gateway only.
	 *
	 * Newspack currently guards its renderer with a fixed supported-gateway
	 * list, so the same public options and field name are used here until that
	 * list becomes filterable upstream.
	 *
	 * @param string $gateway_id Gateway being rendered.
	 */
	public static function render_cover_fees( $gateway_id ) {
		if ( Gateway::ID !== $gateway_id || ! self::cover_fees_allowed() ) {
			return;
		}
		$field = 'newspack-wc-pay-fees';
		$label = get_option( 'newspack_donations_allow_covering_fees_label', '' );
		if ( ! $label ) {
			$label = __( 'I’d like to cover the transaction fee so my full donation supports Chicago Reader’s mission.', 'chicago-reader' );
		}
		?>
		<fieldset>
			<p class="form-row newspack-cover-fees" style="display:flex">
				<input id="<?php echo esc_attr( $field . '_' . Gateway::ID ); ?>" name="<?php echo esc_attr( $field ); ?>" type="checkbox" value="1" <?php checked( (bool) get_option( 'newspack_donations_allow_covering_fees_default', false ) ); ?> />
				<label for="<?php echo esc_attr( $field . '_' . Gateway::ID ); ?>" style="display:inline"><?php echo esc_html( $label ); ?></label>
			</p>
	</fieldset>
		<?php
	}

	/**
	 * Persist the same session value Newspack's fee calculator consumes.
	 *
	 * @param string $posted_data Serialized checkout data.
	 */
	public static function persist_cover_fees( $posted_data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$data = array();
		parse_str( $posted_data, $data );
		if ( Gateway::ID === ( $data['payment_method'] ?? '' ) ) {
			WC()->session->set( 'newspack-wc-pay-fees', isset( $data['newspack-wc-pay-fees'] ) && '1' === $data['newspack-wc-pay-fees'] ? 1 : 0 );
		}
	}

	/** Whether the active Newspack donation fee feature should be shown. */
	private static function cover_fees_allowed() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! is_checkout() ) {
			return false;
		}
		if ( WC()->cart->get_coupon_discount_totals() ) {
			return false;
		}
		return (bool) get_option( 'newspack_donations_allow_covering_fees', true ) && Routing::ALL === Routing::cart_state();
	}
}
