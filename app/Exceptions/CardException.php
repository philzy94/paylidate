<?php

namespace App\Exceptions;

class CardException extends AppException
{
    public static function notFound(): self
    {
        return new self('Card not found', 404, 'card_not_found');
    }

    public static function amountTooLow(): self
    {
        return new self('The amount does not cover the card fee', 422, 'card_amount_too_low');
    }

    public static function providerFailed(string $message = ''): self
    {
        return new self($message ?: 'Unable to complete the card request', 502, 'card_provider_failed');
    }
}
