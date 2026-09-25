<?php

namespace App\Http\Resources;

use App\Models\UserProfileField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserProfileField
 */
class UserProfileFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'options' => $this->type === UserProfileField::TYPE_DROPDOWN ? array_values($this->options ?? []) : [],
            'position' => $this->position,
            'values_count' => $this->whenCounted('values'),
        ];
    }
}
