# OutlinePay for WooCommerce

WooCommerce payment gateway that takes Korean payment methods through the merchant's payment provider, connected by OutlinePay. The wp.org listing text is in `readme.txt`.

OutlinePay is operated by Arandu Payments LLC.

## Requirements

- PHP 7.4 or later
- WordPress 6.2 or later
- WooCommerce 9.9 or later
- Store currency one the payment provider takes (KRW for CookiePay)

## Layout

| Path | What it holds |
| --- | --- |
| `outlinepay-for-woocommerce.php` | Plugin header, constants, compatibility declarations |
| `includes/class-plugin.php` | Hook wiring |
| `includes/class-api-client.php` | HTTP client for the OutlinePay plugin API |
| `includes/class-gateway.php` | One gateway class, one instance per method (`outlinepay_<method>`) |
| `includes/class-order-sync.php` | The one place a payment becomes an order change |
| `includes/class-refunds.php` | Refund button, and refunds made elsewhere added to the order |
| `includes/class-shopper-cancel.php` | Optional Cancel for shoppers in My Account, and no native cancel on paid orders |
| `includes/class-order-display.php` | Deposit details, card receipt link and test payment line |
| `includes/class-return-handler.php` | Shopper return (`?wc-api=outlinepay_return`) |
| `includes/class-callback-controller.php` | Signed callback (`POST /wp-json/outlinepay/v1/callback`) |
| `includes/class-scheduler.php` | Action Scheduler jobs: method refresh, reconcile (payments and unconfirmed refunds), daily self-check, version report |
| `includes/class-self-check.php` | Self-check engine: local checks, the run against OutlinePay, offline codes, the stored result |
| `includes/class-self-check-mail.php` | Opt-in email when a daily self-check starts failing |
| `includes/class-site-health.php` | Site Health test "OutlinePay connection" |
| `includes/class-abilities.php` | Abilities API (WordPress 6.9+): `outlinepay/run-self-check`, `outlinepay/get-connection-status` |
| `includes/class-unpaid-guard.php` | Expires open attempts before WooCommerce cancels an unpaid order |
| `includes/class-setup.php` | The one-time setup gate: checkout waits for the first run where checks A to I all pass |
| `includes/class-admin-page.php` | WooCommerce > OutlinePay: the setup screen with Fix all, then the connected view with the Self-check section |
| `assets/js/admin.js` | Copy buttons for the diagnostic code, no build step |
| `includes/class-blocks-method.php`, `assets/js/blocks.js` | Checkout block integration, no build step |

## Self-check offline codes

When a self-check run reaches OutlinePay, its id (`chk_...`) is the diagnostic code. When it does not (a staging copy, no connection, OutlinePay unreachable), the plugin shows `OP-OFFLINE-` followed by one letter per failed check, in this order. A letter never changes meaning. New checks get new letters at the end. J is retired and never used: it belonged to a check that was dropped before release.

| Letter | Check | Fails when |
| --- | --- | --- |
| A | `connection_saved` | No saved connection, or the credentials no longer decrypt |
| B | `connection_status` | The installation is not connected (revoked: the payment provider acts) |
| C | `api_reachable` | OutlinePay did not answer within 5 seconds, refused the token, or did not accept the run |
| D | `scheduler_jobs` | Reconcile or method refresh is missing or more than an hour late |
| E | `wp_cron` | `DISABLE_WP_CRON` is set and no scheduled action ran in 2 hours |
| F | `methods_fresh` | Methods not fetched in 2 hours, or none shown at checkout |
| G | `store_currency` | The store currency is not one the payment provider takes (the installation's `currencies`, KRW when the server sent none) |
| H | `not_a_copy` | This site is a copy of the store (see `Site_Identity`) |
| I | `versions` | PHP, WordPress or WooCommerce below the plugin header minimums, or HPOS compatibility not registered |

An offline code always has at least one letter. With every local check green, it is `OP-OFFLINE-C`.

## Setup after connect

After connect, the OutlinePay page shows a setup screen until one stored self-check result has checks A to I all passing. Until then no OutlinePay method shows at checkout. Fix all applies the fixes the plugin can make (store currency, recurring jobs and a reconcile run, method refresh), then runs the checks again. When OutlinePay refuses a second run within a minute, the local rows are rerun and merged into the stored result.

The first all-green result saves `outlinepay_setup_done`. The gate is one-time: later failures show on the page but do not hide checkout. Disconnect deletes the flag. Only checks A to I decide. Rows OutlinePay adds show on the page but never block setup.

Upgrade rule (`Setup::upgrade()`, called from `Plugin::boot` on every request before any gateway is asked): when there is no flag, the store holds a saved installation and readable credentials, and `outlinepay_reported_version` is missing or below 0.5.0, the flag is saved outright. `finish_connect` saves the running version in the same request as the connection, so a fresh connect never matches.

## Development

```sh
composer install
composer lint    # phpcs: WordPress-Extra and PHPCompatibilityWP 7.4+
composer test    # unit tests, no WordPress or database needed
```

Point a development store at another API base in `wp-config.php`:

```php
define( 'OUTLINEPAY_API_BASE', 'http://localhost:3000/plugin/v1' );
```

Translations: edit `languages/outlinepay-for-woocommerce-ko_KR.po`, then run

```sh
msgfmt -o languages/outlinepay-for-woocommerce-ko_KR.mo languages/outlinepay-for-woocommerce-ko_KR.po
```

## Building a release zip

```sh
git archive --format=zip --prefix=outlinepay-for-woocommerce/ -o outlinepay-for-woocommerce.zip HEAD
```

`.gitattributes` keeps development files out of the archive. `.distignore` lists the same files for other build tools.

## License

GPL-2.0-or-later. See `LICENSE`.
