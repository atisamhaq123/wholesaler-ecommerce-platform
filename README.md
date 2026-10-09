# My Store

A working B2B wholesale marketplace built with core PHP, MySQL/PDO, Bootstrap 5, custom CSS and vanilla JavaScript. No PHP framework, Node build or Composer dependency is required. Native iOS/Android apps are a separate future phase; this application includes an installable PWA.

## Quick start

Requirements: PHP **8.2+**, MySQL **8.0.16+**, and PHP extensions `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl` and `openssl`. MySQL constraints and locking are required; SQLite is not supported.

1. Create a database:

   ```sql
   CREATE DATABASE my_store CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Copy `.env.example` to `.env` and configure your database connection. Environment variables supplied by the host take precedence. The default local configuration uses WAMP's root account with an empty password; use a dedicated database user in deployment.
3. From the project directory:

   ```sh
   php bin/migrate.php
   php bin/seed.php
   php -S localhost:8080 -t public public/router.php
   ```

4. Open **http://localhost:8080**. Only `public/` is a web document root. No compilation or external assets are needed at runtime.

On this Windows workspace, PHP is available at `C:\wamp64\bin\php\php8.4.15\php.exe`. PowerShell example:

```powershell
& 'C:\wamp64\bin\php\php8.4.15\php.exe' -d xdebug.mode=off -S localhost:8080 -t public public/router.php
```

The local `my_store` database was migrated and seeded during implementation. Migrations track applied files; seeding skips any database that already has users. Do not seed demonstration accounts in production.

## Demo accounts

All passwords: **`DemoStore!2026`**.

| Role | Email | Business |
| --- | --- | --- |
| Retailer | retailer@mystore.test | Corner & Co. Market |
| Wholesaler | wholesaler@mystore.test | Greenfield Wholesale |
| Second wholesaler | supplier@mystore.test | Northline Supply |
| Pending wholesaler | pending@mystore.test | Borough Goods |
| Administrator | admin@mystore.test | My Store Operations |

The seed includes 12 canonical products, 25 supplier offers, tier prices, a saved retailer address, private messages, and clearly labeled fictional external comparisons. New orders are created through the application; there are no invented historical sales metrics.

## Working flows

- **Retailer:** sign in → marketplace → compare an offer → add packs to bag → update quantity → choose fulfillment per supplier → choose saved address for delivery → review exact totals → confirm demo transaction → view order, receipt and expenses → message supplier.
- **Wholesaler:** sign in → create/edit a product offer using the canonical catalog → configure tiers, inventory, images and delivery → review confirmed order → accepted → preparing → ready for pickup or shipped → delivered.
- **Administrator:** sign in → Businesses → approve the pending supplier → manage users, catalog and categories → import comparisons → inspect orders/payments/refunds → adjust pricing settings → inspect audit log.
- **Accounts:** register as retailer or wholesaler, verify email, request/reset password, edit business profile, change password, add/remove retailer delivery addresses, sign out.

All mutations use CSRF-protected POST requests and server authorization. There are no decorative dead buttons. Native selects/details handle navigation without a JavaScript UI framework. Forms use submit loading states and server-rendered success/error feedback. Message polling uses safe DOM text nodes.

## Payments and reservations

`PAYMENT_MODE=demo` is the default. Missing Stripe credentials also select explicit demo mode. **Demo confirmation never collects money**, and demo payments/payouts must not be represented as real transactions. Admin sales and collected fee metrics exclude demos; retailer expense summaries include them with clear labels for testing.

For hosted **Stripe test** checkout:

```dotenv
PAYMENT_MODE=stripe
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
APP_URL=http://localhost:8080
```

Only `sk_test_` credentials are accepted. This MVP deliberately blocks live keys. Hosted Stripe Checkout collects card data; this application never accepts raw card numbers. Enable these test webhook events at `/webhooks/stripe`:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `refund.created`, `refund.updated`, `refund.failed`

For local testing, use Stripe CLI:

```sh
stripe listen --forward-to localhost:8080/webhooks/stripe
```

Copy the listener's webhook signing secret into your local environment. Use a Stripe test card such as `4242 4242 4242 4242`, any future expiry and any three-digit CVC **on Stripe's hosted page only**. Card-only sessions are used to avoid delayed payment methods holding uncertain inventory. The browser success return never marks an order paid. Signed, amount- and currency-matched server events confirm payments; event IDs and checkout row locks prevent double confirmation. Signatures have a five-minute tolerance.

Stock is decremented under row locks at checkout creation, and a reservation records those units. Default reservation expiry is 30 minutes; the first Stripe session attempt extends it to 31 minutes to meet Stripe's minimum session lifetime. The exact provider request is stored before networking and retried with the same idempotency key. Payment consumes the reservation without decrementing stock again. Expiration/cancellation releases it once. Stripe sessions are closed or reconciled server-side before locally releasing stock; a paid session is confirmed rather than cancelled. A webhook arriving after stock has already been released is rejected and needs manual refund/reconciliation, never unreserved fulfillment.

Schedule expiration **every minute**:

```sh
php /absolute/path/to/my-store/bin/expire.php
```

Use cron on Linux or Windows Task Scheduler. Demo expirations also run when entering checkout/create. Provider failures leave reservations intact for a safe retry instead of freeing inventory while an active payment session might still accept a payment.

### Separate supplier payouts

Collection, buyer fees and transfers are separate records. Configure authorized Stripe Connect **test** accounts in Admin → Businesses. After a Stripe-paid order is delivered, an admin can send its test transfer from the order details. Transfer amount is merchandise plus delivery; buyer fees and configured taxes remain on the platform. Provider processing fees are charged to the platform. Transfers use order-specific idempotency keys. No transfer occurs in demo mode.

Before any real-money launch, implement and review live-mode onboarding, connected-account capability checks, tax responsibility, transfer reversals, disputes and operational reconciliation for your jurisdiction. Connect availability, account authorization, settlement availability and provider restrictions require configuration in Stripe. Current test transfers may fail gracefully if funds or account capabilities are unavailable. See [Stripe hosted checkout](https://docs.stripe.com/api/checkout/sessions/create) and [separate charges and transfers](https://docs.stripe.com/connect/separate-charges-and-transfers).

### Refunds and partial cancellation

Admins can cancel/refund a **whole unshipped supplier order**. Its merchandise, delivery, tax and buyer fee are refunded in full; other suppliers in the parent checkout are unaffected. Refunds subtract from expense totals. Inventory restocks once after a confirmed provider refund (immediately for demos). The parent is `partially_refunded` until all supplier orders are refunded. Failed/pending provider refunds do not reduce expense totals. Do not fulfill an order while its refund is pending.

Item-level partial refunds, shipped returns and transfer reversals need manual operations and are not automated. Orders with sent payouts cannot use this cancellation workflow. Refunds created outside the application are not automatically attributed to a supplier order; reconcile those manually. Refunds issued through the app have stable provider idempotency keys.

## Pricing defaults

- Currency USD; amounts are integer minor units throughout storage and checkout calculations. Supported marketplace currencies use two decimals.
- Buyer fee **50 basis points = 0.5%**, applied after volume-tier discounts, excluding delivery/tax.
- Configurable buyer fee rate and basis; optional basis is merchandise plus delivery, excluding tax. New orders snapshot the selected basis and rates. The default satisfies the requested MVP calculation.
- Tax defaults to zero, applied to discounted merchandise only. A single configurable marketplace rate is a simplified MVP rule, not jurisdiction-aware tax calculation.
- Round tax/fee **half-up to the nearest minor unit per supplier order**, then sum supplier orders into the parent. Product tier prices are already integer minor units. No coupon engine is implemented; discounts come from quantity tiers.
- Currency cannot be changed once checkout history exists, preserving consistent monetary history.
- Merchandise plus delivery is limited to 1,000,000,000 minor units per checkout to keep fee multiplication and database totals within safe bounds. Large purchases can be split.
- Normalization supports each, g/kg/lb/oz and ml/L. Mass, volume and each are not compared across unit dimensions. Matching canonical product identity remains an administrator responsibility.
- The comparison table highlights lowest normalized unit price and lowest known one-pack-plus-delivery cost separately. Unknown delivery fees are not zero. Tier/MOQ, radius, taxes and buyer fee can change actual basket totals; checkout is authoritative. No currency conversion is performed.

## Email, external suppliers and mapping

Local `MAIL_MODE=log` writes account emails to **private `storage/mail/`**. Open the latest file locally to use a verification/reset link; those tokens never appear in ordinary pages or application logs. Production requires `MAIL_MODE=mail`, a functioning PHP `mail()` transport, and `MAIL_FROM`. Configure your host's sendmail/SMTP relay and SPF/DKIM; replace the mail transport with a focused SMTP library if your host requires it. Reset/verification tokens are hashed in the database, expire after an hour and are single-use. Login, reset, verification and messaging requests are rate-limited.

External supplier integrations use the `SupplierAdapter` interface. `CsvAdapter` is the implemented fallback. Admin → Comparisons has a downloadable template and transactional CSV import (2 MB / 2,000 rows maximum). Pack price/delivery fee are decimal amounts in CSV; storage uses minor units. `delivery_fee` blank means unknown. Timestamps are UTC `YYYY-MM-DD HH:MM:SS` and cannot be in the future. Imports append offers; admins can delete old rows. Use only authorized or licensed source data. Sample Sam's Club values are fictional, not live/API data; there is no scraping or Sam's Club API integration. Comparison offers never enter marketplace carts.

Coordinates are optional and entered manually. Delivery radii use straight-line estimates; missing coordinates display **Distance unavailable** and cannot satisfy a configured radius. Quote-only delivery cannot be checked out until the supplier sets a known fee; pickup remains eligible if enabled.

Optional Mapbox driving-route comparisons:

```dotenv
MAP_PROVIDER=mapbox
MAP_API_KEY=your_authorized_mapbox_token
```

Coordinates are sent server-side to the [Mapbox Directions API](https://docs.mapbox.com/api/navigation/directions/) only when configured. Route results are cached in the user's private session for 15 minutes; integration errors fall back to explicitly labeled straight-line estimates. Credentials are never returned to the browser. Route comparison uses the retailer's first saved address; checkout radius eligibility uses the selected address. Geocoding and maps UI are not implemented; no location is inferred from a private address.

## Verification

```sh
php tests/run.php
```

Requires a local database account allowed to create/drop a **new disposable test database** and PHP `proc_open`. The runner uses a unique `my_store_test_*` database and cleans it up, leaving `my_store` untouched. HTTP tests launch their own server on `127.0.0.1:8097` and stop it afterward; keep that port free. Tests cover authorization, CSRF, conversation privacy/XSS, normalization, fee rounding, current tiers, checkout snapshots, multi-supplier splits, expiration, refunds, competing checkout processes, signed duplicate webhooks and complete retailer/wholesaler/admin flows.

No browser automation tool is available in this environment. HTTP pages and workflows were exercised; desktop/mobile appearance and install prompts still need visual review in actual browsers. Stripe, email transport and Mapbox network calls require credentials and were not verified against real providers; signature/event behavior is tested with signed fixtures, not invented real payments.

## Production deployment

Use Apache/PHP-FPM or Nginx/PHP-FPM with document root **`/absolute/path/to/my-store/public`**, HTTPS and `APP_ENV=production`. Configure trusted HTTPS termination so PHP's `HTTPS` variable is set correctly. Never serve the project root, and do not use PHP's development server in production.

- Apache: enable `mod_rewrite`, allow the provided `public/.htaccess`, disable directory listing.
- Nginx example:

  ```nginx
  root /var/www/my-store/public;
  location / { try_files $uri /index.php?$query_string; }
  location = /index.php {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/index.php;
      fastcgi_param HTTPS on;
      fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }
  location ~ \.php$ { return 404; }
  location ~ /\. { deny all; }
  ```

- Restrict `.env`, database access and private storage; grant only the PHP service account write access to `storage/`. Back up MySQL and image storage. Runtime storage contains sessions, image uploads, safe application logs and local email outbox (local only).
- `APP_ENV=production` enforces HTTPS, secure/HttpOnly/SameSite cookies and rejects the local log mail transport for account emails. Remove demo accounts and use strong individual credentials before deployment.
- Uploaded JPEG/PNG/WebP images are MIME/size/dimension checked, decoded and re-encoded, stored outside public root with random names, and served through an approved-offer route. No uploaded PHP is executed.
- Run migration with a privileged deployment account; grant the runtime database account only necessary DML rights. Schedule reservation expiration every minute and monitor webhook failures/reconciliation.
- CSRF, prepared queries, session regeneration, contextual HTML escaping, participant/order ownership checks and a restrictive CSP are included. Errors never display SQL or stack traces.
- PWA service worker caches an explicit list of public static assets only. Authenticated pages, message responses, media, checkout and payment routes are never cached. Increase the static cache version when changing assets. Manifest icons are bundled; localhost/HTTPS is required for service workers. This PWA is not a native mobile application.

## Source layout and limits

`app/controllers` handles requests; `app/Models` encapsulates PDO access; `app/services` contains pricing, account, inventory, payments, imports and order rules; `app/middleware` enforces authorization; `app/views` contains reusable PHP templates. `public` is the sole web root. `database/migrations`, `bin`, `tests` and `storage` remain private. See [database relationships](docs/DATABASE.md).

The implemented MVP supports the requested core business flows. Integration limits are explicit: Stripe test-only, manual Connect onboarding/payout initiation, simple tax rate, no native apps, no authorized live external feeds, manual coordinate entry, optional Mapbox routes without geocoding, whole-supplier-order refunds only, and basic expense tracking. Listing authors choose existing canonical products; administrators create/edit the shared canonical catalog. No Figma files were supplied, so the interface uses a consistent green/cream visual system with CSS product illustrations; real supplier image uploads are supported.
