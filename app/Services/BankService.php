<?php

namespace App\Services;

use App\Bank;
use App\Exceptions\BankException;
use App\User;

/**
 * A user's payout bank account. Each user has one; it is only ever visible to them.
 */
class BankService
{
    protected FlutterwaveService $flutterwave;

    public function __construct(FlutterwaveService $flutterwave)
    {
        $this->flutterwave = $flutterwave;
    }

    /**
     * Look up the holder of a bank account.
     *
     * @return array<string, mixed>
     *
     * @throws BankException
     */
    public function resolve(string $accountNumber, string $bankCode): array
    {
        $response = $this->flutterwave->verifyBankAccountNumber($accountNumber, $bankCode);

        if (($response['status'] ?? null) !== 'success') {
            throw BankException::invalidAccount();
        }

        return $response['data'];
    }

    public function forUser(User $user): ?Bank
    {
        return Bank::where('user_id', $user->id)->first();
    }

    public function find(User $user): Bank
    {
        return $this->forUser($user) ?? throw BankException::notFound();
    }

    /**
     * Save (or replace) the user's bank account.
     *
     * @param array<string, mixed> $data
     */
    public function save(User $user, array $data): Bank
    {
        return Bank::updateOrCreate(['user_id' => $user->id], [
            'account_name' => $data['account_name'],
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'bank_code' => $data['bank_code'],
            'branch_name' => $data['branch_name'] ?? 'null',
        ]);
    }
}
