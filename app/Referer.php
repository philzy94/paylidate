<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;

class Referer extends Model
{
    protected $fillable = [
        'user_id','amount'
    ];

    /** @return HasOne<Transaction, $this> */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
