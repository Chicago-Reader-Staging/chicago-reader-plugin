const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	retries: 0,
	timeout: 90_000,
	use: {
		baseURL:
			process.env.DONATION_TEST_BASE_URL ||
			'https://chicagoreader.newspackstaging.com',
		browserName: 'chromium',
		channel: 'chrome',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
