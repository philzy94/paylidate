<?php

namespace App\Exceptions;

class BankException extends AppException
{
    public static function notFound(): self
    {
        return new self('Account details not found', 404, 'bank_not_found');
    }

    public static function invalidAccount(): self
    {
        return new self('We could not verify this bank account', 422, 'bank_invalid_account');
    }
}
