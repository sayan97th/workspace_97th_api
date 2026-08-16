@php
    $email_title = "{$inviter_name} invited you to join {$workspace_name}";
    $email_preview = "{$inviter_name} would like you to join {$workspace_name} as a {$role_label}.";
    $first_name = trim(explode(' ', $inviter_name)[0] ?? $inviter_name);
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:32px 32px 28px;">
        <x-emails.workspace-badge :mono="$workspace_mono" :color="$workspace_color" :name="$workspace_name" :role="$role_label" />

        <h1 class="email-heading" style="margin:0 0 12px; font-size:21px; line-height:1.35; color:#111827;">
            You're invited to join {{ $workspace_name }}
        </h1>
        <p class="email-text" style="margin:0 0 20px; font-size:14px; line-height:1.65; color:#4b5563;">
            <strong class="email-heading" style="color:#111827;">{{ $inviter_name }}</strong> would like you to join
            <strong class="email-heading" style="color:#111827;">{{ $workspace_name }}</strong> as a
            <strong class="email-heading" style="color:#111827;">{{ $role_label }}</strong>. Once you're in, you'll be able to see the boards, updates and files your team is already working on.
        </p>

        @if ($invite_message)
            <p class="email-muted" style="margin:0 0 8px; font-size:12px; font-weight:600; letter-spacing:0.02em; text-transform:uppercase; color:#9ca3af;">
                A note from {{ $first_name }}
            </p>
            <x-emails.panel>&ldquo;{{ $invite_message }}&rdquo;</x-emails.panel>
        @endif

        <div style="margin:28px 0;">
            <x-emails.button :href="$accept_url">Join {{ $workspace_name }}</x-emails.button>
        </div>

        @if ($expires_at)
            <p class="email-muted" style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
                This invitation is valid until {{ $expires_at->format('F j, Y') }}, so don't wait too long to jump in.
            </p>
        @endif

        <p class="email-muted" style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
            Wasn't expecting this? No action is needed, you can safely ignore this email and nothing will change.
        </p>

        <p class="email-muted email-divider" style="margin:0; padding-top:20px; border-top:1px solid #f3f4f6; font-size:12px; line-height:1.6; color:#9ca3af;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $accept_url }}" style="color:#e53e2e; word-break:break-all;">{{ $accept_url }}</a>
        </p>
    </div>
</x-emails.layout>
