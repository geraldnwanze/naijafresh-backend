<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\Auditable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use Auditable, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'user_id',
        'status',
        'contact_first_name',
        'contact_last_name',
        'contact_phone',
        'contact_email',
        'delivery_street',
        'delivery_area',
        'delivery_city',
        'delivery_state',
        'delivery_country',
        'delivery_notes',
        'delivery_window_id',
        'delivery_window_label',
        'delivery_window_time',
        'delivery_date',
        'payment_method',
        'currency',
        'subtotal_kobo',
        'delivery_fee_kobo',
        'discount_kobo',
        'total_kobo',
        'placed_at',
        'confirmed_at',
        'prepared_at',
        'delivered_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal_kobo' => 'integer',
            'delivery_fee_kobo' => 'integer',
            'discount_kobo' => 'integer',
            'total_kobo' => 'integer',
            'delivery_date' => 'date',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'prepared_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return HasOne<Delivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /**
     * @return BelongsTo<DeliveryWindow, $this>
     */
    public function deliveryWindow(): BelongsTo
    {
        return $this->belongsTo(DeliveryWindow::class);
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    #[Scope]
    protected function status(Builder $query, OrderStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof OrderStatus ? $status->value : $status);
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    #[Scope]
    protected function awaitingDelivery(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrderStatus::ReadyForPickup->value,
            OrderStatus::OutForDelivery->value,
        ]);
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $term)).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->whereRaw('LOWER(reference) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_first_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_last_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_phone) LIKE ?', [$like])
                ->orWhereRaw('LOWER(contact_email) LIKE ?', [$like]);
        });
    }

    /**
     * @return list<string>
     */
    public static function auditedEvents(): array
    {
        return ['updated'];
    }
}
