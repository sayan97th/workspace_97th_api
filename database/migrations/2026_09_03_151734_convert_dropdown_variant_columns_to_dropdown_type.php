<?php

use App\Models\BoardColumn;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Dropdown" started out as a `tags`-typed column flagged with a config
     * `variant` (see the prior migration and `BoardColumnConfig` on the
     * frontend), so its cell picker could differ from an ordinary Tags
     * column's without a schema change. `BoardColumn::TYPE_DROPDOWN` now
     * exists as its own first-class type — every column round-trips through
     * the API with its real type instead of `tags` plus a config flag, so
     * this converts every column that flag was already marking, and drops
     * the flag (no longer meaningful once the type itself says "dropdown").
     */
    public function up(): void
    {
        BoardColumn::query()
            ->where('type', BoardColumn::TYPE_TAGS)
            ->get()
            ->each(function (BoardColumn $column) {
                if (data_get($column->config, 'variant') !== 'dropdown') {
                    return;
                }

                $config = $column->config;
                unset($config['variant']);
                $column->type = BoardColumn::TYPE_DROPDOWN;
                $column->config = $config ?: null;
                $column->save();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        BoardColumn::query()
            ->where('type', BoardColumn::TYPE_DROPDOWN)
            ->get()
            ->each(function (BoardColumn $column) {
                $column->type = BoardColumn::TYPE_TAGS;
                $column->config = [...($column->config ?? []), 'variant' => 'dropdown'];
                $column->save();
            });
    }
};
