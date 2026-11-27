<?php

declare(strict_types=1);

namespace App\Identity\Messaging;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Delivers one-time codes over the configured channels in order (WhatsApp first, then SMS),
 * falling back automatically when one fails. Returns the channel that delivered it.
 */
final class CodeSender implements CodeDelivery
{
    /** @var array<string, MessageChannel> */
    private array $channels;

    public function __construct(WhatsAppChannel $whatsapp, SmsChannel $sms, LogChannel $log)
    {
        $this->channels = ['whatsapp' => $whatsapp, 'sms' => $sms, 'log' => $log];
    }

    public function send(string $phoneE164, string $code): string
    {
        $appName = (string) config('app.name');

        foreach ($this->order() as $channel) {
            try {
                $channel->sendCode($phoneE164, $code, $appName);

                return $channel->name();
            } catch (Throwable $e) {
                Log::warning("Code delivery via {$channel->name()} failed: {$e->getMessage()}");
            }
        }

        throw new RuntimeException('No messaging channel could deliver the code.');
    }

    /** @return list<MessageChannel> */
    private function order(): array
    {
        $override = config('identity.messaging.driver_override');
        if (is_string($override) && isset($this->channels[$override])) {
            return [$this->channels[$override]];
        }

        $ordered = [];
        foreach ((array) config('identity.otp.channels') as $name) {
            $channel = $this->channels[$name] ?? null;
            if ($channel !== null && $channel->isConfigured()) {
                $ordered[] = $channel;
            }
        }

        // Nothing configured (local development): log the code rather than fail.
        return $ordered !== [] ? $ordered : [$this->channels['log']];
    }
}
