<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CardService;
use Illuminate\Http\Request;

class CardController extends Controller
{
    protected $cards;

    public function __construct(CardService $cards)
    {
        $this->cards = $cards;
    }

    public function index()
    {
        return $this->success($this->cards->cardsFor(auth('api')->user()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'currency' => 'required|in:NGN,USD',
            'amount' => 'required|numeric|min:1',
            'label' => 'nullable|string',
        ]);

        return $this->success($this->cards->create(auth('api')->user(), $data['currency'], (float) $data['amount'], $data['label'] ?? null));
    }

    public function fund(Request $request)
    {
        $data = $request->validate([
            'virtual_card_id' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        return $this->success($this->cards->fund(auth('api')->user(), $data['virtual_card_id'], (float) $data['amount']));
    }
}
