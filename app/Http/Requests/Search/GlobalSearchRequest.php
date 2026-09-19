<?php

namespace App\Http\Requests\Search;

use App\Services\Search\GlobalSearchService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GlobalSearchRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => [
                'required',
                'string',
                'min:'.GlobalSearchService::MIN_TERM_LENGTH,
                'max:'.GlobalSearchService::MAX_TERM_LENGTH,
            ],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.GlobalSearchService::MAX_LIMIT],
        ];
    }

    /**
     * The search term with surrounding whitespace removed, so a query made of
     * spaces never reaches the service as a "valid" term.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }
}
