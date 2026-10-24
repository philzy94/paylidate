<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CardController extends Controller
{
    protected CardService $cards;

    public function __construct(CardService $cards)
    {
        $this->cards = $cards;
    }

    public function index(): JsonResponse
    {
        return $this->success($this->cards->cardsFor($this->authUser()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => 'required|in:NGN,USD',
            'amount' => 'required|numeric|min:1',
            'label' => 'nullable|string',
        ]);

        return $this->success($this->cards->create($this->authUser(), $data['currency'], (float) $data['amount'], $data['label'] ?? null));
    }

    public function fund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'virtual_card_id' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        return $this->success($this->cards->fund($this->authUser(), $data['virtual_card_id'], (float) $data['amount']));
    }
}
