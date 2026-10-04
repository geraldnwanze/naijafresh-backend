@php
    use App\Support\Money;
@endphp
<x-mail::table>
| Item | Total |
| :--- | ---: |
@foreach ($order->items as $item)
| {{ $item->quantityLabel() }} × {{ $item->name }}{{ $item->variant_name ? ' ('.$item->variant_name.')' : '' }}{{ $item->storage_type->value === 'frozen' ? ' ❄️' : '' }} | {{ Money::format($item->line_total_kobo, $order->currency) }} |
@endforeach
| Subtotal | {{ Money::format($order->subtotal_kobo, $order->currency) }} |
| Delivery | {{ Money::format($order->delivery_fee_kobo, $order->currency) }} |
@if ($order->discount_kobo > 0)
| Discount | −{{ Money::format($order->discount_kobo, $order->currency) }} |
@endif
| **Total** | **{{ Money::format($order->total_kobo, $order->currency) }}** |
</x-mail::table>
