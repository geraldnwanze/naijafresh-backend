<?php

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ValidatesCartItems;
use App\Services\StoreSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    use ValidatesCartItems;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->cartItemRules(), [
            'contact' => ['required', 'array'],
            'contact.first_name' => ['required', 'string', 'max:80'],
            'contact.last_name' => ['required', 'string', 'max:80'],
            'contact.phone' => ['required', 'string', 'max:30'],
            'contact.email' => ['required', 'string', 'email', 'max:255'],

            'delivery' => ['required', 'array'],
            'delivery.street' => ['required', 'string', 'max:255'],
            'delivery.area' => ['required', 'string', 'max:120'],
            'delivery.city' => ['required', 'string', 'max:120'],
            'delivery.state' => ['nullable', 'string', 'max:120'],
            'delivery.country' => ['nullable', 'string', 'max:120'],
            'delivery.notes' => ['nullable', 'string', 'max:500'],

            'delivery_window_id' => ['required', 'integer', Rule::exists('delivery_windows', 'id')->where('is_active', true)],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:today'],

            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        $settings = app(StoreSettings::class);

        return [
            function (Validator $validator) use ($settings): void {
                $method = $this->input('payment_method');

                if ($method === null || $validator->errors()->has('payment_method')) {
                    return;
                }

                if (! $settings->paymentMethodEnabled(PaymentMethod::from($method))) {
                    $validator->errors()->add('payment_method', 'That payment method is not currently available.');
                }
            },
        ];
    }
}
