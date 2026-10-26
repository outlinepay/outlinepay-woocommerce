=== OutlinePay for WooCommerce ===
Contributors: outlinepay
Tags: payments, korea, krw, virtual account, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.9
Stable tag: 0.6.0
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
* Puts a virtual account order on hold and shows the bank, account number and deadline on the thank-you page, the shopper's order page and in the order email. The order moves to processing when the deposit arrives.
* Checks unpaid orders every 15 minutes, so a payment is not missed when the shopper closes the window early.
* Marks test payments as test payments in order notes, on the order screen, on the shopper's order received page and in the shopper's emails.
* Refunds from the order screen with WooCommerce's Refund button, once your payment provider turns refunds on for your store. Card, foreign card, bank transfer, KakaoPay and NaverPay payments can be refunded. Partial refunds work for card payments only.
* Adds refunds made in your payment provider's dashboard to the order, so WooCommerce shows the same refunded total.
* Can let shoppers cancel their own paid orders from My Account. Cancelling refunds the full payment and cancels the order. Off until you turn on "Let shoppers cancel their own paid orders".
* Shows a "View card receipt" link for card payments on the order screen, the thank-you page, the shopper's order page and the completed-order email.
* Supports High-Performance Order Storage (HPOS).
* Walks you through setup after you connect. The OutlinePay page lists each check in two groups: needed to take payments, and keeps things running smoothly. Fix all makes the fixes the plugin can make itself. Payment methods show at checkout once every check needed to take payments passes.
* Checks its own setup. The Self-check on the OutlinePay page shows each check, who needs to act when one fails, a button for the fixes the plugin can make, and a diagnostic code to send to your payment provider. It also runs once a day, and again when the store currency or the site address changes, or after an update.
* Shows a notice on every admin page while a check you can fix fails, with a link to the OutlinePay page.
* Emails the site admin address when a daily check starts failing. Turn off "Email me when a daily check fails" to stop it.
* Adds an "OutlinePay connection" test to Tools > Site Health.
* On WordPress 6.9 and later, offers two read-only abilities for tools and AI agents that act for a store manager: run the self-check, and read the connection status.

The store currency must be Korean won (KRW).

OutlinePay is operated by Arandu Payments LLC.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate OutlinePay for WooCommerce.
3. Set the store currency to South Korean won (KRW) in WooCommerce > Settings > General.
4. Ask your payment provider for a connect code.
5. Open WooCommerce > OutlinePay, paste the code and click Connect.
6. The page shows the setup checks. Click Fix all. If a check still fails, follow the line next to it, then click Check again.
7. When every check passes, choose which methods to show at checkout and click Save methods.

The plugin sends nothing to OutlinePay until you click Connect.

The plugin needs scheduled tasks to run: WP-Cron, or a server cron job that replaces it. Without them the daily self-check and the background reconcile cannot run, and the setup check for WP-Cron does not pass. Most hosts have this on by default.

== Frequently Asked Questions ==

= Why do I not see any OutlinePay methods at checkout? =

Each method shows only when all of these are true. The store is connected. Setup on the OutlinePay page is complete. The store currency is KRW. Your payment provider has the method enabled. You have not hidden it on the OutlinePay page.

= What is test mode? =

Your payment provider decides whether a connection is in test mode or live mode. The OutlinePay page shows a Test mode badge while the connection is in test mode. Test payments go through your payment provider's test system and are not real sales, so do not ship goods for them. To go live, ask your payment provider for a live connect code.

= Does deactivating the plugin disconnect my store? =

No. Deactivating stops background checks but keeps the connection. Use the Disconnect button to disconnect. Deleting the plugin also disconnects the store.

= How do refunds work? =

When your payment provider has turned refunds on for your store, open the order and use WooCommerce's Refund button. The refund goes to your payment provider through OutlinePay. Card, foreign card, bank transfer, KakaoPay and NaverPay payments can be refunded. Partial refunds work for card payments only. Your payment provider sets a daily refund limit for your store.

Sometimes the payment provider takes a moment to confirm a refund. WooCommerce then shows a message, and the refund appears on the order by itself once it is confirmed.

Virtual account and mobile carrier billing payments cannot be refunded from WooCommerce. Refund them in your payment provider's dashboard. A refund made there also appears on the order.

The Connection table on the OutlinePay page shows whether refunds are on.

= Can shoppers cancel their orders? =

Only if you turn on "Let shoppers cancel their own paid orders" on the OutlinePay page and choose the order statuses. The shopper then sees a Cancel button on their order in My Account until anything is refunded. A paid order never gets WooCommerce's own Cancel link, which would cancel it without a refund. Cancelling refunds the full payment and sets the order to Cancelled.

= What happens on a staging copy of my store? =

The plugin remembers the address your store was connected at. A copy at another address, such as a one-click staging site, turns OutlinePay off by itself. The copy cannot take real payments and sends nothing to OutlinePay. The OutlinePay page then offers two choices. Connect the copy separately with its own connect code. Or confirm that your store moved to the new address.

= What is the diagnostic code? =

The Self-check shows a code. Send it to your payment provider when you need help. A code that starts with chk_ means OutlinePay received the check and your provider can look it up. A code that starts with OP-OFFLINE- was made on your site because OutlinePay could not be reached. Each letter after it names one failed check.

= Where are the logs? =

In WooCommerce > Status > Logs, under the source "outlinepay". The logs hold order ids, payment ids, refund ids, amounts, statuses and error codes. They never hold shopper details or credentials.

== External services ==

This plugin connects to two external services. It contacts them only after you connect the store. A copy of the store at another address contacts neither.

= OutlinePay =

OutlinePay connects your store to your payment provider. The plugin talks to the OutlinePay API at api.outlinepay.com.

What is sent, and when:

* When you click Connect: the connect code, your store address, the address OutlinePay uses to notify your store, and the versions of PHP, WordPress, WooCommerce and this plugin.
* Once after each plugin update: the same version numbers.
* When a shopper clicks to pay: the order id, order number, total, currency, chosen method, the address to return the shopper to, and an item name made of your store name and the order number. Also the shopper's billing name, email, phone and address. OutlinePay passes these to your payment provider to open the payment window and does not store them.
* When the payment provider opens its window with a form, OutlinePay sends that form back to your store. The form can hold the shopper's billing details. Your store keeps it on the order until the shopper returns, or the order is paid, on hold, cancelled or failed, and the shopper's browser posts it to the provider. A form nobody used is removed at the first background check after it expires, and WooCommerce's personal data eraser removes it too. It is never logged.
* To check a payment: the order id and payment id. This happens when the shopper returns, when OutlinePay notifies your store, every 15 minutes for recent unpaid orders, and before WooCommerce cancels an unpaid order.
* When you refund an order from the order screen: the payment id, the amount and WooCommerce's refund id. The refund reason stays in WooCommerce.
* When a shopper cancels their own order (only if you turned this on): the payment id, the order total and a refund id made from the order id. No shopper details.
* To check a refund that your payment provider has not confirmed yet: the refund id. This happens when OutlinePay notifies your store and every 15 minutes until it is confirmed.
* When a shopper finishes an order with another payment method after opening an OutlinePay payment: the order id, and a request to close the open OutlinePay payment.
* Every hour: a request for the methods your provider has enabled.
* When you open the OutlinePay page: a request for the connection status and the enabled methods. If your store's notification address has changed, the new address is sent.
* When you confirm that your store moved to a new address: the new notification address.
* When you click Disconnect or delete the plugin: a request to disconnect. A staging copy never sends this, so it cannot switch off your live store.
* When you click Run check on the OutlinePay page: the results of the store's own checks. Each result is a check name, pass or fail, who needs to act, and short details such as the store currency, the software versions, how many methods are shown and how long OutlinePay took to answer. No shopper or order details. OutlinePay then sends one signed test message to the store's notification address.
* Once a day: the same check results.
* When the store currency or the site address changes, and after WooCommerce or this plugin is updated: the same check results.

While "Email me when a daily check fails" is on (the default), the email goes from your own site to the site admin address. Nothing about it is sent to OutlinePay.

OutlinePay terms: https://outlinepay.com/terms
OutlinePay privacy policy: https://outlinepay.com/privacy

= CookiePay =

CookiePay is the payment provider that takes the payment. The shopper clicks Pay on a page of your store, which opens CookiePay's payment window, and enters payment details there. The shopper's billing name, email, phone and address reach CookiePay through OutlinePay, as described above.

CookiePay privacy policy: https://www.cookiepayments.com/terms/private

== Changelog ==

= 0.6.0 =
* Inside the plugin, the page carries your payment provider's name and logo once the store is connected. Stores connected before this version get them within a day, with the daily check. Later changes by the provider arrive the same way.
* Order notes and the admin notices use your payment provider's name, for example "CookiePay payment ... is paid." A line under the heading says "Powered by OutlinePay".
* The Connection table names the plugin and its version.
* The row on WooCommerce > Settings > Payments says "Get paid through your preferred local payment providers." and names the connected provider.

= 0.5.3 =
* After a cancel or a refusal in the payment window, the pay page always says what happened. The message no longer goes missing on the first load. It shows once per attempt.
* The checks on the OutlinePay page come in two groups: "Needed to take payments" and "Keeps things running smoothly". Setup is done when every check in the first group passes, as before.
* When OutlinePay cannot reach the store's payment update address, the check says what that changes: orders paid by shoppers who close the window update within 15 minutes instead of right away.
* When setup is done but a recommended check fails, the page says how many need attention.
* The admin notice now shows whenever a check needed to take payments fails: "OutlinePay: a check needed to take payments is failing." Other checks you can fix keep the softer notice.
* Run check no longer says "A check ran less than a minute ago." when it did update the results.

= 0.5.2 =
* The self-check runs again when the store currency or the site address changes, and after WooCommerce or this plugin is updated.
* The hourly method refresh also checks the store currency, the connection, the methods shown at checkout and the scheduled tasks. A problem shows within an hour. A refresh that works also counts as OutlinePay answering.
* When a failing check hides the payment methods, every admin page shows "OutlinePay: payment methods are not showing at checkout." with a link to the OutlinePay page. When another check fails that you can fix, the notice says "OutlinePay's self-check found a problem." It goes away when a later check passes.
* "Email me when a daily check fails" is now on by default. If you turned it off before, it stays off.

= 0.5.1 =
* The payment window opens larger, at least 900 by 650 pixels when the screen allows. The provider's whole page, with the amount, now fits.
* The plugin tells your payment provider whether the shopper is on a phone or a computer, so a phone gets the provider's phone page.
* When the shopper presses Cancel in the payment window, the store says the payment was cancelled. When the provider refuses the payment in its window, the store says it was refused and suggests another card or method. The order gets one private note for each, however often the shopper reloads.
* An order paid in test mode says so on the shopper's order received page and in the emails the shopper gets.
* WooCommerce > Settings > Payments shows "Test mode" next to OutlinePay while the store is in test mode.

= 0.5.0 =
* WooCommerce > Settings > Payments shows one OutlinePay row instead of one row per method. The plugin now needs WooCommerce 9.9 or later.
* After activation, a notice links to the OutlinePay page and puts the cursor in the connect code field.
* After connecting, the OutlinePay page shows a setup screen with every check and its result. Fix all makes every fix the plugin can make itself: it sets the store currency, schedules the background jobs and runs them, and fetches the payment methods. Checks the plugin cannot fix keep their instruction, such as asking your host to run WP-Cron.
* Payment methods show at checkout only after every setup check has passed once. After that, a failing daily check shows on the page but does not hide checkout. A store that was already connected before updating to 0.5.0 skips setup and keeps its checkout.
* When a setup check still fails, the setup screen links to your payment provider's support page and shows the diagnostic code to give them.
* When a payment cannot start, the order gets one private note with the reason, such as an error from the payment provider. Pressing Place order again with the same problem adds no further note.
* At connect, and in the daily self-check, the plugin checks that the store currency is one your payment provider takes, and offers one click to switch the store. Prices keep their numbers. Prices then show the number of decimals that currency has, none for the won.
* Self-check rows that fail now carry their fix: Run now for background checks, Use this address for a moved store, and Copy code when OutlinePay recorded problems for your payment provider.
* Order notes are always in the store's language. A private note when a shopper starts an OutlinePay payment, and another when a payment window attempt expires. A refused refund gets a note with the amount and the reason.
* The pay page has a link back to the order. While the payment window is open, the Pay button says to finish the payment in the window.
* Checkout shows each payment method's name only, without a line under it.
* In My Account, an order that already has a refund says that cancellation is not available, instead of showing nothing.
* Payment methods show at checkout right after connecting, without waiting for the background refresh.

= 0.4.2 =
* The order screen offers Refund via OutlinePay only on orders that were paid. An order the shopper never paid shows a manual refund only.
* Test mode no longer says that no real money moves. Some providers charge a real card in test mode and reverse it later, so the plugin now says to ship nothing for test payments.

= 0.4.1 =
* Payment method names and the line under them now follow the store's language. English stores see English, Korean stores see Korean.

= 0.4.0 =
* Payment windows that open with a form now work. The shopper sees a page of your store with the order, the total and a Pay button. The button opens the provider's payment window.
* When the shopper finishes in that window, it closes and your store's tab moves to the thank-you page.
* If the browser blocks the window, the payment continues in the same tab. If the shopper closes the window, the page offers Pay again and Back to checkout.
* The provider shows your store name and the order number as the item name.

= 0.3.0 =
* Refund from the order screen with WooCommerce's Refund button, when your payment provider turns refunds on.
* Refunds made in the payment provider's dashboard, and refunds the provider confirms later, are added to the order.
* Optional Cancel button for shoppers on paid orders in My Account. It refunds the full payment and cancels the order. Off by default.
* "View card receipt" link for card payments.
* Virtual account details on the shopper's My Account order page.
* The Connection table shows whether refunds are on.

= 0.2.0 =
* Self-check on the OutlinePay page: each check with who needs to act, a diagnostic code with a Copy button, and the last result kept on the page.
* The check also runs once a day and can email the site admin when a daily check starts failing (off by default).
* The store answers OutlinePay's signed test message without touching any order.
* New Site Health test: OutlinePay connection.
* Two read-only abilities on WordPress 6.9 and later: outlinepay/run-self-check and outlinepay/get-connection-status.

= 0.1.0 =
* First release.
