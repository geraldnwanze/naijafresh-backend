<?php

namespace App\Http\Resources;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'amount_kobo' => $this->amount_kobo,
            'amount' => Money::format($this->amount_kobo),
            'incurred_on' => $this->incurred_on->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
