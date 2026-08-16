@php
    $email_title = 'Your verification code';
    $email_preview = "Your code is {$code}, it expires in {$expires_minutes} minutes.";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:32px 32px 28px;">
        <p class="email-muted" style="margin:0 0 8px; font-size:12px; font-weight:600; letter-spacing:0.02em; text-transform:uppercase; color:#9ca3af;">
            Two factor authentication
        </p>
        <h1 class="email-heading" style="margin:0 0 12px; font-size:21px; line-height:1.35; color:#111827;">
            Verify it's you, {{ $first_name }}
        </h1>
        <p class="email-text" style="margin:0 0 24px; font-size:14px; line-height:1.65; color:#4b5563;">
            Enter the code below to finish signing in to your {{ config('app.name') }} account. If you'd rather use
            your authenticator app, that still works too.
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
            <tr>
                <td class="email-panel" style="padding:22px 20px; background-color:#f9fafb; border-radius:10px; border-left:3px solid #e53e2e; text-align:center;">
                    <p class="email-heading" style="margin:0; font-size:32px; font-weight:700; letter-spacing:0.35em; color:#111827;">
                        {{ $code }}
                    </p>
                </td>
            </tr>
        </table>

        <p class="email-muted" style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
            This code expires in {{ $expires_minutes }} minutes and can only be used once. If you didn't just try to
            sign in, please secure your account by changing your password.
        </p>

        <p class="email-muted email-divider" style="margin:0; padding-top:20px; font-size:12px; line-height:1.6; color:#9ca3af; border-top:1px solid #f3f4f6;">
            For your security, never share this code with anyone, not even someone claiming to be from our team.
        </p>
    </div>
</x-emails.layout>
