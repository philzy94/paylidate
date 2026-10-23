<?php

namespace Tests\Unit;

use App\Services\PaymentService;
use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    public function testSplitAmountDeductsReferralFromTotal()
    {
        $split = PaymentService::splitAmount(1000, 50, 3);

        $this->assertSame(2850, $split['amount']);
        $this->assertSame(150, $split['referral_amount']);
    }

    public function testSplitAmountWithoutReferralKeepsFullTotal()
    {
        $split = PaymentService::splitAmount(250, 0, 4);

        $this->assertSame(1000, $split['amount']);
        $this->assertSame(0, $split['referral_amount']);
    }
}
