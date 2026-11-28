<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Billing (Sprint 3.3): expiries, monthly invoices and reminders. Safe to run more than once a day.
Schedule::command('billing:daily')->dailyAt('02:15')->withoutOverlapping();
