<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Case-insensitive "contains" filters built on LIKE, portable across MySQL and SQLite. `%`, `_`
 * and the escape character inside a term are escaped so they match literally.
 */
final class LikeTerm
{
    /** Escape character used in every pattern. */
    public const ESCAPE = '!';

    /**
     * The term escaped for use inside a LIKE pattern.
     */
    public static function escape(string $term): string
    {
        return strtr($term, [
            self::ESCAPE => self::ESCAPE.self::ESCAPE,
            '%' => self::ESCAPE.'%',
            '_' => self::ESCAPE.'_',
        ]);
    }

    /**
     * Narrows `$query` to rows where any of `$columns` contains `$term`. An empty term keeps
     * every row.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @param  array<int, string>  $columns
     * @return TBuilder
     */
    public static function whereContains(BuilderContract $query, array $columns, string $term): BuilderContract
    {
        if ($term === '') {
            return $query;
        }

        $pattern = '%'.self::escape($term).'%';

        return $query->where(function ($inner) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $inner->orWhereRaw("{$column} LIKE ? ESCAPE '".self::ESCAPE."'", [$pattern]);
            }
        });
    }

    /**
     * Orders rows whose `$column` starts with `$term` first, the "relevance" boost every search
     * tab shares. No-op for an empty term.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function orderByPrefix(BuilderContract $query, string $column, string $term): BuilderContract
    {
        if ($term === '') {
            return $query;
        }

        return $query->orderByRaw(
            "CASE WHEN {$column} LIKE ? ESCAPE '".self::ESCAPE."' THEN 0 ELSE 1 END",
            [self::escape($term).'%']
        );
    }
}
