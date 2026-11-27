<?php

declare(strict_types=1);

namespace App\Identity\Messaging;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Business (Meta Cloud API). One-time codes use an approved "authentication" template.
 */
final class WhatsAppChannel implements MessageChannel
{
    public function name(): string
    {
        return 'whatsapp';
    }

    public function isConfigured(): bool
    {
        return filled(config('identity.messaging.whatsapp.token')) && filled(config('identity.messaging.whatsapp.phone_number_id'));
    }

    public function sendCode(string $phoneE164, string $code, string $appName): void
    {
        $cfg = (array) config('identity.messaging.whatsapp');
        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $cfg['api_version'], $cfg['phone_number_id']);

        $response = Http::withToken((string) $cfg['token'])->timeout(8)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($phoneE164, '+'),
            'type' => 'template',
            'template' => [
                'name' => $cfg['otp_template'],
                'language' => ['code' => 'en'],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp delivery failed: HTTP '.$response->status());
        }
    }
}
