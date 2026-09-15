# Donation Stripe Gateway

This module routes only products accepted by `Newspack\Donations::is_donation_product()` to a separate Stripe account. Normal WooCommerce products and existing subscriptions keep their original gateway. Mixed carts are rejected because WooCommerce orders have one payment gateway.

## Host configuration

Define these outside WordPress options for both `TEST` and `LIVE` modes:

```php
CHICAGO_READER_DONATION_STRIPE_TEST_PUBLISHABLE_KEY
CHICAGO_READER_DONATION_STRIPE_TEST_SECRET_KEY
CHICAGO_READER_DONATION_STRIPE_TEST_WEBHOOK_SECRET
CHICAGO_READER_DONATION_STRIPE_TEST_ACCOUNT_ID
```

Replace `TEST` with `LIVE` for live values. Live mode also requires `CHICAGO_READER_DONATION_STRIPE_LIVE_ALLOWED` to be boolean `true`. `CHICAGO_READER_DONATION_STRIPE_LIVE_HOSTS` may be a comma-separated hostname allowlist; it defaults to `chicagoreader.com,www.chicagoreader.com`.

The webhook URL is shown in WooCommerce payment settings. Register it at the pinned API version `2026-08-26.dahlia` for the event types listed in `Webhook_Handler.php`. Register staging and production domains for Apple Pay and Google Pay in their corresponding Stripe modes.

## Local verification

1. Run `composer install`, `composer test`, and `composer run lint`.
2. Mount the plugin in the Newspack workspace using the deployed Newspack/WooCommerce versions.
3. Forward Stripe CLI test events to `/?wc-api=chicago_reader_donation_stripe`.
4. Verify guest and account one-time donations, monthly/annual signup, a forced renewal, decline and 3DS recovery, saved-method add/change/delete, partial/full refund, Dashboard refund, duplicate webhooks, cover fees, and wallet-capable devices.
5. Verify merchandise remains on the official `stripe` gateway and mixed carts stop before payment.

The module must stay disabled in production until Action Scheduler, account identity, webhook delivery, wallet-domain registration, and the full staging matrix are green.

The deployment workflows can generate these constants from repository secrets. For the staging matrix site name, configure `STAGING_DONATION_STRIPE_TEST_PUBLISHABLE_KEY_<SITE>`, `STAGING_DONATION_STRIPE_TEST_SECRET_KEY_<SITE>`, `STAGING_DONATION_STRIPE_TEST_WEBHOOK_SECRET_<SITE>`, and `STAGING_DONATION_STRIPE_TEST_ACCOUNT_ID_<SITE>`. Production uses the corresponding `PROD_DONATION_STRIPE_LIVE_*_<SITE>` names. Empty sets leave the gateway disabled; partial or mode-mismatched sets fail deployment.
