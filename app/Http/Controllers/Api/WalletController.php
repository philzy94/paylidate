<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    protected WalletService $wallets;

    public function __construct(WalletService $wallets)
    {
        $this->wallets = $wallets;
    }

    public function index(): JsonResponse
    {
        return $this->success($this->wallets->walletFor($this->authUser()));
    }

    // create a wallet with virtual account
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['bvn' => 'required|digits:11']);

        $wallet = $this->wallets->open($this->authUser(), $data['bvn']);

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $wallet,
        ], 201);
    }

    // credit the wallet for a verified Flutterwave payment
    public function creditWalletByFL(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tx_ref' => 'required|string',
            'narration' => 'required|string',
        ]);

        return $this->success($this->wallets->topUp($this->authUser(), $data['tx_ref'], $data['narration']));
    }

    // Flutterwave webhook for bank transfers into a virtual account
    public function creditWalletByTransfer(Request $request): JsonResponse
    {
        $this->wallets->creditFromTransferWebhook($request->header('verif-hash'), $request->all());

        return response()->json(['status' => 'success', 'message' => 'success']);
    }

    // debit Wallet
    public function debitWallet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'narration' => 'required|string',
        ]);

        return $this->success($this->wallets->spend($this->authUser(), (float) $data['amount'], $data['narration']));
    }
}
