# Donation Stripe: Architecture and Decisions

This document explains why the Donation Stripe module exists, which responsibilities remain with Newspack and WooCommerce, and how the module processes payments in a separate Stripe account.

## Summary

Chicago Reader needs two payment paths on one WooCommerce site:

- store purchases use the existing official Stripe gateway and store Stripe account;
- Newspack donations use the independent donation Stripe account.

Newspack and the official WooCommerce Stripe extension do not provide product-by-product routing between two independent Stripe accounts. This module fills that specific gap. It does not replace Newspack's Donate block, modal checkout, donation products, donor accounts, reporting, or WooCommerce Subscriptions.

```text
Newspack Donate block
        -> Newspack identifies a donation
        -> Newspack modal checkout
        -> WooCommerce order or subscription
        -> Donation Stripe gateway
        -> Independent donation Stripe account
        -> WooCommerce records the verified result
```

## Responsibility boundaries

| Responsibility | Owner |
|---|---|
| Donate block, amounts, frequencies, campaigns, and modal checkout | Newspack |
| Definition of a donation product | Newspack |
| Orders, customers, refunds, token framework, and My Account | WooCommerce |
| Subscription schedules, renewal orders, retries, and payment-method-change workflows | WooCommerce Subscriptions |
| Card fields, authorization, 3-D Secure, wallets, and PaymentMethods | Stripe |
| Routing Newspack donations to the independent account | This module |
| Isolating donation and store Stripe objects | This module |

The design rule is to reuse Newspack and WooCommerce wherever they already provide a feature and customize only the separate-account payment boundary.

## Donation classification and routing

`Routing.php` calls Newspack's canonical classifier:

```php
Newspack\Donations::is_donation_product( $product_id )
```

This replaces the prototype's manually configured product category. Newspack's classifier understands its donation metadata, generated products, variations, and legacy donation-product IDs. Using the same classifier prevents Newspack and the gateway from disagreeing about what is a donation.

Routing is fail-closed:

1. A donation-only checkout exposes only Donation Stripe.
2. A non-donation checkout hides Donation Stripe and leaves normal gateways unchanged.
3. A mixed donation/merchandise checkout is blocked.
4. Renewals and refunds follow the gateway stored on the order or subscription.
5. Missing classification or incomplete account configuration prevents a charge.

Mixed carts are blocked because [WooCommerce permits one payment per order](https://woocommerce.com/document/managing-orders/paying-for-orders/). Safely splitting a checkout across two independent Stripe accounts would require two coordinated orders and payments.

Official Newspack references:

- [`Newspack\Donations::is_donation_product()`](https://github.com/Automattic/newspack-plugin/blob/21d5921609c63e2de41c58b70d9ea5e934ff5360/includes/class-donations.php#L274-L303)
- [Newspack donation tools and Donate block](https://newspack.com/how-to-meet-your-giving-season-goals/)
- [Donate block amounts and recurring-payment requirements](https://newspack.com/february-20-2024-usability-improvements-for-you-and-your-readers/)

## Newspack modal compatibility

Donors continue to use Newspack's native Donate block and modal. `Compatibility.php` adds the gateway through Newspack's official `newspack_blocks_modal_checkout_supported_gateways` filter. It also connects the gateway to Newspack's existing cover-fee field and session value instead of creating a separate fee system.

- [Newspack modal supported-gateway filter and fallback](https://github.com/Automattic/newspack-blocks/blob/dcc77268c77b68c555766a457bd5b0cd6728157f/includes/class-modal-checkout.php#L278-L335)
- [Newspack modal checkout behavior](https://newspack.com/february-3-2025-subscription-network-and-checkout-enhancements/)
- [Newspack cover-fee behavior](https://newspack.com/november-13-2023-a-cornucopia-of-new-reader-facing-features/)
- [Newspack cover-fee implementation](https://github.com/Automattic/newspack-workspace/blob/03cde2a776185c99ed299e60d71426c1fbf24a93/plugins/newspack-plugin/includes/plugins/woocommerce/class-woocommerce-cover-fees.php)

## Initial payment flow

1. Newspack supplies the donation product and selected amount.
2. WooCommerce validates checkout and creates the order.
3. The gateway derives amount, currency, customer, order ownership, and donation status from server-side WooCommerce data.
4. Stripe's Payment Element collects card details. Card numbers and CVCs do not pass through WordPress.
5. Stripe.js creates a ConfirmationToken.
6. The server creates or reuses one PaymentIntent for the order and confirms it with the token.
7. Stripe.js handles any required customer authentication.
8. The server retrieves the PaymentIntent and verifies its amount, currency, account, mode, metadata, and order relationship before marking the order paid.
9. A signed webhook provides independent asynchronous reconciliation.

Relevant Stripe documentation:

- [Payment Element](https://docs.stripe.com/payments/payment-element)
- [PaymentIntents](https://docs.stripe.com/payments/payment-intents)
- [PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle)
- [Server-side finalization with ConfirmationTokens](https://docs.stripe.com/payments/payment-element/migration-ct)

## Recurring donations

WooCommerce Subscriptions remains the scheduler; the module does not create Stripe Billing subscriptions. At signup, the method is attached to a Stripe Customer in the donation account and represented by a WooCommerce payment token. When a renewal is due, WooCommerce creates a renewal order and invokes the gateway-specific scheduled-payment hook. The module validates the subscription, token owner, account, and mode before creating an off-session PaymentIntent.

Successful and failed renewals are reported through WooCommerce Subscriptions' normal APIs. This preserves its status, retry, customer, and administrator workflows.

- [WooCommerce Subscriptions gateway integration](https://woocommerce.com/document/subscriptions/develop/payment-gateway-integration/)
- [Scheduled subscription-payment actions](https://woocommerce.com/document/subscriptions/develop/action-reference/)
- [Failed recurring-payment retries](https://woocommerce.com/document/subscriptions/develop/failed-payment-retry/)
- [Official WooCommerce Stripe subscription implementation used as the behavioral reference](https://github.com/woocommerce/woocommerce-gateway-stripe/blob/48b8c4e93af973c46b24fdcc9c0f16e66673e1ee/includes/compat/trait-wc-stripe-subscriptions.php)

## Saved methods and My Account

Stripe stores the actual PaymentMethod. WooCommerce stores only its provider reference and display fields such as brand, last four digits, and expiration.

`Token_Manager.php` uses `WC_Payment_Token_CC` and adds donation-specific safeguards:

- donation methods have their own default, separate from store methods;
- tokens must match the WordPress user, gateway, Stripe account, and mode;
- a method used by an active donation subscription cannot be deleted prematurely;
- a replacement can update one or all eligible donation subscriptions;
- store and donation Stripe methods cannot be interchanged.

SetupIntents support adding or changing a method without an immediate charge, including zero-dollar trials. Methods intended for renewals use off-session setup with customer consent.

- [WooCommerce Payment Token API](https://developer.woocommerce.com/docs/features/payments/payment-token-api)
- [Stripe SetupIntents](https://docs.stripe.com/payments/setup-intents)
- [Saving a method during payment](https://docs.stripe.com/payments/save-during-payment)
- [Strong Customer Authentication](https://docs.stripe.com/strong-customer-authentication)

## Apple Pay and Google Pay

The module uses Stripe's Express Checkout Element. Stripe determines wallet availability from the device, browser, currency, account configuration, and customer wallet. Unavailable wallets remain hidden. Staging and production domains must be registered separately.

- [Express Checkout Element](https://docs.stripe.com/elements/express-checkout-element)
- [Accept an Express Checkout payment](https://docs.stripe.com/elements/express-checkout-element/accept-a-payment?payment-ui=elements)
- [Register payment-method domains](https://docs.stripe.com/payments/payment-methods/pmd-registration)

## Webhooks and background reconciliation

`Webhook_Handler.php` verifies Stripe's signature against the exact raw request body, accepts only required event types, and places the event ID in Action Scheduler. The worker retrieves current Stripe state, locks the order, verifies the provider object against it, and records processed event IDs.

This is necessary because Stripe can retry events, deliver duplicates, and deliver events out of order. Reconciliation must therefore be idempotent and based on current provider state rather than delivery order.

- [Stripe webhooks, duplicates, and event ordering](https://docs.stripe.com/webhooks)
- [Webhook signature and raw-body requirements](https://docs.stripe.com/webhooks/signature)
- [Action Scheduler](https://actionscheduler.org/)
- [Action Scheduler API](https://actionscheduler.org/api/)

## Refunds and disputes

WooCommerce full and partial refunds are sent to Stripe with deterministic idempotency keys and stored Stripe refund IDs. Refunds initiated in the Stripe Dashboard are reconciled back into WooCommerce without duplication. Dispute webhooks record the dispute ID and status and add an administrative note; they do not automatically delete, refund, or cancel the order.

- [Stripe Refund API](https://docs.stripe.com/api/refunds)
- [Stripe disputes](https://docs.stripe.com/disputes)
- [Stripe dispute lifecycle](https://docs.stripe.com/disputes/how-disputes-work)

## Security and duplicate protection

The module uses overlapping controls:

- Stripe-hosted Elements keep raw card data out of WordPress.
- Server-side WooCommerce data controls the amount and currency.
- Stable idempotency keys make Stripe API retries safe.
- Operation locks prevent concurrent finalization, renewal, refund, and webhook changes.
- Existing intents are reused where appropriate.
- Every token and payment is checked against account ID and test/live mode.
- Webhook signatures and timestamps are verified.
- Secrets are host-injected, not stored in normal WordPress settings or committed to Git.
- Live mode requires an explicit host switch and approved hostname.
- Logs and order notes omit credentials, cardholder data, and raw provider errors.

- [Stripe idempotent requests](https://docs.stripe.com/api/idempotent_requests)
- [Stripe API keys and restricted keys](https://docs.stripe.com/keys)
- [Stripe integration and PCI security](https://docs.stripe.com/security/guide)

## Rejected alternatives

### Category-based routing

Rejected because Newspack already owns a more complete definition of a donation.

### Replacing Newspack's donation UI

Rejected because the Donate block, modal, amount controls, account creation, and campaign behavior already exist.

### Impersonating the official `stripe` gateway

Rejected because it would collide with store settings, customers, tokens, subscriptions, refunds, and webhook state.

### Globally swapping the official Stripe gateway's keys

Rejected because asynchronous events, existing subscriptions, refunds, or retries could reach the wrong account.

### Stripe Billing subscriptions

Rejected because WooCommerce Subscriptions already owns the site's subscription schedule and lifecycle.

### Stripe Connect solely as an internal router

Rejected because Connect changes merchant-of-record, fee, payout, negative-balance, dispute, refund, and tax responsibilities without removing the WooCommerce/Newspack gateway work.

## Verification required before production

Automated policy tests and CI do not replace real payment acceptance testing. Before production enablement, the module must pass staging tests for:

- guest and logged-in one-time donations;
- monthly and annual signup and renewal;
- 3-D Secure and failed-renewal recovery;
- saved-method add, change, delete, and default behavior;
- full, partial, and Dashboard-originated refunds;
- duplicate and out-of-order webhooks;
- donation/store account isolation and mixed-cart blocking;
- cover fees and campaign metadata;
- Apple Pay and Google Pay on compatible devices;
- Action Scheduler execution and delayed jobs.

Production should be deployed disabled, configured with restricted live credentials, and enabled only after a controlled real-money payment and refund confirm the entire path.

## Code map

- `Gateway.php`: payment, setup, renewal, refund, and Stripe-object verification.
- `Routing.php`: Newspack classification and gateway selection.
- `Compatibility.php`: Newspack modal and cover-fee adapters.
- `Token_Manager.php`: WooCommerce tokens and donation subscription methods.
- `Webhook_Handler.php`: signed event ingress and asynchronous reconciliation.
- `Configuration.php`: host-injected secrets, account verification, and live-mode guard.
- `Lock.php`: concurrency protection.
- `assets/checkout.js`: Stripe Elements, wallets, confirmation, and authentication.

In one sentence: **the module leaves the complete Newspack/WooCommerce donation system in place and replaces only its final payment gateway so donation money and payment records remain isolated in Chicago Reader's donation Stripe account.**
