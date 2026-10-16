<?php

namespace App\Services\Board;

use App\Http\Controllers\Board\BoardCommentController;
use App\Http\Controllers\Board\BoardItemCommentController;
use App\Models\BoardComment;
use App\Models\BoardItemComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores and removes the files attached to an item update
 * ({@see BoardItemCommentController}) or a board discussion update
 * ({@see BoardCommentController}), both when the update is posted and when
 * its author edits it later.
 */
class CommentAttachmentService
{
    public function __construct(
        private readonly CommentThreadActionsService $comment_actions,
    ) {}

    /**
     * Applies an author's edit in one go: the new body (the old one is kept
     * as a revision by {@see CommentThreadActionsService::editBody()}), the
     * attachments they took off and the files they added. Changing only the
     * files still counts as an edit, so the "(edited)" marker shows. Fails
     * with a `body` validation error when the update would be left with
     * neither text nor files.
     *
     * @param  array<int, UploadedFile>  $new_files
     * @param  Collection<int, int|string>  $removed_attachment_ids
     */
    public function editComment(
        BoardItemComment|BoardComment $comment,
        string $body,
        array $new_files,
        Collection $removed_attachment_ids,
        string $directory,
        User $editor,
    ): void {
        $removed_attachment_ids = $removed_attachment_ids->map(fn ($id) => (int) $id)->unique()->values();
        $remaining_count = $comment->attachments()->whereNotIn('id', $removed_attachment_ids->all())->count();

        if ($body === '' && $remaining_count === 0 && count($new_files) === 0) {
            throw ValidationException::withMessages([
                'body' => 'An update needs some text or at least one file.',
            ]);
        }

        DB::transaction(function () use ($comment, $body, $new_files, $removed_attachment_ids, $directory, $editor) {
            $is_body_changed = $comment->body !== $body;
            $this->comment_actions->editBody($comment, $body, $editor);

            $removed_count = $this->removeByIds($comment, $removed_attachment_ids);
            $this->storeFiles($comment, $new_files, $directory, $editor);

            if (! $is_body_changed && ($removed_count > 0 || count($new_files) > 0)) {
                $comment->update(['edited_at' => now()]);
            }
        });
    }

    /**
     * Uploads `$files` under `$directory` on the app disk, one
     * `Str::uuid()` named file each, and records them on `$comment`.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function storeFiles(BoardItemComment|BoardComment $comment, array $files, string $directory, ?User $uploader): void
    {
        foreach ($files as $file) {
            $extension = $file->getClientOriginalExtension();
            $path = $file->storeAs($directory, Str::uuid().'.'.$extension, config('filesystems.app_disk'));

            $comment->attachments()->create([
                'uploaded_by_id' => $uploader?->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'extension' => $extension,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize() ?? 0,
            ]);
        }
    }

    /**
     * Removes the attachments of `$comment` whose ids are in
     * `$attachment_ids` (ids of other comments are ignored) and deletes their
     * files once the surrounding transaction commits, so a rolled back edit
     * never loses a file. Returns how many were removed.
     *
     * @param  Collection<int, int>  $attachment_ids
     */
    public function removeByIds(BoardItemComment|BoardComment $comment, Collection $attachment_ids): int
    {
        if ($attachment_ids->isEmpty()) {
            return 0;
        }

        $attachments = $comment->attachments()->whereIn('id', $attachment_ids->all())->get();
        if ($attachments->isEmpty()) {
            return 0;
        }

        $file_paths = $attachments->pluck('file_path')->all();
        $comment->attachments()->whereIn('id', $attachments->modelKeys())->delete();

        DB::afterCommit(function () use ($file_paths) {
            $disk = Storage::disk(config('filesystems.app_disk'));
            foreach ($file_paths as $file_path) {
                if ($disk->exists($file_path)) {
                    $disk->delete($file_path);
                }
            }
        });

        return $attachments->count();
    }
}
