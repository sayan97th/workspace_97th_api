<?php

namespace App\Http\Requests\Board;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBoardViewRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Every field is optional — this is also the "save filters for this
     * board view" endpoint, called with just the subset of state that
     * changed (e.g. only `filter_state` after tweaking Advanced Filters).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            // A single emoji (possibly several Unicode code points, e.g. a skin-tone
            // modifier or a multi-person ZWJ sequence) — 32 comfortably bounds any
            // real emoji grapheme without needing a fragile emoji-matching regex.
            'emoji' => ['sometimes', 'nullable', 'string', 'max:32'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'filter_state' => ['sometimes', 'nullable', 'array'],
            'sort_state' => ['sometimes', 'nullable', 'array'],
            'group_by_option_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden_column_ids' => ['sometimes', 'nullable', 'array'],
            'pinned_column_ids' => ['sometimes', 'nullable', 'array'],
            'row_height' => ['sometimes', 'string', Rule::in(['single', 'double', 'triple'])],
            'conditional_color_rules' => ['sometimes', 'nullable', 'array'],
            // Markdown source, saved by a `doc`-type view's autosave.
            'doc_content' => ['sometimes', 'nullable', 'string'],
            // Chart type/data source/grouping — only meaningful for a `chart`-type view. See App\Services\Board\ChartDataService.
            'chart_config' => ['sometimes', 'nullable', 'array'],
            'chart_config.chart_type' => ['sometimes', 'nullable', 'string', Rule::in(['bar', 'stacked_bar', 'line', 'pie', 'donut'])],
            'chart_config.source_view_id' => ['sometimes', 'nullable', 'integer'],
            'chart_config.group_by_column_id' => ['sometimes', 'nullable', 'string'],
            'chart_config.split_by_column_id' => ['sometimes', 'nullable', 'string'],
            'chart_config.aggregate_fn' => ['sometimes', 'nullable', 'string', Rule::in(['count', 'sum', 'average'])],
            'chart_config.value_column_id' => ['sometimes', 'nullable', 'string'],
            'chart_config.date_bucket' => ['sometimes', 'nullable', 'string', Rule::in(['day', 'week', 'month'])],
        ];
    }
}
