=== WuloPay for WooCommerce ===
Contributors: wulopay
Tags: payments, credit card, south africa, 3d secure, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Visa and Mastercard in your WooCommerce store with WuloPay. Shoppers pay on WuloPay's secure page, with 3-D Secure.

== Description ==

WuloPay for WooCommerce adds card payments (Visa, Mastercard) to your store. At checkout the shopper is sent to
WuloPay's secure payment page, pays (3-D Secure is handled there), and comes back to your order confirmation.

* Card details never touch your site, which keeps your PCI DSS scope at the simplest level (SAQ A).
* Orders are confirmed by signed webhooks, and checked again with WuloPay when the shopper returns.
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
4. In the merchant portal, Product Configuration, Webhooks: add the endpoint shown under "Webhook signing secret"
   (`https://your-store/wp-json/wulopay/v1/webhook`) and paste its signing secret into the plugin settings.
5. Place a test order with a sandbox test card, for example 4000 0000 0000 2701, any future expiry and any security code.

== Frequently Asked Questions ==

= Do card details pass through my website? =
No. The shopper types them on WuloPay's payment page, inside the card processor's secure fields.

= What if my webhook isn't set up yet? =
When the shopper returns, the plugin asks WuloPay whether the order was paid and completes it. Webhooks also cover
shoppers who close the browser before returning, so set them up before going live.

= Does it work with WooCommerce Subscriptions? =
Yes (WooCommerce Subscriptions 7.0 or newer). At sign-up the shopper agrees to the subscription's terms on WuloPay's
page and the card is stored with WuloPay. WooCommerce Subscriptions keeps the schedule, and each renewal is charged
through WuloPay; a declined renewal follows your WooCommerce Subscriptions retry rules. Customers change their card
from My Account. Supported: cancellation, suspension, reactivation, date changes and payment method changes. Not
supported: changing a subscription's amount (charges are capped at what the shopper agreed to) and several
subscriptions in one order. Refund renewal payments in the WuloPay merchant portal.

= How do I go live? =
WuloPay gives you live API keys and the live API address. Enter them, choose Live and place a small real order.

== Changelog ==

= 1.1.0 =
* WooCommerce Subscriptions support: sign-ups (with free trials and sign-up fees), automatic renewals, payment method
  changes, cancellation, suspension, reactivation and date changes.

= 1.0.0 =
* First release: hosted checkout, signed webhooks, refunds, block checkout, HPOS.
