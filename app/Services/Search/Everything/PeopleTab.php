<?php

namespace App\Services\Search\Everything;

use App\Models\User;
use App\Support\LikeTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * "People": active accounts (deactivated and deleted ones are left out). Every word of the term
 * must match the first name, last name, email or job title, so "mike witham" finds Mike Witham.
 */
final class PeopleTab implements SearchTab
{
    public function count(SearchCriteria $criteria): int
    {
        return $this->query($criteria)->count();
    }

    public function results(SearchCriteria $criteria, int $offset, int $limit): array
    {
        $query = $this->query($criteria);
        $total = $query->clone()->count();

        if ($criteria->sortsByTime()) {
            $query->orderByDesc('created_at');
        } else {
            LikeTerm::orderByPrefix($query, 'first_name', $criteria->term);
        }

        $data = $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'job_title' => $user->job_title,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo_url' => $user->profile_photo_url,
            ])
            ->all();

        return ['data' => $data, 'total' => $total];
    }

    public function facets(SearchCriteria $criteria): array
    {
        return [];
    }

    /**
     * @return Builder<User>
     */
    private function query(SearchCriteria $criteria): Builder
    {
        $query = User::query()->where('is_active', true);

        foreach (preg_split('/\s+/u', $criteria->term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            LikeTerm::whereContains($query, ['first_name', 'last_name', 'email', 'job_title'], $word);
        }

        return $query;
    }
}
