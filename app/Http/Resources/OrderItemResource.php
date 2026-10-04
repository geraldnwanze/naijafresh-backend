<?php

namespace App\Http\Resources;

use App\Enums\StorageType;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_slug' => $this->whenLoaded('product', fn () => $this->product?->slug),
            'name' => $this->name,
            'type' => $this->type->value,
            'sold_by' => $this->sold_by->value,
            'storage_type' => $this->storage_type->value,
            'is_frozen' => $this->storage_type === StorageType::Frozen,
            'unit' => $this->unit,
            'variant_name' => $this->variant_name,
            'image_url' => $this->image_url,
            'unit_price_kobo' => $this->unit_price_kobo,
            'unit_price' => Money::format($this->unit_price_kobo),
            'quantity' => $this->quantity,
            'quantity_label' => $this->quantityLabel(),
            'line_total_kobo' => $this->line_total_kobo,
            'line_total' => Money::format($this->line_total_kobo),

            // Cost snapshot and profit are for staff only.
            'unit_cost_kobo' => $this->when($isAdmin, $this->unit_cost_kobo),
            'line_cost_kobo' => $this->when($isAdmin, $this->line_cost_kobo),
            'line_profit_kobo' => $this->when(
                $isAdmin,
                fn () => $this->line_cost_kobo === null ? null : $this->line_total_kobo - $this->line_cost_kobo,
            ),
        ];
    }
}
