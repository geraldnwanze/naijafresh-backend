<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'exists:payments,reference'],
            // Only honoured by the mock provider so the demo checkout can
            // simulate a declined card.
            'mock_outcome' => ['nullable', Rule::in(['success', 'fail'])],
        ];
    }
}
