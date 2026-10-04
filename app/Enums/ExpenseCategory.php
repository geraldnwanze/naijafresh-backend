<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Delivery = 'delivery';
    case Packaging = 'packaging';
    case Rent = 'rent';
    case Salaries = 'salaries';
    case Marketing = 'marketing';
    case Utilities = 'utilities';
    case PaymentFees = 'payment_fees';
    case Wastage = 'wastage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery & riders',
            self::Packaging => 'Packaging',
            self::Rent => 'Rent',
            self::Salaries => 'Salaries & wages',
            self::Marketing => 'Marketing',
            self::Utilities => 'Utilities',
            self::PaymentFees => 'Payment fees',
            self::Wastage => 'Spoilage & wastage',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
