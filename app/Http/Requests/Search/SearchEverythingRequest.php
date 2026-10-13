<?php

namespace App\Http\Requests\Search;

use App\Models\User;
use App\Services\Search\Everything\FileKind;
use App\Services\Search\Everything\SearchCriteria;
use App\Services\Search\Everything\SearchEverythingService;
use App\Services\Search\Everything\UpdatesTab;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchEverythingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.SearchEverythingService::MAX_TERM_LENGTH],
            'tab' => ['nullable', Rule::in(SearchEverythingService::TABS)],
            'sort' => ['nullable', Rule::in([SearchCriteria::SORT_RELEVANCE, SearchCriteria::SORT_TIME])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_archived' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.*' => [Rule::in(UpdatesTab::FILTERS)],
            'file_types' => ['nullable', 'array'],
            'file_types.*' => [Rule::in(FileKind::ALL)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.SearchEverythingService::MAX_PER_PAGE],
        ];
    }

    public function criteria(): SearchCriteria
    {
        /** @var User $user */
        $user = $this->user();

        return new SearchCriteria(
            user: $user,
            term: (string) $this->validated('q', ''),
            sort: $this->validated('sort') ?? SearchCriteria::SORT_RELEVANCE,
            date_from: $this->validated('date_from') ? CarbonImmutable::createFromFormat('Y-m-d', $this->validated('date_from')) : null,
            date_to: $this->validated('date_to') ? CarbonImmutable::createFromFormat('Y-m-d', $this->validated('date_to')) : null,
            include_archived: $this->boolean('include_archived'),
            filters: array_values(array_unique($this->validated('filters', []))),
            file_types: array_values(array_unique($this->validated('file_types', []))),
        );
    }

    /**
     * Trims the term so a query made only of spaces behaves like an empty one.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }
}
