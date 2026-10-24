<?php

namespace Tests\Unit\Services;

use App\Exceptions\TransactionException;
use App\Mail\CreateTransactionMail;
use App\Mail\ReportTransaction;
use App\Product;
use App\Services\TransactionNotifier;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TransactionNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(): Transaction
    {
        $product = Product::factory()->create(['price' => 500, 'quantity' => 10, 'transaction_type' => 'sell']);

        return Transaction::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'transaction_ref' => 'PD_NOTIFY_1',
        ]);
    }

    public function testDetailsSummariseTheTransactionForEmails()
    {
        $details = app(TransactionNotifier::class)->details($this->transaction());

        $this->assertSame('PD_NOTIFY_1', $details['transaction_ref']);
        $this->assertSame(2, $details['total_quantity']);
        $this->assertEquals(1000, $details['total_price']);
        $this->assertSame('No description', $details['description']);
    }

    public function testDetailsFailForATransactionWithoutAProduct()
    {
        $transaction = new Transaction(['transaction_ref' => 'PD_GONE']);

        $this->expectException(TransactionException::class);

        app(TransactionNotifier::class)->details($transaction);
    }

    public function testCreatedEmailsTheBuyerAndTheSeller()
    {
        Mail::fake();
        $transaction = $this->transaction();
        $buyer = User::find($transaction->user_id);

        app(TransactionNotifier::class)->created($transaction, $buyer);

        Mail::assertSent(CreateTransactionMail::class, 2);
    }

    public function testReportAndResolveEmailTheSellerAndEveryAdmin()
    {
        Mail::fake();
        config(['app.admin_emails' => ['staff@paylidate.test']]);
        $transaction = $this->transaction();
        $notifier = app(TransactionNotifier::class);

        $notifier->reported($transaction, 'seller@paylidate.test', 'Never arrived');
        $notifier->resolved($transaction, 'seller@paylidate.test');

        Mail::assertSent(ReportTransaction::class, fn ($mail) => $mail->hasTo('seller@paylidate.test'));
        Mail::assertSent(ReportTransaction::class, fn ($mail) => $mail->hasTo('staff@paylidate.test'));
        Mail::assertSent(ReportTransaction::class, 4);
    }

    public function testStaffAreSkippedWhenNoAdminEmailsAreConfigured()
    {
        Mail::fake();
        config(['app.admin_emails' => []]);

        app(TransactionNotifier::class)->reported($this->transaction(), 'seller@paylidate.test', 'Late');

        Mail::assertSent(ReportTransaction::class, 1);
    }
}
