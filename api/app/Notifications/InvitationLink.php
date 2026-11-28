<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email with a link to accept an invitation to a training provider's academy. */
final class InvitationLink extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $academy,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to {$this->academy} on SimuWork")
            ->line("You have been invited to join {$this->academy} on SimuWork.")
            ->action('Accept the invitation', $this->url)
            ->line('The link expires in '.(int) config('identity.invitations.ttl_days').' days. If you were not expecting it, you can ignore this email.')
            ->salutation('Developed and Maintained by Mayura Consultancy Services');
    }
}
