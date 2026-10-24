<?php

namespace Tests\Feature;

use App\Dispute;
use App\DisputeChat;
use App\Mail\DisputeMail;
use App\Product;
use App\Transaction;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    /** @return array{0: User, 1: User, 2: Transaction} buyer, seller, transaction */
    private function deal(): array
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $seller->id, 'transaction_type' => 'sell']);
        $transaction = Transaction::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        return [$buyer, $seller, $transaction];
    }

    public function testAPartyCanOpenADisputeAndEveryoneIsEmailed()
    {
        Mail::fake();
        config(['app.admin_emails' => ['staff@example.test']]);
        [$buyer, , $transaction] = $this->deal();
        $this->signIn($buyer);

        $this->postJson('/api/open-dispute', [
            'transaction_id' => $transaction->id,
            'subject' => 'Not delivered',
            'description' => 'Nothing arrived',
        ])->assertOk();

        $this->assertDatabaseHas('disputes', ['transaction_id' => $transaction->id, 'user_id' => $buyer->id, 'subject' => 'Not delivered']);
        $this->assertEquals(1, $transaction->fresh()->dispute);
        Mail::assertSent(DisputeMail::class, 3);
    }

    public function testOutsidersCannotOpenADisputeOnSomeoneElsesTransaction()
    {
        Mail::fake();
        [, , $transaction] = $this->deal();
        $this->signIn(User::factory()->create());

        $this->postJson('/api/open-dispute', ['transaction_id' => $transaction->id, 'subject' => 's', 'description' => 'd'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'dispute_forbidden');

        $this->assertSame(0, Dispute::count());
        Mail::assertNothingSent();
    }

    public function testOpenDisputeValidatesInput()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/open-dispute', [])->assertStatus(422)->assertJsonValidationErrors(['transaction_id', 'subject', 'description']);
        $this->postJson('/api/open-dispute', ['transaction_id' => 9999, 'subject' => 's', 'description' => 'd'])->assertStatus(404);
    }

    public function testDisputesAreVisibleToThePartiesAndStaffOnly()
    {
        [$buyer, $seller, $transaction] = $this->deal();
        Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => 's', 'dispute' => 'd']);

        $this->signIn($seller);
        $this->getJson("/api/transaction-disputes/{$transaction->id}")->assertOk()->assertJsonCount(1, 'data');

        $this->signIn(User::factory()->create(['is_admin' => true]));
        $this->getJson("/api/transaction-disputes/{$transaction->id}")->assertOk();

        $this->signIn(User::factory()->create());
        $this->getJson("/api/transaction-disputes/{$transaction->id}")->assertStatus(403);
    }

    public function testResolvingTheLastDisputeClearsTheTransactionFlag()
    {
        [$buyer, , $transaction] = $this->deal();
        $transaction->update(['dispute' => true, 'status' => 3]);
        $dispute = Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => 's', 'dispute' => 'd']);
        $this->signIn($buyer);

        $this->postJson('/api/resolve-dispute', ['id' => $dispute->id])->assertOk();

        $this->assertTrue((bool) $dispute->fresh()->dispute_solved);
        $this->assertEquals(0, $transaction->fresh()->dispute);
        $this->assertSame(0, $transaction->fresh()->status);
    }

    public function testTransactionStaysDisputedWhileAnotherDisputeIsOpen()
    {
        [$buyer, , $transaction] = $this->deal();
        $transaction->update(['dispute' => true]);
        $first = Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => '1', 'dispute' => 'd']);
        Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => '2', 'dispute' => 'd']);
        $this->signIn($buyer);

        $this->postJson('/api/resolve-dispute', ['id' => $first->id])->assertOk();

        $this->assertEquals(1, $transaction->fresh()->dispute);
    }

    public function testOnlyTheOpenerOrStaffCanResolve()
    {
        [$buyer, $seller, $transaction] = $this->deal();
        $dispute = Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => 's', 'dispute' => 'd']);

        $this->signIn($seller);
        $this->postJson('/api/resolve-dispute', ['id' => $dispute->id])->assertStatus(403);
        $this->assertFalse((bool) $dispute->fresh()->dispute_solved);

        $this->signIn(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/resolve-dispute', ['id' => $dispute->id])->assertOk();
        $this->assertTrue((bool) $dispute->fresh()->dispute_solved);
    }

    public function testChatMessagesBelongToTheDisputeTransaction()
    {
        [$buyer, $seller, $transaction] = $this->deal();
        $dispute = Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => 's', 'dispute' => 'd']);

        $this->signIn($seller);
        $this->postJson('/api/send-dispute-chat', ['dispute_id' => $dispute->id, 'message' => 'I shipped it', 'transaction_id' => 999])->assertOk();

        $chat = DisputeChat::first();
        $this->assertSame($transaction->id, $chat->transaction_id);
        $this->assertSame($seller->name, $chat->sender);

        $this->getJson("/api/dispute-chat/{$dispute->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function testOutsidersCannotReadOrPostToADisputeChat()
    {
        [$buyer, , $transaction] = $this->deal();
        $dispute = Dispute::create(['user_id' => $buyer->id, 'transaction_id' => $transaction->id, 'subject' => 's', 'dispute' => 'd']);
        $this->signIn(User::factory()->create());

        $this->postJson('/api/send-dispute-chat', ['dispute_id' => $dispute->id, 'message' => 'hi'])->assertStatus(403);
        $this->getJson("/api/dispute-chat/{$dispute->id}")->assertStatus(403);
        $this->assertSame(0, DisputeChat::count());
    }

    public function testChatOnAnUnknownDisputeIsNotFound()
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/send-dispute-chat', ['dispute_id' => 999, 'message' => 'hi'])->assertStatus(404)->assertJsonPath('code', 'dispute_not_found');
    }
}
