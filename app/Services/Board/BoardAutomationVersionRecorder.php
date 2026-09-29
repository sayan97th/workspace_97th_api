<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;
use App\Models\BoardAutomationVersion;
use App\Models\User;

/**
 * Keeps the version history of an automation, see {@see BoardAutomationVersion}. A save that
 * changes nothing a version keeps (turning it on or off, transferring it) adds no version.
 */
class BoardAutomationVersionRecorder
{
    /** Which snapshot fields belong to which part of the sentence, for "what changed". */
    private const PARTS = [
        'trigger' => ['trigger_type', 'trigger_column_id', 'trigger_value', 'trigger_config'],
        'conditions' => ['conditions', 'condition_operator', 'condition_groups'],
        'actions' => ['actions'],
        'else_actions' => ['else_actions'],
        'details' => ['name', 'description', 'importance', 'failure_alert'],
    ];

    /**
     * Records the automation as it is now, returns the new version or null when nothing changed.
     */
    public function record(BoardAutomation $automation, ?User $user): ?BoardAutomationVersion
    {
        $snapshot = $this->snapshot($automation);
        $latest = BoardAutomationVersion::where('automation_id', $automation->id)->orderByDesc('version')->first();

        $changed_parts = $latest ? $this->changedParts((array) $latest->snapshot, $snapshot) : array_keys(self::PARTS);
        if ($latest && $changed_parts === []) {
            return null;
        }

        $version = BoardAutomationVersion::create([
            'automation_id' => $automation->id,
            'version' => ($latest?->version ?? 0) + 1,
            'snapshot' => $snapshot,
            'changed_parts' => $latest ? $changed_parts : [],
            'changed_by_id' => $user?->id,
        ]);

        $stale_ids = BoardAutomationVersion::where('automation_id', $automation->id)
            ->orderByDesc('version')
            ->skip(BoardAutomationVersion::MAX_VERSIONS)
            ->take(PHP_INT_MAX)
            ->pluck('id');
        if ($stale_ids->isNotEmpty()) {
            BoardAutomationVersion::whereIn('id', $stale_ids)->delete();
        }

        return $version;
    }

    /**
     * The fields a version keeps, with the actions always as a list.
     *
     * @return array<string, mixed>
     */
    public function snapshot(BoardAutomation $automation): array
    {
        $snapshot = [];
        foreach (BoardAutomationVersion::SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $automation->getAttribute($field);
        }
        $snapshot['actions'] = $automation->resolvedActions();
        $snapshot['else_actions'] = $automation->resolvedElseActions();
        $snapshot['condition_operator'] = $automation->condition_operator ?: 'and';

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, string>
     */
    private function changedParts(array $before, array $after): array
    {
        $normalize = fn (mixed $value) => json_encode(($value === [] || $value === '') ? null : $value);
        $changed = [];
        foreach (self::PARTS as $part => $fields) {
            foreach ($fields as $field) {
                if ($normalize($before[$field] ?? null) !== $normalize($after[$field] ?? null)) {
                    $changed[] = $part;
                    break;
                }
            }
        }

        return $changed;
    }
}
