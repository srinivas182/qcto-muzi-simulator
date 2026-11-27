<?php

declare(strict_types=1);

namespace App\Identity\Messaging;

use App\Identity\PhoneNumber;
use Illuminate\Support\Facades\Log;

/** Development channel: writes the code to the application log instead of sending it. */
final class LogChannel implements MessageChannel
{
    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function sendCode(string $phoneE164, string $code, string $appName): void
    {
        Log::info("[{$appName}] one-time code {$code} for ".PhoneNumber::mask($phoneE164));
    }
}
