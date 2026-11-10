# Changelog

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
