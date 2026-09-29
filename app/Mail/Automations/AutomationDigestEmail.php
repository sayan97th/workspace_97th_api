<?php

namespace App\Mail\Automations;

use App\Jobs\SendEmailJob;
use App\Services\Board\AutomationActions\RunsDigestActions;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email an automation's `send_digest` action delivers through {@see SendEmailJob}: an optional
 * intro and a table of the items that passed the digest filter, see {@see RunsDigestActions}.
 */
class AutomationDigestEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $columns  the labels of the columns shown after the item name
     * @param  array<int, array{name: string, group: string, url: string, values: array<int, string>}>  $rows
     * @param  int  $total  how many items matched, there may be more than rows
     */
    public function __construct(
        public string $email_subject,
        public string $intro,
        public ?string $board_label,
        public string $cta_path,
        public array $columns,
        public array $rows,
        public int $total,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->email_subject);
    }

    public function content(): Content
    {
        $frontend_url = rtrim((string) config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.automations.digest',
            with: [
                'email_title' => $this->email_subject,
                'intro' => $this->intro,
                'board_label' => $this->board_label,
                'columns' => $this->columns,
                'rows' => $this->rows,
                'total' => $this->total,
                'hidden_count' => max(0, $this->total - count($this->rows)),
                'cta_url' => $frontend_url.$this->cta_path,
            ],
        );
    }
}
