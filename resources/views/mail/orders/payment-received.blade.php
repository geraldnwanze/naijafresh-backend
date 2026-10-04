@php
    use App\Support\Money;
@endphp
<x-mail::message>
# Payment received

Hi {{ $order->contact_first_name }}, we've received **{{ Money::format($payment->amount_kobo, $payment->currency) }}** for order **{{ $order->reference }}**. Your order is confirmed.

@include('mail.orders._delivery')

@include('mail.orders._frozen')

<x-mail::button :url="$orderUrl">
View your order
</x-mail::button>

Payment reference: {{ $payment->reference }}

Thanks for choosing NaijaFresh,<br>
{{ config('app.name') }}
</x-mail::message>
