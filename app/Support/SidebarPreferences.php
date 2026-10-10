<?php

namespace App\Support;

/**
 * Shape and defaults of `users.sidebar_preferences`, the personal layout of
 * the workspace sidebar:
 *
 * - `sections`: every personal section of the app rail (Home, My work, Favorites,
 *   Automations, Recent) in the order the user dragged them to, each one shown in the
 *   rail or hidden in its "More" menu.
 * - `collapsed_sections`: the collapsible sections the user folded, plus the
 *   Favorites workspace groups, stored as `favorites_workspace:{id}`.
 *
 * Stored values are always read through {@see normalize()}, so a section
 * added in a later release shows up for users who saved an older layout.
 */
final class SidebarPreferences
{
    public const SECTION_KEYS = ['home', 'my_work', 'favorites', 'automations', 'recent'];

    /** Sections that start out in the rail's "More" menu until the user turns them on. */
    public const DEFAULT_HIDDEN_SECTION_KEYS = ['recent'];

    public const COLLAPSED_SECTION_PATTERN = '/^(favorites|recent|favorites_workspace:\d+)$/';

    public const MAX_COLLAPSED_SECTIONS = 200;

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array{sections: array<int, array{key: string, is_visible: bool}>, collapsed_sections: array<int, string>}
     */
    public static function normalize(?array $stored): array
    {
        $sections = [];
        $seen = [];

        foreach ($stored['sections'] ?? [] as $section) {
            $key = $section['key'] ?? null;
            if (! in_array($key, self::SECTION_KEYS, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sections[] = ['key' => $key, 'is_visible' => (bool) ($section['is_visible'] ?? true)];
        }

        foreach (self::SECTION_KEYS as $key) {
            if (! isset($seen[$key])) {
                $sections[] = ['key' => $key, 'is_visible' => ! in_array($key, self::DEFAULT_HIDDEN_SECTION_KEYS, true)];
            }
        }

        $collapsed_sections = collect($stored['collapsed_sections'] ?? [])
            ->filter(fn ($value) => is_string($value) && preg_match(self::COLLAPSED_SECTION_PATTERN, $value))
            ->unique()
            ->take(self::MAX_COLLAPSED_SECTIONS)
            ->values()
            ->all();

        return ['sections' => $sections, 'collapsed_sections' => $collapsed_sections];
    }
}
