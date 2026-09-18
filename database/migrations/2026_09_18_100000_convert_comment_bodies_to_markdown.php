<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use League\HTMLToMarkdown\HtmlConverter;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `board_item_comments.body` / `board_comments.body` used to be
     * `Purifier::clean()`-ed HTML (the composer serialized Tiptap's document
     * to HTML); the composer now stores Markdown directly. Backfills
     * whatever rows already exist so old comments keep rendering correctly
     * under `RichTextContent`'s new Markdown-based renderer instead of
     * showing their raw HTML tags as literal text.
     */
    public function up(): void
    {
        $converter = new HtmlConverter(['strip_tags' => true]);

        foreach (['board_item_comments', 'board_comments'] as $table) {
            DB::table($table)
                ->whereNotNull('body')
                ->where('body', 'like', '%<%')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($converter, $table) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['body' => trim($converter->convert($row->body))]);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     *
     * One-way data backfill — the original HTML isn't kept anywhere to
     * restore, so there's nothing to reverse.
     */
    public function down(): void {}
};
