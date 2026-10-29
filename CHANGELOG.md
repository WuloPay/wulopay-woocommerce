# Changelog

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
