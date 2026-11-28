<?php

declare(strict_types=1);

return [
    // Defaults until Super Admin saves HQ → Billing settings (Sprint 3.3).
    'currency' => 'ZAR',
    'vat_rate' => (float) env('BILLING_VAT_RATE', 15),
];
