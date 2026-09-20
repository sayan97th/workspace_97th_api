<?php

namespace App\Rules;

use App\Models\BoardColumn;
use App\Support\FormulaReferences;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates the expression a Formula column saves. Evaluation happens in the
 * browser, so this only guards what the API can check without running it: the
 * expression is well formed, and every column it reads is a real, readable
 * column of the same tab and scope (a root item's formula reads root columns,
 * a subitem's reads subitem columns), never the formula's own column.
 */
class ValidFormulaExpression implements ValidationRule
{
    /**
     * Column types a formula can read. Mirrors `FORMULA_SOURCE_KINDS` in the
     * frontend's formulaEngine.ts; files, checklists, timelines, other
     * formulas and mirrors have no single value a formula could use.
     *
     * @var array<int, string>
     */
    public const SOURCE_TYPES = [
        BoardColumn::TYPE_TEXT,
        BoardColumn::TYPE_LONG_TEXT,
        BoardColumn::TYPE_PHONE,
        BoardColumn::TYPE_EMAIL,
        BoardColumn::TYPE_LINK,
        BoardColumn::TYPE_NUMBER,
        BoardColumn::TYPE_STATUS,
        BoardColumn::TYPE_LABEL,
        BoardColumn::TYPE_DROPDOWN,
        BoardColumn::TYPE_TAGS,
        BoardColumn::TYPE_DATE,
        BoardColumn::TYPE_CHECKBOX,
        BoardColumn::TYPE_RATING,
        BoardColumn::TYPE_PROGRESS,
        BoardColumn::TYPE_AUTO_NUMBER,
        BoardColumn::TYPE_PEOPLE,
        BoardColumn::TYPE_VOTE,
    ];

    /**
     * @param  int|null  $board_view_id  The tab the formula column lives in.
     * @param  string  $scope  The formula column's own scope (`item` or `subitem`).
     * @param  int|null  $own_column_id  The formula column itself, when it already exists.
     */
    public function __construct(
        private readonly ?int $board_view_id,
        private readonly string $scope,
        private readonly ?int $own_column_id = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('The :attribute must be text.'));

            return;
        }

        $problem = FormulaReferences::structureProblem($value);
        if ($problem !== null) {
            $fail(__('The :attribute '.$problem));

            return;
        }

        $ids = FormulaReferences::columnIds($value);
        if ($ids === []) {
            return;
        }

        if ($this->own_column_id !== null && in_array($this->own_column_id, $ids, true)) {
            $fail(__('The :attribute cannot reference its own column.'));

            return;
        }

        $types_by_id = $this->board_view_id === null
            ? []
            : BoardColumn::where('board_view_id', $this->board_view_id)
                ->where('scope', $this->scope)
                ->whereIn('id', $ids)
                ->pluck('type', 'id');

        foreach ($ids as $id) {
            $type = $types_by_id[$id] ?? null;

            if ($type === null) {
                $fail(__('The :attribute references a column that does not exist in this table.'));

                return;
            }

            if (! in_array($type, self::SOURCE_TYPES, true)) {
                $fail(__('The :attribute references a column whose type cannot be used in a formula.'));

                return;
            }
        }
    }
}
