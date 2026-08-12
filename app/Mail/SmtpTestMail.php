<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by {@link \App\Console\Commands\TestSmtpEmail} to verify the SMTP
 * credentials configured in .env are working.
 */
class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $mailerName)
    {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SMTP Test Email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.smtp-test',
            with: [
                'mailer' => $this->mailerName,
                'sent_at' => now(),
            ],
        );
    }
}
