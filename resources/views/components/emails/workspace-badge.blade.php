@props([
    'mono',
    'color',
    'name',
    'role' => null,
])
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    <tr>
        <td style="width:44px; vertical-align:middle;">
            <table role="presentation" width="44" cellpadding="0" cellspacing="0" style="width:44px; height:44px; border-radius:12px; background-color:{{ $color }};">
                <tr>
                    <td align="center" valign="middle" style="width:44px; height:44px; font-size:15px; font-weight:700; color:#ffffff; text-align:center;">
                        {{ $mono }}
                    </td>
                </tr>
            </table>
        </td>
        <td style="padding-left:12px; vertical-align:middle;">
            <p class="email-heading" style="margin:0; font-size:15px; font-weight:600; color:#111827;">{{ $name }}</p>
            @if ($role)
                <p class="email-muted" style="margin:2px 0 0; font-size:13px; color:#6b7280;">{{ $role }}</p>
            @endif
        </td>
    </tr>
</table>
