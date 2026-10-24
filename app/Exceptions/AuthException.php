<?php

namespace App\Exceptions;

class AuthException extends AppException
{
    public static function userExists(): self
    {
        return (new self('User already exist. please login', 409, 'user_exists'))->withStatus('exist');
    }

    public static function invalidReferral(): self
    {
        return new self('Invalid referral link', 422, 'invalid_referral');
    }

    public static function invalidCredentials(): self
    {
        return (new self('Wrong email and password', 401, 'invalid_credentials'))->withStatus('failed');
    }

    public static function inactive(): self
    {
        return (new self('Your account is not activated', 401, 'account_inactive'))->withStatus('failed');
    }
}
