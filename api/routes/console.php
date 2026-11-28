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

// Learning (Sprint 4.6b): gentle reminders to quiet learners, in the morning (not on the hour: shared runners are busy then).
Schedule::command('learning:daily')->dailyAt('09:12')->timezone('Africa/Johannesburg')->withoutOverlapping();
