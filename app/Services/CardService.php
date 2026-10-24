<?php

namespace App\Services;

use App\Exceptions\CardException;
use App\User;
use App\VirtualCard;

/**
 * Virtual cards are funded from the user's own wallet, never from the platform balance.
 */
class CardService
{
    /** Creation fee kept from the amount: NGN 100 or USD 1. */
    public const FEES = ['NGN' => 100];

    public const DEFAULT_FEE = 1;

    protected FlutterwaveService $flutterwave;

    protected WalletService $wallets;

    public function __construct(FlutterwaveService $flutterwave, WalletService $wallets)
    {
        $this->flutterwave = $flutterwave;
        $this->wallets = $wallets;
    }

    public function cardsFor(User $user)
    {
        return VirtualCard::where('user_id', $user->id)->get()->each(function ($card) {
            $card->data = $this->flutterwave->getvirtualCard($card->card_id)['data'] ?? null;
        });
    }

    /**
     * Create a card funded with $amount (less the fee), paid from the user's wallet.
     */
    public function create(User $user, string $currency, float $amount, ?string $label): array
    {
        $funding = $amount - (self::FEES[$currency] ?? self::DEFAULT_FEE);

        if ($funding <= 0) {
            throw CardException::amountTooLow();
        }

        $this->wallets->spend($user, $amount, 'Virtual card creation');

        $response = $this->flutterwave->virtualCard($currency, $funding, $user->name);

        if (($response['status'] ?? null) !== 'success') {
            $this->wallets->creditWalletById($user->id, $amount);

            throw CardException::providerFailed($response['message'] ?? '');
        }

        VirtualCard::create([
            'user_id' => $user->id,
            'card_id' => $response['data']['id'],
            'account_id' => $response['data']['account_id'],
            'currency' => $response['data']['currency'],
            'label' => $label,
        ]);

        return $response['data'];
    }

    /**
     * Top up one of the user's own cards from their wallet.
     */
    public function fund(User $user, string $cardId, float $amount): array
    {
        $card = VirtualCard::where('user_id', $user->id)->where('card_id', $cardId)->first();

        if (! $card) {
            throw CardException::notFound();
        }

        $this->wallets->spend($user, $amount, 'Virtual card funding');

        $response = $this->flutterwave->fundVirtualCard($card->card_id, $amount);

        if (($response['status'] ?? null) !== 'success') {
            $this->wallets->creditWalletById($user->id, $amount);

            throw CardException::providerFailed($response['message'] ?? '');
        }

        return $response['data'];
    }
}
