<?php

namespace Tests\Unit\Mail;

use App\Mail\CreateProductMail;
use App\Mail\CreateTransactionMail;
use App\Mail\DisputeMail;
use App\Mail\ForgotPasswordMail;
use App\Mail\FulfilmentMail;
use App\Mail\RegistrationMail;
use App\Mail\ReportTransaction;
use App\Mail\RequestWithdrawal;
use App\Mail\SellerAcceptTransactionMail;
use App\Mail\SellerFulfilmentMail;
use App\Mail\WalletCreated;
use App\Product;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailablesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: callable(): Mailable, 1: string, 2: ?string}> */
    public static function mailables(): array
    {
        $user = fn () => User::factory()->make(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $transaction = ['transaction_ref' => 'PD_1', 'amount' => 1000];

        return [
            'create product' => [fn () => new CreateProductMail($user(), Product::factory()->make()), 'mails.create_product', null],
            'create transaction' => [fn () => new CreateTransactionMail($user(), $transaction), 'mails.create_transaction', 'New transaction'],
            'dispute' => [fn () => new DisputeMail('Not delivered', 'Item missing', $transaction, 'Ada'), 'mails.dispute', 'Dispute (Not delivered)'],
            'forgot password' => [fn () => new ForgotPasswordMail($user(), 'https://app.test/reset/abc'), 'mails.forgot_password', 'Reset Pasword'],
            'fulfilment' => [fn () => new FulfilmentMail('Ada', 'hash', $transaction), 'mails.fulfilment', null],
            'registration' => [fn () => new RegistrationMail($user(), 'https://app.test/verify/abc'), 'mails.registration', 'Verify Email'],
            'report transaction' => [fn () => new ReportTransaction('Ada', 'PD_1', 'seller', 'It broke'), 'mails.report_transaction', 'Report Transaction'],
            'request withdrawal' => [fn () => new RequestWithdrawal('Ada', 5), 'mails.request_withdrawal', 'Withdrawal Request'],
            'seller accept' => [fn () => new SellerAcceptTransactionMail('seller@example.test', 'buyer@example.test', $transaction), 'mails.seller_accept_transaction', 'Review and accept transaction terms'],
            'seller fulfilment' => [fn () => new SellerFulfilmentMail('Ada', 'hash', $transaction), 'mails.seller_fulfilment', null],
            'wallet created' => [fn () => new WalletCreated('Ada'), 'mails.wallet_created', null],
        ];
    }

    /** @param callable(): Mailable $make */
    #[DataProvider('mailables')]
    public function testEachMailableBuildsWithItsViewAndSubject(callable $make, string $view, ?string $subject)
    {
        $mail = $make();

        $built = $mail->build();

        $this->assertSame($view, $built->view);
        if ($subject !== null) {
            $this->assertSame($subject, $built->subject);
        }
        $this->assertFileExists(resource_path('views/' . str_replace('.', '/', $view) . '.blade.php'));
    }
}
