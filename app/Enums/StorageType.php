<?php

namespace App\Enums;

enum StorageType: string
{
    case Ambient = 'ambient';
    case Chilled = 'chilled';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Ambient => 'Room temperature',
            self::Chilled => 'Keep refrigerated',
            self::Frozen => 'Frozen',
        };
    }
}
