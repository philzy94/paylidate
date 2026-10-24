<?php

namespace App\Http\Controllers\ThirdPartyApi;

use App\Http\Controllers\Api\AuthController as BaseAuthController;

/**
 * Third-party API authentication shares the first-party implementation;
 * responses additionally include the user's wallet.
 */
class AuthController extends BaseAuthController
{
    protected function registered(array $result)
    {
        $result['user']->load('wallet');

        return parent::registered($result);
    }
}
