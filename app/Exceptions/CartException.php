<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * Thrown when a cart cannot be priced or fulfilled: empty cart, unknown
 * product, unavailable product, invalid quantity, or insufficient stock.
 * Rendered as HTTP 422 with a `message` and field-style `errors` payload.
 */
class CartException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(string $message, public array $errors = [])
    {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('Your cart is empty.', ['items' => ['Your cart is empty.']]);
    }

    public static function unavailable(string $productName): self
    {
        return new self("\"{$productName}\" is currently unavailable.", [
            'items' => ["\"{$productName}\" is currently unavailable."],
        ]);
    }

    public static function invalidQuantity(string $message): self
    {
        return new self($message, ['items' => [$message]]);
    }

    /**
     * @param  int  $available  Items left, or grams left for weight-sold products.
     */
    public static function insufficientStock(Product $product, int $available): self
    {
        $left = $product->quantityLabel($available);

        $message = $available >= $product->minPurchasableQuantity()
            ? "Only {$left} of \"{$product->name}\" left in stock."
            : "\"{$product->name}\" is out of stock.";

        return new self($message, ['items' => [$message]]);
    }
}
