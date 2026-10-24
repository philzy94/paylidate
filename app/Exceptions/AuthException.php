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

    public static function tokenInvalid(): self
    {
        return new self('This token is invalid or has expired.', 400, 'token_invalid');
    }

    public static function forbidden(): self
    {
        return new self('You are not allowed to perform this action', 403, 'auth_forbidden');
    }

    public static function cannotRateSelf(): self
    {
        return new self('You can not rate yourself', 422, 'rate_self');
    }

    public static function userNotFound(): self
    {
        return new self('User not found', 404, 'user_not_found');
    }
}
