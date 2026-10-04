<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Reference
{
    public static function order(): string
    {
        return 'NF-'.mb_strtoupper(Str::random(8));
    }

    public static function payment(): string
    {
        return 'NFPAY-'.mb_strtoupper(Str::random(10));
    }
}
