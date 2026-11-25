<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Mencegah kode pemulihan/undangan jatuh ke log melalui fallback transport. */
class SensitiveMailGuard
{
    public function assertAvailable(): void
    {
        if (app()->environment('production') && ! $this->isDeliverable((string) config('mail.default'), [])) {
            throw new HttpException(503, 'Pengiriman email belum tersedia. Coba lagi nanti.');
        }
    }

    /** @param list<string> $visited */
    private function isDeliverable(string $mailer, array $visited): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $transport = config("mail.mailers.{$mailer}.transport");
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = config("mail.mailers.{$mailer}.mailers", []);
            if (! is_array($children) || $children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (! is_string($child) || ! $this->isDeliverable($child, [...$visited, $mailer])) {
                    return false;
                }
            }

            return true;
        }

        return in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true);
    }
}
