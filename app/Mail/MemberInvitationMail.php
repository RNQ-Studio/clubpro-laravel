<?php

namespace App\Mail;

use App\Models\MemberInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MemberInvitationMail extends Mailable
{
    public function __construct(public readonly MemberInvitation $invitation, public readonly string $claimToken) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Undangan hubungan anggota ClubPro');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.member-invitation');
    }
}
