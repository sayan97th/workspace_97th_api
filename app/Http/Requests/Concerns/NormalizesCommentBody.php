<?php

namespace App\Http\Requests\Concerns;

/**
 * Comments are sent as multipart forms, which turn every line break of the
 * Markdown body into CRLF. Normalizing back to LF keeps the stored body the
 * same as the editor's Markdown, so saving an untouched edit is not seen as
 * a change.
 */
trait NormalizesCommentBody
{
    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => str_replace("\r\n", "\n", $body)]);
        }
    }
}
