<?php

namespace App\Http\Requests\Address;

class UpdateAddressRequest extends StoreAddressRequest
{
    public function authorize(): bool
    {
        $address = $this->route('address');

        return $address !== null && $address->user_id === $this->user()?->id;
    }
}
