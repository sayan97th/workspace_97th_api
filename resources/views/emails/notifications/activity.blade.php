@php
    $email_title = "{$actor_name} ".lcfirst($action_label);
    $email_preview = "{$actor_name} {$action_label} {$action_target}";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:32px 32px 28px;">
        @if ($board_label)
            <p class="email-muted" style="margin:0 0 8px; font-size:12px; font-weight:600; letter-spacing:0.02em; text-transform:uppercase; color:#9ca3af;">
                {{ $board_label }}
            </p>
        @endif

        <h1 class="email-heading" style="margin:0 0 12px; font-size:21px; line-height:1.35; color:#111827;">
            {{ $email_title }}
        </h1>
        <p class="email-text" style="margin:0 0 28px; font-size:14px; line-height:1.65; color:#4b5563;">
            <strong class="email-heading" style="color:#111827;">{{ $actor_name }}</strong>
            {{ lcfirst($action_label) }} {{ $action_target }}.
        </p>

        <div style="margin:0 0 28px;">
            <x-emails.button :href="$cta_url">View in workspace</x-emails.button>
        </div>

        <p class="email-muted" style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
            You're receiving this because of your notification preferences. You can change what you get emailed about at any time from your profile settings.
        </p>

        <p class="email-muted email-divider" style="margin:0; padding-top:20px; border-top:1px solid #f3f4f6; font-size:12px; line-height:1.6; color:#9ca3af;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $cta_url }}" style="color:#e53e2e; word-break:break-all;">{{ $cta_url }}</a>
        </p>
    </div>
</x-emails.layout>
