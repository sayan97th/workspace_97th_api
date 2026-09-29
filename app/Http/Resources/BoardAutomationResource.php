<?php

namespace App\Http\Resources;

use App\Models\BoardAutomation;
use App\Services\Board\BoardAutomationHealthChecker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin BoardAutomation
 */
class BoardAutomationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'board_view_id' => $this->board_view_id,
            'name' => $this->name,
            'description' => $this->description,
            'is_enabled' => $this->is_enabled,
            'importance' => $this->importance ?? BoardAutomation::IMPORTANCE_MINOR,
            'trigger_type' => $this->trigger_type,
            'trigger_column_id' => $this->trigger_column_id,
            'trigger_value' => $this->trigger_value,
            'trigger_config' => $this->trigger_config ?? (object) [],
            'conditions' => $this->conditions ?? [],
            'condition_operator' => $this->condition_operator ?: 'and',
            'condition_groups' => $this->condition_groups ?? [],
            'else_actions' => $this->resource->resolvedElseActions(),
            'failure_alert' => $this->failure_alert ?: BoardAutomation::FAILURE_ALERT_APP,
            'action_type' => $this->action_type,
            'action_params' => $this->action_params,
            'actions' => $this->resource->resolvedActions(),
            // Only a "When a webhook is received" automation has one, the secret URL to post to.
            'webhook_url' => $this->resource->webhookUrl(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            // How many runs in a row failed, reset by a run that works. The board pauses it at its threshold.
            'consecutive_failures' => (int) ($this->consecutive_failures ?? 0),
            'last_failed_at' => $this->last_failed_at?->toIso8601String(),
            'paused_reason' => $this->paused_reason,
            // What it uses that no longer exists, each with the sentence part it belongs to.
            'problems' => app(BoardAutomationHealthChecker::class)->problems($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Filled in by `BoardAutomationController`, which loads the run stats and the creator.
            'run_count' => (int) ($this->run_logs_count ?? 0),
            'version_count' => (int) ($this->versions_count ?? 0),
            'last_run_at' => $this->run_logs_max_created_at ? Carbon::parse($this->run_logs_max_created_at)->toIso8601String() : null,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->full_name,
            ] : null),
            // Whoever answers for the automation, the creator until ownership is transferred.
            'owner' => $this->whenLoaded('owner', fn () => ($this->owner ?? $this->creator) ? [
                'id' => ($this->owner ?? $this->creator)->id,
                'name' => ($this->owner ?? $this->creator)->full_name,
            ] : null),
        ];
    }
}
