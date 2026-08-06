<?php

namespace App\Http\Resources;

use App\Models\BoardViewFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file uploaded to a `file_gallery`-type board view.
 *
 * @mixin BoardViewFile
 */
class BoardViewFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_view_id' => $this->board_view_id,
            'file_name' => $this->file_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'is_image' => $this->is_image,
            'download_url' => $this->download_url,
            'created_at' => $this->created_at,
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->id,
                'full_name' => $this->uploader->full_name,
                'profile_photo_url' => $this->uploader->profile_photo_url,
            ] : null),
        ];
    }
}
