<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delivery_fee_kobo' => ['required', 'integer', 'min:0'],
            'free_delivery_threshold_kobo' => ['required', 'integer', 'min:0'],
            'cash_on_delivery_enabled' => ['required', 'boolean'],
            'bank_transfer_enabled' => ['required', 'boolean'],
            'store_open' => ['required', 'boolean'],
        ];
    }
}
