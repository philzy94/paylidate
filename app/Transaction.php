<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'user_id', 'product_id', 'quantity', 'transaction_ref', 'status',
        'amount', 'accept_transaction', 'dispute', 'description', 'referer_id'
    ];

    /** @return BelongsTo<User, $this> */
    public function secondary_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Referer, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referer::class);
    }

    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** @return HasMany<DisputeChat, $this> */
    public function dispute_chat(): HasMany
    {
        return $this->hasMany(DisputeChat::class);
    }

    /** @return HasMany<Dispute, $this> */
    public function dispute(): HasMany
    {
        return $this->hasMany(Dispute::class);
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
