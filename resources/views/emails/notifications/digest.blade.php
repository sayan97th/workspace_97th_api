@php
    $email_title = "You have {$total_unread} unread ".($total_unread === 1 ? 'notification' : 'notifications');
    $email_preview = "Here is what you missed this {$period}.";
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:34px 32px 28px;">
        <h1 style="margin:0 0 8px; font-size:21px; line-height:1.4; font-weight:700; color:#0a1717;">
            Hi {{ $recipient_name }}, here is what you missed this {{ $period }}
        </h1>
        <p style="margin:0 0 24px; font-size:14.5px; line-height:1.7; color:#2b3c40;">
            {{ $email_title }} waiting for you.
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            @foreach ($items as $item)
                <tr>
                    <td style="padding:14px 0; border-top:1px solid #f0f0ef;">
                        @if ($item['board_label'])
                            <p style="margin:0 0 4px; font-size:11.5px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#7e8889;">
                                {{ $item['board_label'] }}
                            </p>
                        @endif
                        <p style="margin:0 0 4px; font-size:14px; line-height:1.6; color:#2b3c40;">
                            <strong style="color:#0a1717;">{{ $item['actor_name'] }}</strong>
                            {{ lcfirst($item['action_label']) }} {{ $item['action_target'] }}
                        </p>
                        <p style="margin:0; font-size:12px; color:#a7aead;">
                            {{ $item['time_label'] }}
                            &middot;
                            <a href="{{ $item['url'] }}" style="color:#e53e2e;">Open</a>
                        </p>
                    </td>
                </tr>
            @endforeach
        </table>

        @if ($hidden_count > 0)
            <p style="margin:0 0 24px; font-size:13.5px; color:#2b3c40;">
                And {{ $hidden_count }} more waiting in your notifications.
            </p>
        @endif

        <div style="margin:0 0 30px;">
            <x-emails.button :href="$cta_url">Open workspace</x-emails.button>
        </div>

        <p style="margin:0; padding-top:20px; border-top:1px solid #f0f0ef; font-size:12px; line-height:1.6; color:#a7aead;">
            You are receiving this digest because you turned it on in your notification settings.
            <a href="{{ $preferences_url }}" style="color:#e53e2e;">Change how often you get it</a>.
        </p>
    </div>
</x-emails.layout>
