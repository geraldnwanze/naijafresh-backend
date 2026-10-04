@php
    use App\Support\Money;
@endphp
<x-mail::message>
# New order {{ $order->reference }}

**{{ $order->contact_first_name }} {{ $order->contact_last_name }}** just placed an order worth **{{ Money::format($order->total_kobo, $order->currency) }}**.

**Payment:** {{ $order->payment_method->label() }}{{ $order->payment?->isPaid() ? ' — paid' : ' — not yet paid' }}

@include('mail.orders._summary')

@include('mail.orders._delivery')

@if ($order->items->contains(fn ($item) => $item->storage_type->value === 'frozen'))
<x-mail::panel>
❄️ **Includes frozen items.** Pack them in a cooler bag and make sure they leave in time for the delivery window.
</x-mail::panel>
@endif

<x-mail::button :url="$adminUrl">
Open in admin
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
