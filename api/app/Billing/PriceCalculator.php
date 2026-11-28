<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\BillingSetting;
use App\Models\Package;
use App\Models\PackagePrice;
use Illuminate\Validation\ValidationException;

/**
 * Prices a number of seats on a package. All money is in integer cents; VAT is rounded once (half up).
 * The web shop uses the same rules (packages/core `quotePackage`) so the shown price always matches the invoice.
 */
final class PriceCalculator
{
    /**
     * @param  iterable<PackagePrice>|null  $bands  defaults to the package's own price bands
     * @return array{seats: int, unit_price_cents: int, band_from: int, subtotal_cents: int, vat_rate: float, vat_cents: int, total_cents: int, billing: string, term_months: int|null, per_seat_per_month_cents: int}
     */
    public function quote(Package $package, int $seats, ?float $vatRate = null, ?iterable $bands = null): array
    {
        if ($seats < $package->min_seats) {
            throw ValidationException::withMessages(['seats' => "This package needs at least {$package->min_seats} seats."]);
        }
        $band = null;
        foreach ($bands ?? $package->prices as $price) {
            if ($price->from_seats <= $seats && ($band === null || $price->from_seats > $band->from_seats)) {
                $band = $price;
            }
        }
        if ($band === null) {
            throw ValidationException::withMessages(['seats' => 'There is no price for this number of seats.']);
        }

        $vatRate ??= BillingSetting::current()->vatRate();
        $subtotal = $band->unit_price_cents * $seats;
        $vat = self::vat($subtotal, $vatRate);

        return [
            'seats' => $seats,
            'unit_price_cents' => $band->unit_price_cents,
            'band_from' => $band->from_seats,
            'subtotal_cents' => $subtotal,
            'vat_rate' => $vatRate,
            'vat_cents' => $vat,
            'total_cents' => $subtotal + $vat,
            'billing' => $package->billing,
            'term_months' => $package->term_months,
            'per_seat_per_month_cents' => $package->billing === Package::TERM && $package->term_months
                ? (int) round($band->unit_price_cents / $package->term_months)
                : $band->unit_price_cents,
        ];
    }

    /** VAT on an amount in cents, rounded half up to the cent. */
    public static function vat(int $cents, float $rate): int
    {
        return (int) round($cents * $rate / 100, 0, PHP_ROUND_HALF_UP);
    }
}
