@props([
    'title',
    'preview' => null,
])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title }}</title>
    <style>
        @media (prefers-color-scheme: dark) {
            .email-bg { background-color: #09090b !important; }
            .email-card { background-color: #18181b !important; border-color: #27272a !important; }
            .email-heading { color: #f4f4f5 !important; }
            .email-text { color: #d4d4d8 !important; }
            .email-muted { color: #71717a !important; }
            .email-divider { border-color: #27272a !important; }
            .email-panel { background-color: #202023 !important; }
            .email-brand { color: #f4f4f5 !important; }
        }
        @media (max-width: 600px) {
            .email-padding { padding: 24px !important; }
        }
    </style>
</head>
<body class="email-bg" style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
    @if ($preview)
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
            {{ $preview }}&#8203;
        </div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="email-bg" style="background-color:#f4f4f5; padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
                    <tr>
                        <td style="padding:0 4px 24px; text-align:center;">
                            <span class="email-brand" style="display:inline-block; font-size:15px; font-weight:700; letter-spacing:0.01em; color:#111827;">
                                <span style="display:inline-block; width:8px; height:8px; margin-right:8px; border-radius:50%; background-color:#e53e2e; vertical-align:middle;"></span>
                                <span style="vertical-align:middle;">{{ config('app.name') }}</span>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-card" style="background-color:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #e5e7eb;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 4px 0;">
                            <x-emails.footer />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
