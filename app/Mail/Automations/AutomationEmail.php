<?php

namespace App\Mail\Automations;

use App\Jobs\SendEmailJob;
use App\Services\Board\BoardAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email an automation's `send_email` action delivers, dispatched through
 * {@see SendEmailJob}. The subject and body are the automation author's own
 * text with its tokens already filled in, see
 * {@see BoardAutomationService::renderMessage()}.
 */
class AutomationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email_subject,
        public string $message_body,
        public ?string $board_label,
        public string $cta_path,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->email_subject);
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.automations.message',
            with: [
                'email_title' => $this->email_subject,
                'message_body' => $this->message_body,
                'board_label' => $this->board_label,
                'cta_url' => $frontend_url.$this->cta_path,
            ],
        );
    }
}
