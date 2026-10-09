# Verification record

Verified locally on 2026-10-09 using PHP 8.4.15 and MySQL 8.4.7 (WAMP).

`php -d xdebug.mode=off tests/run.php` passed **96 checks**. Tests create a unique disposable MySQL database, seed it, exercise real HTTP routes on an owned temporary PHP server, and remove the test database/server afterward. Application demo data in `my_store` is not modified by tests.

Covered:

- Whole multi-supplier checkout with current quantity tier prices, delivery/tax/buyer fees and immutable product/price snapshots.
- Half-up buyer fee rounding and optional delivery-inclusive basis; compatible mass normalization and incompatible dimensions.
- Two competing PHP processes attempt to reserve the final inventory: one succeeds, one is rejected, stock stays nonnegative.
- Reservation expiration/release and duplicate demo confirmations; signed duplicate Stripe webhook fixtures confirm once; invalid/stale signatures fail.
- Whole-supplier-order partial refund, unaffected sibling order and inventory restocking once.
- Retailer HTTP flow: sign in, add to cart, review checkout, confirm demo purchase, view invoice and export expense CSV.
- Wholesaler HTTP fulfillment flow: accepted, preparing, ready for pickup, delivered; delivery branch tested through the service layer.
- Administrator HTTP approval flow, fee/rate/basis settings and category management.
- Registration, email verification, single-use/expired password reset tokens, private local email outbox.
- Listing creation/tier persistence/archive, private address save/delete, authorized comparison CSV import and template download.
- Role boundaries, cross-supplier listing/order access, private conversation membership, stored-message escaping, session-bound CSRF.
- Public, retailer, supplier and administrator page responses; manifest and local Bootstrap asset availability.

PHP source syntax and JavaScript syntax were checked separately. No interactive browser tool was available, so this does not claim screenshot, accessibility audit, iOS/Android visual review or actual PWA install verification. Credential-dependent Stripe API/Connect transfers, mail transport and Mapbox requests were not exercised against providers. Their limitations/setup are documented in README; demo behavior and signed server event processing were exercised locally.
