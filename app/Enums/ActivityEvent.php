<?php

namespace App\Enums;

enum ActivityEvent: string
{
    case UserRegistered = 'user_registered';
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case LoggedOut = 'logged_out';
    case OrderPlaced = 'order_placed';
    case OrderStatusChanged = 'order_status_changed';
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';

    public function label(): string
    {
        return match ($this) {
            self::UserRegistered => 'Registered',
            self::LoginSucceeded => 'Signed in',
            self::LoginFailed => 'Failed sign-in',
            self::LoggedOut => 'Signed out',
            self::OrderPlaced => 'Order placed',
            self::OrderStatusChanged => 'Order status changed',
            self::PaymentSucceeded => 'Payment received',
            self::PaymentFailed => 'Payment failed',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $event): array => ['value' => $event->value, 'label' => $event->label()], self::cases());
    }
}
