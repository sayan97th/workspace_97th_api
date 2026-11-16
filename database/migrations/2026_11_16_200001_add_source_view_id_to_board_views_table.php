<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filter views: a tab whose `source_view_id` is set shows that tab's
     * items, groups and columns (normally the board's Main table) and keeps
     * only its own filter/sort/display settings, like monday.com board views
     * or Google Sheets filter views.
     *
     * Existing Table, Gantt and Calendar tabs that were added but never got
     * any content (no groups and no columns of their own) were created by the
     * old "+ Add view" flow, which left them blank. They are linked to their
     * board's primary tab so they show the board's data right away. Tabs that
     * already hold content of their own are left untouched.
     */
    public function up(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->unsignedBigInteger('source_view_id')->nullable()->after('view_type');
            $table->foreign('source_view_id')->references('id')->on('board_views')->nullOnDelete();
        });

        $empty_views = DB::table('board_views as view')
            ->join('board_views as primary_view', function ($join) {
                $join->on('primary_view.board_id', '=', 'view.board_id')
                    ->where('primary_view.is_primary', true);
            })
            ->where('view.is_primary', false)
            ->whereIn('view.view_type', ['table', 'gantt', 'calendar'])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('board_groups')->whereColumn('board_groups.board_view_id', 'view.id'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('board_columns')->whereColumn('board_columns.board_view_id', 'view.id'))
            ->get(['view.id as view_id', 'primary_view.id as primary_view_id']);

        foreach ($empty_views as $row) {
            DB::table('board_views')->where('id', $row->view_id)->update(['source_view_id' => $row->primary_view_id]);
        }
    }

    public function down(): void
    {
        Schema::table('board_views', function (Blueprint $table) {
            $table->dropForeign(['source_view_id']);
            $table->dropColumn('source_view_id');
        });
    }
};
