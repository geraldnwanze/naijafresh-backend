@php
    use App\Enums\PaymentMethod;
    use App\Enums\PaymentStatus;
    use App\Support\Money;
@endphp
<x-mail::message>
# Thanks, {{ $order->contact_first_name }}!

We've received your order **{{ $order->reference }}**.

@include('mail.orders._summary')

@if ($order->payment_method === PaymentMethod::CashOnDelivery)
You'll pay **{{ Money::format($order->total_kobo, $order->currency) }}** in cash when your order arrives.
@elseif ($order->payment_method === PaymentMethod::BankTransfer)
You chose bank transfer. We'll confirm your order as soon as your payment reaches us.
@elseif ($order->payment?->status === PaymentStatus::Paid)
Your payment has been received.
@else
Your card payment is still pending. You can complete it from your order page and we'll confirm your order straight away.
@endif

@include('mail.orders._delivery')

@include('mail.orders._frozen')

<x-mail::button :url="$orderUrl">
View your order
</x-mail::button>

Thanks for choosing NaijaFresh,<br>
{{ config('app.name') }}
</x-mail::message>
