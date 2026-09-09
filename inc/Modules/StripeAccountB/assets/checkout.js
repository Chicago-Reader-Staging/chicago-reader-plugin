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
	let mountedNode = null;
	let confirmed = false;
	let handlingAction = false;

	function mount() {
		const mountPoint = document.getElementById(
			'chicago-reader-account-b-payment-element'
		);
		if ( ! mountPoint ) {
			return;
		}

		if ( paymentElement && mountedNode === mountPoint ) {
			return;
		}

		if ( paymentElement ) {
			paymentElement.destroy();
		}

		const options = {
			mode: 'payment',
			amount: Number.parseInt( mountPoint.dataset.amount, 10 ),
			currency: mountPoint.dataset.currency,
			paymentMethodTypes: [ 'card' ],
		};
		if ( mountPoint.dataset.setupFutureUsage ) {
			options.setupFutureUsage = mountPoint.dataset.setupFutureUsage;
		}

		elements = stripe.elements( options );
		paymentElement = elements.create( 'payment' );
		paymentElement.mount( mountPoint );
		mountedNode = mountPoint;
		confirmed = false;
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
			if ( confirmed || handlingAction ) {
				return true;
			}
			handlePayment();
			return false;
		}
	);

	async function handlePayment() {
		showError( '' );
		if ( ! elements ) {
			mount();
		}
		if ( ! elements ) {
			showError( 'Payment fields are still loading. Please try again.' );
			return;
		}

		try {
			const submitResult = await elements.submit();
			if ( submitResult.error ) {
				showError( submitResult.error.message );
				return;
			}

			const tokenResult = await stripe.createConfirmationToken( {
				elements,
				params: {
					return_url: window.location.href,
				},
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
		} catch ( error ) {
			confirmed = false;
			showError(
				error.message ||
					'Payment fields could not be submitted. Please try again.'
			);
		}
	}

	$( document.body ).on( 'checkout_error', function () {
		confirmed = false;
		handlingAction = false;
		$( '#chicago-reader-account-b-confirmation-token' ).val( '' );
	} );

	$( 'form.checkout' ).on(
		'checkout_place_order_success',
		function ( event, result ) {
			if ( ! result.account_b_requires_action ) {
				return true;
			}

			handlingAction = true;
			handleNextAction( result );
			return false;
		}
	);

	async function handleNextAction( result ) {
		const actionResult = await stripe.handleNextAction( {
			clientSecret: result.account_b_client_secret,
		} );

		if ( actionResult.error ) {
			releaseCheckout();
			showError( actionResult.error.message );
			return;
		}

		if (
			! actionResult.paymentIntent ||
			actionResult.paymentIntent.status !== 'succeeded'
		) {
			releaseCheckout();
			showError(
				'Payment authentication did not complete. Please try again.'
			);
			return;
		}

		try {
			await finalizePayment( result );
		} catch ( error ) {
			// The signed webhook is the authoritative backstop. Once Stripe says the
			// payment succeeded, redirecting is safer than inviting a second charge.
		}

		window.location = result.redirect;
	}

	async function finalizePayment( result ) {
		const body = new URLSearchParams( {
			action: 'chicago_reader_account_b_finalize',
			nonce: chicagoReaderAccountB.finalizeNonce,
			order_id: result.order_id,
			order_key: result.account_b_order_key,
			payment_intent_id: result.account_b_payment_intent_id,
		} );
		const response = await window.fetch( chicagoReaderAccountB.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type':
					'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) {
			throw new Error( 'Payment finalization failed.' );
		}
	}

	function releaseCheckout() {
		confirmed = false;
		handlingAction = false;
		$( '#chicago-reader-account-b-confirmation-token' ).val( '' );
		$( 'form.checkout' ).removeClass( 'processing' ).unblock();
	}
} )( jQuery );
