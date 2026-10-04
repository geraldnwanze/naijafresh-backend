<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use Auditable, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'provider',
        'method',
        'status',
        'currency',
        'amount_kobo',
        'reference',
        'provider_reference',
        'authorization_url',
        'paid_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount_kobo' => 'integer',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return list<string>
     */
    public static function auditedEvents(): array
    {
        return ['updated'];
    }

    /**
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        return ['created_at', 'updated_at', 'authorization_url', 'meta'];
    }
}
