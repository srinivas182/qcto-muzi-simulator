<?php

declare(strict_types=1);

namespace App\Identity\Messaging;

interface MessageChannel
{
    public function name(): string;

    /** Whether this channel has the credentials it needs. */
    public function isConfigured(): bool;

    /**
     * Send a one-time code. Throws on delivery failure so the next channel can be tried.
     */
    public function sendCode(string $phoneE164, string $code, string $appName): void;
}
