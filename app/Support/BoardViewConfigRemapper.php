<?php

namespace App\Support;

use App\Services\Board\BoardDuplicationService;
use App\Services\Board\BoardTemplateService;

/**
 * Chart, Workload and Dashboard tabs keep their settings as JSON that names
 * another tab and that tab's columns by id. When a board is copied (a
 * duplicate, or a board created from a template) those ids must point at the
 * copies, which is what these helpers do. Ids with no copy are left as they
 * are, so a setting that pointed at something outside the copied board keeps
 * working. Used by {@see BoardDuplicationService} and {@see BoardTemplateService}.
 */
class BoardViewConfigRemapper
{
    /** Column id keys a Dashboard widget's `config` may hold. */
    private const WIDGET_COLUMN_KEYS = [
        'column_id',
        'status_column_id',
        'people_column_id',
        'date_column_id',
        'effort_column_id',
        'group_by_column_id',
        'split_by_column_id',
        'value_column_id',
    ];

    /**
     * @param  array<string, mixed>|null  $config
     * @param  array<int, int>  $view_id_map
     * @param  array<int, int>  $column_id_map
     * @return array<string, mixed>|null
     */
    public static function chart(?array $config, array $view_id_map, array $column_id_map): ?array
    {
        if ($config === null) {
            return null;
        }

        $config['source_view_id'] = self::mapInt($config['source_view_id'] ?? null, $view_id_map);
        foreach (['group_by_column_id', 'split_by_column_id', 'value_column_id'] as $key) {
            $config[$key] = self::mapString($config[$key] ?? null, $column_id_map);
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @param  array<int, int>  $view_id_map
     * @param  array<int, int>  $column_id_map
     * @return array<string, mixed>|null
     */
    public static function workload(?array $config, array $view_id_map, array $column_id_map): ?array
    {
        if ($config === null) {
            return null;
        }

        $config['source_view_id'] = self::mapInt($config['source_view_id'] ?? null, $view_id_map);
        foreach (['people_column_id', 'date_column_id', 'effort_column_id'] as $key) {
            $config[$key] = self::mapString($config[$key] ?? null, $column_id_map);
        }

        return $config;
    }

    /**
     * Only widgets reading the copied board itself (`source_board_id` null or
     * the source board's id) are remapped, a widget reading another board is
     * left untouched.
     *
     * @param  array<string, mixed>|null  $config
     * @param  array<int, int>  $view_id_map
     * @param  array<int, int>  $column_id_map
     * @return array<string, mixed>|null
     */
    public static function dashboard(?array $config, int $source_board_id, int $target_board_id, array $view_id_map, array $column_id_map): ?array
    {
        if ($config === null || ! isset($config['widgets']) || ! is_array($config['widgets'])) {
            return $config;
        }

        $config['widgets'] = array_map(function ($widget) use ($source_board_id, $target_board_id, $view_id_map, $column_id_map) {
            if (! is_array($widget)) {
                return $widget;
            }

            $widget_board_id = isset($widget['source_board_id']) ? (int) $widget['source_board_id'] : null;
            if ($widget_board_id !== null && $widget_board_id !== $source_board_id) {
                return $widget;
            }

            if ($widget_board_id !== null) {
                $widget['source_board_id'] = $target_board_id;
            }
            $widget['source_view_id'] = self::mapInt($widget['source_view_id'] ?? null, $view_id_map);

            $settings = is_array($widget['config'] ?? null) ? $widget['config'] : [];
            foreach (self::WIDGET_COLUMN_KEYS as $key) {
                if (array_key_exists($key, $settings)) {
                    $settings[$key] = self::mapString($settings[$key], $column_id_map);
                }
            }
            if (isset($settings['column_ids']) && is_array($settings['column_ids'])) {
                $settings['column_ids'] = array_map(fn ($id) => self::mapString($id, $column_id_map), $settings['column_ids']);
            }
            $widget['config'] = $settings;

            return $widget;
        }, $config['widgets']);

        return $config;
    }

    /**
     * @param  array<int, int>  $map
     */
    private static function mapInt(mixed $id, array $map): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $map[(int) $id] ?? (int) $id;
    }

    /**
     * Column ids are stored as strings in these configs (a sentinel such as
     * `__group__` may sit in the same field), so only numeric ids are mapped.
     *
     * @param  array<int, int>  $map
     */
    private static function mapString(mixed $id, array $map): mixed
    {
        if (! is_numeric($id)) {
            return $id;
        }

        return isset($map[(int) $id]) ? (string) $map[(int) $id] : (string) $id;
    }
}
