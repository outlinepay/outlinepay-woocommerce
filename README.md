# OutlinePay for WooCommerce

WooCommerce payment gateway that takes Korean payment methods through the merchant's payment provider, connected by OutlinePay. The wp.org listing text is in `readme.txt`.

OutlinePay is operated by Arandu Payments LLC.

## Requirements

- PHP 7.4 or later
- WordPress 6.2 or later
- WooCommerce 8.0 or later
- Store currency KRW

## Layout

| Path | What it holds |
| --- | --- |
| `outlinepay-for-woocommerce.php` | Plugin header, constants, compatibility declarations |
| `includes/class-plugin.php` | Hook wiring |
| `includes/class-api-client.php` | HTTP client for the OutlinePay plugin API |
| `includes/class-gateway.php` | One gateway class, one instance per method (`outlinepay_<method>`) |
| `includes/class-order-sync.php` | The one place a payment becomes an order change |
| `includes/class-return-handler.php` | Shopper return (`?wc-api=outlinepay_return`) |
| `includes/class-callback-controller.php` | Signed callback (`POST /wp-json/outlinepay/v1/callback`) |
| `includes/class-scheduler.php` | Action Scheduler jobs: method refresh, reconcile, version report |
| `includes/class-unpaid-guard.php` | Expires open attempts before WooCommerce cancels an unpaid order |
| `includes/class-admin-page.php` | WooCommerce > OutlinePay |
| `includes/class-blocks-method.php`, `assets/js/blocks.js` | Checkout block integration, no build step |

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
