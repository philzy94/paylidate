<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class DisputeChat extends Model
{
    protected $fillable = [
        'user_id','transaction_id','message', 'dispute_id', 'sender'
     ];

    public function user()
    {
        return $this->belongsTo('App\User');
    }
    public function dispute()
    {
        return $this->belongsTo('App\Dispute');
    }
    public function transaction()
    {
        return $this->belongsTo('App\Transaction');
    }
}
