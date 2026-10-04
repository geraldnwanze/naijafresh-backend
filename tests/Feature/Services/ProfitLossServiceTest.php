<?php

use App\Enums\ExpenseCategory;
use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Accounting\ProfitLossService;

/**
 * Lagos is UTC+1, so 2026-10-03 23:30 UTC is already 4 Oct locally.
 *
 * @param  array<string, mixed>  $order
 * @param  array<string, mixed>  $item
 */
function orderWithLine(array $order, Product $product, array $item): Order
{
    $order = Order::factory()->create($order);
    OrderItem::factory()->for($order)->create($item + [
        'product_id' => $product->id,
        'name' => $product->name,
        'sold_by' => $product->sold_by,
    ]);

    return $order;
}

beforeEach(function (): void {
    config()->set('naijafresh.timezone', 'Africa/Lagos');
    $this->service = app(ProfitLossService::class);

    $staples = Category::factory()->create(['name' => 'Staples']);
    $protein = Category::factory()->create(['name' => 'Protein']);

    $this->rice = Product::factory()->for($staples)->byWeight()->create(['name' => 'Parboiled Rice']);
    $this->beef = Product::factory()->for($protein)->create(['name' => 'Beef Pack']);
    $this->mystery = Product::factory()->for($staples)->create(['name' => 'Mystery Item']);

    // 2 kg rice at ₦1,900/kg costing ₦1,500/kg. Delivered 2 Oct (11:00 Lagos).
    orderWithLine(
        ['status' => OrderStatus::Delivered, 'placed_at' => '2026-10-01 09:00:00', 'delivered_at' => '2026-10-02 10:00:00',
            'subtotal_kobo' => 380_000, 'delivery_fee_kobo' => 200_000, 'discount_kobo' => 0, 'total_kobo' => 580_000],
        $this->rice,
        ['quantity' => 2000, 'unit_price_kobo' => 190_000, 'line_total_kobo' => 380_000, 'unit_cost_kobo' => 150_000, 'line_cost_kobo' => 300_000],
    );

    // Delivered 23:30 UTC on 3 Oct = 00:30 on 4 Oct in Lagos, with a ₦500 discount.
    orderWithLine(
        ['status' => OrderStatus::Delivered, 'placed_at' => '2026-10-03 08:00:00', 'delivered_at' => '2026-10-03 23:30:00',
            'subtotal_kobo' => 650_000, 'delivery_fee_kobo' => 200_000, 'discount_kobo' => 50_000, 'total_kobo' => 800_000],
        $this->beef,
        ['quantity' => 1, 'unit_price_kobo' => 650_000, 'line_total_kobo' => 650_000, 'unit_cost_kobo' => 500_000, 'line_cost_kobo' => 500_000],
    );

    // Delivered 3 Oct but the product had no cost price when it sold.
    orderWithLine(
        ['status' => OrderStatus::Delivered, 'placed_at' => '2026-10-03 07:00:00', 'delivered_at' => '2026-10-03 12:00:00',
            'subtotal_kobo' => 100_000, 'delivery_fee_kobo' => 200_000, 'discount_kobo' => 0, 'total_kobo' => 300_000],
        $this->mystery,
        ['quantity' => 1, 'unit_price_kobo' => 100_000, 'line_total_kobo' => 100_000, 'unit_cost_kobo' => null, 'line_cost_kobo' => null],
    );

    // Cancelled: never counts.
    orderWithLine(
        ['status' => OrderStatus::Cancelled, 'placed_at' => '2026-10-02 09:00:00', 'delivered_at' => null,
            'subtotal_kobo' => 900_000, 'delivery_fee_kobo' => 200_000, 'discount_kobo' => 0, 'total_kobo' => 1_100_000],
        $this->beef,
        ['quantity' => 1, 'unit_price_kobo' => 900_000, 'line_total_kobo' => 900_000, 'unit_cost_kobo' => 500_000, 'line_cost_kobo' => 500_000],
    );

    // Placed but not delivered yet: only counts on the "placed" basis.
    orderWithLine(
        ['status' => OrderStatus::Confirmed, 'placed_at' => '2026-10-04 08:00:00', 'delivered_at' => null,
            'subtotal_kobo' => 500_000, 'delivery_fee_kobo' => 200_000, 'discount_kobo' => 0, 'total_kobo' => 700_000],
        $this->beef,
        ['quantity' => 1, 'unit_price_kobo' => 500_000, 'line_total_kobo' => 500_000, 'unit_cost_kobo' => 350_000, 'line_cost_kobo' => 350_000],
    );

    Expense::factory()->create(['category' => ExpenseCategory::Rent, 'amount_kobo' => 1_000_000, 'incurred_on' => '2026-10-02']);
    Expense::factory()->create(['category' => ExpenseCategory::Delivery, 'amount_kobo' => 300_000, 'incurred_on' => '2026-10-04']);
    Expense::factory()->create(['category' => ExpenseCategory::Rent, 'amount_kobo' => 999_999, 'incurred_on' => '2026-09-30']); // outside the range
});

it('builds revenue, cost of goods, gross profit, expenses and net profit', function (): void {
    $report = $this->service->report('2026-10-01', '2026-10-04');

    expect($report['summary'])->toMatchArray([
        'orders' => 3,
        'product_sales_kobo' => 1_130_000,
        'delivery_fees_kobo' => 600_000,
        'discounts_kobo' => 50_000,
        'revenue_kobo' => 1_680_000,
        'cogs_kobo' => 800_000,
        'gross_profit_kobo' => 880_000,
        'gross_margin_pct' => 52.4,
        'expenses_kobo' => 1_300_000,
        'net_profit_kobo' => -420_000,
        'net_margin_pct' => -25.0,
        'average_order_value_kobo' => 560_000,
    ]);
});

it('excludes cancelled orders and counts undelivered ones only on the placed basis', function (): void {
    $placed = $this->service->report('2026-10-01', '2026-10-04', ProfitLossService::BASIS_PLACED);

    // delivered (3) + the confirmed one placed 4 Oct; the cancelled order is out.
    expect($placed['summary']['orders'])->toBe(4)
        ->and($placed['summary']['revenue_kobo'])->toBe(580_000 + 800_000 + 300_000 + 700_000)
        ->and($placed['summary']['cogs_kobo'])->toBe(300_000 + 500_000 + 350_000);
});

it('flags sales that have no cost price instead of treating them as free', function (): void {
    $report = $this->service->report('2026-10-01', '2026-10-04');

    expect($report['cost_coverage'])->toBe(['uncosted_lines' => 1, 'uncosted_sales_kobo' => 100_000]);

    $mystery = collect($report['by_product'])->firstWhere('name', 'Mystery Item');
    expect($mystery['has_uncosted'])->toBeTrue()
        ->and($mystery['profit_kobo'])->toBe(0)
        ->and($mystery['margin_pct'])->toBeNull();
});

it('ranks products by profit with weight quantities in kilograms', function (): void {
    $report = $this->service->report('2026-10-01', '2026-10-04');

    expect(collect($report['by_product'])->pluck('name')->all())->toBe(['Beef Pack', 'Parboiled Rice', 'Mystery Item']);

    $rice = collect($report['by_product'])->firstWhere('name', 'Parboiled Rice');
    expect($rice)->toMatchArray([
        'category' => 'Staples',
        'quantity' => 2000,
        'quantity_label' => '2 kg',
        'revenue_kobo' => 380_000,
        'cost_kobo' => 300_000,
        'profit_kobo' => 80_000,
        'margin_pct' => 21.1,
    ]);
});

it('breaks profit down by category', function (): void {
    $categories = collect($this->service->report('2026-10-01', '2026-10-04')['by_category'])->keyBy('category');

    expect($categories['Protein']['profit_kobo'])->toBe(150_000)
        ->and($categories['Protein']['margin_pct'])->toBe(23.1)
        // rice (80,000) + the uncosted mystery item contributes no profit
        ->and($categories['Staples']['profit_kobo'])->toBe(80_000);
});

it('groups expenses by category, ignoring ones outside the range', function (): void {
    $byCategory = $this->service->report('2026-10-01', '2026-10-04')['expenses_by_category'];

    expect($byCategory)->toBe([
        ['category' => 'rent', 'label' => 'Rent', 'amount_kobo' => 1_000_000],
        ['category' => 'delivery', 'label' => 'Delivery & riders', 'amount_kobo' => 300_000],
    ]);
});

it('cuts days at local midnight, not UTC midnight', function (): void {
    $series = collect($this->service->report('2026-10-01', '2026-10-04', groupBy: 'day')['series'])->keyBy('period_start');

    expect($series)->toHaveCount(4)
        ->and($series['2026-10-01']['revenue_kobo'])->toBe(0)
        ->and($series['2026-10-02'])->toMatchArray(['orders' => 1, 'revenue_kobo' => 580_000, 'cogs_kobo' => 300_000, 'expenses_kobo' => 1_000_000, 'net_profit_kobo' => -720_000])
        ->and($series['2026-10-03'])->toMatchArray(['orders' => 1, 'revenue_kobo' => 300_000])
        // delivered 23:30 UTC on the 3rd = 00:30 Lagos on the 4th
        ->and($series['2026-10-04'])->toMatchArray(['orders' => 1, 'revenue_kobo' => 800_000, 'cogs_kobo' => 500_000, 'expenses_kobo' => 300_000, 'net_profit_kobo' => 0]);
});

it('keeps the trend consistent with the summary', function (string $groupBy): void {
    $report = $this->service->report('2026-09-20', '2026-10-10', groupBy: $groupBy);

    expect(array_sum(array_column($report['series'], 'revenue_kobo')))->toBe($report['summary']['revenue_kobo'])
        ->and(array_sum(array_column($report['series'], 'cogs_kobo')))->toBe($report['summary']['cogs_kobo'])
        ->and(array_sum(array_column($report['series'], 'expenses_kobo')))->toBe($report['summary']['expenses_kobo'])
        ->and(array_sum(array_column($report['series'], 'net_profit_kobo')))->toBe($report['summary']['net_profit_kobo']);
})->with(['day', 'week', 'month']);

it('buckets weeks from Monday and months from the 1st, zero-filled', function (): void {
    $weeks = $this->service->report('2026-10-01', '2026-10-04', groupBy: 'week')['series'];
    $months = $this->service->report('2026-09-15', '2026-11-02', groupBy: 'month')['series'];

    // 1 Oct 2026 is a Thursday, so the week starts Monday 28 Sep.
    expect(array_column($weeks, 'period_start'))->toBe(['2026-09-28'])
        ->and(array_column($months, 'period_start'))->toBe(['2026-09-01', '2026-10-01', '2026-11-01']);
});

it('reports zeros and null margins for an empty period', function (): void {
    $report = $this->service->report('2025-01-01', '2025-01-07');

    expect($report['summary']['revenue_kobo'])->toBe(0)
        ->and($report['summary']['gross_margin_pct'])->toBeNull()
        ->and($report['summary']['net_margin_pct'])->toBeNull()
        ->and($report['by_product'])->toBe([])
        ->and($report['series'])->toHaveCount(7);
});
