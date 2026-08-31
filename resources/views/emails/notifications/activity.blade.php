@php
    $email_title = "{$actor_name} ".lcfirst($action_label);
    $email_preview = "{$actor_name} {$action_label} {$action_target}";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:34px 32px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="width:52px; vertical-align:top; padding-top:2px;">
                    <x-emails.avatar :name="$actor_name" :initials="$actor_initials" :photo_url="$actor_photo_url" />
                </td>
                <td style="vertical-align:top; padding-left:14px;">
                    @if ($board_label)
                        <p style="margin:0 0 6px; font-size:11.5px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#7e8889;">
                            {{ $board_label }}
                        </p>
                    @endif
                    <h1 style="margin:0; font-size:19px; line-height:1.4; font-weight:700; color:#0a1717;">
                        {{ $email_title }}
                    </h1>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 28px; font-size:14.5px; line-height:1.7; color:#2b3c40;">
            <strong style="color:#0a1717;">{{ $actor_name }}</strong>
            {{ lcfirst($action_label) }} {{ $action_target }}.
        </p>

        <div style="margin:0 0 30px;">
            <x-emails.button :href="$cta_url">View in workspace</x-emails.button>
        </div>

        <p style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#a7aead;">
            You're receiving this because of your notification preferences. You can change what you get emailed about at any time from your profile settings.
        </p>

        <p style="margin:0; padding-top:20px; border-top:1px solid #f0f0ef; font-size:12px; line-height:1.6; color:#a7aead;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $cta_url }}" style="color:#e53e2e; word-break:break-all;">{{ $cta_url }}</a>
        </p>
    </div>
</x-emails.layout>
