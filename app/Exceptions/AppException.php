<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base class for domain errors that are safe to show to API clients.
 *
 * Throw a subclass from a service or controller and Laravel renders it as a
 * JSON error with the matching HTTP status; no try/catch is needed.
 */
class AppException extends RuntimeException
{
    protected int $httpStatus = 400;

    protected string $errorCode = 'error';

    protected string $errorStatus = 'error';

    public function __construct(string $message = '', ?int $httpStatus = null, ?string $errorCode = null)
    {
        parent::__construct($message ?: 'An error occured. Please try again');

        $this->httpStatus = $httpStatus ?? $this->httpStatus;
        $this->errorCode = $errorCode ?? $this->errorCode;
    }

    public function withStatus(string $status): static
    {
        $this->errorStatus = $status;

        return $this;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => $this->errorStatus,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->httpStatus);
    }

    /**
     * Expected client errors are not worth reporting to Sentry. Returning true
     * tells Laravel the exception is already handled; false uses default reporting.
     */
    public function report(): bool
    {
        return $this->httpStatus < 500;
    }
}
