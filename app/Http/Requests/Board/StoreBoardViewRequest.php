<?php

namespace App\Http\Requests\Board;

use App\Enums\BoardViewType;
use App\Http\Requests\Board\Concerns\ValidatesGanttConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBoardViewRequest extends FormRequest
{
    use ValidatesGanttConfig;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            // Which content the tab renders — immutable once created (see App\Enums\BoardViewType), defaults to 'table'.
            'view_type' => ['sometimes', 'string', Rule::in(BoardViewType::values())],
            // A single emoji (possibly several Unicode code points, e.g. a skin-tone
            // modifier or a multi-person ZWJ sequence) — 32 comfortably bounds any
            // real emoji grapheme without needing a fragile emoji-matching regex.
            'emoji' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_primary' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'filter_state' => ['sometimes', 'nullable', 'array'],
            'sort_state' => ['sometimes', 'nullable', 'array'],
            'group_by_option_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden_column_ids' => ['sometimes', 'nullable', 'array'],
            'pinned_column_ids' => ['sometimes', 'nullable', 'array'],
            'row_height' => ['sometimes', 'string', Rule::in(['single', 'double', 'triple', 'quad'])],
            'conditional_color_rules' => ['sometimes', 'nullable', 'array'],
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
            // Date columns/coloring/group filter/week start, only meaningful for a `calendar`-type view.
            'calendar_config' => ['sometimes', 'nullable', 'array'],
            'calendar_config.date_column_ids' => ['sometimes', 'nullable', 'array'],
            'calendar_config.date_column_ids.*' => ['string', 'max:64'],
            'calendar_config.color_by' => ['sometimes', 'nullable', 'string', 'max:64'],
            'calendar_config.hidden_group_ids' => ['sometimes', 'nullable', 'array'],
            'calendar_config.hidden_group_ids.*' => ['integer'],
            'calendar_config.show_people' => ['sometimes', 'boolean'],
            'calendar_config.week_starts_on' => ['sometimes', 'integer', Rule::in([0, 1])],
            // Bar source/coloring/group filter/toggles/baseline, only meaningful for a `gantt`-type view.
            ...$this->ganttConfigRules(),
        ];
    }
}
