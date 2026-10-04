<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'provider' => $this->provider,
            'is_mock' => $this->provider === 'mock',
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount_kobo' => $this->amount_kobo,
            'amount' => Money::format($this->amount_kobo, $this->currency),
            'currency' => $this->currency,
            'authorization_url' => $this->authorization_url,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
