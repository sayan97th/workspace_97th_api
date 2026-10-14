<?php

namespace App\Http\Requests\Board\Concerns;

use Illuminate\Validation\Rule;

/**
 * Rules for a `gantt`-type view's `gantt_config`, shared by creating and
 * editing a view. The baseline is a snapshot of every item's dates at the
 * moment it was saved, keyed by item id, so the chart can draw the planned
 * dates under the live ones.
 */
trait ValidatesGanttConfig
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function ganttConfigRules(): array
    {
        return [
            'gantt_config' => ['sometimes', 'nullable', 'array'],
            'gantt_config.date_column_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'gantt_config.color_by' => ['sometimes', 'nullable', 'string', 'max:64'],
            'gantt_config.hidden_group_ids' => ['sometimes', 'nullable', 'array'],
            'gantt_config.hidden_group_ids.*' => ['integer'],
            'gantt_config.bar_label' => ['sometimes', 'nullable', 'string', Rule::in(['none', 'name'])],
            'gantt_config.show_group_summary' => ['sometimes', 'boolean'],
            'gantt_config.show_dependencies' => ['sometimes', 'boolean'],
            'gantt_config.show_critical_path' => ['sometimes', 'boolean'],
            'gantt_config.show_legend' => ['sometimes', 'boolean'],
            'gantt_config.show_weekends' => ['sometimes', 'boolean'],
            'gantt_config.show_baseline' => ['sometimes', 'boolean'],
            'gantt_config.baseline' => ['sometimes', 'nullable', 'array'],
            'gantt_config.baseline.saved_at' => ['required_with:gantt_config.baseline', 'date'],
            'gantt_config.baseline.saved_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gantt_config.baseline.items' => ['present_with:gantt_config.baseline', 'array', 'max:5000'],
            'gantt_config.baseline.items.*.start' => ['required', 'date_format:Y-m-d'],
            'gantt_config.baseline.items.*.end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
