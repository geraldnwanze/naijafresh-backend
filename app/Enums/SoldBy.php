<?php

namespace App\Enums;

enum SoldBy: string
{
    case Unit = 'unit';
    case Weight = 'weight';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Per item',
            self::Weight => 'By weight',
        };
    }
}
