=== OutlinePay for WooCommerce ===
Contributors: outlinepay
Tags: payments, korea, krw, virtual account, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take Korean payment methods in WooCommerce through your payment provider, connected by OutlinePay.

== Description ==

OutlinePay for WooCommerce adds the payment methods your Korean payment provider has enabled for your store. Shoppers pay in the provider's own payment window. Your store never sees card details.

The first supported provider is CookiePay.

Methods your provider can enable:

* Credit card
* Foreign card (paid in KRW)
* Real-time bank transfer
* Virtual account
* KakaoPay
* NaverPay
* Mobile carrier billing

What the plugin does:

* Connects your store with a one-time connect code from your payment provider. No API keys to copy.
* Shows each enabled method at checkout. You can hide any method. You cannot add one your provider has not enabled.
* Works with the Checkout block and the classic checkout.
* Marks an order paid only after OutlinePay confirms the payment, for the exact order total.
* Puts a virtual account order on hold and shows the bank, account number and deadline on the thank-you page and in the order email. The order moves to processing when the deposit arrives.
* Checks recent unpaid orders every 15 minutes, so a payment is not missed when the shopper closes the window early.
* Supports High-Performance Order Storage (HPOS).

The store currency must be Korean won (KRW).

OutlinePay is operated by Arandu Payments LLC.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate OutlinePay for WooCommerce.
3. Set the store currency to South Korean won (KRW) in WooCommerce > Settings > General.
4. Ask your payment provider for a connect code.
5. Open WooCommerce > OutlinePay, paste the code and click Connect.
6. Choose which methods to show at checkout and click Save methods.

The plugin sends nothing to OutlinePay until you click Connect.

== Frequently Asked Questions ==

= Why do I not see any OutlinePay methods at checkout? =

Each method shows only when all of these are true. The store is connected. The store currency is KRW. Your payment provider has the method enabled. You have not hidden it on the OutlinePay page.

= What is test mode? =

Your payment provider decides whether a connection is in test mode or live mode. The OutlinePay page shows a Test mode badge when no real money moves. To go live, ask your payment provider for a live connect code.

= Does deactivating the plugin disconnect my store? =

No. Deactivating stops background checks but keeps the connection. Use the Disconnect button to disconnect. Deleting the plugin also disconnects the store.

= How do I refund a payment? =

Refund it in your payment provider's dashboard. This version does not refund from WooCommerce.

= Where are the logs? =

In WooCommerce > Status > Logs, under the source "outlinepay". The logs hold order ids, payment ids, statuses and error codes. They never hold shopper details or credentials.

== External services ==

This plugin connects to two external services. It contacts them only after you connect the store.

= OutlinePay =

OutlinePay connects your store to your payment provider. The plugin talks to the OutlinePay API at api.outlinepay.com.

What is sent, and when:

* When you click Connect: the connect code, your store address, the address OutlinePay uses to notify your store, and the versions of PHP, WordPress, WooCommerce and this plugin.
* Once after each plugin update: the same version numbers.
* When a shopper clicks to pay: the order id, order number, total, currency, chosen method and the address to return the shopper to. Also the shopper's billing name, email, phone and address. OutlinePay passes these to your payment provider to open the payment window and does not store them.
* To check a payment: the order id and payment id. This happens when the shopper returns, when OutlinePay notifies your store, every 15 minutes for recent unpaid orders, and before WooCommerce cancels an unpaid order.
* Every hour, and when you open the OutlinePay page: a request for the methods your provider has enabled.
* When you click Disconnect or delete the plugin: a request to disconnect.

OutlinePay terms: https://outlinepay.com/terms
OutlinePay privacy policy: https://outlinepay.com/privacy

= CookiePay =

CookiePay is the payment provider that takes the payment. The shopper is sent to CookiePay's payment window and enters payment details there. The shopper's billing name, email, phone and address reach CookiePay through OutlinePay, as described above.

CookiePay privacy policy: https://www.cookiepayments.com/terms/private

== Changelog ==

= 0.1.0 =
* First release.
