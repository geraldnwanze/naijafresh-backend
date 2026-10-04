<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use Auditable, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'name',
        'price_delta_kobo',
        'cost_delta_kobo',
        'is_default',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta_kobo' => 'integer',
            'cost_delta_kobo' => 'integer',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
