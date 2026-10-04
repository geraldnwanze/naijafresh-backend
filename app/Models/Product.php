<?php

namespace App\Models;

use App\Enums\PreparationType;
use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StockLevel;
use App\Enums\StorageType;
use App\Models\Concerns\Auditable;
use App\Support\Weight;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Auditable, HasFactory;

    /**
     * Mirrors the column defaults so unsaved/just-created models behave.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sold_by' => 'unit',
        'storage_type' => 'ambient',
    ];

    /** @var list<string> */
    protected $fillable = [
        'category_id',
        'type',
        'sold_by',
        'storage_type',
        'name',
        'slug',
        'description',
        'price_kobo',
        'compare_at_price_kobo',
        'cost_price_kobo',
        'unit',
        'min_weight_grams',
        'weight_step_grams',
        'max_weight_grams',
        'stock_quantity',
        'is_available',
        'preparation_type',
        'image_url',
        'tags',
        'is_featured',
        'position',
        'serves',
        'prep_time_minutes',
        'included_items',
        'not_included_items',
        'storage_instructions',
        'cooking_instructions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'sold_by' => SoldBy::class,
            'storage_type' => StorageType::class,
            'preparation_type' => PreparationType::class,
            'price_kobo' => 'integer',
            'compare_at_price_kobo' => 'integer',
            'cost_price_kobo' => 'integer',
            'min_weight_grams' => 'integer',
            'weight_step_grams' => 'integer',
            'max_weight_grams' => 'integer',
            'stock_quantity' => 'integer',
            'prep_time_minutes' => 'integer',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'position' => 'integer',
            'tags' => 'array',
            'included_items' => 'array',
            'not_included_items' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isMealKit(): bool
    {
        return $this->type === ProductType::MealKit;
    }

    /**
     * Profit on one unit (one item, or one kg for weight-sold products) before
     * delivery and overheads; null until a cost price is entered.
     */
    public function profitPerUnitKobo(): ?int
    {
        return $this->cost_price_kobo === null ? null : $this->price_kobo - $this->cost_price_kobo;
    }

    /**
     * Profit as a percentage of the selling price, rounded to one decimal.
     */
    public function marginPercent(): ?float
    {
        $profit = $this->profitPerUnitKobo();

        return $profit === null || $this->price_kobo <= 0
            ? null
            : round($profit / $this->price_kobo * 100, 1);
    }

    public function isSoldByWeight(): bool
    {
        return $this->sold_by === SoldBy::Weight;
    }

    public function isFrozen(): bool
    {
        return $this->storage_type === StorageType::Frozen;
    }

    /**
     * Grams added per step for weight-sold products (default 500 g).
     */
    public function weightStepGrams(): int
    {
        return max(1, (int) ($this->weight_step_grams ?: 500));
    }

    /**
     * Smallest weight (grams) a customer can order; defaults to one step.
     */
    public function minWeightGrams(): int
    {
        return max(1, (int) ($this->min_weight_grams ?: $this->weightStepGrams()));
    }

    /**
     * Smallest quantity that can be bought in this product's quantity unit
     * (1 item, or the minimum weight in grams).
     */
    public function minPurchasableQuantity(): int
    {
        return $this->isSoldByWeight() ? $this->minWeightGrams() : 1;
    }

    public function isPurchasable(): bool
    {
        return $this->is_available && $this->stock_quantity >= $this->minPurchasableQuantity();
    }

    /**
     * Human-readable quantity: "3" for items, "2.5 kg" for weight-sold products.
     */
    public function quantityLabel(int $quantity): string
    {
        return $this->isSoldByWeight() ? Weight::format($quantity) : (string) $quantity;
    }

    /**
     * Price of $quantity at $unitPriceKobo. For weight-sold products the unit
     * price is per kilogram and the quantity is grams.
     */
    public function lineTotalKobo(int $unitPriceKobo, int $quantity): int
    {
        return $this->isSoldByWeight()
            ? Weight::priceKobo($unitPriceKobo, $quantity)
            : $unitPriceKobo * $quantity;
    }

    /**
     * Why $quantity is not an orderable amount of this product, or null when it is.
     */
    public function quantityError(int $quantity): ?string
    {
        if (! $this->isSoldByWeight()) {
            return $quantity > 99
                ? "You can order up to 99 of \"{$this->name}\" at a time."
                : null;
        }

        $min = $this->minWeightGrams();
        $step = $this->weightStepGrams();

        if ($quantity < $min) {
            return "The minimum order for \"{$this->name}\" is ".Weight::format($min).'.';
        }

        if ($quantity % $step !== 0) {
            return "\"{$this->name}\" is sold in steps of ".Weight::format($step).'.';
        }

        if ($this->max_weight_grams !== null && $quantity > $this->max_weight_grams) {
            return "The maximum order for \"{$this->name}\" is ".Weight::format($this->max_weight_grams).'.';
        }

        return null;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function available(Builder $query): Builder
    {
        return $query->where('is_available', true)->where('stock_quantity', '>', 0);
    }

    /**
     * Stock at or below the restock threshold (config naijafresh.inventory):
     * items for unit products, grams for weight-sold products.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function lowStock(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where(fn (Builder $unit) => $unit->where('sold_by', SoldBy::Unit->value)->where('stock_quantity', '<=', self::lowStockThresholdFor(SoldBy::Unit)))
                ->orWhere(fn (Builder $weight) => $weight->where('sold_by', SoldBy::Weight->value)->where('stock_quantity', '<=', self::lowStockThresholdFor(SoldBy::Weight)));
        });
    }

    /**
     * Restock threshold in the product's quantity unit (items, or grams).
     */
    public static function lowStockThresholdFor(SoldBy $soldBy): int
    {
        return $soldBy === SoldBy::Weight
            ? (int) config('naijafresh.inventory.low_stock_grams')
            : (int) config('naijafresh.inventory.low_stock_units');
    }

    /**
     * Where $stock sits against this product's restock threshold.
     */
    public function stockLevelFor(int $stock): StockLevel
    {
        return match (true) {
            $stock <= 0 => StockLevel::Out,
            $stock <= self::lowStockThresholdFor($this->sold_by) => StockLevel::Low,
            default => StockLevel::Ok,
        };
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function featured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function ofType(Builder $query, ProductType|string $type): Builder
    {
        return $query->where('type', $type instanceof ProductType ? $type->value : $type);
    }

    /**
     * Full-text-ish search across name, description and tags.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $term)).'%';

        return $query->where(function (Builder $q) use ($like, $term): void {
            $q->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                ->orWhereJsonContains('tags', mb_strtolower($term))
                ->orWhereHas('category', fn (Builder $c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
        });
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderByDesc('id');
    }
}
