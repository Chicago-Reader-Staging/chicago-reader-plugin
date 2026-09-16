const { test, expect } = require( '@playwright/test' );

async function unlockGate( page ) {
	const gate = page.locator( 'form input[type="password"]' );
	if ( ! ( await gate.isVisible() ) ) {
		return;
	}
	const password = process.env.DONATION_TEST_GATE_PASSWORD;
	if ( ! password ) {
		throw new Error(
			'Staging password gate is present; set DONATION_TEST_GATE_PASSWORD for this browser test.'
		);
	}
	await gate.fill( password );
	await gate.press( 'Enter' );
}

for ( const frequency of [ 'One-time', 'Monthly', 'Annually' ] ) {
	test( `Newspack ${ frequency } donation opens one Stripe card checkout`, async ( {
		page,
	}, testInfo ) => {
		await page.goto( '/about/local-news-public-good/' );
		await unlockGate( page );

		await page.getByRole( 'tab', { name: frequency } ).click();
		await page.getByRole( 'radio', { name: '$75' } ).check();
		await page.getByRole( 'button', { name: 'Donate now' } ).click();
		await unlockGate( page );

		await expect( page ).toHaveURL( /\/checkout\// );
		await expect( page.getByText( /\$\s*75\.00/ ).first() ).toBeVisible();
		await expect(
			page
				.locator(
					'#chicago-reader-donation-stripe-payment-element iframe'
				)
				.first()
		).toBeVisible();

		const sdkScripts = await page
			.locator( 'script[src*="js.stripe.com"]' )
			.count();
		expect(
			sdkScripts,
			'Stripe.js must load once, not once per gateway'
		).toBe( 1 );
		await expect(
			page.getByRole( 'button', { name: 'DONATE NOW' } )
		).toBeVisible();
		await page.screenshot( {
			path: testInfo.outputPath( 'pre-payment-checkout.png' ),
			fullPage: true,
		} );

		// Pre-payment UI only: no charge, order, or subscription is asserted here.
	} );
}
