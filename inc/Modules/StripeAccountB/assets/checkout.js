/**
 * Mounts an inline Stripe Payment Element for the Account B gateway and
 * intercepts native checkout submission to collect a ConfirmationToken
 * before the order posts, per Stripe's deferred-intent integration.
 *
 * @param {Function} $ jQuery.
 */
/* global Stripe, chicagoReaderAccountB, jQuery */
( function ( $ ) {
	'use strict';

	if (
		typeof chicagoReaderAccountB === 'undefined' ||
		typeof Stripe === 'undefined'
	) {
		return;
	}

	const stripe = Stripe( chicagoReaderAccountB.publishableKey );
	let elements = null;
	let paymentElement = null;
	let confirmed = false;

	function mount() {
		const mountPoint = document.getElementById(
			'chicago-reader-account-b-payment-element'
		);
		if ( ! mountPoint || paymentElement ) {
			return;
		}
		elements = stripe.elements( {
			mode: 'payment',
			amount: chicagoReaderAccountB.amount,
			currency: chicagoReaderAccountB.currency,
		} );
		paymentElement = elements.create( 'payment' );
		paymentElement.mount( '#chicago-reader-account-b-payment-element' );
	}

	function showError( message ) {
		$( '#chicago-reader-account-b-errors' ).text( message );
	}

	function isAccountBSelected() {
		return (
			$( 'input[name="payment_method"]:checked' ).val() ===
			chicagoReaderAccountB.gatewayId
		);
	}

	$( document.body ).on(
		'updated_checkout payment_method_selected',
		function () {
			if ( isAccountBSelected() ) {
				mount();
			}
		}
	);

	$( document.body ).on(
		'checkout_place_order_' + chicagoReaderAccountB.gatewayId,
		function () {
			if ( confirmed ) {
				return true;
			}
			handlePayment();
			return false;
		}
	);

	async function handlePayment() {
		showError( '' );

		const submitResult = await elements.submit();
		if ( submitResult.error ) {
			showError( submitResult.error.message );
			return;
		}

		const tokenResult = await stripe.createConfirmationToken( {
			elements,
		} );
		if ( tokenResult.error ) {
			showError( tokenResult.error.message );
			return;
		}

		$( '#chicago-reader-account-b-confirmation-token' ).val(
			tokenResult.confirmationToken.id
		);
		confirmed = true;
		$( 'form.checkout' ).trigger( 'submit' );
	}
} )( jQuery );
