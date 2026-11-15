<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the online payment provider refuses or fails a call (initialise
 * or verify). The exception message is for logs; `customerMessage()` is what
 * the shopper may see. Rendered as HTTP 502.
 */
class PaymentGatewayException extends RuntimeException
{
    private function __construct(string $message, private readonly string $customerMessage)
    {
        parent::__construct($message);
    }

    public static function couldNotStart(string $reason): self
    {
        return new self(
            "Paystack could not initialize this payment: {$reason}",
            "We couldn't start your card payment just now. Your order is saved, so you can try again from your order page in a moment.",
        );
    }

    public static function couldNotConfirm(string $reason): self
    {
        return new self(
            "Paystack could not verify this payment: {$reason}",
            "We couldn't confirm your payment with the bank yet. If you were charged, don't pay again: it will be confirmed shortly. Otherwise try again in a moment.",
        );
    }

    public function customerMessage(): string
    {
        return $this->customerMessage;
    }
}
