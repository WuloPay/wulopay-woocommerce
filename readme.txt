=== WuloPay for WooCommerce ===
Contributors: wulopay
Tags: payments, credit card, south africa, 3d secure, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Visa and Mastercard in your WooCommerce store with WuloPay. Shoppers pay on WuloPay's secure page, with 3-D Secure.

== Description ==

WuloPay for WooCommerce adds card payments (Visa, Mastercard) to your store. At checkout the shopper is sent to
WuloPay's secure payment page, pays (3-D Secure is handled there), and comes back to your order confirmation.

* Card details never touch your site, which keeps your PCI DSS scope at the simplest level (SAQ A).
* Orders are confirmed by signed webhooks, checked again with WuloPay when the shopper returns, and checked in the
  background until the payment link expires, so a payment is never missed.
* One payment link per order: if the shopper places the order again, the earlier link is cancelled first, so an
  order can't be paid twice.
* Full and partial refunds made in the WuloPay merchant portal are added to the WooCommerce order automatically.
* Full and partial refunds from the WooCommerce order screen.
* Works with the classic and the block checkout, and with High-Performance Order Storage.
* Your branding on the payment page when WuloPay enables it for your account.
* Works with WooCommerce Subscriptions: the card is stored with WuloPay at sign-up and every renewal is charged
  automatically, including free trials and sign-up fees.

You need a WuloPay merchant account. Payments are in South African rand (ZAR).

Keep PHP up to date: use PHP 8.4 or 8.5 on its latest patch release. The plugin warns you in the admin if your
PHP release has known critical security flaws.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. In the WuloPay merchant portal, open Product Configuration, enable **Hosted Checkout** and copy the API key id and secret.
3. In WooCommerce, Settings, Payments, WuloPay: paste the key and secret for the Sandbox (or Live) environment and enable the method.
4. In the merchant portal, Product Configuration, Webhooks: add the endpoint shown under "Sandbox webhook signing
   secret" (`https://your-store/wp-json/wulopay/v1/webhook`) and paste its signing secret into that field. When you
   go live, do the same in your live merchant portal and paste that secret into "Live webhook signing secret".
5. Place a test order with a sandbox test card, for example 4000 0000 0000 2701, any future expiry and any security code.

== Frequently Asked Questions ==

= Do card details pass through my website? =
No. The shopper types them on WuloPay's payment page, inside the card processor's secure fields.

= What if my webhook isn't set up yet? =
When the shopper returns, the plugin asks WuloPay whether the order was paid and completes it, and orders still
waiting for payment are checked in the background every 15 minutes until the payment link expires. Webhooks confirm
payments straight away, so set them up before going live.

= Does it work with WooCommerce Subscriptions? =
Yes (WooCommerce Subscriptions 7.0 or newer). At sign-up the shopper agrees to the subscription's terms on WuloPay's
page and the card is stored with WuloPay. WooCommerce Subscriptions keeps the schedule, and each renewal is charged
through WuloPay; a declined renewal follows your WooCommerce Subscriptions retry rules. Customers change their card
from My Account. Supported: cancellation, suspension, reactivation, date changes and payment method changes. Not
supported: changing a subscription's amount (charges are capped at what the shopper agreed to) and several
subscriptions in one order. Refund renewal payments in the WuloPay merchant portal.

= WuloPay doesn't show at checkout =
WuloPay is offered only when all of these hold: the method is enabled, the store currency is South African rand (ZAR),
and the API key id and secret for the selected environment (Sandbox or Live, plus the API URL for Live) are filled in.
The WuloPay settings page lists whatever is missing, and WooCommerce, Settings, Payments shows "Action needed" until
the key is entered.

= Can I change the logo at checkout? =
Yes. The `wulopay_wc_checkout_logo` filter returns the logo URL; return another URL, or an empty string for no logo.

= How do I go live? =
WuloPay gives you live API keys and the live API address. Enter them, choose Live and place a small real order.

== Changelog ==

= 1.3.0 =
* One payment link per order: when a shopper places the same order again (back button, a second tab, a changed
  cart), the earlier WuloPay payment page is cancelled before a new one opens, so the order can't be paid twice. If
  the earlier one was already paid, the shopper goes straight to the order confirmation; if a payment is in progress
  in another window, the shopper is asked to finish it there.
* Refunds made in the WuloPay merchant portal are added to the WooCommerce order automatically (stock is not changed).
* Separate webhook signing secrets for sandbox and live. The single secret of earlier versions keeps working until
  you replace it.
* Background status check: orders still waiting for payment are checked with WuloPay every 15 minutes until the
  payment link expires, in case a webhook was missed and the shopper didn't come back to the shop.

= 1.2.1 =
* Fix: on some hosts payments failed with "Amount can have at most 2 decimals" (servers with PHP's
  serialize_precision set to 17). Amounts are now always sent with two decimals.
* Block checkout: WuloPay is listed in the checkout block editor whenever it is enabled, and offered to shoppers once
  the store is set up.

= 1.2.0 =
* The WuloPay logo in the WooCommerce Payments list and next to the payment method at checkout (classic and block).
  Developers can change or remove the checkout logo with the wulopay_wc_checkout_logo filter.
* Clearer setup: until an API key is entered for the selected environment, WooCommerce shows "Action needed" and asks
  you to finish setting up WuloPay before enabling it. The settings page says what keeps WuloPay off the checkout
  (switched off, store currency not ZAR, or a missing key).

= 1.1.0 =
* WooCommerce Subscriptions support: sign-ups (with free trials and sign-up fees), automatic renewals, payment method
  changes, cancellation, suspension, reactivation and date changes.

= 1.0.0 =
* First release: hosted checkout, signed webhooks, refunds, block checkout, HPOS.
