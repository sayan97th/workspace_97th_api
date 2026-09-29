<?php

namespace App\Models;

use App\Support\PortableAutomationDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An automation template an administrator published for every board of the account, listed in
 * the Create tab's "Created by" category. `definition` is board agnostic (see
 * {@see PortableAutomationDefinition}): its column and group ids are cleared, and `column_kinds`
 * maps each cleared spot (`trigger_column_id`, `conditions.0.column_id`,
 * `actions.1.params.target_column_id`) to the `{type, scope}` of column it needs.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $definition
 * @property array<string, array{type: string, scope: string}>|null $column_kinds
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
#[Fillable(['name', 'description', 'definition', 'column_kinds', 'created_by_id'])]
class AccountAutomationTemplate extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'column_kinds' => 'array',
        ];
    }
}
