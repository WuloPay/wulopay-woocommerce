# WuloPay for WooCommerce

Accept Visa and Mastercard in a WooCommerce store with WuloPay. The shopper pays on WuloPay's secure payment page
(3-D Secure included) and returns to the order confirmation; the order is confirmed by a signed webhook and checked
again with WuloPay when the shopper comes back. Card details never touch the store.

Requires PHP 8.3+, WordPress 6.6+ and WooCommerce 9.0+ (tested with WordPress 7.1, WooCommerce 11.2, PHP 8.4.27).
Use PHP 8.4 or 8.5 on its latest patch release; the plugin warns store managers when PHP lacks critical security fixes.
Payments are in the store's currency: ZAR, NAD, BWP, LSL or SZL (sandbox: all five; live: other than ZAR on request).

## Install

Download the zip from [Releases](../../releases) (or zip this repository without `dev/` and `.github/`), then
WordPress admin, Plugins, Add New, Upload. Setup steps are in [readme.txt](readme.txt).

## How it works

| Step | What happens |
|---|---|
| Place order | `process_payment()` creates a WuloPay checkout session (amount, order number, return URL, the order id in metadata) and redirects to its URL. The order stays *pending*. |
| Shopper pays | On WuloPay's page: card fields, 3-D Secure, receipt, then back to the order-received page with `session_id`. |
| Place again | If the order already has a session: paid → completed and sent to the receipt; in progress → the shopper is asked to finish it; open → cancelled (`POST /Checkout/Sessions/{id}/Cancel`) before a new one is created. One payable session per order. |
| Confirmation | `POST /wp-json/wulopay/v1/webhook` with `checkout.session.completed` (signature checked against the sandbox or live secret, event id de-duplicated), or on return the plugin calls `GET /Checkout/Sessions/{id}`, or the background check (Action Scheduler, every 15 minutes until the session ends). The order is completed only if the session id, amount and currency match the order. |
| Refund | The order screen's refund button calls `POST /Checkout/Sessions/{id}/Refunds`. A refund made in the merchant portal arrives as `refund.succeeded` and is added to the order (the gap between WuloPay's total refunded and the order's). |

**WooCommerce Subscriptions** (`includes/class-wulopay-subscriptions.php`, active only when the extension is):

| Step | What happens |
|---|---|
| Sign-up | The subscription's recurring terms become a WuloPay plan (created once per set of terms). The session uses `mode: "subscription"`, merchant billing, `maxAmount` = the recurring total and `firstAmount` = the order total (0 for a free trial: the card is only checked and stored). The shopper agrees to the terms on WuloPay's page. |
| Confirmation | Same as a payment; the WuloPay subscription id is stored on the WooCommerce subscription. A free-trial order (total 0) is completed when the card is stored. |
| Renewal | `woocommerce_scheduled_subscription_payment_wulopay` calls `POST /Billing/Subscriptions/{id}/Charges` with idempotency key `wc-renewal-<order>-<attempt>`: paid → `payment_complete`; declined → order *failed* (WooCommerce Subscriptions' retry rules apply; the next attempt uses a new key). A network error keeps the key, so a retry can't charge twice. |
| Change card | My Account → Change payment method opens a WuloPay card-update page (`CardUpdateSession`); switching from another method stores a card with a zero-amount sign-up. |
| Cancel / expire | Cancels the WuloPay subscription, so the stored card can't be charged again. |

Not declared to WooCommerce Subscriptions: amount changes and multiple subscriptions per order.

**Branding and setup checks**: the gateway's `icon` is the square WuloPay mark (WooCommerce Payments list);
`get_icon()` and the block label show the full logo at checkout (`wulopay_wc_checkout_logo` filter, '' for none).
Under the method, a "We accept" row shows the Visa and Mastercard marks (setting `accept_marks`; filter
`wulopay_wc_accept_marks`, a list of `['brand', 'label', 'src']`, empty for no row).
`is_available()` needs: enabled, a store currency in `WuloPay_Gateway::CURRENCIES` (filter `wulopay_wc_currencies`), and a key for the selected environment. `needs_setup()` is true
without a key, so WooCommerce shows "Action needed" and won't enable the method; `checkout_blockers()` lists the
reasons on the settings page.

Built on [wulopay-php](https://github.com/WuloPay/wulopay-php), bundled in `lib/`.

## Develop

```
docker compose -f dev/docker-compose.yml up -d
WULOPAY_KEY_ID=wk_... WULOPAY_SECRET=... sh dev/setup.sh     # store at http://localhost:8088 (admin / admin)
```

The sandbox accepts `http://localhost` return URLs. WuloPay can't reach a local webhook URL; use a tunnel, or rely
on the return check while developing.

Checks run in CI: PHP lint (8.3 to 8.5), WordPress coding standards (WordPress-Extra), PHP compatibility (8.3+).

## Security

Report problems privately as described in [SECURITY.md](SECURITY.md). Keep API secrets in the plugin settings
(stored in the WordPress database), never in code or version control.

## Licence

GPL-2.0-or-later (see [LICENSE](LICENSE)). The bundled wulopay-php library is MIT licensed.
