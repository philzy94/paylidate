<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\AppException;
use App\Exceptions\AuthException;
use App\Exceptions\PaymentVerificationException;
use App\Exceptions\TransactionException;
use Tests\TestCase;

class TypedExceptionsTest extends TestCase
{
    public function testRendersUserSafeJsonWithStatusAndCode()
    {
        $response = TransactionException::notAllowed()->render();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'code' => 'transaction_not_allowed', 'message' => 'Not Allow'],
            $response->getData(true)
        );
    }

    public function testDuplicatePaymentIsAConflict()
    {
        $exception = PaymentVerificationException::alreadyVerified();

        $this->assertSame(409, $exception->httpStatus());
        $this->assertSame('payment_already_verified', $exception->errorCode());
    }

    public function testExistingUserKeepsTheLegacyExistStatus()
    {
        $data = AuthException::userExists()->render()->getData(true);

        $this->assertSame('exist', $data['status']);
        $this->assertSame(409, AuthException::userExists()->httpStatus());
    }

    public function testClientErrorsAreNotReportedButServerErrorsAre()
    {
        // true = handled here (skipped), false = fall through to default reporting
        $this->assertTrue((new AppException('bad request', 400))->report());
        $this->assertFalse((new AppException('boom', 500))->report());
    }

    public function testDefaultMessageIsGeneric()
    {
        $this->assertSame('An error occured. Please try again', (new AppException())->getMessage());
    }
}
