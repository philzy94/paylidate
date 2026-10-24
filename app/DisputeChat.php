<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class DisputeChat extends Model
{
    protected $fillable = [
        'user_id','transaction_id','message', 'dispute_id', 'sender'
     ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
