<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Enums\SoldBy;
use App\Enums\StorageType;
use App\Support\Weight;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

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
        'product_id',
        'product_variant_id',
        'name',
        'type',
        'sold_by',
        'storage_type',
        'unit',
        'variant_name',
        'image_url',
        'unit_price_kobo',
        'quantity',
        'line_total_kobo',
        'unit_cost_kobo',
        'line_cost_kobo',
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
            'unit_price_kobo' => 'integer',
            'quantity' => 'integer',
            'line_total_kobo' => 'integer',
            'unit_cost_kobo' => 'integer',
            'line_cost_kobo' => 'integer',
        ];
    }

    /**
     * "3" for items, "2.5 kg" for weight-sold lines (where quantity is grams).
     */
    public function quantityLabel(): string
    {
        return $this->sold_by === SoldBy::Weight
            ? Weight::format($this->quantity)
            : (string) $this->quantity;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
