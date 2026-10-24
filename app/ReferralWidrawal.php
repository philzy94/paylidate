<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ReferralWidrawal extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'bank_id', 'narration', 'debit_currency', 'f_withdrawal_id', 'status'
    ];


    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
