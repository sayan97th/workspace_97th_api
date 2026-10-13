<?php

namespace App\Http\Requests\Search;

use App\Services\Search\Everything\SearchEverythingService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavedSearchRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:'.SearchEverythingService::MAX_TERM_LENGTH],
            'tab' => ['required', Rule::in(SearchEverythingService::TABS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('query'))) {
            $this->merge(['query' => trim($this->input('query'))]);
        }
    }
}
