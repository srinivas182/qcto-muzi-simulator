/**
 * Package pricing, identical to the API's PriceCalculator: integer cents, the highest price band at or below
 * the number of seats, VAT rounded once, half up. Used for live price calculators; the API re-prices every order.
 */
export interface PriceBand {
  from_seats: number;
  unit_price_cents: number;
}

export interface PackageItem {
  id: string;
  code: string;
  name: string;
  description: string | null;
  programme: { code: string; title: string; prefix: string };
  includes_simulators: boolean;
  includes_courses: boolean;
  billing: 'term' | 'monthly';
  term_months: number | null;
  min_seats: number;
  status: 'draft' | 'published' | 'retired';
  published_at: string | null;
  prices: PriceBand[];
}

export interface PackageQuote {
  seats: number;
  unit_price_cents: number;
  band_from: number;
  subtotal_cents: number;
  vat_rate: number;
  vat_cents: number;
  total_cents: number;
  per_seat_per_month_cents: number;
}

export function vatCents(cents: number, rate: number): number {
  // Half-up rounding on the exact product, matching PHP round(..., PHP_ROUND_HALF_UP) for positive amounts.
  return Math.round((cents * rate) / 100 + Number.EPSILON);
}

export function quotePackage(pkg: Pick<PackageItem, 'prices' | 'min_seats' | 'billing' | 'term_months'>, seats: number, vatRate: number): PackageQuote | null {
  if (!Number.isInteger(seats) || seats < pkg.min_seats) return null;
  let band: PriceBand | undefined;
  for (const p of pkg.prices) if (p.from_seats <= seats && (!band || p.from_seats > band.from_seats)) band = p;
  if (!band) return null;
  const subtotal = band.unit_price_cents * seats;
  const vat = vatCents(subtotal, vatRate);
  return {
    seats,
    unit_price_cents: band.unit_price_cents,
    band_from: band.from_seats,
    subtotal_cents: subtotal,
    vat_rate: vatRate,
    vat_cents: vat,
    total_cents: subtotal + vat,
    per_seat_per_month_cents: pkg.billing === 'term' && pkg.term_months ? Math.round(band.unit_price_cents / pkg.term_months) : band.unit_price_cents,
  };
}

/** South African rand format from the locale, e.g. R 1 234,50 (en-ZA). */
export function formatZar(cents: number, locale = 'en-ZA'): string {
  return new Intl.NumberFormat(locale, { style: 'currency', currency: 'ZAR', minimumFractionDigits: 2 }).format(cents / 100);
}
