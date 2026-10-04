<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'price_delta_kobo' => $this->price_delta_kobo,
            'price_delta_display' => $this->price_delta_kobo === 0
                ? null
                : (($this->price_delta_kobo > 0 ? '+' : '−').Money::format(abs($this->price_delta_kobo))),
            'cost_delta_kobo' => $this->when($request->user()?->isAdmin() ?? false, $this->cost_delta_kobo),
            'is_default' => $this->is_default,
        ];
    }
}
