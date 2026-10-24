<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

    protected $fillable = [
       'user_id','product_id','payment_id','transaction_id','payment_ref','status', 'refund','description',
       'payment_method','currency','verified','balance_before','balance_after'
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return HasOne<Withdrawal, $this> */
    public function withdrawal(): HasOne
    {
        return $this->hasOne(Withdrawal::class);
    }
    /** @return HasOne<Refund, $this> */
    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
    /** @return HasOne<Fulfillment, $this> */
    public function fulfilment(): HasOne
    {
        return $this->hasOne(Fulfillment::class);
    }
}
