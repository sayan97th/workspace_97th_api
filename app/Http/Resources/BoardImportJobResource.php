<?php

namespace App\Http\Resources;

use App\Models\BoardImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BoardImportJob
 */
class BoardImportJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'file_name' => $this->file_name,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'percent' => $this->percent(),
            'created_count' => $this->created_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'columns_created' => $this->columns_created,
            'group_id' => $this->group_id,
            'cancel_requested' => $this->cancel_requested,
            'error_message' => $this->error_message,
        ];
    }
}
