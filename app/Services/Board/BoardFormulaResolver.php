<?php

namespace App\Services\Board;

use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Support\Formula\FormulaEngine;
use Illuminate\Support\Collection;

/**
 * Computes a Formula column's result for one item on the server, with {@see FormulaEngine}, the
 * same way the table computes it in the browser. Automations use it to read a formula in a
 * condition and to copy its result into another column.
 *
 * A formula saved before expressions existed (`operation` plus `source_column_ids`) is read as the
 * equivalent expression, like the frontend's `legacyFormulaToExpression()`.
 */
class BoardFormulaResolver
{
    private const LEGACY_OPERATORS = ['sum' => ' + ', 'subtract' => ' - ', 'multiply' => ' * ', 'divide' => ' / '];

    /** @var array<string, array<string, array{kind: string, title: string, options: array<string, string>}>> view id and scope => sources */
    private array $sources_cache = [];

    /**
     * The formula's result for the item, or null when the column has no formula or it errors.
     *
     * @return array{ok: true, value: mixed, text: string}|array{ok: false, code: string, message: string}|null
     */
    public function outcome(BoardColumn $column, BoardItem $item): ?array
    {
        $expression = self::expression($column);
        if ($expression === null) {
            return null;
        }

        $values = [];
        foreach ($item->relationLoaded('values') ? $item->values : $item->values()->get() as $value) {
            $values[(string) $value->column_id] = $value->value;
        }

        return (new FormulaEngine($this->sources($column), $values, (string) $item->name))->run($expression);
    }

    /**
     * The result as text, the way the cell shows it, empty when there is none.
     */
    public function text(BoardColumn $column, BoardItem $item): string
    {
        $outcome = $this->outcome($column, $item);

        return $outcome !== null && $outcome['ok'] ? $outcome['text'] : '';
    }

    /**
     * The saved expression, or the legacy fixed operation turned into one.
     */
    public static function expression(BoardColumn $column): ?string
    {
        $config = (array) ($column->config ?? []);
        $expression = trim((string) ($config['expression'] ?? ''));
        if ($expression !== '') {
            return $expression;
        }

        $references = array_map(fn ($id) => '{#'.$id.'}', (array) ($config['source_column_ids'] ?? []));
        $operation = $config['operation'] ?? null;
        if (! $operation || $references === []) {
            return null;
        }

        return $operation === 'concat' ? implode(' & " " & ', $references) : implode(self::LEGACY_OPERATORS[$operation] ?? ' + ', $references);
    }

    /**
     * The columns a formula of this tab and scope can read, keyed by id, with the item name.
     *
     * @return array<string, array{kind: string, title: string, options: array<string, string>}>
     */
    private function sources(BoardColumn $column): array
    {
        $key = $column->board_view_id.':'.$column->scope;
        if (isset($this->sources_cache[$key])) {
            return $this->sources_cache[$key];
        }

        /** @var Collection<int, BoardColumn> $columns */
        $columns = BoardColumn::where('board_view_id', $column->board_view_id)
            ->where('scope', $column->scope)
            ->whereIn('type', FormulaEngine::SOURCE_TYPES)
            ->get(['id', 'type', 'label', 'config']);

        $sources = [FormulaEngine::NAME_COLUMN => ['kind' => 'text', 'title' => 'Item', 'options' => []]];
        foreach ($columns as $source) {
            $options = [];
            foreach ((array) ($source->config['options'] ?? []) as $option) {
                if (is_array($option) && isset($option['id'])) {
                    $options[(string) $option['id']] = (string) ($option['label'] ?? '');
                }
            }
            $sources[(string) $source->id] = ['kind' => $source->type, 'title' => (string) $source->label, 'options' => $options];
        }

        return $this->sources_cache[$key] = $sources;
    }
}
