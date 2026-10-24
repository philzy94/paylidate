<?php

namespace App\Services;

use Twilio\Rest\Client;

/**
 * Thin wrapper around Twilio so callers (and tests) do not touch the SDK directly.
 */
class SmsService
{
    /**
     * Send $body to a Nigerian local number such as 08012345678.
     */
    public function sendToNigerianNumber(string $localNumber, string $body): void
    {
        $config = config('services.twilio');

        (new Client($config['sid'], $config['token']))->messages->create(
            '+234' . substr($localNumber, 1),
            ['from' => $config['number'], 'body' => $body]
        );
    }
}
