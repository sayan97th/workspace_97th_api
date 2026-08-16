@php
    $email_title = 'Reset your password';
    $email_preview = "Here's your link to choose a new password, it expires in {$expires_minutes} minutes.";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:32px 32px 28px;">
        <p class="email-muted" style="margin:0 0 8px; font-size:12px; font-weight:600; letter-spacing:0.02em; text-transform:uppercase; color:#9ca3af;">
            Password reset
        </p>
        <h1 class="email-heading" style="margin:0 0 12px; font-size:21px; line-height:1.35; color:#111827;">
            Let's get you back in, {{ $first_name }}
        </h1>
        <p class="email-text" style="margin:0 0 20px; font-size:14px; line-height:1.65; color:#4b5563;">
            We received a request to reset the password for your {{ config('app.name') }} account. Click the button
            below to choose a new one. This link will stay active for {{ $expires_minutes }} minutes.
        </p>

        <div style="margin:28px 0;">
            <x-emails.button :href="$reset_url">Reset password</x-emails.button>
        </div>

        <p class="email-muted" style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
            Didn't ask for this? No action is needed, your password is safe and nothing will change unless you open
            the link above.
        </p>

        <p class="email-muted email-divider" style="margin:0; padding-top:20px; border-top:1px solid #f3f4f6; font-size:12px; line-height:1.6; color:#9ca3af;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $reset_url }}" style="color:#e53e2e; word-break:break-all;">{{ $reset_url }}</a>
        </p>
    </div>
</x-emails.layout>
