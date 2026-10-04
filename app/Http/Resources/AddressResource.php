<?php

namespace App\Http\Resources;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Address
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'street' => $this->street,
            'area' => $this->area,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'notes' => $this->notes,
            'is_default' => $this->is_default,
        ];
    }
}
