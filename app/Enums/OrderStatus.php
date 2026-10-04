<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::Preparing => 'Preparing',
            self::ReadyForPickup => 'Ready for pickup',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Ordered list of statuses that make up the fulfilment pipeline.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [
            self::Pending,
            self::Confirmed,
            self::Processing,
            self::Preparing,
            self::ReadyForPickup,
            self::OutForDelivery,
            self::Delivered,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /**
     * Statuses this status is allowed to transition into.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        if ($this->isTerminal()) {
            return [];
        }

        $pipeline = self::pipeline();
        $index = array_search($this, $pipeline, true);

        $next = [];

        if ($index !== false && isset($pipeline[$index + 1])) {
            $next[] = $pipeline[$index + 1];
        }

        // An order can be cancelled any time before it is out for delivery.
        if (! in_array($this, [self::OutForDelivery], true)) {
            $next[] = self::Cancelled;
        }

        return $next;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
