<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers (invoices, credit notes). The counter row is locked for the rest of the caller's
 * transaction, so two invoices can never get the same number, and a rolled-back invoice gives its number back.
 */
final class DocumentNumbers
{
    public static function next(string $name, string $prefix): string
    {
        return DB::transaction(function () use ($name, $prefix): string {
            $row = DB::table('document_counters')->where('name', $name)->lockForUpdate()->first();
            $number = (int) ($row->next_number ?? 1);
            DB::table('document_counters')->where('name', $name)->update(['next_number' => $number + 1]);

            return $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        });
    }
}
