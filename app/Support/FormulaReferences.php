<?php

namespace App\Support;

/**
 * Reads the column references inside a Formula column's saved expression.
 *
 * A saved expression names columns by id, `{#12}`, so renaming a column never
 * breaks it (the editor shows and edits them by title, then converts back on
 * save). `{#__name}` is the row's own name. Text inside quotes is a literal
 * and is never scanned for references, mirroring the frontend's parser.
 *
 * This is deliberately not a full formula parser: the browser is the only
 * place expressions are evaluated. The API only needs enough structure to
 * refuse a malformed expression, find the columns it depends on, and rewrite
 * those ids when a board is duplicated.
 */
class FormulaReferences
{
    /** The pseudo column that reads the row's own name. */
    public const NAME_COLUMN = '__name';

    /**
     * The column ids (as ints) the expression reads, in first-seen order and
     * without repeats. The row-name pseudo column is not a real column, so it
     * is left out.
     *
     * @return array<int, int>
     */
    public static function columnIds(string $expression): array
    {
        $ids = [];
        foreach (self::scan($expression)['references'] as $reference) {
            if (ctype_digit($reference)) {
                $ids[(int) $reference] = (int) $reference;
            }
        }

        return array_values($ids);
    }

    /**
     * A message describing why the expression is structurally broken, or null
     * when it is well formed: every string closed, every parenthesis and
     * brace balanced, and every reference in the `{#id}` form.
     */
    public static function structureProblem(string $expression): ?string
    {
        $scan = self::scan($expression);

        if ($scan['problem'] !== null) {
            return $scan['problem'];
        }

        foreach ($scan['references'] as $reference) {
            if ($reference !== self::NAME_COLUMN && ! ctype_digit($reference)) {
                return 'must reference columns by id, like {#12}.';
            }
        }

        return null;
    }

    /**
     * Rewrites every `{#id}` reference through `$id_map` (old id => new id).
     * A reference to an id that is not in the map is kept as it was.
     *
     * @param  array<int, int>  $id_map
     */
    public static function remap(string $expression, array $id_map): string
    {
        $output = '';
        $length = strlen($expression);
        $index = 0;

        while ($index < $length) {
            $char = $expression[$index];

            if ($char === '"' || $char === "'") {
                $end = self::stringEnd($expression, $index);
                if ($end === -1) {
                    $output .= substr($expression, $index);
                    break;
                }
                $output .= substr($expression, $index, $end - $index);
                $index = $end;
            } elseif ($char === '{') {
                $close = strpos($expression, '}', $index);
                if ($close === false) {
                    $output .= substr($expression, $index);
                    break;
                }
                $inner = trim(substr($expression, $index + 1, $close - $index - 1));
                $old_id = str_starts_with($inner, '#') && ctype_digit(substr($inner, 1)) ? (int) substr($inner, 1) : null;
                $output .= $old_id !== null && isset($id_map[$old_id])
                    ? '{#'.$id_map[$old_id].'}'
                    : substr($expression, $index, $close - $index + 1);
                $index = $close + 1;
            } else {
                $output .= $char;
                $index++;
            }
        }

        return $output;
    }

    /**
     * @return array{references: array<int, string>, problem: string|null}
     */
    private static function scan(string $expression): array
    {
        $references = [];
        $depth = 0;
        $length = strlen($expression);
        $index = 0;

        while ($index < $length) {
            $char = $expression[$index];

            if ($char === '"' || $char === "'") {
                $end = self::stringEnd($expression, $index);
                if ($end === -1) {
                    return ['references' => $references, 'problem' => 'has text that is missing its closing quote.'];
                }
                $index = $end;

                continue;
            }

            if ($char === '{') {
                $close = strpos($expression, '}', $index);
                if ($close === false) {
                    return ['references' => $references, 'problem' => 'has a column reference that is missing its closing brace.'];
                }
                $inner = trim(substr($expression, $index + 1, $close - $index - 1));
                $references[] = str_starts_with($inner, '#') ? substr($inner, 1) : $inner;
                $index = $close + 1;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return ['references' => $references, 'problem' => 'has a closing parenthesis without an opening one.'];
                }
            }

            $index++;
        }

        return [
            'references' => $references,
            'problem' => $depth > 0 ? 'has a parenthesis that is never closed.' : null,
        ];
    }

    /**
     * The offset just past the string literal that opens at `$start`, or -1
     * when it never closes. A doubled quote inside the literal escapes it.
     */
    private static function stringEnd(string $expression, int $start): int
    {
        $quote = $expression[$start];
        $length = strlen($expression);
        $index = $start + 1;

        while ($index < $length) {
            if ($expression[$index] === $quote) {
                if (($expression[$index + 1] ?? null) === $quote) {
                    $index += 2;

                    continue;
                }

                return $index + 1;
            }
            $index++;
        }

        return -1;
    }
}
