<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Renders an invoice (or credit note) as a PDF from the Blade view `billing.invoice`. */
final class InvoicePdf
{
    public function render(Invoice $invoice, ?CreditNote $creditNote = null): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('billing.invoice', ['invoice' => $invoice, 'credit' => $creditNote])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public static function money(int $cents): string
    {
        return 'R '.number_format($cents / 100, 2, '.', ' ');
    }
}
