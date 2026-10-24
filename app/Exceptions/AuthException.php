<?php

namespace App\Exceptions;

class AuthException extends AppException
{
    protected string $errorStatus = 'exist';

    public static function userExists(): self
    {
        return new self('User already exist. please login', 409, 'user_exists');
    }

    public static function invalidReferral(): self
    {
        $exception = new self('Invalid referral link', 422, 'invalid_referral');
        $exception->errorStatus = 'error';

        return $exception;
    }
}
