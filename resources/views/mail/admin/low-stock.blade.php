@php
    use App\Enums\StockLevel;
@endphp
<x-mail::message>
# {{ count($products) === 1 ? 'Time to restock' : 'Products to restock' }}

@if ($orderReference)
Order **{{ $orderReference }}** took {{ count($products) === 1 ? 'this product' : 'these products' }} down:
@else
These products are running down:
@endif

<x-mail::table>
| Product | Stock left |
| :--- | ---: |
@foreach ($products as $product)
| {{ $product['name'] }} | {{ $product['level'] === StockLevel::Out->value ? '**Out of stock**' : $product['stock_label'] }} |
@endforeach
</x-mail::table>

Products that hit zero are taken off the shop automatically.

<x-mail::button :url="$inventoryUrl">
Open inventory
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
