<?php

use App\Services\Search\Everything\PlainText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMMENT_TABLES = ['board_item_comments', 'board_comments'];

    /**
     * Stores the data monday.com keeps for an update and for a notification,
     * so the Update feed and the bell render them like monday does.
     *
     * Updates (both comment tables), after monday's `Update` object:
     * `sender_type` (`user`, or `automations` for an update an automation
     * posted, which monday credits to "Automations"), `automation_id` (the
     * automation behind it) and `text_body` (the plain text body next to the
     * formatted `body`).
     *
     * Notifications, after monday's `Notification` object: `title` (the
     * sentence the bell shows), `group_id`/`group_name` (the board > group >
     * item trail) and `thread_id` (the top level update a reply belongs to,
     * monday's `update` link). Existing rows are backfilled.
     */
    public function up(): void
    {
        foreach (self::COMMENT_TABLES as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->string('sender_type', 16)->default('user')->after('user_id');
                $table->foreignId('automation_id')->nullable()->after('sender_type')->constrained('board_automations')->nullOnDelete();
                $table->text('text_body')->nullable()->after('body');
            });

            DB::table($table_name)->select(['id', 'body'])->orderBy('id')->chunkById(500, function ($comments) use ($table_name) {
                foreach ($comments as $comment) {
                    $text = PlainText::fromMarkdown($comment->body);
                    DB::table($table_name)->where('id', $comment->id)->update(['text_body' => $text === '' ? null : $text]);
                }
            });
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('title', 500)->nullable()->after('type');
            $table->unsignedBigInteger('group_id')->nullable()->after('board_item_id');
            $table->string('group_name')->nullable()->after('board_name');
            $table->unsignedBigInteger('thread_id')->nullable()->after('comment_kind');
        });

        DB::table('notifications')->orderBy('id')->chunkById(500, function ($notifications) {
            foreach ($notifications as $notification) {
                DB::table('notifications')->where('id', $notification->id)->update($this->notificationSnapshot($notification));
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['title', 'group_id', 'group_name', 'thread_id']);
        });

        foreach (self::COMMENT_TABLES as $table_name) {
            Schema::table($table_name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('automation_id');
                $table->dropColumn(['sender_type', 'text_body']);
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationSnapshot(object $notification): array
    {
        $group = $notification->board_item_id !== null
            ? DB::table('board_items')
                ->join('board_groups', 'board_groups.id', '=', 'board_items.group_id')
                ->where('board_items.id', $notification->board_item_id)
                ->first(['board_groups.id', 'board_groups.name'])
            : null;

        $comment_table = match ($notification->comment_kind) {
            'item' => 'board_item_comments',
            'board' => 'board_comments',
            default => null,
        };
        $parent_id = $comment_table !== null && $notification->comment_id !== null
            ? DB::table($comment_table)->where('id', $notification->comment_id)->value('parent_id')
            : null;

        $actor = $notification->sender_type !== 'automations' && $notification->actor_id !== null
            ? DB::table('users')->where('id', $notification->actor_id)->first(['first_name', 'last_name'])
            : null;
        $sender_name = $notification->sender_type === 'automations'
            ? 'Automations'
            : ($actor !== null ? trim("{$actor->first_name} {$actor->last_name}") : null);
        $title = trim(implode(' ', array_filter([$sender_name ?: 'Deleted user', $notification->action_label, $notification->action_target])));

        return [
            'title' => mb_substr($title, 0, 500),
            'group_id' => $group?->id,
            'group_name' => $group?->name,
            'thread_id' => $notification->comment_id !== null ? ($parent_id ?? $notification->comment_id) : null,
        ];
    }
};
