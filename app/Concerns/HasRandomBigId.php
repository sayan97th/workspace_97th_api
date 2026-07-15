<?php

namespace App\Concerns;

/**
 * Assigns the model's primary key a random 10-digit integer instead of a
 * sequential one, so ids don't reveal creation order or row count (e.g. a
 * board id reads like `2257885322`, not `62`). The model must declare
 * `$incrementing = false` and `protected $keyType = 'int'` — this trait only
 * supplies the value, it doesn't change how Eloquent treats the key.
 *
 * Ids are checked against the table (including soft-deleted rows, when the
 * model supports them) before use, so a value is never reused even after a
 * record is trashed.
 */
trait HasRandomBigId
{
    /**
     * The inclusive range random ids are drawn from. Ten digits, matching
     * the format already in use for board ids.
     */
    private const MIN_ID = 1_000_000_000;

    private const MAX_ID = 9_999_999_999;

    protected static function bootHasRandomBigId(): void
    {
        static::creating(function ($model) {
            if ($model->getKey() === null) {
                $model->setAttribute($model->getKeyName(), static::generateUniqueRandomId());
            }
        });
    }

    /**
     * Draw random ids until one isn't already taken. The id space is ~9
     * billion wide, so collisions are rare enough that a retry loop is
     * simpler and cheap enough — no need for a coordination service.
     */
    public static function generateUniqueRandomId(): int
    {
        $query = method_exists(static::class, 'withTrashed')
            ? static::withTrashed()
            : static::query();

        do {
            $candidate = random_int(self::MIN_ID, self::MAX_ID);
        } while ((clone $query)->whereKey($candidate)->exists());

        return $candidate;
    }
}
