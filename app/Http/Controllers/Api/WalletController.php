<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    protected $wallets;

    public function __construct(WalletService $wallets)
    {
        $this->wallets = $wallets;
    }

    public function index()
    {
        return $this->success($this->wallets->walletFor(auth('api')->user()));
    }

    // create a wallet with virtual account
    public function create(Request $request)
    {
        $data = $request->validate(['bvn' => 'required|digits:11']);

        $wallet = $this->wallets->open(auth('api')->user(), $data['bvn']);

        return response()->json([
            'status' => 'success',
            'message' => 'success',
            'data' => $wallet,
        ], 201);
    }

    // credit the wallet for a verified Flutterwave payment
    public function creditWalletByFL(Request $request)
    {
        $data = $request->validate([
            'tx_ref' => 'required|string',
            'narration' => 'required|string',
        ]);

        return $this->success($this->wallets->topUp(auth('api')->user(), $data['tx_ref'], $data['narration']));
    }

    // Flutterwave webhook for bank transfers into a virtual account
    public function creditWalletByTransfer(Request $request)
    {
        $this->wallets->creditFromTransferWebhook($request->header('verif-hash'), $request->all());

        return response()->json(['status' => 'success', 'message' => 'success']);
    }

    // debit Wallet
    public function debitWallet(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'narration' => 'required|string',
        ]);

        return $this->success($this->wallets->spend(auth('api')->user(), (float) $data['amount'], $data['narration']));
    }
}
