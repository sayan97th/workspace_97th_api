<?php

namespace App\Console\Commands\Test;

use App\Mail\SmtpTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

// php artisan test:smtp-email --email= --mailer=
class TestSmtpEmail extends Command
{
    protected $signature = 'test:smtp-email
                            {--email= : Email address to send the test message to}
                            {--mailer= : Mailer to use: mailtrap, mailpit, mailgun, smtp (defaults to MAIL_MAILER)}';

    protected $description = 'Send a test email to verify the SMTP credentials configured in .env are working';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email') ?? $this->ask('Which email address should receive the test message?');

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $mailer = $this->resolveMailer();
        $config = config("mail.mailers.{$mailer}", []);

        $this->components->info("Sending test email via [{$mailer}] mailer to {$email}...");
        $this->components->twoColumnDetail('Host', (string) ($config['host'] ?? 'n/a'));
        $this->components->twoColumnDetail('Port', (string) ($config['port'] ?? 'n/a'));
        $this->components->twoColumnDetail('Username', (string) ($config['username'] ?? 'n/a'));

        try {
            Mail::mailer($mailer)->to($email)->send(new SmtpTestMail($mailer));
        } catch (Throwable $exception) {
            $this->components->error("Failed to send the test email: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->success("Test email sent successfully to {$email}.");

        return self::SUCCESS;
    }

    /**
     * Resolve the mailer to send through, falling back to the configured
     * default when the given --mailer option is empty or unsupported.
     */
    private function resolveMailer(): string
    {
        $requested = $this->option('mailer');
        $supported = ['mailtrap', 'mailpit', 'mailgun', 'smtp', 'log'];

        if (! $requested) {
            return config('mail.default');
        }

        if (! in_array($requested, $supported, true)) {
            $this->components->warn("Unknown mailer [{$requested}]. Falling back to the default mailer.");

            return config('mail.default');
        }

        return $requested;
    }
}
