# Changelog

## 1.4.0
- Store currencies NAD, BWP, LSL and SZL besides ZAR (`WuloPay_Gateway::CURRENCIES`, filter `wulopay_wc_currencies`).
  Sessions, plans and refunds already carry the order's currency. The settings page warns when a live store uses a
  currency other than ZAR, which WuloPay enables per merchant.

## 1.3.0
- One payable session per order (`settle_previous_session()`): on a repeated `process_payment()` the stored session
  is retrieved first. Paid → order completed, redirect to the receipt; processing (payment or 3-D Secure under way) →
  the shopper is asked to finish it; open → `POST /Checkout/Sessions/{id}/Cancel` (a 409 means the shopper just
  started paying it: re-read), then a new session. Replaced session ids are kept in `_wulopay_previous_sessions`; a
  payment reported on one of them adds a warning note instead of being ignored.
- Merchant-portal refunds: `refund.succeeded` records the gap between WuloPay's `totalRefunded` and the order's
  refunded total as a WooCommerce refund (`refund_payment` false, no restock). Refunds started in WooCommerce are
  already counted, and repeats change nothing. Needs the WuloPay API to send `refund.succeeded` for portal refunds.
- `sandbox_webhook_secret` and `live_webhook_secret`; a secret only accepts its environment's events. The earlier
  single `webhook_secret` stays valid for both (shown in the settings until cleared).
- `WuloPay_Status_Check`: Action Scheduler job 10 minutes after each new session, then every 15 minutes (at most 8
  checks), until the order is paid or the session has expired or was cancelled. Unscheduled on uninstall.

## 1.2.1
- Fix: "Amount can have at most 2 decimals" on servers with `serialize_precision = 17`. Bundles wulopay-php 1.2.2,
  which encodes request bodies with the shortest exact form of each number.
- Block checkout: `is_active()` is true whenever the method is enabled (the checkout block editor lists it, and
  gets the script through `get_payment_method_script_handles_for_admin()`); shoppers see it only when
  `canMakePayment()` finds the store ready (same rules as `is_available()`).

## 1.2.0
- WuloPay logo in the WooCommerce Payments settings list (square mark) and next to the payment method at the classic
  and block checkout (full logo). Filter `wulopay_wc_checkout_logo` changes the checkout logo URL, or removes it
  when it returns ''.
- `needs_setup()`: while the selected environment has no usable API key, WooCommerce shows "Action needed" and won't
  enable the method ("Finish setting up WuloPay before enabling it"), instead of enabling a method that never
  appears at checkout.
- The settings page lists what keeps WuloPay off the checkout: switched off, store currency not ZAR, missing key id,
  secret or live API URL.

## 1.1.0
- WooCommerce Subscriptions support: sign-ups with the shopper's agreement to the terms (free trials and sign-up fees
  included), renewals charged through WuloPay with idempotency keys, payment method changes (card-update page),
  cancellation, suspension, reactivation and date changes. Requires WooCommerce Subscriptions 7.0+ when used.
- Bundles wulopay-php 1.1.0.

## 1.0.0
- WuloPay hosted checkout for WooCommerce (classic and block checkout, HPOS).
- Orders confirmed by signed webhooks and by a server-side check when the shopper returns.
- Full and partial refunds from the order screen.
- Requires PHP 8.3+, WordPress 6.6+, WooCommerce 9.0+ (tested with WordPress 7.1, WooCommerce 11.2, PHP 8.4.27).
- Admin warning when the server's PHP release has known critical security flaws (PHP2Shell, October 2026).
