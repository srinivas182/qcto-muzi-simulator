<?php

declare(strict_types=1);

namespace App\Identity\Messaging;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** SMS through Clickatell (South Africa). Other SA providers can be added behind the same interface. */
final class SmsChannel implements MessageChannel
{
    public function name(): string
    {
        return 'sms';
    }

    public function isConfigured(): bool
    {
        return filled(config('identity.messaging.sms.api_key'));
    }

    public function sendCode(string $phoneE164, string $code, string $appName): void
    {
        $response = Http::withHeaders(['Authorization' => (string) config('identity.messaging.sms.api_key')])
            ->timeout(8)
            ->post('https://platform.clickatell.com/messages', [
                'messages' => [[
                    'channel' => 'sms',
                    'to' => ltrim($phoneE164, '+'),
                    'content' => "{$code} is your {$appName} code. It expires in 10 minutes. Never share it.",
                ]],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('SMS delivery failed: HTTP '.$response->status());
        }
    }

    /** Send a plain text message (for example an invitation link). */
    public function sendText(string $phoneE164, string $text): void
    {
        $response = Http::withHeaders(['Authorization' => (string) config('identity.messaging.sms.api_key')])
            ->timeout(8)
            ->post('https://platform.clickatell.com/messages', ['messages' => [['channel' => 'sms', 'to' => ltrim($phoneE164, '+'), 'content' => $text]]]);

        if ($response->failed()) {
            throw new RuntimeException('SMS delivery failed: HTTP '.$response->status());
        }
    }
}
