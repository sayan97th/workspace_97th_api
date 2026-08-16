@php
    $email_title = 'Welcome to '.config('app.name').'!';
    $email_preview = "Your workspace is ready, {$first_name}. Let's get everything organized in one place.";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:32px 32px 28px;">
        <p class="email-muted" style="margin:0 0 8px; font-size:12px; font-weight:600; letter-spacing:0.02em; text-transform:uppercase; color:#9ca3af;">
            Welcome
        </p>
        <h1 class="email-heading" style="margin:0 0 12px; font-size:22px; line-height:1.35; color:#111827;">
            Great to have you here, {{ $first_name }}
        </h1>
        <p class="email-text" style="margin:0 0 20px; font-size:14px; line-height:1.65; color:#4b5563;">
            Your account is all set. {{ config('app.name') }} is the place where your boards, updates and files live
            together, so nothing gets lost in a chat thread or a forgotten folder again. Think of it as the single
            home for everything your team is working on.
        </p>

        <div style="margin:28px 0;">
            <x-emails.button :href="$login_url">Go to your workspace</x-emails.button>
        </div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td class="email-panel" style="padding:18px 20px; background-color:#f9fafb; border-radius:10px;">
                    <p class="email-muted" style="margin:0 0 12px; font-size:12px; font-weight:600; letter-spacing:0.02em; text-transform:uppercase; color:#9ca3af;">
                        A few ideas to get you started
                    </p>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="padding:0 0 10px; width:8px; vertical-align:top;">
                                <span style="display:inline-block; width:6px; height:6px; margin-top:7px; border-radius:50%; background-color:#e53e2e;"></span>
                            </td>
                            <td style="padding:0 0 10px;">
                                <p class="email-text" style="margin:0; font-size:13.5px; line-height:1.6; color:#4b5563;">
                                    Create your first board and lay out the work the way your team actually thinks about it.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:0 0 10px; width:8px; vertical-align:top;">
                                <span style="display:inline-block; width:6px; height:6px; margin-top:7px; border-radius:50%; background-color:#e53e2e;"></span>
                            </td>
                            <td style="padding:0 0 10px;">
                                <p class="email-text" style="margin:0; font-size:13.5px; line-height:1.6; color:#4b5563;">
                                    Invite your teammates so everyone is looking at the same up to date information.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td style="width:8px; vertical-align:top;">
                                <span style="display:inline-block; width:6px; height:6px; margin-top:7px; border-radius:50%; background-color:#e53e2e;"></span>
                            </td>
                            <td>
                                <p class="email-text" style="margin:0; font-size:13.5px; line-height:1.6; color:#4b5563;">
                                    Turn on two factor authentication in your security settings for extra peace of mind.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <p class="email-muted email-divider" style="margin:0; padding-top:20px; border-top:1px solid #f3f4f6; font-size:12.5px; line-height:1.6; color:#9ca3af;">
            Questions along the way? Just reply to this email, a real person on our team will read it.
        </p>
    </div>
</x-emails.layout>
