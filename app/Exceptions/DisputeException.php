<?php

namespace App\Exceptions;

class DisputeException extends AppException
{
    public static function notFound(): self
    {
        return new self('Dispute not found', 404, 'dispute_not_found');
    }

    public static function forbidden(): self
    {
        return new self('Unauthorize', 403, 'dispute_forbidden');
    }
}
