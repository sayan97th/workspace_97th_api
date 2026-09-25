<?php

namespace App\Support\Admin;

use App\Models\User;
use App\Models\UserProfileField;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Builds the Administration > Users query from request filters, shared by the paginated
 * list, the CSV export and the bulk actions so all three always agree on "which users".
 *
 * Every filter accepts a single value or a comma separated list, so the older single value
 * callers (`role=admin`, `department=unassigned`) keep working unchanged.
 *
 * Supported query parameters:
 *  - `search`: name or email contains.
 *  - `type`: `staff` or `client` tier.
 *  - `role`: platform roles, any of.
 *  - `account_status`: `active`, `disabled`, `deleted`, any of.
 *  - `email_status`: `verified` or `unverified`.
 *  - `department`: department ids and/or `unassigned`, any of.
 *  - `last_active_from`, `last_active_to` (Y-m-d), `last_active_never=1`.
 *  - `created_from`, `created_to` (Y-m-d).
 *  - `fields[{id}][contains|min|max|from|to|in|empty]`: custom profile field filters.
 *  - `ids`: restricts to the given user ids (the selected rows of an export).
 */
class AdminUserQuery
{
    public const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    public const ALL_ROLES = ['super_admin', 'admin', 'staff', 'client'];

    public const ALLOWED_SORT_FIELDS = ['name', 'email', 'role', 'department', 'status', 'created_at', 'last_active'];

    /** Highest privilege first, used to rank a user's role for sorting when they hold more than one. */
    private const ROLE_SORT_PRIORITY = ['super_admin', 'admin', 'staff', 'client'];

    /**
     * @return Builder<User>
     */
    public static function fromRequest(Request $request): Builder
    {
        $account_statuses = self::listParam($request, 'account_status', ['active', 'disabled', 'deleted']);

        $query = User::query()
            ->select('users.*')
            ->addSelect(['last_active_at' => UserSession::query()
                ->selectRaw('MAX(last_used_at)')
                ->whereColumn('user_sessions.user_id', 'users.id'),
            ])
            ->with(['roles:id,name,display_name', 'department:id,name', 'profileFieldValues']);

        if (in_array('deleted', $account_statuses, true)) {
            $query->withTrashed();
        }

        self::applyStatusFilter($query, $account_statuses);
        self::applyTypeFilter($query, $request->query('type'));

        $roles = self::listParam($request, 'role', self::ALL_ROLES);
        if ($roles !== []) {
            $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles));
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $email_status = $request->query('email_status');
        if ($email_status === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($email_status === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        self::applyDepartmentFilter($query, self::listParam($request, 'department'));
        self::applyLastActiveFilter($query, $request);

        if ($from = self::dateParam($request, 'created_from')) {
            $query->where('users.created_at', '>=', $from->startOfDay());
        }
        if ($to = self::dateParam($request, 'created_to')) {
            $query->where('users.created_at', '<=', $to->endOfDay());
        }

        $field_filters = $request->query('fields');
        if (is_array($field_filters) && $field_filters !== []) {
            self::applyProfileFieldFilters($query, $field_filters);
        }

        $ids = self::listParam($request, 'ids');
        if ($ids !== []) {
            $query->whereIn('users.id', array_map('intval', array_filter($ids, 'ctype_digit')));
        }

        return $query;
    }

    public static function applySort(Builder $query, string $sort_field, string $sort_direction): void
    {
        switch ($sort_field) {
            case 'name':
                $query->orderBy('first_name', $sort_direction)->orderBy('last_name', $sort_direction);
                break;

            case 'status':
                $query->orderBy('is_active', $sort_direction);
                break;

            case 'department':
                // Left join (not the `department` relation) so users with no department, or
                // whose department was soft deleted, still appear, sorted by name being null.
                $query->leftJoin('departments', function ($join) {
                    $join->on('departments.id', '=', 'users.department_id')
                        ->whereNull('departments.deleted_at');
                })->orderBy('departments.name', $sort_direction);
                break;

            case 'role':
                $case_when = collect(self::ROLE_SORT_PRIORITY)
                    ->map(fn (string $role, int $index) => "WHEN EXISTS (SELECT 1 FROM user_role INNER JOIN roles ON roles.id = user_role.role_id WHERE user_role.user_id = users.id AND roles.name = '{$role}') THEN ".($index + 1))
                    ->implode(' ');
                $query->orderByRaw("(CASE {$case_when} ELSE ".(count(self::ROLE_SORT_PRIORITY) + 1).' END) '.$sort_direction);
                break;

            case 'email':
                $query->orderBy('email', $sort_direction);
                break;

            case 'last_active':
                // Never active users always sink to the bottom, whichever direction.
                $query->orderByRaw('CASE WHEN last_active_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('last_active_at', $sort_direction);
                break;

            default:
                $query->orderBy('users.created_at', $sort_direction);
                break;
        }

        $query->orderBy('users.id', $sort_direction);
    }

    /**
     * Comma separated (or repeated `key[]`) query parameter, trimmed, optionally restricted
     * to an allow list.
     *
     * @param  array<int, string>|null  $allowed
     * @return array<int, string>
     */
    public static function listParam(Request $request, string $key, ?array $allowed = null): array
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $values = array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn ($value) => $value !== '')));

        return $allowed === null ? $values : array_values(array_intersect($values, $allowed));
    }

    public static function dateParam(Request $request, string $key): ?Carbon
    {
        $value = $request->query($key);
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private static function applyStatusFilter(Builder $query, array $statuses): void
    {
        if ($statuses === []) {
            return;
        }

        $query->where(function (Builder $q) use ($statuses) {
            if (in_array('active', $statuses, true)) {
                $q->orWhere(fn (Builder $inner) => $inner->whereNull('users.deleted_at')->where('is_active', true));
            }
            if (in_array('disabled', $statuses, true)) {
                $q->orWhere(fn (Builder $inner) => $inner->whereNull('users.deleted_at')->where('is_active', false));
            }
            if (in_array('deleted', $statuses, true)) {
                $q->orWhereNotNull('users.deleted_at');
            }
        });
    }

    private static function applyTypeFilter(Builder $query, mixed $type): void
    {
        if ($type === 'staff') {
            $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', self::STAFF_ROLES));
        } elseif ($type === 'client') {
            $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'client'))
                ->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', self::STAFF_ROLES));
        }
    }

    /**
     * @param  array<int, string>  $departments
     */
    private static function applyDepartmentFilter(Builder $query, array $departments): void
    {
        if ($departments === []) {
            return;
        }

        $include_unassigned = in_array('unassigned', $departments, true);
        $department_ids = array_map('intval', array_values(array_filter($departments, 'ctype_digit')));

        $query->where(function (Builder $q) use ($include_unassigned, $department_ids) {
            // Not just `whereNull('department_id')`: a soft deleted department still leaves its
            // old members' `department_id` set, but the relation (and the UI) treats them as
            // unassigned, so the filter has to agree with what the table shows.
            if ($include_unassigned) {
                $q->orWhereDoesntHave('department');
            }
            if ($department_ids !== []) {
                $q->orWhereIn('users.department_id', $department_ids);
            }
        });
    }

    private static function applyLastActiveFilter(Builder $query, Request $request): void
    {
        $from = self::dateParam($request, 'last_active_from');
        $to = self::dateParam($request, 'last_active_to');
        $never = $request->boolean('last_active_never');

        if (! $from && ! $to && ! $never) {
            return;
        }

        $query->where(function (Builder $q) use ($from, $to, $never) {
            if ($from || $to) {
                // "Last active" is the newest session activity, so a range means: some session
                // was used on or after `from`, and none was used after the end of `to`.
                $q->orWhere(function (Builder $range) use ($from, $to) {
                    $range->whereHas('sessions', function (Builder $sessions) use ($from) {
                        if ($from) {
                            $sessions->where('last_used_at', '>=', $from->copy()->startOfDay());
                        }
                    });
                    if ($to) {
                        $range->whereDoesntHave('sessions', fn (Builder $sessions) => $sessions->where('last_used_at', '>', $to->copy()->endOfDay()));
                    }
                });
            }
            if ($never) {
                $q->orWhereDoesntHave('sessions');
            }
        });
    }

    /**
     * @param  array<mixed>  $filters
     */
    private static function applyProfileFieldFilters(Builder $query, array $filters): void
    {
        $fields = UserProfileField::query()
            ->whereIn('id', array_map('intval', array_keys($filters)))
            ->get()
            ->keyBy('id');

        foreach ($filters as $field_id => $filter) {
            $field = $fields->get((int) $field_id);
            if (! $field || ! is_array($filter)) {
                continue;
            }

            if (filter_var($filter['empty'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $query->whereDoesntHave('profileFieldValues', fn (Builder $q) => $q->where('field_id', $field->id)->whereNotNull('value')->where('value', '!=', ''));

                continue;
            }

            $query->whereHas('profileFieldValues', function (Builder $q) use ($field, $filter) {
                $q->where('field_id', $field->id);

                switch ($field->type) {
                    case UserProfileField::TYPE_NUMBER:
                        if (isset($filter['min']) && is_numeric($filter['min'])) {
                            $q->whereRaw('CAST(value AS DECIMAL(20,4)) >= ?', [(float) $filter['min']]);
                        }
                        if (isset($filter['max']) && is_numeric($filter['max'])) {
                            $q->whereRaw('CAST(value AS DECIMAL(20,4)) <= ?', [(float) $filter['max']]);
                        }
                        break;

                    case UserProfileField::TYPE_DATE:
                        if (isset($filter['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filter['from'])) {
                            $q->where('value', '>=', $filter['from']);
                        }
                        if (isset($filter['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filter['to'])) {
                            $q->where('value', '<=', $filter['to']);
                        }
                        break;

                    case UserProfileField::TYPE_DROPDOWN:
                        $in = is_array($filter['in'] ?? null) ? $filter['in'] : explode(',', (string) ($filter['in'] ?? ''));
                        $in = array_values(array_filter(array_map('trim', $in), fn ($value) => $value !== ''));
                        if ($in !== []) {
                            $q->whereIn('value', $in);
                        }
                        break;

                    default:
                        $contains = trim((string) ($filter['contains'] ?? ''));
                        if ($contains !== '') {
                            $q->where('value', 'LIKE', "%{$contains}%");
                        }
                        break;
                }
            });
        }
    }
}
