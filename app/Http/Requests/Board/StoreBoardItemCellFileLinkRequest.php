<?php

namespace App\Http\Requests\Board;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Files cell's "From Link" entry: any external URL (a PDF, a Figma or Miro
 * board, a Google Doc, ...) plus an optional label shown in its place.
 *
 * `source` records which menu option added it. "From Google Drive", "From
 * Dropbox", "From Box" and "From OneDrive & SharePoint" only accept links
 * that live on that provider, plain "From Link" accepts any host.
 */
class StoreBoardItemCellFileLinkRequest extends FormRequest
{
    public const SOURCE_LINK = 'link';

    /**
     * Allowed hosts per cloud provider, a URL matches when its host is one of
     * these or a subdomain of one.
     *
     * @var array<string, list<string>>
     */
    public const PROVIDER_HOSTS = [
        'google_drive' => ['drive.google.com', 'docs.google.com'],
        'dropbox' => ['dropbox.com', 'dropboxusercontent.com', 'db.tt'],
        'box' => ['box.com', 'box.net', 'boxcloud.com'],
        'onedrive' => ['onedrive.live.com', 'onedrive.com', '1drv.ms', 'sharepoint.com', 'sharepoint.us'],
    ];

    /**
     * @var array<string, string>
     */
    public const PROVIDER_NAMES = [
        'google_drive' => 'Google Drive',
        'dropbox' => 'Dropbox',
        'box' => 'Box',
        'onedrive' => 'OneDrive or SharePoint',
    ];

    /**
     * Adds a scheme to a bare "example.com/file.pdf" so it validates and
     * opens as an absolute URL, the same way a browser address bar would.
     */
    protected function prepareForValidation(): void
    {
        $url = trim((string) $this->input('url', ''));

        if ($url !== '' && ! preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        $this->merge([
            'url' => $url,
            'text' => trim((string) $this->input('text', '')),
            'source' => $this->input('source') ?: self::SOURCE_LINK,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https', $this->providerHostRule()],
            'text' => ['nullable', 'string', 'max:255'],
            'source' => ['required', 'string', Rule::in([self::SOURCE_LINK, ...array_keys(self::PROVIDER_HOSTS)])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Paste a link to add.',
            'url.url' => 'Enter a valid http or https link.',
            'source.in' => 'Choose a supported link source.',
        ];
    }

    /**
     * Rejects a provider link whose host does not belong to that provider.
     */
    private function providerHostRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $source = (string) $this->input('source');
            $allowed_hosts = self::PROVIDER_HOSTS[$source] ?? null;

            if ($allowed_hosts === null) {
                return;
            }

            $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));
            $is_allowed = collect($allowed_hosts)->contains(
                fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed)
            );

            if (! $is_allowed) {
                $fail('Paste a link from '.self::PROVIDER_NAMES[$source].'.');
            }
        };
    }
}
