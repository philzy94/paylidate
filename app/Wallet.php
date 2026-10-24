<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'order_ref', 'tx_ref', 'account_number', 'balance', 'bonus', 'bank_name'
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletsettlementId, $this> */
    public function wallet_settlement(): HasMany
    {
        return $this->hasMany(WalletsettlementId::class);
    }

    /** @return HasMany<WalletHistory, $this> */
    public function wallet_history(): HasMany
    {
        return $this->hasMany(WalletHistory::class);
    }
}
