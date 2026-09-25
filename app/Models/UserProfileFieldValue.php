<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user's value for one {@see UserProfileField}. Stored as text regardless of the field
 * type: numbers as their decimal string, dates as `Y-m-d`, dropdowns as the option id.
 *
 * @property int $id
 * @property int $user_id
 * @property int $field_id
 * @property string|null $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['user_id', 'field_id', 'value'])]
class UserProfileFieldValue extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserProfileField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(UserProfileField::class, 'field_id');
    }
}
