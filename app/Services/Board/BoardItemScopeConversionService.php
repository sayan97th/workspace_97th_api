<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use Illuminate\Support\Collection;

/**
 * Carries a row's cell values across the item/subitem boundary.
 *
 * A root item and a subitem read from two separate column sets (see
 * {@see BoardColumn::SCOPE_ITEM} and {@see BoardColumn::SCOPE_SUBITEM}), so a
 * value keyed by an item column would sit orphaned on a converted row and
 * never render. This service re-keys every value whose column has a
 * counterpart in the target scope (same type and same label) and drops the
 * rest, so a converted row keeps everything that can still be shown.
 */
class BoardItemScopeConversionService
{
    /**
     * Column types whose stored value means the same thing in any column of
     * that type, so it can be re-keyed as is.
     *
     * @var array<int, string>
     */
    private const PORTABLE_TYPES = [
        BoardColumn::TYPE_TEXT,
        BoardColumn::TYPE_LONG_TEXT,
        BoardColumn::TYPE_PEOPLE,
        BoardColumn::TYPE_DATE,
        BoardColumn::TYPE_TIMELINE,
        BoardColumn::TYPE_NUMBER,
        BoardColumn::TYPE_CHECKBOX,
        BoardColumn::TYPE_PHONE,
        BoardColumn::TYPE_EMAIL,
        BoardColumn::TYPE_RATING,
        BoardColumn::TYPE_LINK,
    ];

    /**
     * Column types whose value is one or more option ids of the column's own
     * option list, so it has to be translated through the option label.
     *
     * @var array<int, string>
     */
    private const OPTION_TYPES = [
        BoardColumn::TYPE_STATUS,
        BoardColumn::TYPE_DROPDOWN,
    ];

    /**
     * Re-keys `$board_item`'s values from the source column set to the target
     * one. Values that have no counterpart are deleted.
     */
    public function convert(BoardItem $board_item, int $source_view_id, string $source_scope, int $target_view_id, string $target_scope): void
    {
        $source_columns = BoardColumn::where('board_view_id', $source_view_id)->where('scope', $source_scope)->get()->keyBy('id');
        $target_columns = BoardColumn::where('board_view_id', $target_view_id)->where('scope', $target_scope)->get();

        $claimed_target_ids = [];

        foreach ($board_item->values()->get() as $value) {
            /** @var BoardItemValue $value */
            $source = $source_columns->get($value->column_id);

            if ($source === null) {
                continue;
            }

            $target = $this->findCounterpart($source, $target_columns, $claimed_target_ids);

            if ($target === null) {
                $value->delete();

                continue;
            }

            $claimed_target_ids[] = $target->id;

            $translated = $this->translateValue($value->value, $source, $target);

            if ($translated === null) {
                $value->delete();

                continue;
            }

            $value->update(['column_id' => $target->id, 'value' => $translated]);
        }
    }

    /**
     * The target column that plays the same role as `$source`: same type and
     * the same label (ignoring case), and not already taken by another value.
     *
     * @param  Collection<int, BoardColumn>  $target_columns
     * @param  array<int, int>  $claimed_target_ids
     */
    private function findCounterpart(BoardColumn $source, Collection $target_columns, array $claimed_target_ids): ?BoardColumn
    {
        if (! in_array($source->type, [...self::PORTABLE_TYPES, ...self::OPTION_TYPES], true)) {
            return null;
        }

        return $target_columns->first(
            fn (BoardColumn $column) => $column->type === $source->type
                && mb_strtolower($column->label) === mb_strtolower($source->label)
                && ! in_array($column->id, $claimed_target_ids, true)
        );
    }

    /**
     * The value as the target column stores it, or null when it cannot be
     * expressed there (an option the target column does not have).
     */
    private function translateValue(mixed $value, BoardColumn $source, BoardColumn $target): mixed
    {
        if (! in_array($source->type, self::OPTION_TYPES, true)) {
            return $value;
        }

        $source_labels = $this->optionLabelsById($source);
        $target_ids_by_label = $this->optionIdsByLabel($target);

        $translate_one = function ($option_id) use ($source_labels, $target_ids_by_label) {
            $label = $source_labels[(string) $option_id] ?? null;

            return $label === null ? null : ($target_ids_by_label[mb_strtolower($label)] ?? null);
        };

        if (is_array($value)) {
            $mapped = array_values(array_filter(array_map($translate_one, $value), fn ($id) => $id !== null));

            return $mapped === [] ? null : $mapped;
        }

        return $translate_one($value);
    }

    /**
     * @return array<string, string> option id => label
     */
    private function optionLabelsById(BoardColumn $column): array
    {
        $labels = [];

        foreach ($column->config['options'] ?? [] as $option) {
            $labels[(string) $option['id']] = (string) $option['label'];
        }

        return $labels;
    }

    /**
     * @return array<string, string> lower-cased option label => option id
     */
    private function optionIdsByLabel(BoardColumn $column): array
    {
        $ids = [];

        foreach ($column->config['options'] ?? [] as $option) {
            if (($option['is_active'] ?? true) === false) {
                continue;
            }

            $ids[mb_strtolower((string) $option['label'])] = (string) $option['id'];
        }

        return $ids;
    }
}
