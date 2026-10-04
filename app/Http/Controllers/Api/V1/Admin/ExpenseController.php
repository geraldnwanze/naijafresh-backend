<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreExpenseRequest;
use App\Http\Requests\Admin\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Expense::query()
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('incurred_on', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('incurred_on', '<=', $to))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereRaw(
                'LOWER(description) LIKE ?',
                ['%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $term)).'%'],
            ));

        $total = (int) (clone $query)->sum('amount_kobo');

        $expenses = $query
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ExpenseResource::collection($expenses)->additional([
            'summary' => ['total_kobo' => $total],
            'categories' => ExpenseCategory::options(),
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        $expense = Expense::create($request->validated());

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }

    public function show(Expense $expense)
    {
        return new ExpenseResource($expense);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $expense->update($request->validated());

        return new ExpenseResource($expense);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }
}
