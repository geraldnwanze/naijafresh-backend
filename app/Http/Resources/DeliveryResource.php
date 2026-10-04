<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'window_label' => $this->window_label,
            'window_time' => $this->window_time,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'rider_name' => $this->when($isAdmin || $this->status->value === 'out_for_delivery', $this->rider_name),
            'rider_phone' => $this->when($isAdmin || $this->status->value === 'out_for_delivery', $this->rider_phone),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'notes' => $this->when($isAdmin, $this->notes),
        ];
    }
}
