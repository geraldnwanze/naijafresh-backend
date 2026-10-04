<?php

namespace App\Enums;

enum StockLevel: string
{
    case Ok = 'ok';
    case Low = 'low';
    case Out = 'out';

    /**
     * Higher is worse; used to tell when a sale pushed a product down a level.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Low => 1,
            self::Out => 2,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'In stock',
            self::Low => 'Low stock',
            self::Out => 'Out of stock',
        };
    }
}
