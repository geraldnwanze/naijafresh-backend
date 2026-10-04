<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Paystack = 'paystack';
    case BankTransfer = 'bank_transfer';
    case CashOnDelivery = 'cash_on_delivery';

    public function label(): string
    {
        return match ($this) {
            self::Paystack => 'Pay with card (Paystack)',
            self::BankTransfer => 'Bank transfer',
            self::CashOnDelivery => 'Cash on delivery',
        };
    }

    /**
     * Whether this method is settled through an online gateway before fulfilment.
     */
    public function isOnline(): bool
    {
        return $this === self::Paystack;
    }
}
