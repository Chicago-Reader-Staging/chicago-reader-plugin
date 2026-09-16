# Real-plugin integration tests

This suite is separate from the stand-in unit suite. Run it only against an isolated local WordPress database and hostname, never staging or production:

```sh
DONATION_INTEGRATION_WP_ROOT=/absolute/path/to/local/wordpress composer run test:integration
```

The WordPress installation must have the Chicago Reader DonationStripe module enabled alongside Newspack 6.50.3, Newspack Blocks 4.31.2, WooCommerce 11.1.0, WooCommerce Stripe 11.0.0, WooCommerce Subscriptions 9.2.0, and Name Your Price 3.8.2. Configure Newspack's generated donation products before running. The bootstrap rejects non-local hostnames and missing required plugins.

Current coverage is limited to real Newspack product classification and real gateway/Subscriptions registration. It does **not** establish successful Stripe charging, token persistence, automatic renewals, refunds, or webhooks. Expand those tests using actual Woo/WCS objects before claiming integration readiness. If the licensed plugins or a local database/runtime are unavailable, report this suite as **BLOCKED**, not passed or skipped.
