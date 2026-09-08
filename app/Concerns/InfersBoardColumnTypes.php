<?php

namespace App\Concerns;

use App\Models\BoardColumn;
use App\Services\Board\BoardItemImportService;
use App\Services\Board\MondayBoardImportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guesses a {@see BoardColumn} data type (and, for option-based types, the
 * option list) from a column's label and its raw values — mirrors the
 * inference {@see MondayBoardImportService} already uses
 * for the `board:import-monday*` artisan commands, reused here by
 * {@see BoardItemImportService} for the "Import items"
 * wizard's "Map columns" step (its suggested type per source column) and its
 * "create a new column" mapping mode.
 */
trait InfersBoardColumnTypes
{
    /** @var array<int, string> */
    private const OPTION_COLOR_PALETTE = [
        '#00c875', '#579bfc', '#a25ddc', '#fdab3d', '#e2445c',
        '#66ccff', '#ff642e', '#7f5347', '#bb3354', '#0086c0', '#9d99b9',
    ];

    /** Familiar monday.com status/priority labels get their usual color instead of a palette-cycled one. */
    private const KNOWN_OPTION_COLORS = [
        'Done' => '#00c875',
        'Working on it' => '#fdab3d',
        'Stuck' => '#e2445c',
        'On hold' => '#797e93',
        'On Track' => '#00c875',
        'Ready for Dev' => '#579bfc',
        'Waiting for deployment' => '#a25ddc',
        'Outlining' => '#9d99b9',
        'Backlog' => '#c4c4c4',
        'Resources' => '#66ccff',
        'In Progress' => '#fdab3d',
        'High' => '#e2445c',
        'Medium' => '#fdab3d',
        'Low' => '#579bfc',
    ];

    /**
     * Header labels (case-insensitive) that mean "this column names a staff
     * member" — matched against an existing user instead of stored as plain
     * text.
     *
     * @var array<int, string>
     */
    private const PEOPLE_LABELS = [
        'owner', 'owners', 'assignee', 'assignees', 'person', 'people', 'reporter', 'developer',
        'interviewer', 'designer', 'epic owner',
    ];

    /** A real tag/label token reads as a short word or phrase; prose split on a comma produces much longer fragments. */
    private const MAX_TAG_TOKEN_LENGTH = 40;

    /**
     * Falls back to {@see BoardColumn::TYPE_TEXT}/`TYPE_LONG_TEXT` whenever
     * the guess is uncertain, so an unrecognized column still imports every
     * value verbatim instead of losing data to a bad type match.
     *
     * @param  array<int, string>  $raw_values
     * @return array{0: string, 1: array<int, string>}
     */
    private function inferColumnType(string $label, array $raw_values): array
    {
        $values = array_values(array_filter($raw_values, fn (string $value) => $value !== ''));
        $label_lower = mb_strtolower(trim($label));

        if ($values === []) {
            return [BoardColumn::TYPE_TEXT, []];
        }

        if (str_contains($label_lower, 'checkbox')) {
            return [BoardColumn::TYPE_CHECKBOX, []];
        }

        if ($this->isAllNumeric($values)) {
            return [BoardColumn::TYPE_NUMBER, []];
        }

        // Value-based, not label-based — unlike a monday.com export's own
        // groups (which always name a date pair "X - Start"/"X - End" with
        // no "date" in sight), a generic upload's own header naming is
        // unpredictable, so this can't lean on the label containing "date"
        // the way the CLI importer's version of this heuristic does.
        if ($this->isMostlyDates($values)) {
            return [BoardColumn::TYPE_DATE, []];
        }

        if (in_array($label_lower, self::PEOPLE_LABELS, true)) {
            return [BoardColumn::TYPE_PEOPLE, []];
        }

        if (str_contains($label_lower, 'priority')) {
            return [BoardColumn::TYPE_LABEL, array_values(array_unique($values))];
        }

        if (str_contains($label_lower, 'status')) {
            return [BoardColumn::TYPE_STATUS, array_values(array_unique($values))];
        }

        if ($this->isMostlyCommaSeparated($values)) {
            $tokens = [];
            foreach ($values as $value) {
                foreach ($this->splitList($value) as $token) {
                    $tokens[$token] = true;
                }
            }

            $token_labels = array_keys($tokens);

            // A genuine tag list splits into short, word-or-phrase-like tokens
            // ("backend", "urgent"); free-text prose that merely contains a
            // comma splits into much longer sentence fragments — that shape
            // difference is what tells the two apart, not the raw token count.
            if (count($token_labels) <= 60 && $this->maxLength($token_labels) <= self::MAX_TAG_TOKEN_LENGTH) {
                return [BoardColumn::TYPE_TAGS, $token_labels];
            }
        } else {
            $distinct = array_values(array_unique($values));

            if (count($distinct) <= 12 && count($values) > count($distinct) && $this->maxLength($distinct) <= 40) {
                return [BoardColumn::TYPE_STATUS, $distinct];
            }
        }

        if ($this->maxLength($values) > 150 || $this->averageLength($values) > 80) {
            return [BoardColumn::TYPE_LONG_TEXT, []];
        }

        return [BoardColumn::TYPE_TEXT, []];
    }

    /** @param  array<int, string>  $values */
    private function isAllNumeric(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<int, string>  $values */
    private function isMostlyDates(array $values): bool
    {
        $parseable = 0;

        foreach ($values as $value) {
            if ($this->parseDate($value) !== null) {
                $parseable++;
            }
        }

        return ($parseable / count($values)) >= 0.9;
    }

    /** @param  array<int, string>  $values */
    private function isMostlyCommaSeparated(array $values): bool
    {
        $with_comma = 0;

        foreach ($values as $value) {
            if (str_contains($value, ',')) {
                $with_comma++;
            }
        }

        return ($with_comma / count($values)) >= 0.3;
    }

    /** @param  array<int, string>  $values */
    private function maxLength(array $values): int
    {
        return array_reduce($values, fn (int $max, string $value) => max($max, mb_strlen($value)), 0);
    }

    /** @param  array<int, string>  $values */
    private function averageLength(array $values): float
    {
        return array_sum(array_map('mb_strlen', $values)) / count($values);
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Splits a comma-separated cell (people names, tag tokens) into trimmed,
     * non-empty tokens.
     *
     * @return array<int, string>
     */
    private function splitList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $value) => $value !== ''));
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, array{id: string, label: string, color: string, is_active: bool}>
     */
    private function buildOptions(array $labels): array
    {
        $options = [];

        foreach (array_values($labels) as $index => $label) {
            $options[] = [
                'id' => (string) Str::uuid(),
                'label' => $label,
                'color' => self::KNOWN_OPTION_COLORS[$label] ?? self::OPTION_COLOR_PALETTE[$index % count(self::OPTION_COLOR_PALETTE)],
                'is_active' => true,
            ];
        }

        return $options;
    }
}
