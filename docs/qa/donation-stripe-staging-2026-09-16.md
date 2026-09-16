# Donation Stripe staging evidence — 2026-09-16

Status: **incomplete; not approved for production**. The 2026-09-16 follow-up fixed subscription isolation, made migration default to disabled, and added a fail-closed new-payment gate for account separation and scheduler health. The configured test account must now also match the independently verified nonprofit account ID pinned in code, so a wrong account ID and matching wrong key cannot pass by agreeing with each other. The live account ID is deliberately unapproved in code. The canary is now a 12-hour recurring action; the previous one-time/admin-visit-only canary could leave a healthy production runner looking stale after 24 hours. The original payment test below exercised staging commit `56d86d6`; the safety fixes were subsequently deployed disabled by [staging workflow 35134621068](https://github.com/Chicago-Reader-Staging/chicago-reader-plugin/actions/runs/35134621068).

Safety action: after confirming the wrong Stripe account and stuck webhook jobs, **Donation Stripe was disabled in staging** through WooCommerce → Payments → Donation Stripe. The admin showed “Your settings have been saved” and the gateway checkbox remained unchecked. The deployed routing code returns no payment gateways for a donation-only cart when Donation Stripe is unavailable, so this blocks new donation checkout rather than falling back to the official Stripe account. Existing payment reconciliation/refund hooks remain loaded. Do not re-enable until the destination account and queue are corrected and verified.

This record separates observed browser behavior from untested claims. Browser checks used real clicks in the staging site; no WordPress or Stripe API commands were used for site verification. The staging transaction used Stripe's test card, so no real funds moved.

## Environment and sample

- Site: `chicagoreader.newspackstaging.com`
- Feature branch: `feature/newspack-donation-stripe-gateway`
- Deployed code: `56d86d6`; [successful staging workflow](https://github.com/Chicago-Reader-Staging/chicago-reader-plugin/actions/runs/35051444137)
- Test order: `11072078`, $75 monthly donation, completed in WooCommerce
- Test subscription: `11072079`, active, next payment shown as October 15, 2026
- Test payment method: Visa ending 4242, expires 12/34, shown as default in the donor's My Account
- Admin browser verification: logged in as `mthompson` and inspected WooCommerce Status, subscription `11072079`, parent order `11072078`, and the matching Stripe test payment through browser UI on September 16. No WordPress or Stripe API command was used.

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
| Automatic renewal setup | **Staging protection confirmed; renewal untested** | WooCommerce → Status says **Subscriptions Mode: Staging**, with live URL `https://chicagoreader.com`. Subscription `11072079` is Active but says: “Payment method: Manual Renewal Subscription locked to Manual Renewal while the store is in staging mode. Payment method changes will take effect in live mode.” This is not evidence by itself that the gateway caused manual renewal. No automatic renewal has been observed. |
| Stripe destination account and PaymentIntent | **Fail: account isolation** | Parent order `11072078` records PaymentIntent `pi_3UGATMLJyj1mjPmo0uq7HLJr`. The [matching Stripe test payment](https://dashboard.stripe.com/acct_1TqEqILJyj1mjPmo/test/payments/pi_3UGATMLJyj1mjPmo0uq7HLJr) is a succeeded $75 charge in account `acct_1TqEqILJyj1mjPmo`. WooCommerce → Status lists **that same account ID** as the official WooCommerce Stripe gateway's connected account. The test donation therefore does not satisfy the requirement that donations use a separate Stripe account. No further paid acceptance test should be treated as an isolation pass until configuration/routing is corrected. |
| WooCommerce admin notes and token evidence | **Partial** | Subscription `11072079` has notes “Payment status marked complete” and “Status changed from Pending to Active”; parent order `11072078` has the PaymentIntent in its payment note. The Stripe payment says it set up PaymentMethod `pm_1UGATILJyj1mjPmotPsdfE8o` for future off-session payments. A WooCommerce token association and scheduled renewal have not been proven. |
| Gateway readiness | **Fail: configured to the official account** | WooCommerce Payments lists **Donation Stripe — Test mode** and **Legacy Donation Stripe — Inactive**. The Donation Stripe settings page says “Connected to the expected Stripe account” and displays `acct_1TqEqILJyj1mjPmo`, exactly the same account WooCommerce Status lists for the official gateway. Thus the host-injected expected account is itself wrong for the separate-account requirement; the key/account self-check did not catch this. WooCommerce Status reports Newspack 6.51.3 (not the plan's 6.50.3), WooCommerce 11.1.0, Subscriptions 9.2.0, Name Your Price 3.8.2, and PHP 8.3.33. |
| Webhook and Action Scheduler health | **Fail: donation jobs stuck pending** | Donation Stripe settings say **Last scheduler canary: never** and **Last webhook received: September 15 23:29:32 CDT; processed: never**. In WooCommerce → Status → Scheduled Actions, searching `chicago_reader_donation_stripe` finds four pending jobs: one canary scheduled September 15 at 16:49:48 UTC and three `chicago_reader_donation_stripe_process_event` jobs, including event `evt_1UG2FxLJyj1mjPmoMz9YLVVx`, with creation-only logs. All four remained pending on September 16, more than a day after the first was due. Sorting **all completed actions** by newest scheduled date shows the newest completed action was scheduled **August 11, 2026** and finished via Async Request at 17:18:50 UTC; no September completion appears. WooCommerce Status reports WP-Cron disabled, 490 pending actions overall, and 48,850 failed actions. Its active-plugin list includes **Action Scheduler – Disable Default Queue Runner**, whose WordPress plugin description says it removes the default runner from `action_scheduler_run_queue`; [Action Scheduler documents](https://actionscheduler.org/wp-cli/) that an alternate runner is required when this is active. WordPress Site Health separately reports a failed scheduled event, `newspack_nl_mailchimp_refresh_cache`. Together these point to a site-wide scheduler outage, though the precise host cause remains unverified. No manual Run action was invoked, so natural queue behavior remains observable. |

Follow-up browser and API check: the separate Stripe **Reader Institute For Community Journalism/Chicago Reader** test context is `acct_1F24k4LAprqx9n8w`. A read-only test-mode `GET /v1/account` confirms `business_type=non_profit`, `country=US`, and `charges_enabled=true`. This is the nonprofit destination, not the unrelated “New business sandbox” (`acct_1TZC3DCT3JJM9YIo`). A read-only attempt to retrieve the earlier $75 donation PaymentIntent `pi_3UGATMLJyj1mjPmo0uq7HLJr` from this account returned `resource_missing`, corroborating that the earlier charge went to the wrong account. Its API-key page lists **no restricted keys**; do not substitute its account-wide standard secret or temporary CLI credential for the planned restricted integration key. The staging WordPress File Manager is installed but displays “Invalid backend configuration. Readable volumes not available,” so it cannot supply the licensed plugin code for local integration tests. Re-opening subscription `11072079` confirms its “Manual Renewal” display is explicitly attributed by WooCommerce to **Subscriptions Mode: Staging**, not to a gateway failure. The canary and three webhook actions remained pending on the follow-up check.

On September 16 the nonprofit test account gained a dedicated, test-only, enabled webhook endpoint, `we_1UGNP4LAprqx9n8wBiJ3P8IG`, for the exact URL shown by WooCommerce, `https://chicagoreader.newspackstaging.com/wc-api/chicago_reader_donation_stripe/`, with exactly the 11 event types accepted by the handler. Its one-time signing secret was piped directly into the GitHub Actions staging secret without displaying or storing it in the repository. The staging `ACCOUNT_ID` deployment secret was changed to `acct_1F24k4LAprqx9n8w`. The API secret and publishable key have **not** yet been changed, so the gateway must remain disabled and the new account guard should reject payment initiation. A deployment-builder regression was found and fixed: the builder now accepts a mode-matched `rk_test_` key as well as `sk_test_`, with dummy-key build checks confirming the expected acceptance/rejection.

After workflow 35134621068 completed, the real WooCommerce Donation Stripe settings screen showed **disabled**, expected account `acct_1F24k4LAprqx9n8w`, **“Blocked: the API key could not be verified against the expected Stripe account,”** and **last scheduler canary: never**. This verifies that the new account pin is loaded in staging and the remaining old API key does not accidentally authorize a payment. It is a deliberate fail-closed result, not a successful payment test.

## Automated coverage actually present

`composer test` now passes **46 stand-in unit tests / 93 assertions**. They include regressions proving “update all” and token attachment do not migrate an official-Stripe donation subscription, that order-pay routing uses the stored order gateway rather than an unrelated cart, that a wrong-but-distinct account ID is not an approved test destination, and that a mode-matched `rk_test_` restricted key is accepted. They also confirm token attachment does not forcibly disable manual renewal. These tests do **not** boot WordPress, WooCommerce, Newspack, or Stripe and cannot prove renewals.

`composer run test:integration` is a separate real-plugin suite. It currently exits **BLOCKED** before running because this machine has no configured local WordPress installation with the licensed WooCommerce Subscriptions and Name Your Price plugins. It must not be reported as passing. The current integration assertions cover real Newspack classification and gateway/WCS registration, not the full payment lifecycle.

`npm run test:e2e` now has **three passing real-browser, no-charge staging tests**: one-time, monthly, and annual donation selection reaches checkout, mounts a Stripe card iframe, and loads Stripe.js once. These tests stop before payment submission. They are **not** the payment acceptance matrix. Chrome is required by `playwright.config.js`. Pre-payment screenshots are written to ignored `test-results/`; traces are retained on failure. No card data is entered or stored by these checks.

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

1. Correct the donation gateway's test-account identity so it is distinct from the official WooCommerce Stripe account `acct_1TqEqILJyj1mjPmo`; then repeat a new synthetic donation and verify both Stripe dashboards by exact PaymentIntent ID. Do not infer separation from gateway labels.
2. Inspect the subscription's stored gateway and WooCommerce token association, and diagnose the Action Scheduler runner. Never turn automatic payments on globally to investigate the staging label.
3. Obtain an isolated local installation with the specified real plugin versions and run `composer run test:integration`; expand it to token persistence and WCS lifecycle before claiming integration coverage.
4. After deploying reviewed fixes, create a *new synthetic* recurring donor, upstage only that subscription ID, process its renewal through Woo admin, and verify the Woo renewal order and exactly one new donation-account Stripe charge. Continue through the remaining acceptance matrix before production enablement.
