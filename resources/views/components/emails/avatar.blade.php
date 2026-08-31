@props([
    'name',
    'initials',
    'photo_url' => null,
    'size' => 44,
])
@php
    $font_size = (int) round($size * 0.37);
@endphp
<table role="presentation" width="{{ $size }}" cellpadding="0" cellspacing="0" style="width:{{ $size }}px; height:{{ $size }}px;">
    <tr>
        <td align="center" valign="middle" style="width:{{ $size }}px; height:{{ $size }}px; border-radius:50%; background-color:#b72d24; background-image:linear-gradient(135deg, #ee5042, #8a1c1b); font-size:{{ $font_size }}px; font-weight:700; color:#ffffff; text-align:center;">
            @if ($photo_url)
                <img src="{{ $photo_url }}" width="{{ $size }}" height="{{ $size }}" alt="{{ $name }}" style="display:block; width:{{ $size }}px; height:{{ $size }}px; border-radius:50%; object-fit:cover;">
            @else
                {{ $initials }}
            @endif
        </td>
    </tr>
</table>
