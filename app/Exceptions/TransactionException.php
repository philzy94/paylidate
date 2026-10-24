<?php

namespace App\Exceptions;

class TransactionException extends AppException
{
    public static function notFound(): self
    {
        return new self('Transaction not found', 404, 'transaction_not_found');
    }

    public static function notAllowed(): self
    {
        return new self('Not Allow', 403, 'transaction_not_allowed');
    }

    public static function quantityUnavailable(): self
    {
        return new self('You can not request more than the availble quantity', 422, 'quantity_unavailable');
    }

    public static function ownReferral(): self
    {
        return new self('You cannot use your referral link to create a product', 422, 'own_referral');
    }
}
