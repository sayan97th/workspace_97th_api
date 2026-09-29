<?php

namespace App\Http\Resources;

use App\Models\AccountAutomationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountAutomationTemplate
 */
class AccountAutomationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'definition' => $this->definition,
            'column_kinds' => $this->column_kinds ?? (object) [],
            'created_at' => $this->created_at,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->full_name,
            ] : null),
        ];
    }
}
