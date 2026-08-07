<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>You've been invited to join {{ $workspace_name }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <h1 style="margin:0 0 16px; font-size:20px; line-height:1.4; color:#111827;">
                                You've been invited to join {{ $workspace_name }}
                            </h1>
                            <p style="margin:0 0 12px; font-size:14px; line-height:1.6; color:#374151;">
                                <strong>{{ $inviter_name }}</strong> has invited you to join
                                <strong>{{ $workspace_name }}</strong> as a
                                <strong>{{ $role_label }}</strong>.
                            </p>

                            @if ($invite_message)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px; background-color:#f9fafb; border-radius:8px;">
                                    <tr>
                                        <td style="padding:12px 16px; font-size:13.5px; line-height:1.6; color:#4b5563; font-style:italic;">
                                            "{{ $invite_message }}"
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                                <tr>
                                    <td style="border-radius:8px; background-color:#e53e2e;">
                                        <a href="{{ $accept_url }}" target="_blank" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                            Accept invitation
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            @if ($expires_at)
                                <p style="margin:0 0 24px; font-size:12.5px; line-height:1.6; color:#9ca3af;">
                                    This invitation expires on {{ $expires_at->format('F j, Y') }}.
                                </p>
                            @endif

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#9ca3af;">
                                If the button above doesn't work, copy and paste this link into your browser:<br>
                                <a href="{{ $accept_url }}" style="color:#e53e2e; word-break:break-all;">{{ $accept_url }}</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
