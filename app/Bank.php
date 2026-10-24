<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $fillable = [
        'user_id', 'bank_name', 'account_number', 'bank_code', 'account_name', 'branch_name'
    ];
    protected $hidden = ['created_at', 'updated_at',  'user_id', 'branch_name'];

    /** @return HasMany<Withdrawal, $this> */
    public function withdrawal(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
    /** @return HasMany<Refund, $this> */
    public function refund(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
    /** @return HasMany<ReferralWidrawal, $this> */
    public function referral_bonus_withdrawal(): HasMany
    {
        return $this->hasMany(ReferralWidrawal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
