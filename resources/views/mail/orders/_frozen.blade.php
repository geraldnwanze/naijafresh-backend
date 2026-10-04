@if ($order->items->contains(fn ($item) => $item->storage_type->value === 'frozen'))
<x-mail::panel>
❄️ **Your order includes frozen items.** We pack them in an insulated cooler bag. Please be available during your delivery window so they reach you frozen, and put them in the freezer straight away.
</x-mail::panel>
@endif
