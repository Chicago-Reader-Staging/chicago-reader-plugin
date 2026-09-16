# Donation Stripe staging evidence — 2026-09-16

Status: **incomplete; not approved for production**.

This record separates observed browser behavior from untested claims. Browser checks used real clicks in the staging site; no WordPress or Stripe API commands were used for site verification. The staging transaction used Stripe's test card, so no real funds moved.

## Environment and sample

- Site: `chicagoreader.newspackstaging.com`
- Feature branch: `feature/newspack-donation-stripe-gateway`
- Deployed code: `56d86d6`; [successful staging workflow](https://github.com/Chicago-Reader-Staging/chicago-reader-plugin/actions/runs/35051444137)
- Test order: `11072078`, $75 monthly donation, completed in WooCommerce
- Test subscription: `11072079`, active, next payment shown as October 15, 2026
- Test payment method: Visa ending 4242, expires 12/34, shown as default in the donor's My Account

Do not use these test objects as proof of live-account behavior.

## Observed results

| Scenario | Result | Evidence / limitation |
| --- | --- | --- |
| Staging deployment | Pass | Workflow above completed, including lint and SFTP swap. |
| Newspack monthly donation selection to checkout | Pass | Browser selected the $75 monthly option and reached checkout showing `Donate: Monthly`, `$75.00 / month`. |
| Stripe checkout field mounting | Pass | Card fields and Google Pay appeared in the checkout browser. |
| Duplicate Stripe.js regression | Pass | Fresh browser DOM showed exactly one `js.stripe.com` script after `56d86d6`; prior duplicate warning was absent. |
| Initial monthly card payment | Pass at WooCommerce UI level | Test card checkout reached order-received; order `11072078` displays Completed and subscription `11072079` displays Active. |
| Saved payment method | Partial | Donor My Account lists Visa ending 4242 as default, but does not visibly label it as a donation method. Ownership, Stripe account association, and token persistence across renewal were not verified. |
| Automatic renewal setup | **Unresolved** | Subscription details display **“Via Manual Renewal”** despite the saved Visa card. This may be a label bug or an actual manual-renewal configuration. Do not treat the subscription as renewal-ready until an admin-side inspection and forced scheduled renewal prove it. |
| Stripe destination account and PaymentIntent | **Blocked** | Stripe Dashboard browser session is signed out. No Stripe-side payment, account ID, or webhook status was verified. |
| WooCommerce admin notes/webhook evidence | **Blocked** | Available site browser session is a customer account; `/wp-admin` redirects to My Account. |

## Automated coverage actually present

`composer test` passes **8 tests / 16 assertions** in `tests/unit/DonationStripePolicyTest.php`. These are stub-based policy tests covering basic cart classification, minor-unit conversion, gateway identity, and selected advertised capabilities. They do not boot WordPress, WooCommerce, Newspack, Stripe, or a browser. There is no integration or end-to-end suite in the repository. CI passing is not a full payment acceptance result.

## Acceptance work not yet evidenced

- Guest one-time and guest recurring checkout with account creation.
- Annual recurring signup and a scheduled automatic renewal.
- Failed renewal, authentication-required recovery, retry, and payment-method replacement.
- Add/change/delete/default donation methods in My Account; admin changes; multiple subscriptions.
- Apple Pay on supported Apple hardware and Google Pay completion on a supported device.
- Full/partial WooCommerce refunds, Dashboard-originated refunds, disputes, and idempotent reconciliation.
- Valid, duplicate, delayed, and out-of-order signed webhooks; Action Scheduler delivery and queue health.
- Cover-fee calculation, campaign/referrer/UTM metadata, 3DS, declines, reload/double-click behavior.
- Merchandise and non-donation subscription isolation on the official Stripe gateway; exact donation Stripe account identity.
- HPOS on/off, security/authorization checks, and production live-mode controls.

## Next required checks

1. In a signed-in Stripe **test-mode** Dashboard, locate order `11072078` by its payment metadata or test email and confirm the PaymentIntent status and the donation account identity. This cannot be inferred from a WooCommerce thank-you page.
2. In WooCommerce admin, inspect subscription `11072079` for the actual payment method, renewal mode, token metadata, and order notes. Resolve the “Via Manual Renewal” contradiction before any production release.
3. Run a controlled staging scheduled renewal for a synthetic subscription, then verify the resulting WooCommerce renewal order and Stripe test-account charge in both browser dashboards.
4. Build the missing integration/browser suites and execute the remaining staging matrix before production enablement.
