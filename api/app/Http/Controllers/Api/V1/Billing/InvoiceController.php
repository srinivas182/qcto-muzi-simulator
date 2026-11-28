<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\BillingPresenter;
use App\Billing\InvoicePdf;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Provider: its invoices, with PDF downloads of invoices and credit notes. */
final class InvoiceController extends Controller
{
    public function __construct(private readonly InvoicePdf $pdf) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Invoice::query()->latest('issue_date')->latest('number')->limit(200)->get()->map(fn (Invoice $i) => BillingPresenter::invoice($i))->all()]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => BillingPresenter::invoice($invoice, true)]);
    }

    public function pdf(Invoice $invoice): Response
    {
        return self::download($this->pdf, $invoice, false);
    }

    public function creditNotePdf(Invoice $invoice): Response
    {
        return self::download($this->pdf, $invoice, true);
    }

    public static function download(InvoicePdf $pdf, Invoice $invoice, bool $credit): Response
    {
        $note = $credit ? $invoice->creditNote()->firstOrFail() : null;
        $name = ($note->number ?? $invoice->number).'.pdf';

        return response($pdf->render($invoice, $note), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
