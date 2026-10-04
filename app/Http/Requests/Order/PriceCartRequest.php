<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\Concerns\ValidatesCartItems;
use Illuminate\Foundation\Http\FormRequest;

class PriceCartRequest extends FormRequest
{
    use ValidatesCartItems;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->cartItemRules();
    }
}
