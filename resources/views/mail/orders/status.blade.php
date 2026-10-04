@php
    use App\Enums\OrderStatus;
@endphp
<x-mail::message>
# Hi {{ $order->contact_first_name }},

@switch($order->status)
@case(OrderStatus::Confirmed)
Good news — we've confirmed your order **{{ $order->reference }}** and will start preparing it.

@include('mail.orders._delivery')
@break
@case(OrderStatus::OutForDelivery)
Your order **{{ $order->reference }}** is on its way to you.

@if ($order->delivery?->rider_name)
**Your rider:** {{ $order->delivery->rider_name }}{{ $order->delivery->rider_phone ? ' · '.$order->delivery->rider_phone : '' }}

@endif
@include('mail.orders._delivery')

@include('mail.orders._frozen')
@break
@case(OrderStatus::Delivered)
Your order **{{ $order->reference }}** has been delivered. Enjoy your meal!

If anything isn't right, reply to this email and we'll sort it out.
@break
@case(OrderStatus::Cancelled)
Your order **{{ $order->reference }}** has been cancelled.

@if ($order->cancellation_reason)
**Reason:** {{ $order->cancellation_reason }}

@endif
If you've already paid, please reply to this email and we'll help with the next steps.
@break
@default
Your order **{{ $order->reference }}** is now **{{ $order->status->label() }}**.
@endswitch

<x-mail::button :url="$orderUrl">
View your order
</x-mail::button>

Thanks for choosing NaijaFresh,<br>
{{ config('app.name') }}
</x-mail::message>
