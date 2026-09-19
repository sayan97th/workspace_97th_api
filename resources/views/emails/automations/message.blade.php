<x-emails.layout :title="$email_title" :preview="$message_body">
    <div class="email-padding" style="padding:34px 32px 28px;">
        @if ($board_label)
            <p style="margin:0 0 6px; font-size:11.5px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#7e8889;">
                {{ $board_label }}
            </p>
        @endif

        <h1 style="margin:0 0 18px; font-size:19px; line-height:1.4; font-weight:700; color:#0a1717;">
            {{ $email_title }}
        </h1>

        <p style="margin:0 0 28px; font-size:14.5px; line-height:1.7; color:#2b3c40; white-space:pre-line;">{{ $message_body }}</p>

        <div style="margin:0 0 30px;">
            <x-emails.button :href="$cta_url">View in workspace</x-emails.button>
        </div>

        <p style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#a7aead;">
            You're receiving this because an automation on a board you are part of was set up to email you.
        </p>

        <p style="margin:0; padding-top:20px; border-top:1px solid #f0f0ef; font-size:12px; line-height:1.6; color:#a7aead;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $cta_url }}" style="color:#e53e2e; word-break:break-all;">{{ $cta_url }}</a>
        </p>
    </div>
</x-emails.layout>
