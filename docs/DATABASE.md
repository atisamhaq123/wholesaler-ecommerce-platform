# Database relationships and constraints

All tables use InnoDB. IDs are unsigned big integers, timestamps are UTC, monetary values are unsigned integer minor units. Foreign keys deliberately restrict deletion of records that appear in purchase history; deactivate users and archive offers instead of deleting them.

| Area | Relationships / invariants |
| --- | --- |
| Accounts | `users.email` is unique; passwords are PHP password hashes. One `businesses` row per user. Wholesaler approval is pending/approved/suspended. `addresses` belong to a user; `fulfillment_settings` belong to a supplier user. |
| Catalog | `categories` → `products` → `offers`. Product SKU is unique. Multiple supplier offers can refer to one canonical product. Offers carry pack/price/MOQ/stock; minimums, increments and positive pack sizes have DB/application constraints. `offer_images` and `price_tiers` belong to offers; quantity tiers are unique per offer/threshold. |
| External | `external_sources` → `external_offers` → canonical products. External offers have independent currency, timestamp, URL, pack and optional delivery fee. They have no cart or order relationship. |
| Cart | One cart per retailer, one cart-item row per offer. Quantities are checked server-side again at checkout. |
| Checkout | `checkouts` → `supplier_orders` → `order_items`. Parent stores retailer, status, mode, totals, rates, fee basis, address snapshot, unique request key and expiry. Each supplier order holds supplier identity and fulfillment snapshots plus totals. Order items snapshot name/SKU/category/pack/unit/price; later listing edits cannot rewrite history. |
| Money | One `payments` row per parent checkout. Provider session and intent IDs are unique. Exact hosted-session request payload survives uncertain API responses. One whole-order refund and one payout record per supplier order; provider operations use stable idempotency keys. `webhook_events.event_id` is a primary key. |
| Inventory | One reservation per checkout/offer. Available stock excludes reserved units. Reserved → consumed on payment, or reserved → released on expiration/cancellation. Checkout locks cart then offer IDs in ascending order. Stock cannot go negative; atomic conditional update provides a second guard. Confirm/release lock the parent row. |
| Fulfillment | Payment status on parent and fulfillment status on supplier order are separate. History rows record every permitted transition. Pickup and delivery follow different preparing/ready/shipped paths. Refunded supplier orders become cancelled. |
| Messages | `conversations` may reference a canonical product or supplier order. `conversation_participants` membership is unique per conversation/user. All reads, polling and writes require membership. Messages have microsecond timestamps, safe rendering and indexed paginated history. |
| Security | `tokens` contains unique SHA-256 hashes of reset/verification secrets, kind, expiry and usage time. `rate_limits` contains hashed buckets and 15-minute windows. No raw card details are stored. |
| Operations | `settings` stores non-secret configuration, not integration keys. `audit_logs` records admin actor/action/entity, avoiding secrets/message content/private address payloads. `migrations` tracks applied migration files. |

Indexes cover catalog category/name/supplier lookups, user addresses, offer tiers, supplier fulfillment status, pending checkout expiry, reservation expiry, message history and unique identity/payment keys. The PDO helper always prepares statements with native MySQL binding. Dynamic sort clauses come from a hard-coded allowlist and pagination values are validated integers.

Default fee is 0.5% of merchandise after tier discounts, excluding delivery and tax. Each supplier fee and tax use half-up integer rounding before the parent sums them. Refunds preserve original order snapshots and subtract separate refund components from expense totals; they do not rewrite invoice totals.

Migration files are forward-only. MySQL DDL commits implicitly; back up before schema upgrades. The initial migration targets an empty database, and the migration ledger makes completed upgrades repeatable. A partially failed initial DDL migration needs DBA review before retrying.
