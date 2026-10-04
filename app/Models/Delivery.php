<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'status',
        'fee_kobo',
        'window_label',
        'window_time',
        'scheduled_date',
        'rider_name',
        'rider_phone',
        'dispatched_at',
        'delivered_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'fee_kobo' => 'integer',
            'scheduled_date' => 'date',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
