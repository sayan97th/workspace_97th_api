@php
    $email_title = "{$actor_name} assigned you to \"{$item_name}\"";
    $email_preview = "{$actor_name} put you on \"{$item_name}\"" . ($table_name ? " in {$table_name}" : '') . '.';

    // Only the rows that actually resolved render — a notification whose
    // item/group/view/board happened to be deleted after the fact still
    // sends a usable (just shorter) breadcrumb instead of showing blanks.
    $breadcrumb_rows = array_filter([
        'Workspace' => $workspace_name,
        'Board' => $board_label,
        'View' => $view_name,
        'Table' => $table_name,
        'Item' => $item_name,
    ]);
@endphp
<x-emails.layout :title="$email_title" :preview="$email_preview">
    <div class="email-padding" style="padding:34px 32px 28px;">
        @if ($workspace_name)
            <x-emails.workspace-badge :mono="$workspace_mono ?? '?'" :color="$workspace_color ?? '#e53e2e'" :name="$workspace_name" role="New assignment" />
        @endif

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
            <tr>
                <td style="width:52px; vertical-align:top; padding-top:2px;">
                    <x-emails.avatar :name="$actor_name" :initials="$actor_initials" :photo_url="$actor_photo_url" />
                </td>
                <td style="vertical-align:top; padding-left:14px;">
                    <h1 style="margin:0; font-size:19px; line-height:1.4; font-weight:700; color:#0a1717;">
                        <strong>{{ $actor_name }}</strong> assigned you to &ldquo;{{ $item_name }}&rdquo;
                    </h1>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 22px; font-size:14.5px; line-height:1.7; color:#2b3c40;">
            You're now on the hook for this item — here's exactly where to find it:
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px; border:1px solid #f0f0ef; border-radius:12px; overflow:hidden;">
            @foreach ($breadcrumb_rows as $row_label => $row_value)
                <tr>
                    <td style="padding:11px 16px; {{ $loop->last ? '' : 'border-bottom:1px solid #f0f0ef;' }} width:120px; background-color:#fafaf5; font-size:11.5px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#7e8889; vertical-align:top;">
                        {{ $row_label }}
                    </td>
                    <td style="padding:11px 16px; {{ $loop->last ? '' : 'border-bottom:1px solid #f0f0ef;' }} font-size:13.5px; font-weight:{{ $row_label === 'Item' ? '700' : '500' }}; color:#0a1717;">
                        {{ $row_value }}
                    </td>
                </tr>
            @endforeach
        </table>

        <div style="margin:0 0 30px;">
            <x-emails.button :href="$cta_url">Open &ldquo;{{ $item_name }}&rdquo;</x-emails.button>
        </div>

        <p style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#a7aead;">
            You're receiving this because of your notification preferences. You can change what you get emailed about at any time from your profile settings.
        </p>

        <p style="margin:0; padding-top:20px; border-top:1px solid #f0f0ef; font-size:12px; line-height:1.6; color:#a7aead;">
            If the button above doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ $cta_url }}" style="color:#e53e2e; word-break:break-all;">{{ $cta_url }}</a>
        </p>
    </div>
</x-emails.layout>
