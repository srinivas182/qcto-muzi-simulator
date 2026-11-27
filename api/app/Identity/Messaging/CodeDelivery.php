<?php

declare(strict_types=1);

namespace App\Identity\Messaging;

interface CodeDelivery
{
    /** Deliver a one-time code; returns the channel used (whatsapp, sms or log). */
    public function send(string $phoneE164, string $code): string;
}
