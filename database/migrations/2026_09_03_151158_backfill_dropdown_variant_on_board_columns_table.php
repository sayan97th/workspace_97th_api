<?php

use App\Models\BoardColumn;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every `tags`-typed column labeled "Dropdown" predates the config
     * `variant` flag the Table view now uses to tell a "Dropdown" column
     * (a chip multi-select with no search box) apart from an ordinary
     * free-form Tags column — see `BoardColumnConfig` on the frontend and
     * `toTableColumnDef` in `TableBoardView.tsx`. Without this backfill,
     * every "Dropdown" column created before that change keeps rendering
     * with the Tags cell's search-driven picker instead. Matching on the
     * literal label is a one-time heuristic safe for existing data: the
     * label is exactly what both the "New board" seeder (`BoardViewController`)
     * and the Table view's own "+" gallery entry write for a column created
     * as "Dropdown", and nothing else in the codebase produces a `tags`
     * column under that name.
     */
    public function up(): void
    {
        BoardColumn::query()
            ->where('type', BoardColumn::TYPE_TAGS)
            ->where('label', 'Dropdown')
            ->get()
            ->each(function (BoardColumn $column) {
                if (data_get($column->config, 'variant') === 'dropdown') {
                    return;
                }

                $column->config = [...($column->config ?? []), 'variant' => 'dropdown'];
                $column->save();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        BoardColumn::query()
            ->where('type', BoardColumn::TYPE_TAGS)
            ->where('label', 'Dropdown')
            ->get()
            ->each(function (BoardColumn $column) {
                if (! is_array($column->config) || ! array_key_exists('variant', $column->config)) {
                    return;
                }

                $config = $column->config;
                unset($config['variant']);
                $column->config = $config;
                $column->save();
            });
    }
};
