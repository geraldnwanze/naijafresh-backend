@php
    use App\Support\Money;
@endphp
<x-mail::message>
# Your payment didn't go through

Hi {{ $order->contact_first_name }}, the payment of **{{ Money::format($payment->amount_kobo, $payment->currency) }}** for order **{{ $order->reference }}** wasn't completed, so we haven't confirmed the order yet.

You can try again from your order page — it only takes a moment.

<x-mail::button :url="$orderUrl">
Complete payment
</x-mail::button>

Thanks for choosing NaijaFresh,<br>
{{ config('app.name') }}
</x-mail::message>
