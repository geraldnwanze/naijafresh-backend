<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SoldBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateInventoryRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $products = Product::query()
            ->with('category:id,name,slug')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($filters['low_stock'] ?? false, fn ($q) => $q->lowStock())
            ->orderBy('stock_quantity')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return ProductResource::collection($products)->additional([
            'meta' => [
                'low_stock_threshold' => Product::lowStockThresholdFor(SoldBy::Unit),
                'low_stock_threshold_grams' => Product::lowStockThresholdFor(SoldBy::Weight),
            ],
        ]);
    }

    public function update(UpdateInventoryRequest $request, Product $product)
    {
        $product->update($request->validated());

        return new ProductResource($product->load('category'));
    }
}
