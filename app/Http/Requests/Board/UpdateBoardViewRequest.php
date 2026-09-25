<?php

namespace App\Http\Requests\Board;

use App\Services\Board\DashboardDataService;
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
            // Shown in the tab's hover card and the "Manage views" panel.
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'filter_state' => ['sometimes', 'nullable', 'array'],
            'sort_state' => ['sometimes', 'nullable', 'array'],
            'group_by_option_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden_column_ids' => ['sometimes', 'nullable', 'array'],
            'pinned_column_ids' => ['sometimes', 'nullable', 'array'],
            'row_height' => ['sometimes', 'string', Rule::in(['single', 'double', 'triple', 'quad'])],
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
            // People, date and effort columns plus capacity, for a `workload`-type view. See App\Services\Board\WorkloadDataService.
            'workload_config' => ['sometimes', 'nullable', 'array'],
            'workload_config.source_view_id' => ['sometimes', 'nullable', 'integer'],
            'workload_config.people_column_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'workload_config.date_column_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'workload_config.effort_column_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'workload_config.capacity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'workload_config.bucket' => ['sometimes', 'nullable', 'string', Rule::in(['day', 'week'])],
            'workload_config.capacity_overrides' => ['sometimes', 'nullable', 'array', 'max:500'],
            'workload_config.capacity_overrides.*' => ['numeric', 'min:0', 'max:100000'],
            // Widgets and their layout, for a `dashboard`-type view. See App\Services\Board\DashboardDataService.
            'dashboard_config' => ['sometimes', 'nullable', 'array'],
            'dashboard_config.widgets' => ['sometimes', 'array', 'max:'.DashboardDataService::MAX_WIDGETS],
            'dashboard_config.widgets.*.id' => ['required', 'string', 'max:40'],
            'dashboard_config.widgets.*.type' => ['required', 'string', Rule::in(DashboardDataService::WIDGET_TYPES)],
            'dashboard_config.widgets.*.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'dashboard_config.widgets.*.width' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'dashboard_config.widgets.*.source_board_id' => ['sometimes', 'nullable', 'integer'],
            'dashboard_config.widgets.*.source_view_id' => ['sometimes', 'nullable', 'integer'],
            'dashboard_config.widgets.*.config' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
