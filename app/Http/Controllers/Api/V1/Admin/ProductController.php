<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SoldBy;
use App\Enums\StorageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'type' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['category', 'variants'])
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        $product = $this->persist(new Product, $request->validated());

        return (new ProductResource($product->load(['category', 'variants'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product)
    {
        return new ProductResource($product->load(['category', 'variants']));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $product = $this->persist($product, $request->validated());

        return new ProductResource($product->load(['category', 'variants']));
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $variants = $data['variants'] ?? null;
            unset($data['variants']);

            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name'], $product->id);

            $data['sold_by'] = $data['sold_by'] ?? $product->sold_by->value;

            if (array_key_exists('storage_type', $data) && $data['storage_type'] === null) {
                $data['storage_type'] = StorageType::Ambient->value;
            }

            if ($data['sold_by'] === SoldBy::Weight->value) {
                // Priced per kilogram; weight limits and stock are grams.
                $data['unit'] = 'kg';
                $data['min_weight_grams'] ??= $data['weight_step_grams'];
            } else {
                $data['min_weight_grams'] = null;
                $data['weight_step_grams'] = null;
                $data['max_weight_grams'] = null;
            }

            $product->fill($data)->save();

            if ($variants !== null) {
                $product->variants()->delete();

                foreach (array_values($variants) as $index => $variant) {
                    $product->variants()->create([
                        'kind' => $variant['kind'] ?? 'protein',
                        'name' => $variant['name'],
                        'price_delta_kobo' => (int) ($variant['price_delta_kobo'] ?? 0),
                        'cost_delta_kobo' => (int) ($variant['cost_delta_kobo'] ?? 0),
                        'is_default' => (bool) ($variant['is_default'] ?? false),
                        'position' => $index,
                    ]);
                }
            }

            return $product;
        });
    }

    private function uniqueSlug(string $value, ?int $ignoreId): string
    {
        $base = Str::slug($value);
        $slug = $base;
        $suffix = 2;

        while (
            Product::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
