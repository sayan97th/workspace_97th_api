<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Maps an app user to their Slack member id, so a notification for that user can be
 * delivered as a direct message from the app's bot. See {@see SlackInstallation}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $slack_installation_id
 * @property string $slack_user_id
 * @property string|null $slack_display_name
 * @property Carbon|null $linked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read SlackInstallation $installation
 */
#[Fillable(['user_id', 'slack_installation_id', 'slack_user_id', 'slack_display_name', 'linked_at'])]
class SlackUserLink extends Model
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
            'linked_at' => 'datetime',
        ];
    }
}
