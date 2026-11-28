<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Billing\InvoicePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A new tax invoice, with its PDF attached, to the provider's billing email. */
final class InvoiceIssued extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $number,
        public readonly int $totalCents,
        public readonly string $dueDate,
        public readonly string $pdf,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tax invoice {$this->number}")
            ->line("Your tax invoice {$this->number} for ".InvoicePdf::money($this->totalCents)." is attached. It is due on {$this->dueDate}.")
            ->line('You can pay by EFT using the invoice number as the reference, or online from Licences & packages → Invoices.')
            ->attachData($this->pdf, "{$this->number}.pdf", ['mime' => 'application/pdf'])
            ->salutation('Developed and Maintained by Mayura Consultancy Services');
    }
}
