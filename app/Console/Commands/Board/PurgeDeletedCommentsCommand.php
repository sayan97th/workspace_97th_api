<?php

namespace App\Console\Commands\Board;

use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Services\Board\CommentThreadActionsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

// php artisan comments:purge-deleted
class PurgeDeletedCommentsCommand extends Command
{
    protected $signature = 'comments:purge-deleted';

    protected $description = 'Permanently remove comments deleted more than the undo window ago, together with their attachment files';

    public function handle(): int
    {
        $cutoff = now()->subDays(CommentThreadActionsService::UNDO_WINDOW_DAYS);
        $purged = 0;

        foreach ([BoardItemComment::class, BoardComment::class] as $model_class) {
            $model_class::onlyTrashed()
                ->where('deleted_at', '<', $cutoff)
                ->with('attachments')
                ->chunkById(100, function ($comments) use ($model_class, &$purged) {
                    foreach ($comments as $comment) {
                        // Replies go with their update (the database cascades the rows), so their files have to go too.
                        $replies = $model_class::withTrashed()->where('parent_id', $comment->id)->with('attachments')->get();

                        foreach ($replies->concat([$comment]) as $doomed) {
                            $this->deleteFiles($doomed);
                        }

                        $comment->forceDelete();
                        $purged++;
                    }
                });
        }

        $this->components->info("Purged {$purged} deleted comment(s).");

        return self::SUCCESS;
    }

    /**
     * Removes every attachment file of the comment from the app disk, skipping
     * files that are already gone.
     */
    private function deleteFiles(BoardItemComment|BoardComment $comment): void
    {
        $disk = Storage::disk(config('filesystems.app_disk'));

        foreach ($comment->attachments as $attachment) {
            if ($disk->exists($attachment->file_path)) {
                $disk->delete($attachment->file_path);
            }
        }
    }
}
