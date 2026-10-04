<?php

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

describe('profit & loss endpoint', function (): void {
    it('is for admins only', function (): void {
        $this->getJson('/api/v1/admin/accounting/profit-loss')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss')
            ->assertForbidden();
    });

    it('returns the statement, breakdowns and trend', function (): void {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss?from=2026-10-01&to=2026-10-07&basis=placed&group_by=day')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'period' => ['from', 'to', 'basis', 'group_by', 'timezone'],
                'summary' => ['orders', 'revenue_kobo', 'cogs_kobo', 'gross_profit_kobo', 'gross_margin_pct', 'expenses_kobo', 'net_profit_kobo', 'net_margin_pct'],
                'cost_coverage' => ['uncosted_lines', 'uncosted_sales_kobo'],
                'expenses_by_category',
                'by_product',
                'by_category',
                'series',
            ]])
            ->assertJsonPath('data.period.basis', 'placed')
            ->assertJsonCount(7, 'data.series');
    });

    it('defaults to this month, delivered basis, with a trend sized to the range', function (): void {
        $today = CarbonImmutable::now(config('naijafresh.timezone'));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss')
            ->assertOk()
            ->assertJsonPath('data.period.from', $today->startOfMonth()->toDateString())
            ->assertJsonPath('data.period.to', $today->toDateString())
            ->assertJsonPath('data.period.basis', 'delivered')
            ->assertJsonPath('data.period.group_by', 'day');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss?from=2026-01-01&to=2026-03-15')
            ->assertJsonPath('data.period.group_by', 'week');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss?from=2026-01-01&to=2026-09-30')
            ->assertJsonPath('data.period.group_by', 'month');
    });

    it('rejects bad ranges and options', function (array $query, string $field): void {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'end before start' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'to'],
        'longer than a year' => [['from' => '2024-01-01', 'to' => '2026-01-01'], 'to'],
        'unknown basis' => [['basis' => 'accrual'], 'basis'],
        'unknown grouping' => [['group_by' => 'hour'], 'group_by'],
        'bad date' => [['from' => '10/01/2026'], 'from'],
    ]);
});

describe('expenses', function (): void {
    it('is for admins only', function (): void {
        $customer = User::factory()->create();

        $this->getJson('/api/v1/admin/expenses')->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/expenses')->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/admin/expenses', [])->assertForbidden();
    });

    it('records an expense', function (): void {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/expenses', [
            'category' => 'rent',
            'description' => 'October shop rent',
            'amount_kobo' => 12_000_000,
            'incurred_on' => '2026-10-01',
            'notes' => 'Paid by transfer',
        ])->assertCreated();

        expect($response->json('data.category_label'))->toBe('Rent')
            ->and($response->json('data.amount'))->toBe('₦120,000.00')
            ->and($response->json('data.incurred_on'))->toBe('2026-10-01');

        $this->assertDatabaseHas('expenses', ['description' => 'October shop rent', 'amount_kobo' => 12_000_000]);
    });

    it('validates an expense', function (array $override, string $field): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/expenses', $override + [
            'category' => 'rent',
            'description' => 'Rent',
            'amount_kobo' => 100_000,
            'incurred_on' => '2026-10-01',
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'zero amount' => [['amount_kobo' => 0], 'amount_kobo'],
        'unknown category' => [['category' => 'yachts'], 'category'],
        'missing description' => [['description' => ''], 'description'],
        'bad date' => [['incurred_on' => 'yesterday'], 'incurred_on'],
    ]);

    it('lists newest first, filtered, with a total and the category options', function (): void {
        Expense::factory()->create(['category' => ExpenseCategory::Rent, 'description' => 'Shop rent', 'amount_kobo' => 1_000_000, 'incurred_on' => '2026-10-01']);
        Expense::factory()->create(['category' => ExpenseCategory::Delivery, 'description' => 'Rider payout', 'amount_kobo' => 300_000, 'incurred_on' => '2026-10-04']);
        Expense::factory()->create(['category' => ExpenseCategory::Delivery, 'description' => 'Old rider payout', 'amount_kobo' => 200_000, 'incurred_on' => '2026-08-04']);

        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/expenses')->assertOk();
        expect(collect($all->json('data'))->pluck('description')->all())->toBe(['Rider payout', 'Shop rent', 'Old rider payout'])
            ->and($all->json('summary.total_kobo'))->toBe(1_500_000)
            ->and(collect($all->json('categories'))->pluck('value'))->toContain('rent', 'wastage');

        $filtered = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/expenses?category=delivery&from=2026-10-01&to=2026-10-31')->assertOk();
        expect(collect($filtered->json('data'))->pluck('description')->all())->toBe(['Rider payout'])
            ->and($filtered->json('summary.total_kobo'))->toBe(300_000);

        $searched = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/expenses?search=SHOP')->assertOk();
        expect($searched->json('data'))->toHaveCount(1);
    });

    it('updates and deletes an expense', function (): void {
        $expense = Expense::factory()->create(['description' => 'Before']);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/expenses/{$expense->id}", [
            'category' => 'packaging',
            'description' => 'After',
            'amount_kobo' => 555_000,
            'incurred_on' => '2026-10-02',
        ])->assertOk()->assertJsonPath('data.description', 'After');

        expect($expense->refresh()->amount_kobo)->toBe(555_000);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/admin/expenses/{$expense->id}")->assertOk();
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    });

    it('feeds the profit & loss expense totals', function (): void {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/expenses', [
            'category' => 'marketing',
            'description' => 'Ads',
            'amount_kobo' => 400_000,
            'incurred_on' => '2026-10-03',
        ])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/accounting/profit-loss?from=2026-10-01&to=2026-10-07')
            ->assertJsonPath('data.summary.expenses_kobo', 400_000)
            ->assertJsonPath('data.summary.net_profit_kobo', -400_000)
            ->assertJsonPath('data.expenses_by_category.0.label', 'Marketing');
    });
});
