<?php

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\ExpenseCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CartException;
use App\Models\DeliveryWindow;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Demo history (development and staging, never production) so the accounting pages have something to show: ~170
 * orders spread over the last 60 days (mostly delivered, a few cancelled or
 * still open) and three months of operating expenses. Orders are placed through
 * OrderService, so prices, stock and cost snapshots behave like real sales.
 */
class DemoDataSeeder extends Seeder
{
    public function run(OrderService $orders, PaymentService $payments): void
    {
        // Orders and expenses aren't upserted, so a second run would double the history.
        if (Order::query()->exists()) {
            $this->command?->info('Demo history skipped: orders already exist.');

            return;
        }

        // History, not live sales: no emails and no bell entries for ~170 old orders.
        Notification::fake();

        // Same for the audit trail and activity log.
        app(AuditLogger::class)->withoutAuditing(function () use ($orders, $payments): void {
            $this->seedOrders($orders, $payments);
            $this->seedExpenses();
        });
    }

    private function seedOrders(OrderService $orders, PaymentService $payments): void
    {
        $customer = User::where('email', 'customer@naijafresh.test')->first();
        $window = DeliveryWindow::query()->active()->first();
        $products = Product::query()->available()->with('variants')->get();

        if ($customer === null || $window === null || $products->isEmpty()) {
            return;
        }

        $timezone = (string) config('naijafresh.timezone');

        for ($i = 0; $i < 170; $i++) {
            $daysAgo = mt_rand(0, 59);
            $placedAt = CarbonImmutable::now($timezone)
                ->subDays($daysAgo)
                ->setTime(mt_rand(7, 20), mt_rand(0, 59))
                ->setTimezone('UTC');

            if ($placedAt->isFuture()) {
                $placedAt = CarbonImmutable::now()->subHour();
            }

            try {
                $order = $orders->place($customer, [
                    'contact' => [
                        'first_name' => 'Ada',
                        'last_name' => 'Customer',
                        'phone' => '08000000002',
                        'email' => $customer->email,
                    ],
                    'delivery' => ['street' => '12 Admiralty Way', 'area' => 'Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos'],
                    'delivery_window_id' => $window->id,
                    'delivery_date' => $placedAt->toDateString(),
                    'payment_method' => mt_rand(0, 1) === 1 ? 'cash_on_delivery' : 'paystack',
                    'items' => $this->randomItems($products),
                ]);
            } catch (CartException) {
                continue; // a product ran out of stock; skip this order
            }

            $payment = $payments->createForOrder($order);

            $roll = mt_rand(1, 100);
            $status = match (true) {
                $daysAgo === 0 => $roll > 50 ? OrderStatus::Pending : OrderStatus::Confirmed,
                $roll <= 85 => OrderStatus::Delivered,
                default => OrderStatus::Cancelled,
            };

            $timestamps = ['placed_at' => $placedAt, 'created_at' => $placedAt];

            if ($status !== OrderStatus::Pending) {
                $timestamps['confirmed_at'] = $placedAt->addMinutes(30);
            }

            if ($status === OrderStatus::Delivered) {
                $deliveredAt = $placedAt->addHours(mt_rand(4, 30));
                $deliveredAt = $deliveredAt->isFuture() ? CarbonImmutable::now() : $deliveredAt;

                $order->forceFill($timestamps + [
                    'status' => $status,
                    'prepared_at' => $placedAt->addHours(3),
                    'delivered_at' => $deliveredAt,
                ])->save();

                $order->delivery?->forceFill([
                    'status' => DeliveryStatus::Delivered,
                    'dispatched_at' => $deliveredAt->subHour(),
                    'delivered_at' => $deliveredAt,
                ])->save();

                $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => $deliveredAt])->save();
            } elseif ($status === OrderStatus::Cancelled) {
                $order->forceFill($timestamps + [
                    'status' => $status,
                    'cancelled_at' => $placedAt->addHours(2),
                    'cancellation_reason' => 'Customer changed their mind',
                ])->save();
            } else {
                $order->forceFill($timestamps + ['status' => $status])->save();
            }
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{product_id: int, variant_id: int|null, quantity: int}>
     */
    private function randomItems($products): array
    {
        return $products->shuffle()->take(mt_rand(1, 4))->map(function (Product $product): array {
            $variant = $product->variants->isNotEmpty() && mt_rand(0, 1) === 1
                ? $product->variants->random()
                : null;

            return [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'quantity' => $product->isSoldByWeight()
                    ? $product->minWeightGrams() + $product->weightStepGrams() * mt_rand(0, 3)
                    : mt_rand(1, 3),
            ];
        })->values()->all();
    }

    private function seedExpenses(): void
    {
        $timezone = (string) config('naijafresh.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        for ($offset = 0; $offset < 3; $offset++) {
            $month = $today->subMonthsNoOverflow($offset)->startOfMonth();

            $this->expense(ExpenseCategory::Rent, 'Shop & kitchen rent', 120_000, $month);
            $this->expense(ExpenseCategory::Utilities, 'Electricity & water', mt_rand(28_000, 38_000), $month->addDays(4));
            $this->expense(ExpenseCategory::Marketing, 'Instagram & WhatsApp ads', mt_rand(35_000, 55_000), $month->addDays(11));
            $this->expense(ExpenseCategory::Wastage, 'Spoiled produce written off', mt_rand(8_000, 16_000), $month->addDays(14));
            $this->expense(ExpenseCategory::PaymentFees, 'Paystack transaction fees', mt_rand(12_000, 22_000), $month->addDays(19));
            $this->expense(ExpenseCategory::Salaries, 'Kitchen & packing staff', 150_000, $month->addDays(26));

            for ($week = 0; $week < 4; $week++) {
                $this->expense(ExpenseCategory::Packaging, 'Bags, labels & cooler packs', mt_rand(12_000, 22_000), $month->addDays($week * 7 + 2));
                $this->expense(ExpenseCategory::Delivery, 'Rider payouts', mt_rand(18_000, 32_000), $month->addDays($week * 7 + 5));
            }
        }
    }

    private function expense(ExpenseCategory $category, string $description, int $naira, CarbonImmutable $date): void
    {
        if ($date->isFuture()) {
            return;
        }

        Expense::create([
            'category' => $category,
            'description' => $description,
            'amount_kobo' => Money::fromNaira($naira),
            'incurred_on' => $date->toDateString(),
        ]);
    }
}
