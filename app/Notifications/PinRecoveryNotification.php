<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PinRecoveryNotification extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Pemulihan PIN ClubPro')
            ->line('Gunakan kode sekali pakai ini untuk menetapkan PIN akun Anda.')
            ->line($this->code)->line('Berlaku 10 menit. Abaikan bila Anda tidak meminta pemulihan.');
    }
}
