<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An account wide custom profile field defined in Administration > Customization >
 * Profile fields. Each user may hold one {@see UserProfileFieldValue} per field.
 *
 * @property int $id
 * @property string $name
 * @property string $type One of the TYPE_* constants.
 * @property array<int, array{id: string, label: string, color?: string|null}>|null $options Dropdown choices only.
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['name', 'type', 'options', 'position'])]
class UserProfileField extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_DROPDOWN = 'dropdown';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_DROPDOWN];

    /**
     * @return HasMany<UserProfileFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(UserProfileFieldValue::class, 'field_id');
    }

    /**
     * Ids of the dropdown choices, used to validate a submitted dropdown value.
     *
     * @return array<int, string>
     */
    public function optionIds(): array
    {
        return collect($this->options ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'position' => 'integer',
        ];
    }
}
