<?php

namespace App\Enums;

enum PreparationType: string
{
    case Raw = 'raw';
    case Cleaned = 'cleaned';
    case Chopped = 'chopped';
    case Portioned = 'portioned';
    case Blended = 'blended';
    case ReadyToCook = 'ready_to_cook';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw',
            self::Cleaned => 'Washed & cleaned',
            self::Chopped => 'Chopped',
            self::Portioned => 'Portioned',
            self::Blended => 'Blended',
            self::ReadyToCook => 'Ready to cook',
        };
    }
}
