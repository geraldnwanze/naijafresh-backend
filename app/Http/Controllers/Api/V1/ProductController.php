<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StorageType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\Analytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'type' => ['nullable', Rule::enum(ProductType::class)],
            'storage' => ['nullable', Rule::enum(StorageType::class)],
            'sold_by' => ['nullable', Rule::enum(SoldBy::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:40'],
            'featured' => ['nullable', 'boolean'],
            'available' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $query = Product::query()
            ->with(['category', 'variants'])
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->ofType($type))
            ->when($filters['storage'] ?? null, fn ($q, $storage) => $q->where('storage_type', $storage))
            ->when($filters['sold_by'] ?? null, fn ($q, $soldBy) => $q->where('sold_by', $soldBy))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($filters['tag'] ?? null, fn ($q, $tag) => $q->whereJsonContains('tags', $tag))
            ->when(($filters['featured'] ?? false), fn ($q) => $q->featured())
            ->when(($filters['available'] ?? false), fn ($q) => $q->available());

        $search = $filters['search'] ?? null;

        // When searching without an explicit sort, surface name matches first
        // so the obvious result ("Fresh Pepper" for "pepper") leads.
        if ($search !== null && ! isset($filters['sort'])) {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], trim($search))).'%';
            $query->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', [$like]);
        }

        $query = match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_kobo'),
            'price_desc' => $query->orderByDesc('price_kobo'),
            'name' => $query->orderBy('name'),
            default => $query->ordered(),
        };

        if ($search !== null) {
            app(Analytics::class)->track('product_search', ['term' => $search]);
        }

        return ProductResource::collection(
            $query->paginate($filters['per_page'] ?? 24)->withQueryString()
        );
    }

    public function show(Request $request, Product $product)
    {
        $product->load(['category', 'variants']);

        app(Analytics::class)->track('product_viewed', [
            'product' => $product->slug,
            'type' => $product->type->value,
        ]);

        return new ProductResource($product);
    }
}
