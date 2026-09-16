/**
 * Stripe Elements integration for Newspack modal/classic checkout and
 * WooCommerce payment-method forms.
 */
/* global Stripe, chicagoReaderDonationStripe, jQuery */
( function ( $ ) {
	'use strict';

	if (
		typeof Stripe === 'undefined' ||
		typeof chicagoReaderDonationStripe === 'undefined'
	) {
		return;
	}

	const config = chicagoReaderDonationStripe;
	const stripe = Stripe( config.publishableKey );
	let elements = null;
	let paymentElement = null;
	let expressElement = null;
	let mountedNode = null;
	let confirmed = false;
	let handlingAction = false;
	let setupClientSecret = '';
	let setupOperation = '';

	function selected() {
		return (
			$( 'input[name="payment_method"]:checked' ).val() ===
			config.gatewayId
		);
	}

	function usingNewMethod() {
		const token = $(
			'input[name="wc-' + config.gatewayId + '-payment-token"]:checked'
		).val();
		return ! token || token === 'new';
	}

	function showError( message ) {
		$( '#chicago-reader-donation-stripe-errors' ).text( message || '' );
	}

	function toggleNewMethod() {
		$( '.chicago-reader-donation-stripe-new-method' ).toggle(
			usingNewMethod()
		);
	}

	function newOperationId() {
		if ( window.crypto?.randomUUID ) {
			return window.crypto.randomUUID();
		}
		const values = new Uint32Array( 4 );
		window.crypto.getRandomValues( values );
		return Array.from( values, ( value ) =>
			value.toString( 16 ).padStart( 8, '0' )
		).join( '-' );
	}

	function bindCheckoutForms() {
		$( 'form.checkout, form#order_review' )
			.off(
				'checkout_place_order_' + config.gatewayId + '.crDonationStripe'
			)
			.on(
				'checkout_place_order_' +
					config.gatewayId +
					'.crDonationStripe',
				function () {
					if ( confirmed || handlingAction || ! usingNewMethod() ) {
						return true;
					}
					const node = document.getElementById(
						'chicago-reader-donation-stripe-payment-element'
					);
					if ( node?.dataset.context === 'setup' ) {
						prepareSetupForm( $( this ) );
					} else {
						prepareCheckout();
					}
					return false;
				}
			)
			.off( 'checkout_place_order_success.crDonationStripe' )
			.on(
				'checkout_place_order_success.crDonationStripe',
				function ( event, result ) {
					if ( ! result.donation_stripe_requires_action ) {
						return true;
					}
					handlingAction = true;
					handleNextAction( result );
					return false;
				}
			);
	}

	async function requestSetupIntent() {
		if ( setupClientSecret ) {
			return setupClientSecret;
		}
		const body = new URLSearchParams( {
			action: 'chicago_reader_donation_stripe_setup',
			nonce: config.setupNonce,
			operation: setupOperation || ( setupOperation = newOperationId() ),
		} );
		const response = await window.fetch( config.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type':
					'application/x-www-form-urlencoded; charset=UTF-8',
			},
			credentials: 'same-origin',
			body: body.toString(),
		} );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) {
			throw new Error( payload.data?.message || config.genericError );
		}
		setupClientSecret = payload.data.clientSecret;
		return setupClientSecret;
	}

	async function mount() {
		const node = document.getElementById(
			'chicago-reader-donation-stripe-payment-element'
		);
		if ( ! node || ( mountedNode === node && paymentElement ) ) {
			return;
		}
		if ( paymentElement ) {
			paymentElement.destroy();
		}
		if ( expressElement ) {
			expressElement.destroy();
		}
		try {
			const context = node.dataset.context || 'payment';
			if ( context === 'setup' ) {
				const secret = await requestSetupIntent();
				elements = stripe.elements( { clientSecret: secret } );
			} else {
				const deferredSetup = context === 'deferred-setup';
				const options = {
					mode: deferredSetup ? 'setup' : 'payment',
					currency: node.dataset.currency,
					paymentMethodTypes: [ 'card' ],
				};
				if ( ! deferredSetup ) {
					options.amount = Number.parseInt( node.dataset.amount, 10 );
				}
				if ( node.dataset.futureUsage ) {
					options.setupFutureUsage = node.dataset.futureUsage;
				}
				elements = stripe.elements( options );
			}
			paymentElement = elements.create( 'payment', {
				wallets: { applePay: 'never', googlePay: 'never' },
			} );
			paymentElement.mount( node );
			if (
				( context === 'payment' || context === 'deferred-setup' ) &&
				config.walletsEnabled &&
				document.getElementById(
					'chicago-reader-donation-stripe-express'
				)
			) {
				const expressNode = document.getElementById(
					'chicago-reader-donation-stripe-express'
				);
				const expressOptions = {
					paymentMethods: {
						applePay: 'auto',
						googlePay: 'auto',
						link: 'never',
					},
				};
				if ( expressNode.dataset.recurringUnit ) {
					expressOptions.applePay = {
						recurringPaymentRequest: {
							paymentDescription: 'Chicago Reader donation',
							managementURL: config.accountUrl,
							regularBilling: {
								amount: Number.parseInt(
									expressNode.dataset.recurringAmount,
									10
								),
								label: 'Recurring Chicago Reader donation',
								recurringPaymentIntervalUnit:
									expressNode.dataset.recurringUnit,
								recurringPaymentIntervalCount: Number.parseInt(
									expressNode.dataset.recurringInterval,
									10
								),
							},
						},
					};
				}
				expressElement = elements.create(
					'expressCheckout',
					expressOptions
				);
				expressElement.on( 'confirm', handleExpressConfirm );
				expressElement.mount(
					'#chicago-reader-donation-stripe-express'
				);
			}
			mountedNode = node;
			confirmed = false;
			toggleNewMethod();
		} catch ( error ) {
			showError( error.message || config.genericError );
		}
	}

	async function createConfirmationToken() {
		if ( ! elements ) {
			await mount();
		}
		if ( ! elements ) {
			throw new Error( config.genericError );
		}
		const submitted = await elements.submit();
		if ( submitted.error ) {
			throw submitted.error;
		}
		const result = await stripe.createConfirmationToken( {
			elements,
			params: { return_url: window.location.href },
		} );
		if ( result.error ) {
			throw result.error;
		}
		$( '#chicago-reader-donation-stripe-confirmation-token' ).val(
			result.confirmationToken.id
		);
		confirmed = true;
	}

	async function handleExpressConfirm() {
		try {
			await createConfirmationToken();
			$( 'form.checkout' ).trigger( 'submit' );
		} catch ( error ) {
			confirmed = false;
			showError( error.message || config.genericError );
		}
	}

	async function prepareCheckout() {
		showError( '' );
		try {
			await createConfirmationToken();
			$( 'form.checkout, form#order_review' )
				.filter( ':visible' )
				.trigger( 'submit' );
		} catch ( error ) {
			confirmed = false;
			showError( error.message || config.genericError );
			$( 'form.checkout, form#order_review' )
				.removeClass( 'processing' )
				.unblock();
		}
	}

	async function prepareSetupForm( form ) {
		showError( '' );
		try {
			if ( ! elements ) {
				await mount();
			}
			const result = await stripe.confirmSetup( {
				elements,
				clientSecret: setupClientSecret,
				confirmParams: { return_url: window.location.href },
				redirect: 'if_required',
			} );
			if ( result.error ) {
				throw result.error;
			}
			if (
				! result.setupIntent ||
				result.setupIntent.status !== 'succeeded'
			) {
				throw new Error( config.genericError );
			}
			$( '#chicago-reader-donation-stripe-setup-intent' ).val(
				result.setupIntent.id
			);
			confirmed = true;
			form.trigger( 'submit' );
		} catch ( error ) {
			confirmed = false;
			showError( error.message || config.genericError );
			form.removeClass( 'processing' ).unblock();
		}
	}

	$( document.body ).on(
		'updated_checkout payment_method_selected change',
		'input[name="payment_method"], input[name="wc-' +
			config.gatewayId +
			'-payment-token"]',
		function () {
			toggleNewMethod();
			if ( selected() && usingNewMethod() ) {
				mount();
			}
		}
	);

	$( document.body ).on(
		'updated_checkout payment_method_selected',
		function () {
			bindCheckoutForms();
			if ( selected() && usingNewMethod() ) {
				mount();
			}
		}
	);

	$( document ).on( 'submit', 'form#add_payment_method', function () {
		if ( confirmed || ! selected() || ! usingNewMethod() ) {
			return true;
		}
		prepareSetupForm( $( this ) );
		return false;
	} );

	async function handleNextAction( result ) {
		const action = await stripe.handleNextAction( {
			clientSecret: result.donation_stripe_client_secret,
		} );
		const completedIntent = result.donation_stripe_is_setup
			? action.setupIntent
			: action.paymentIntent;
		if (
			action.error ||
			! completedIntent ||
			completedIntent.status !== 'succeeded'
		) {
			releaseCheckout();
			showError( action.error?.message || config.genericError );
			return;
		}
		try {
			const billingEmail =
				document.querySelector( '#billing_email' )?.value || '';
			const body = new URLSearchParams( {
				action: 'chicago_reader_donation_stripe_finalize',
				nonce: config.finalizeNonce,
				order_id: result.order_id,
				order_key: result.donation_stripe_order_key,
				payment_intent_id:
					result.donation_stripe_payment_intent_id || '',
				setup_intent_id: result.donation_stripe_setup_intent_id || '',
				billing_email: billingEmail,
			} );
			const response = await window.fetch( config.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type':
						'application/x-www-form-urlencoded; charset=UTF-8',
				},
				credentials: 'same-origin',
				body: body.toString(),
			} );
			const payload = await response.json();
			if ( ! response.ok || ! payload.success ) {
				throw new Error( payload.data?.message || config.genericError );
			}
			window.location = payload.data.redirect || result.redirect;
		} catch ( error ) {
			// Signed webhooks remain the authoritative backstop if this request fails.
			releaseCheckout();
			showError( error.message || config.genericError );
		}
	}

	function releaseCheckout() {
		confirmed = false;
		handlingAction = false;
		$( '#chicago-reader-donation-stripe-confirmation-token' ).val( '' );
		$( 'form.checkout, form#order_review' )
			.removeClass( 'processing' )
			.unblock();
	}

	$( document.body ).on( 'checkout_error', releaseCheckout );
	$( function () {
		bindCheckoutForms();
		if ( selected() && usingNewMethod() ) {
			mount();
		}
	} );
} )( jQuery );
