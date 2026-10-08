<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's Slack account, connected from the Automations center ("Connect your Slack account").
 * Slack channel automations store the connection they were created with in
 * `slack_connection_id` and post through its workspace, see {@see SlackInstallation}.
 *
 * Unlike {@see SlackUserLink}, which only says who a member is in Slack so notifications reach
 * them, a connection is something the member authorized themselves and can pick again when
 * creating the next Slack automation.
 *
 * @property int $id
 * @property int $user_id
 * @property int $slack_installation_id
 * @property string|null $slack_user_id
 * @property string|null $slack_user_name
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read SlackInstallation $installation
 */
#[Fillable(['user_id', 'slack_installation_id', 'slack_user_id', 'slack_user_name', 'connected_at', 'last_used_at'])]
class SlackConnection extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SlackInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(SlackInstallation::class, 'slack_installation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
