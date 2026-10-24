<?php

namespace App\Exceptions;

class PaymentVerificationException extends AppException
{
    public static function alreadyVerified(): self
    {
        return new self('This Payment has been verified', 409, 'payment_already_verified');
    }

    public static function failed(): self
    {
        return new self(
            'An error occured while trying to verify your payment. Please contact paylidate.com',
            422,
            'payment_verification_failed'
        );
    }
}
