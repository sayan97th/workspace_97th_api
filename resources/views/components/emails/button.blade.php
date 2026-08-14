@props([
    'href',
    'color' => 'primary',
])
@php
    $palette = match ($color) {
        'secondary' => ['background' => '#f4f4f5', 'text' => '#111827'],
        default => ['background' => '#e53e2e', 'text' => '#ffffff'],
    };
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
    <tr>
        <td style="border-radius:10px; background-color:{{ $palette['background'] }};">
            <a href="{{ $href }}" target="_blank" style="display:inline-block; padding:13px 28px; font-size:14px; font-weight:600; color:{{ $palette['text'] }}; text-decoration:none; border-radius:10px;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
