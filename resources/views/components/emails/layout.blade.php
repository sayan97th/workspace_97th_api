@props([
    'title',
    'preview' => null,
])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title }}</title>
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!--<![endif]-->
    <style>
        @media (max-width: 600px) {
            .email-padding { padding: 24px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#fafaf5; font-family:'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
    @if ($preview)
        <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
            {{ $preview }}&#8203;
        </div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fafaf5;">
        <tr>
            <td align="center" style="padding:44px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
                    <tr>
                        <td style="padding:0 4px 28px; text-align:center;">
                            <span style="display:inline-block; font-size:15px; font-weight:800; letter-spacing:0.01em; color:#0a1717;">
                                <span style="display:inline-block; width:8px; height:8px; margin-right:8px; border-radius:50%; background-color:#e53e2e; vertical-align:middle;"></span>
                                <span style="vertical-align:middle;">{{ config('app.name') }}</span>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#ffffff; border-radius:18px; border:1px solid #f0f0ef; box-shadow:0 1px 2px rgba(10,23,23,0.04), 0 8px 24px rgba(10,23,23,0.05); overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="height:4px; line-height:4px; font-size:0; background-color:#e53e2e;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td>
                                        {{ $slot }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 4px 0;">
                            <x-emails.footer />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
