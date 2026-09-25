<?php

namespace App\Services\Favorite;

use App\Models\User;
use App\Models\UserFavoriteItem;

/**
 * Personal favorites (starred boards and folders). Registered as a scoped
 * singleton so the navigation tree resources ask the database once per
 * request, not once per node.
 */
class UserFavoriteService
{
    /** @var array<int, array<int, true>> */
    private array $ids_by_user = [];

    /**
     * Whether `$user` starred the navigation item `$item_id`.
     */
    public function isFavorite(int $item_id, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $this->ids_by_user[$user->id] ??= UserFavoriteItem::query()
            ->where('user_id', $user->id)
            ->pluck('navigation_item_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        return isset($this->ids_by_user[$user->id][$item_id]);
    }

    /**
     * Stars or unstars `$item_id` for `$user`. A new favorite goes to the end
     * of the user's list.
     */
    public function set(User $user, int $item_id, bool $is_favorite): void
    {
        unset($this->ids_by_user[$user->id]);

        if (! $is_favorite) {
            UserFavoriteItem::query()->where('user_id', $user->id)->where('navigation_item_id', $item_id)->delete();

            return;
        }

        $next_position = (int) UserFavoriteItem::query()->where('user_id', $user->id)->max('position') + 1;

        UserFavoriteItem::query()->firstOrCreate(
            ['user_id' => $user->id, 'navigation_item_id' => $item_id],
            ['position' => $next_position]
        );
    }
}
