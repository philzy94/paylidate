<?php

namespace App\Exceptions;

class WalletException extends AppException
{
    public static function notFound(): self
    {
        return new self("You don't have a wallet", 404, 'wallet_not_found');
    }

    public static function alreadyExists(): self
    {
        return new self('This user already has an account', 409, 'wallet_exists');
    }

    public static function invalidAmount(): self
    {
        return new self('Transaction rejected, Amount must be greater than 1 NGN', 400, 'wallet_invalid_amount');
    }

    public static function duplicate(): self
    {
        return new self('Duplicate Transaction', 422, 'wallet_duplicate');
    }

    public static function insufficientFunds(): self
    {
        return new self('insufficient fund', 400, 'wallet_insufficient_funds');
    }

    public static function paymentNotVerified(): self
    {
        return new self('We could not verify this payment', 422, 'wallet_payment_unverified');
    }

    public static function invalidSignature(): self
    {
        return new self('invalid auth header', 403, 'wallet_invalid_signature');
    }

    public static function provider(string $message): self
    {
        return new self($message ?: 'Unable to create wallet', 502, 'wallet_provider_error');
    }
}
