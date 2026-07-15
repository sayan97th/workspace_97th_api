<?php

namespace App\Http\Resources;

use App\Models\WorkspaceNavigationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single board resolved by its globally-unique id. Extends the plain
 * navigation-item payload with the owning workspace and the ancestor trail,
 * since a `/boards/{id}` client only ever knows the id — not the workspace
 * slug or the path of slugs leading to it.
 *
 * @mixin WorkspaceNavigationItem
 */
class BoardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $node = (new WorkspaceNavigationItemResource($this->resource))->toArray($request);

        return array_merge($node, [
            'workspace' => [
                'id' => $this->workspace->id,
                'slug' => $this->workspace->slug,
                'name' => $this->workspace->name,
            ],
            'breadcrumb' => $this->ancestors()->map(fn (WorkspaceNavigationItem $ancestor) => [
                'id' => $ancestor->id,
                'label' => $ancestor->label,
                'slug' => $ancestor->slug,
            ])->values(),
        ]);
    }
}
