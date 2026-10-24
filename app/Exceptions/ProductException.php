<?php

namespace App\Exceptions;

class ProductException extends AppException
{
    public static function notFound(): self
    {
        return new self('Product not found', 404, 'product_not_found');
    }

    public static function notOwner(): self
    {
        return new self('You are not allow to edit the product', 403, 'product_forbidden');
    }

    public static function buyListing(): self
    {
        return new self('You not allow to edit a product you are buying', 403, 'product_buy_listing');
    }

    public static function referralTooHigh(): self
    {
        return new self('Referral bonus must be less than product price', 422, 'product_referral_too_high');
    }

    public static function hasTransactions(): self
    {
        return new self('You can not delete this Product', 409, 'product_has_transactions');
    }
}
