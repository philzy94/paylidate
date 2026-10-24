<?php

namespace App;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Facades\Http;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasApiTokens;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'username', 'email_token', 'password', 'phone', 'avatar', 'active', 'is_admin', 'is_staff', 'needs_password_reset', 'referral_token', 'referred_by'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password', 'remember_token', 'email_token'
    ];

    /**
     * Whether a signup for this email may take over the existing account:
     * deactivated, flagged for reset, or created without a usable password.
     */
    public function canBeClaimed(): bool
    {
        return ! $this->active || $this->needs_password_reset || strlen($this->password) < 8;
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    public function getEmailAttribute(string $value): string
    {
        return strtolower($value);
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /** @return HasOne<Instandpay, $this> */
    public function instantpay(): HasOne
    {
        return $this->hasOne(Instandpay::class);
    }
    /** @return HasOne<Wallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** @return HasMany<WalletHistory, $this> */
    public function wallet_history(): HasMany
    {
        return $this->hasMany(WalletHistory::class);
    }

    /** @return HasOne<Dispute, $this> */
    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class);
    }
    /** @return HasMany<DisputeChat, $this> */
    public function dispute_chat(): HasMany
    {
        return $this->hasMany(DisputeChat::class);
    }

    /** @return HasOne<UserAccount, $this> */
    public function account(): HasOne
    {
        return $this->hasOne(UserAccount::class);
    }
    /** @return HasOne<Bank, $this> */
    public function bank(): HasOne
    {
        return $this->hasOne(Bank::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refund(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
    /** @return HasMany<Withdrawal, $this> */
    public function withdrawal(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /** @return HasMany<ReferralWidrawal, $this> */
    public function referral_bonus_withdrawal(): HasMany
    {
        return $this->hasMany(ReferralWidrawal::class);
    }

    /** @return HasMany<Referer, $this> */
    public function referral(): HasMany
    {
        return $this->hasMany(Referer::class);
    }
}
