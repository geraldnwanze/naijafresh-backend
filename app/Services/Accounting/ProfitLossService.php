<?php

namespace App\Services\Accounting;

use App\Enums\ExpenseCategory;
use App\Enums\OrderStatus;
use App\Enums\SoldBy;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Weight;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the profit & loss statement for a date range.
 *
 *   Revenue          = product sales − discounts + delivery fees collected
 *   Gross profit     = revenue − cost of goods sold (cost snapshot on each sold line)
 *   Net profit       = gross profit − operating expenses (the `expenses` table)
 *
 * Which orders count depends on the basis:
 *   - "delivered": orders that were delivered in the range (by delivered_at)
 *   - "placed":    every non-cancelled order placed in the range (by placed_at)
 *
 * Days, weeks (Monday start) and months are cut at local midnight in
 * config('naijafresh.timezone'); timestamps are stored in UTC. All money is
 * integer kobo; percentages are rounded to one decimal.
 */
class ProfitLossService
{
    public const BASIS_DELIVERED = 'delivered';

    public const BASIS_PLACED = 'placed';

    public const GROUP_DAY = 'day';

    public const GROUP_WEEK = 'week';

    public const GROUP_MONTH = 'month';

    /**
     * @param  string  $from  First local day included, Y-m-d.
     * @param  string  $to  Last local day included, Y-m-d.
     * @return array{
     *     period: array{from: string, to: string, basis: string, group_by: string, timezone: string},
     *     summary: array<string, int|float|null>,
     *     cost_coverage: array{uncosted_lines: int, uncosted_sales_kobo: int},
     *     expenses_by_category: list<array{category: string, label: string, amount_kobo: int}>,
     *     by_product: list<array<string, mixed>>,
     *     by_category: list<array<string, mixed>>,
     *     series: list<array<string, int|string|float|null>>
     * }
     */
    public function report(
        string $from,
        string $to,
        string $basis = self::BASIS_DELIVERED,
        string $groupBy = self::GROUP_DAY,
    ): array {
        $timezone = (string) config('naijafresh.timezone');
        $startLocal = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $endLocal = CarbonImmutable::parse($to, $timezone)->endOfDay();
        $startUtc = $startLocal->setTimezone('UTC');
        $endUtc = $endLocal->setTimezone('UTC');
        $dateColumn = $basis === self::BASIS_PLACED ? 'placed_at' : 'delivered_at';

        $inScope = function (Builder $query) use ($basis, $dateColumn, $startUtc, $endUtc): Builder {
            $basis === self::BASIS_PLACED
                ? $query->where('orders.status', '!=', OrderStatus::Cancelled->value)
                : $query->where('orders.status', OrderStatus::Delivered->value);

            return $query->whereBetween("orders.{$dateColumn}", [$startUtc, $endUtc]);
        };

        $series = $this->emptySeries($startLocal, $endLocal, $groupBy);

        $summary = [
            'orders' => 0,
            'product_sales_kobo' => 0,
            'delivery_fees_kobo' => 0,
            'discounts_kobo' => 0,
            'revenue_kobo' => 0,
            'cogs_kobo' => 0,
        ];

        // One pass over the orders in scope drives both the totals and the trend.
        $orders = $inScope(Order::query())
            ->select([
                'orders.id',
                'orders.subtotal_kobo',
                'orders.delivery_fee_kobo',
                'orders.discount_kobo',
                'orders.total_kobo',
                "orders.{$dateColumn}",
            ])
            ->selectSub(
                OrderItem::query()
                    ->selectRaw('COALESCE(SUM(line_cost_kobo), 0)')
                    ->whereColumn('order_items.order_id', 'orders.id'),
                'cost_kobo',
            )
            ->cursor();

        foreach ($orders as $order) {
            $cost = (int) $order->getAttribute('cost_kobo');
            $when = CarbonImmutable::instance($order->{$dateColumn})->setTimezone($timezone);
            $key = $this->bucketStart($when, $groupBy)->format('Y-m-d');

            $summary['orders']++;
            $summary['product_sales_kobo'] += $order->subtotal_kobo;
            $summary['delivery_fees_kobo'] += $order->delivery_fee_kobo;
            $summary['discounts_kobo'] += $order->discount_kobo;
            $summary['revenue_kobo'] += $order->total_kobo;
            $summary['cogs_kobo'] += $cost;

            $series[$key] ??= $this->blankBucket($key);
            $series[$key]['orders']++;
            $series[$key]['revenue_kobo'] += $order->total_kobo;
            $series[$key]['cogs_kobo'] += $cost;
        }

        [$byProduct, $byCategory, $coverage] = $this->productBreakdown($inScope, $dateColumn);
        [$expenseTotal, $expensesByCategory] = $this->expenses($from, $to, $series, $timezone, $groupBy);

        $grossProfit = $summary['revenue_kobo'] - $summary['cogs_kobo'];
        $netProfit = $grossProfit - $expenseTotal;

        $summary += [
            'gross_profit_kobo' => $grossProfit,
            'gross_margin_pct' => $this->percent($grossProfit, $summary['revenue_kobo']),
            'expenses_kobo' => $expenseTotal,
            'net_profit_kobo' => $netProfit,
            'net_margin_pct' => $this->percent($netProfit, $summary['revenue_kobo']),
            'average_order_value_kobo' => $summary['orders'] > 0
                ? (int) round($summary['revenue_kobo'] / $summary['orders'])
                : 0,
        ];

        ksort($series);

        foreach ($series as &$bucket) {
            $bucket['gross_profit_kobo'] = $bucket['revenue_kobo'] - $bucket['cogs_kobo'];
            $bucket['net_profit_kobo'] = $bucket['gross_profit_kobo'] - $bucket['expenses_kobo'];
        }
        unset($bucket);

        return [
            'period' => [
                'from' => $from,
                'to' => $to,
                'basis' => $basis,
                'group_by' => $groupBy,
                'timezone' => $timezone,
            ],
            'summary' => $summary,
            'cost_coverage' => $coverage,
            'expenses_by_category' => $expensesByCategory,
            'by_product' => $byProduct,
            'by_category' => $byCategory,
            'series' => array_values($series),
        ];
    }

    /**
     * Per-product and per-category profitability. Profit here covers only lines
     * that have a cost snapshot (uncosted sales are reported separately) and
     * leaves out delivery fees and order-level discounts.
     *
     * @param  callable(Builder): Builder  $inScope
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array{uncosted_lines: int, uncosted_sales_kobo: int}}
     */
    private function productBreakdown(callable $inScope, string $dateColumn): array
    {
        $rows = $inScope(OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id'))
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('order_items.product_id', 'order_items.name', 'order_items.sold_by', 'categories.name')
            ->selectRaw(<<<'SQL'
                order_items.product_id,
                order_items.name,
                order_items.sold_by,
                categories.name AS category_name,
                SUM(order_items.quantity) AS quantity,
                SUM(order_items.line_total_kobo) AS revenue_kobo,
                COALESCE(SUM(order_items.line_cost_kobo), 0) AS cost_kobo,
                SUM(CASE WHEN order_items.line_cost_kobo IS NULL THEN order_items.line_total_kobo ELSE 0 END) AS uncosted_revenue_kobo,
                SUM(CASE WHEN order_items.line_cost_kobo IS NULL THEN 1 ELSE 0 END) AS uncosted_lines
            SQL)
            ->get();

        $products = [];
        $categories = [];
        $coverage = ['uncosted_lines' => 0, 'uncosted_sales_kobo' => 0];

        foreach ($rows as $row) {
            $quantity = (int) $row->getAttribute('quantity');
            $revenue = (int) $row->getAttribute('revenue_kobo');
            $cost = (int) $row->getAttribute('cost_kobo');
            $uncostedRevenue = (int) $row->getAttribute('uncosted_revenue_kobo');
            $uncostedLines = (int) $row->getAttribute('uncosted_lines');
            $costedRevenue = $revenue - $uncostedRevenue;
            $profit = $costedRevenue - $cost;
            $category = $row->getAttribute('category_name') ?? 'Uncategorised';

            $coverage['uncosted_lines'] += $uncostedLines;
            $coverage['uncosted_sales_kobo'] += $uncostedRevenue;

            $products[] = [
                'product_id' => $row->product_id,
                'name' => $row->name,
                'category' => $category,
                'sold_by' => $row->sold_by->value,
                'quantity' => $quantity,
                'quantity_label' => $row->sold_by === SoldBy::Weight ? Weight::format($quantity) : (string) $quantity,
                'revenue_kobo' => $revenue,
                'cost_kobo' => $cost,
                'profit_kobo' => $profit,
                'margin_pct' => $this->percent($profit, $costedRevenue),
                'has_uncosted' => $uncostedLines > 0,
            ];

            $categories[$category] ??= ['category' => $category, 'revenue_kobo' => 0, 'cost_kobo' => 0, 'costed_revenue_kobo' => 0];
            $categories[$category]['revenue_kobo'] += $revenue;
            $categories[$category]['cost_kobo'] += $cost;
            $categories[$category]['costed_revenue_kobo'] += $costedRevenue;
        }

        usort($products, fn (array $a, array $b): int => $b['profit_kobo'] <=> $a['profit_kobo']);

        $categoryRows = array_map(function (array $category): array {
            $profit = $category['costed_revenue_kobo'] - $category['cost_kobo'];

            return [
                'category' => $category['category'],
                'revenue_kobo' => $category['revenue_kobo'],
                'cost_kobo' => $category['cost_kobo'],
                'profit_kobo' => $profit,
                'margin_pct' => $this->percent($profit, $category['costed_revenue_kobo']),
            ];
        }, array_values($categories));

        usort($categoryRows, fn (array $a, array $b): int => $b['profit_kobo'] <=> $a['profit_kobo']);

        return [array_slice($products, 0, 100), $categoryRows, $coverage];
    }

    /**
     * Operating expenses in the range, summed in total, by category, and into
     * the trend buckets.
     *
     * @param  array<string, array<string, int|string>>  $series
     * @return array{0: int, 1: list<array{category: string, label: string, amount_kobo: int}>}
     */
    private function expenses(string $from, string $to, array &$series, string $timezone, string $groupBy): array
    {
        $total = 0;
        $byCategory = [];

        $expenses = Expense::query()
            ->whereBetween('incurred_on', [$from, $to])
            ->get(['category', 'amount_kobo', 'incurred_on']);

        foreach ($expenses as $expense) {
            $total += $expense->amount_kobo;
            $byCategory[$expense->category->value] = ($byCategory[$expense->category->value] ?? 0) + $expense->amount_kobo;

            $day = CarbonImmutable::parse($expense->incurred_on->format('Y-m-d'), $timezone);
            $key = $this->bucketStart($day, $groupBy)->format('Y-m-d');

            $series[$key] ??= $this->blankBucket($key);
            $series[$key]['expenses_kobo'] += $expense->amount_kobo;
        }

        arsort($byCategory);

        $rows = [];

        foreach ($byCategory as $value => $amount) {
            $rows[] = [
                'category' => $value,
                'label' => ExpenseCategory::from($value)->label(),
                'amount_kobo' => $amount,
            ];
        }

        return [$total, $rows];
    }

    /**
     * Every bucket in the range, zero-filled so the trend has no gaps.
     *
     * @return array<string, array<string, int|string>>
     */
    private function emptySeries(CarbonImmutable $start, CarbonImmutable $end, string $groupBy): array
    {
        $series = [];
        $cursor = $this->bucketStart($start, $groupBy);

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $series[$key] = $this->blankBucket($key);

            $cursor = match ($groupBy) {
                self::GROUP_WEEK => $cursor->addWeek(),
                self::GROUP_MONTH => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $series;
    }

    /**
     * @return array<string, int|string>
     */
    private function blankBucket(string $periodStart): array
    {
        return [
            'period_start' => $periodStart,
            'orders' => 0,
            'revenue_kobo' => 0,
            'cogs_kobo' => 0,
            'expenses_kobo' => 0,
        ];
    }

    private function bucketStart(CarbonImmutable $moment, string $groupBy): CarbonImmutable
    {
        return match ($groupBy) {
            self::GROUP_WEEK => $moment->startOfWeek(CarbonInterface::MONDAY),
            self::GROUP_MONTH => $moment->startOfMonth(),
            default => $moment->startOfDay(),
        };
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
