<?php

use App\Services\Search\Everything\PlainText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PREVIEW_MAX_LENGTH = 280;

    /**
     * Makes a notification a self-contained record, the way monday.com stores
     * one: a target (`post` for an update or reply, `project` for an item or
     * board, monday's own `target_type` values) plus the text the bell shows,
     * kept on the row instead of joined at read time. `preview` is the message
     * of the update, `item_name` and `board_name` what the card names. Existing
     * rows are backfilled from their comment, item and board.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('target_type', 16)->nullable()->after('comment_kind');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->text('preview')->nullable()->after('action_target');
            $table->string('item_name')->nullable()->after('preview');
            $table->string('board_name')->nullable()->after('item_name');
            $table->index(['target_type', 'target_id']);
        });

        DB::table('notifications')->orderBy('id')->chunkById(500, function ($notifications) {
            foreach ($notifications as $notification) {
                DB::table('notifications')->where('id', $notification->id)->update($this->snapshotOf($notification));
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropColumn(['target_type', 'target_id', 'preview', 'item_name', 'board_name']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(object $notification): array
    {
        $comment_table = match ($notification->comment_kind) {
            'item' => 'board_item_comments',
            'board' => 'board_comments',
            default => null,
        };
        $body = $comment_table !== null && $notification->comment_id !== null
            ? DB::table($comment_table)->where('id', $notification->comment_id)->whereNull('deleted_at')->value('body')
            : null;
        $preview = PlainText::fromMarkdown($body);

        [$target_type, $target_id] = match (true) {
            $comment_table !== null && $notification->comment_id !== null => ['post', $notification->comment_id],
            $notification->board_item_id !== null => ['project', $notification->board_item_id],
            $notification->board_id !== null => ['project', $notification->board_id],
            default => [null, null],
        };

        return [
            'target_type' => $target_type,
            'target_id' => $target_id,
            'preview' => $preview === '' ? null : Str::limit($preview, self::PREVIEW_MAX_LENGTH),
            'item_name' => $notification->board_item_id !== null
                ? DB::table('board_items')->where('id', $notification->board_item_id)->value('name')
                : null,
            'board_name' => $notification->board_id !== null
                ? DB::table('workspace_navigation_items')->where('id', $notification->board_id)->value('label')
                : null,
        ];
    }
};
