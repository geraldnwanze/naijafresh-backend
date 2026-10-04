---
paths:
  - 'app/**'
---

# App

## NaijaFresh API conventions (money, structure, payments)
- All money is stored/calculated as integer kobo (columns `*_kobo`). Never floats. Format with App\Support\Money; resources expose both `*_kobo` and a formatted string.
- API is versioned under routes/api.php as `/api/v1`, name prefix `api.v1.`. Controllers in app/Http/Controllers/Api/V1 (+ /Admin). Token auth via Sanctum (Bearer); admin routes use the `admin` middleware alias (EnsureUserIsAdmin) + `auth:sanctum`.
- Order totals are ALWAYS recomputed server-side in App\Services\Cart\CartPricingService + App\Actions\Orders\PlaceOrder from product ids + quantities. Client-sent prices are ignored.
- Payments go through App\Services\Payments\Contracts\PaymentGateway. `mock` provider (MockPaymentGateway) runs a fully testable checkout with no Paystack keys; `paystack` provider is real. Provider resolved by PaymentGatewayManager from config('naijafresh.payments.provider').
- Runtime business config (delivery fee, COD/bank-transfer toggles, store_open) lives in the `settings` table via App\Services\StoreSettings; config/naijafresh.php holds fallback defaults.
- Order status state machine lives in App\Enums\OrderStatus + App\Services\Orders\OrderStatusService (stamps timestamps, syncs Delivery, settles COD on Delivered).

## No action classes, DTOs, or repositories — controllers + services only
Project decision: business logic lives in plain service classes under app/Services/** invoked from thin controllers. Do NOT introduce app/Actions, *Action classes, DTO/value-object classes, or repository classes.
- Order creation: App\Services\Orders\OrderService::place(User, array): Order
- Cart pricing: App\Services\Cart\CartPricingService::price(iterable, bool $enforceStock): array  (returns array shape, not a DTO — document with PHPDoc array{})
- Payments: gateways implement App\Services\Payments\Contracts\PaymentGateway and return array shapes; orchestration in App\Services\Payments\PaymentService.
Services return Eloquent models or documented associative arrays. Enums are fine (they are not DTOs).

## Weight-sold products: quantities and stock are grams, price is per kg
Products have `sold_by` (SoldBy: unit|weight) and `storage_type` (StorageType: ambient|chilled|frozen). When sold_by = weight:
- `price_kobo` / `order_items.unit_price_kobo` is the price PER KILOGRAM (kobo); `unit` is forced to "kg".
- Cart/order `quantity` and product `stock_quantity` are INTEGER GRAMS (never floats). `weight_step_grams` (default 500), `min_weight_grams`, `max_weight_grams` constrain what can be ordered.
- Line total = App\Support\Weight::priceKobo(pricePerKg, grams) (round half-up) via Product::lineTotalKobo(); validate with Product::quantityError(); format with Weight::format() / Product::quantityLabel().
- order_items snapshot `sold_by` and `storage_type`, so history survives repricing.
Unit products are unchanged (quantity = item count, max 99 per line). Frozen orders surface `has_frozen_items` for packing/delivery. The old `App\Actions\Orders\PlaceOrder` mention in this file is stale — order creation is App\Services\Orders\OrderService::place().

## Cost prices, order cost snapshots and the P&L definitions
- `products.price_kobo` is the SELLING price; `products.cost_price_kobo` is what it costs us (same unit as the price: per item, or per kg when sold_by = weight). Null = not entered. `product_variants.cost_delta_kobo` is an add-on's extra cost.
- Cost is staff-only: it appears in resources only via `$request->user()?->isAdmin()` (ProductResource, ProductVariantResource, OrderItemResource, OrderResource). Never add cost/profit/margin to a public or customer-facing payload (CartController::present is explicit on purpose); tests assert no "cost" in guest/customer JSON.
- Orders snapshot cost per line (`order_items.unit_cost_kobo` / `line_cost_kobo`, null when the product had no cost) in CartPricingService → OrderService, so later cost changes never rewrite history. Null cost is reported as "uncosted", not treated as free.
- P&L lives in App\Services\Accounting\ProfitLossService: revenue = product sales − discounts + delivery fees; gross profit = revenue − COGS (sum of line_cost_kobo); net = gross − expenses (`expenses` table, category = ExpenseCategory). Basis "delivered" (status delivered, by delivered_at) or "placed" (non-cancelled, by placed_at).
- Days/weeks (Monday)/months are cut at local midnight in config('naijafresh.timezone') (Africa/Lagos) though timestamps are UTC.
- DemoDataSeeder (≈170 backdated orders + 3 months of expenses) runs only when APP_ENV=local, via DatabaseSeeder.
