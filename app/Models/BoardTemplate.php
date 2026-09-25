<?php

namespace App\Models;

use App\Services\Board\BoardTemplateService;
use App\Support\BuiltInBoardTemplates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A board someone saved as a template from the board menu ("Save as a
 * template"). `snapshot` is the board's structure (tabs, columns, groups)
 * and, when `includes_items` is set, its items too, in the format described
 * by {@see BoardTemplateService}. Built in templates are not stored here,
 * see {@see BuiltInBoardTemplates}.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $category
 * @property string|null $color
 * @property int|null $created_by_id
 * @property int|null $source_board_id
 * @property bool $includes_items
 * @property array<string, mixed> $snapshot
 * @property int $use_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
#[Fillable(['name', 'description', 'category', 'color', 'created_by_id', 'source_board_id', 'includes_items', 'snapshot', 'use_count'])]
class BoardTemplate extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'includes_items' => 'boolean',
            'snapshot' => 'array',
            'use_count' => 'integer',
        ];
    }
}
