<?php

namespace App\Exceptions;

class PayoutException extends AppException
{
    public static function notFound(string $what = 'Payout request'): self
    {
        return new self($what . ' not found', 404, 'payout_not_found');
    }

    public static function notEntitled(): self
    {
        return new self('You are not allowed to request this payout', 403, 'payout_forbidden');
    }

    public static function alreadyRequested(string $what): self
    {
        return new self("You have made a {$what} request on this transaction already", 422, 'payout_already_requested');
    }

    public static function notEligible(string $reason): self
    {
        return new self($reason, 422, 'payout_not_eligible');
    }

    public static function alreadyPaid(): self
    {
        return new self('This payout has already been transferred', 409, 'payout_already_paid');
    }

    public static function providerFailed(string $message): self
    {
        return new self($message ?: 'Transfer failed', 502, 'payout_provider_failed');
    }

    public static function belowMinimum(int $minimum): self
    {
        return new self('Amount must not be less than NGN ' . number_format($minimum), 400, 'payout_below_minimum');
    }

    public static function noBank(): self
    {
        return new self('Please add your bank details first', 422, 'payout_no_bank');
    }
}
