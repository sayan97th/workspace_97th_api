<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationSetting;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\User;
use App\Support\AutomationSchedule;
use Carbon\CarbonImmutable;

/**
 * "Preview impact" in the builder: before an automation is turned on, which items of the table it
 * would act on and what one run would do, with nothing saved. What "would act on" means depends on
 * the trigger:
 *
 * - `scan`: a scheduled item check acts on every item that passes the conditions, at its next run.
 * - `upcoming`: a date or overdue trigger fires on the items whose date comes up in the next
 *   {@see self::UPCOMING_DAYS} days and that pass the conditions, each with the day it fires.
 * - `conditions`: every other item trigger waits for something to happen, so the preview lists
 *   the items that would pass the conditions if it happened now.
 * - `itemless`: a recurring or webhook automation has no item, only the sample run applies.
 *
 * The sample run is a test run ({@see BoardAutomationService::testRun()}) on the first matching item.
 */
class BoardAutomationImpactPreview
{
    /** Most items checked, a larger table is reported as truncated. */
    public const MAX_SCANNED = 2000;

    /** Most matching items listed. */
    public const MAX_LISTED = 25;

    /** How far ahead date and overdue triggers look. */
    public const UPCOMING_DAYS = 14;

    public function __construct(
        private readonly BoardAutomationService $automation_service,
        private readonly AutomationConditionEvaluator $condition_evaluator,
    ) {}

    /**
     * @return array{mode: string, total_items: int, matching_count: int, is_truncated: bool, items: array<int, array<string, mixed>>, sample: array<string, mixed>|null}
     */
    public function preview(BoardAutomation $automation, ?User $actor): array
    {
        if (in_array($automation->trigger_type, BoardAutomation::itemlessTriggers(), true)) {
            return [
                'mode' => 'itemless',
                'total_items' => 0,
                'matching_count' => 0,
                'is_truncated' => false,
                'items' => [],
                'sample' => ['item' => null, ...$this->automation_service->testRun($automation, null, $actor)],
            ];
        }

        $columns = AutomationConditionEvaluator::tabColumns($automation);
        $trigger_column = $automation->trigger_column_id ? $columns->get((string) $automation->trigger_column_id) : null;
        $is_subitem_trigger = $automation->trigger_type === BoardAutomation::TRIGGER_SUBITEM_CREATED || $trigger_column?->scope === BoardColumn::SCOPE_SUBITEM;

        $query = BoardItem::query()
            ->where('is_archived', false)
            ->whereHas('group', fn ($group) => $group->where('board_view_id', $automation->board_view_id)->where('is_archived', false))
            ->when($is_subitem_trigger, fn ($items) => $items->whereNotNull('parent_id'), fn ($items) => $items->whereNull('parent_id'))
            ->with(['values', 'group']);
        $total = (clone $query)->count();
        $items = $query->orderBy('id')->limit(self::MAX_SCANNED)->get();

        $mode = match ($automation->trigger_type) {
            BoardAutomation::TRIGGER_ITEM_SCAN => 'scan',
            BoardAutomation::TRIGGER_DATE_ARRIVED, BoardAutomation::TRIGGER_ITEM_OVERDUE => 'upcoming',
            default => 'conditions',
        };

        $matching = [];
        foreach ($items as $item) {
            $fires_on = null;
            if ($mode === 'upcoming') {
                $fires_on = $trigger_column ? $this->firesOn($automation, $trigger_column, $item) : null;
                if ($fires_on === null) {
                    continue;
                }
            }
            if ($automation->hasConditions() && ! $this->condition_evaluator->matches($automation, $item, $actor, $automation->conditionFilterState(), $columns)) {
                continue;
            }
            $matching[] = ['item' => $item, 'fires_on' => $fires_on];
        }

        if ($mode === 'upcoming') {
            usort($matching, fn (array $a, array $b) => strcmp((string) $a['fires_on'], (string) $b['fires_on']));
        }
        if ($mode === 'scan') {
            $matching = array_slice($matching, 0, BoardAutomationService::MAX_SCAN_ITEMS);
        }

        $first = $matching[0]['item'] ?? null;

        return [
            'mode' => $mode,
            'total_items' => $total,
            'matching_count' => count($matching),
            'is_truncated' => $total > self::MAX_SCANNED,
            'items' => array_map(fn (array $entry) => [
                'id' => $entry['item']->id,
                'name' => (string) $entry['item']->name,
                'group_name' => (string) ($entry['item']->group?->name ?? ''),
                'fires_on' => $entry['fires_on'],
            ], array_slice($matching, 0, self::MAX_LISTED)),
            'sample' => $first ? ['item' => ['id' => $first->id, 'name' => (string) $first->name], ...$this->automation_service->testRun($automation, $first, $actor)] : null,
        ];
    }

    /**
     * The day a date or overdue trigger would fire for the item within the coming days, null when
     * it does not.
     */
    private function firesOn(BoardAutomation $automation, BoardColumn $column, BoardItem $item): ?string
    {
        $config = (array) ($automation->trigger_config ?? []);
        $today = CarbonImmutable::now(AutomationSchedule::timezone($config['timezone'] ?? null))->startOfDay();
        $value = $item->values->firstWhere('column_id', $column->id)?->value;
        $raw = $column->type === BoardColumn::TYPE_TIMELINE ? (is_array($value) ? ($value['end'] ?? $value['start'] ?? null) : null) : $value;
        $day = is_string($raw) ? substr($raw, 0, 10) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }
        $date = CarbonImmutable::parse($day, $today->getTimezone());

        if ($automation->trigger_type === BoardAutomation::TRIGGER_ITEM_OVERDUE) {
            $status_column_id = $config['status_column_id'] ?? null;
            $done_values = array_map('strval', (array) ($config['done_values'] ?? []));
            if ($status_column_id && in_array((string) $item->values->firstWhere('column_id', (int) $status_column_id)?->value, $done_values, true)) {
                return null;
            }
            // Items already overdue are left alone, the rest fire the day after their date.
            if ($date->lessThan($today)) {
                return null;
            }
            $fires = $date->addDay();
        } else {
            $offset = (int) ($config['offset_days'] ?? 0);
            $fires = empty($config['working_days_only'])
                ? $date->addDays($offset)
                : ($offset === 0
                    ? BoardAutomationSetting::forBoard($automation->board_id)->calendar()->previousWorkingDay($date)
                    : BoardAutomationSetting::forBoard($automation->board_id)->calendar()->addWorkingDays($date, $offset));
        }

        return $fires->greaterThanOrEqualTo($today) && $fires->lessThanOrEqualTo($today->addDays(self::UPCOMING_DAYS)) ? $fires->toDateString() : null;
    }
}
