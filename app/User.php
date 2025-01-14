<?php

namespace App;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Http;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password','phone','avatar','active','is_admin','is_staff'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->hasOne('App\Wallet');
    }

    public function account()
    {
        return $this->hasOne('App\UserAccount');
    }

    function getTransaction($transaction_id){
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.env('FLW_SECRET_KEY')
            ])->get(env('FLW_BASE_URL').'/v3/transactions/'. $transaction_id .'\/verify/');

        return $response;
    }

}
