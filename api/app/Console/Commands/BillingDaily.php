<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\BillingRun;
use Illuminate\Console\Command;

final class BillingDaily extends Command
{
    protected $signature = 'billing:daily';

    protected $description = 'Expire finished licences, issue monthly invoices, and send payment and renewal reminders';

    public function handle(BillingRun $run): int
    {
        $totals = $run->run();
        $this->info(sprintf('Expired %d, invoiced %d, payment reminders %d, renewal reminders %d, no-show seats returned %d.', $totals['expired'], $totals['invoiced'], $totals['reminders'], $totals['renewals'], $totals['no_shows']));

        return self::SUCCESS;
    }
}
