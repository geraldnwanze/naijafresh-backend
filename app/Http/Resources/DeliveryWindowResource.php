<?php

namespace App\Http\Resources;

use App\Models\DeliveryWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryWindow
 */
class DeliveryWindowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'display' => $this->displayTime(),
            'is_active' => $this->is_active,
        ];
    }
}
