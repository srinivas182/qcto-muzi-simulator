@php($m = fn (int $c) => \App\Billing\InvoicePdf::money($c))
@php($s = $invoice->seller)
@php($b = $invoice->buyer)
@php($addr = fn (array $a) => collect([$a['line1'] ?? null, $a['line2'] ?? null, $a['city'] ?? null, $a['province'] ?? null, $a['postal_code'] ?? null])->filter()->implode(', '))
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $credit ? 'Credit note '.$credit->number : 'Tax invoice '.$invoice->number }}</title>
<style>
  @page { margin: 28mm 18mm 22mm 18mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1b1d29; }
  h1 { font-size: 18pt; margin: 0 0 4pt; color: #3b3fd8; }
  .muted { color: #626679; }
  table { width: 100%; border-collapse: collapse; }
  .parties td { vertical-align: top; width: 50%; padding: 0 8pt 10pt 0; }
  .lines th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .5pt; color: #626679; border-bottom: 1pt solid #d9dbe6; padding: 6pt 4pt; }
  .lines td { border-bottom: 1pt solid #eceef4; padding: 7pt 4pt; }
  .num { text-align: right; white-space: nowrap; }
  .totals td { padding: 3pt 4pt; }
  .total td { font-weight: bold; font-size: 11.5pt; border-top: 1.5pt solid #1b1d29; padding-top: 6pt; }
  .box { background: #f4f5fa; padding: 8pt 10pt; border-radius: 4pt; margin-top: 14pt; }
  .stamp { color: #0b7a5c; font-weight: bold; }
  footer { position: fixed; bottom: -12mm; left: 0; right: 0; text-align: center; font-size: 8pt; color: #626679; }
</style>
</head>
<body>
<footer>Developed and Maintained by Mayura Consultancy Services · www.mayuraconsultancy.com</footer>
<table>
  <tr>
    <td>
      <h1>{{ $credit ? 'Credit note' : 'Tax invoice' }}</h1>
      <div><strong>{{ $credit ? $credit->number : $invoice->number }}</strong></div>
      @if ($credit)<div class="muted">Credits tax invoice {{ $invoice->number }} · {{ $credit->reason }}</div>@endif
    </td>
    <td class="num">
      <div>Date: {{ ($credit ? $credit->issue_date : $invoice->issue_date)->format('j F Y') }}</div>
      @unless ($credit)<div>Due: {{ $invoice->due_date->format('j F Y') }}</div>@endunless
      @if ($invoice->period_start)<div class="muted">Period: {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end?->format('j M Y') }}</div>@endif
      @if (! $credit && $invoice->status === 'paid')<div class="stamp">PAID</div>@endif
    </td>
  </tr>
</table>
<br>
<table class="parties">
  <tr>
    <td>
      <div class="muted">From</div>
      <strong>{{ $s['name'] }}</strong><br>
      @if (! empty($s['registration_number']))Reg. no. {{ $s['registration_number'] }}<br>@endif
      @if (! empty($s['vat_number']))VAT no. {{ $s['vat_number'] }}<br>@endif
      {{ $addr($s['address'] ?? []) }}<br>
      {{ $s['email'] ?? '' }} {{ $s['phone'] ?? '' }}
    </td>
    <td>
      <div class="muted">To</div>
      <strong>{{ $b['name'] }}</strong>@if (($b['trading_name'] ?? null) && $b['trading_name'] !== $b['name']) (t/a {{ $b['trading_name'] }})@endif<br>
      @if (! empty($b['registration_number']))Reg. no. {{ $b['registration_number'] }}<br>@endif
      @if (! empty($b['vat_number']))VAT no. {{ $b['vat_number'] }}<br>@endif
      @if (! empty($b['accreditation_number']))QCTO SDP {{ $b['accreditation_number'] }}<br>@endif
      {{ $addr($b['address'] ?? []) }}
    </td>
  </tr>
</table>
<table class="lines">
  <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
  <tbody>
  @foreach ($invoice->lines as $line)
    <tr><td>{{ $line['description'] }}</td><td class="num">{{ $line['quantity'] }}</td><td class="num">{{ $m($line['unit_cents']) }}</td><td class="num">{{ $m($line['amount_cents']) }}</td></tr>
  @endforeach
  </tbody>
</table>
<table class="totals" style="width: 50%; margin-left: 50%; margin-top: 8pt;">
  <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ $credit ? '−' : '' }}{{ $m($invoice->subtotal_cents) }}</td></tr>
  <tr><td>VAT at {{ rtrim(rtrim((string) $invoice->vat_rate, '0'), '.') }}%</td><td class="num">{{ $credit ? '−' : '' }}{{ $m($invoice->vat_cents) }}</td></tr>
  <tr class="total"><td>Total</td><td class="num">{{ $credit ? '−' : '' }}{{ $m($invoice->total_cents) }}</td></tr>
  @if (! $credit && $invoice->paid_cents > 0 && $invoice->status !== 'paid')<tr><td>Paid</td><td class="num">{{ $m($invoice->paid_cents) }}</td></tr><tr><td><strong>Still owed</strong></td><td class="num"><strong>{{ $m($invoice->balanceCents()) }}</strong></td></tr>@endif
</table>
@if (! $credit && $invoice->status !== 'paid' && ! empty($s['bank']['account_number']))
<div class="box">
  <strong>Pay by EFT</strong> · use <strong>{{ $invoice->number }}</strong> as the reference<br>
  {{ $s['bank']['account_name'] ?? $s['name'] }} · {{ $s['bank']['bank'] ?? '' }} · Account {{ $s['bank']['account_number'] }} · Branch code {{ $s['bank']['branch_code'] ?? '' }}
</div>
@endif
</body>
</html>
