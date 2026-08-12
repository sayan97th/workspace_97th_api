<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SMTP Test Email</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px; font-size:20px; line-height:1.4; color:#111827;">
                                SMTP Test Email
                            </h1>
                            <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">
                                This is a test email sent from {{ config('app.name') }} to verify that the
                                <strong>{{ $mailer }}</strong> SMTP configuration is working correctly.
                            </p>
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#9ca3af;">
                                Sent at {{ $sent_at->format('F j, Y g:i A') }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
