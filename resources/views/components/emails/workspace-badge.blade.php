@props([
    'mono',
    'color',
    'name',
    'role' => null,
])
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
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
        <td style="padding-left:14px; vertical-align:middle;">
            <p style="margin:0; font-size:15px; font-weight:700; color:#0a1717;">{{ $name }}</p>
            @if ($role)
                <p style="margin:2px 0 0; font-size:13px; color:#7e8889;">{{ $role }}</p>
            @endif
        </td>
    </tr>
</table>
