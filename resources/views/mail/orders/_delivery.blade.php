<x-mail::panel>
**Delivery window:** {{ $order->delivery_window_label }} ({{ $order->delivery_window_time }}){{ $order->delivery_date ? ' on '.$order->delivery_date->format('D j M Y') : '' }}<br>
**Deliver to:** {{ $order->contact_first_name }} {{ $order->contact_last_name }}, {{ $order->delivery_street }}, {{ $order->delivery_area }}, {{ $order->delivery_city }}{{ $order->delivery_state ? ', '.$order->delivery_state : '' }}<br>
**Phone:** {{ $order->contact_phone }}
</x-mail::panel>
