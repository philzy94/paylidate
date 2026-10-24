<?php

namespace App\Exceptions;

class InstantPayException extends AppException
{
    public static function receiverUnknown(): self
    {
        return new self('Receiver is not a paylidate user', 400, 'instantpay_receiver_unknown');
    }

    public static function toSelf(): self
    {
        return new self('You can not send money to yourself', 400, 'instantpay_to_self');
    }

    public static function paymentUnverified(): self
    {
        return new self('We could not verify this payment', 422, 'instantpay_payment_unverified');
    }

    public static function paymentUsed(): self
    {
        return new self('Invalid payment_ref', 400, 'instantpay_payment_used');
    }

    public static function notFound(): self
    {
        return new self('Invalid withdrawal code', 404, 'instantpay_not_found');
    }

    public static function forbidden(): self
    {
        return new self('unauthorized', 403, 'instantpay_forbidden');
    }

    public static function invalidPin(): self
    {
        return new self('Invalid withdrawal pin', 400, 'instantpay_invalid_pin');
    }

    public static function accountInvalid(): self
    {
        return new self('We could not verify this bank account', 422, 'instantpay_account_invalid');
    }

    public static function notReady(): self
    {
        return new self('Verify your bank account with the withdrawal pin first', 422, 'instantpay_not_ready');
    }

    public static function alreadyWithdrawn(): self
    {
        return new self('This money has been withdrawn', 409, 'instantpay_withdrawn');
    }

    public static function transferFailed(): self
    {
        return new self('Transfer Error', 502, 'instantpay_transfer_failed');
    }
}
