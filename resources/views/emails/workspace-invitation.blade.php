@php
    $email_title = "You've been invited to join {$workspace_name}";
    $email_preview = "{$inviter_name} invited you to join {$workspace_name} as a {$role_label}.";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:32px 32px 28px;">
        <x-emails.workspace-badge :mono="$workspace_mono" :color="$workspace_color" :name="$workspace_name" :role="$role_label" />

        <h1 class="email-heading" style="margin:0 0 12px; font-size:21px; line-height:1.35; color:#111827;">
            You're invited to join {{ $workspace_name }}
        </h1>
        <p class="email-text" style="margin:0 0 20px; font-size:14px; line-height:1.65; color:#4b5563;">
            <strong class="email-heading" style="color:#111827;">{{ $inviter_name }}</strong> has invited you to collaborate as a
            <strong class="email-heading" style="color:#111827;">{{ $role_label }}</strong>.
        </p>

        @if ($invite_message)
            <x-emails.panel>&ldquo;{{ $invite_message }}&rdquo;</x-emails.panel>
        @endif

        <div style="margin:28px 0;">
            <x-emails.button :href="$accept_url">Accept invitation</x-emails.button>
        </div>

        @if ($expires_at)
            <p class="email-muted" style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
                This invitation expires on {{ $expires_at->format('F j, Y') }}.
            </p>
        @endif

        <p class="email-muted email-divider" style="margin:0; padding-top:20px; border-top:1px solid #f3f4f6; font-size:12px; line-height:1.6; color:#9ca3af;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $accept_url }}" style="color:#e53e2e; word-break:break-all;">{{ $accept_url }}</a>
        </p>
    </div>
</x-emails.layout>
