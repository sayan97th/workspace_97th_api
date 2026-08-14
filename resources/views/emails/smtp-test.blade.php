<x-emails.layout title="SMTP Test Email" preview="SMTP configuration test">
    <div class="email-padding" style="padding:32px;">
        <h1 class="email-heading" style="margin:0 0 16px; font-size:20px; line-height:1.4; color:#111827;">
            SMTP Test Email
        </h1>
        <p class="email-text" style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">
            This is a test email sent from {{ config('app.name') }} to verify that the
            <strong class="email-heading" style="color:#111827;">{{ $mailer }}</strong> SMTP configuration is working correctly.
        </p>
        <p class="email-muted" style="margin:0; font-size:12px; line-height:1.6; color:#9ca3af;">
            Sent at {{ $sent_at->format('F j, Y g:i A') }}.
        </p>
    </div>
</x-emails.layout>
