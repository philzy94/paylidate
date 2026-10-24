<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Instandpay extends Model
{
    protected $hidden = ['withdrawal_pin'];

    protected $fillable = [
        'user_id', 'receiver_id', 'receiver_name', 'bank_name', 'receiver_number', 'sender_email', 'sender_name', 'account_number', 'link_token', 'payment_ref', 'tracking_id', 'account_name', 'bank_code', 'withdrawal_pin', 'amount', 'status', 'description'
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
