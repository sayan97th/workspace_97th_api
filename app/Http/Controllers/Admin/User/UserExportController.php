<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfileField;
use App\Support\Admin\AdminUserQuery;
use App\Support\Admin\CsvExport;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/admin/users/export
 *
 * CSV of Administration > Users. Takes the same filters and sort as the list (see
 * {@see AdminUserQuery}) plus `ids` for "export selected", so the file always matches what
 * the admin is looking at. Custom profile fields become extra columns.
 */
class UserExportController extends Controller
{
    private const ROLE_LABELS = [
        'super_admin' => 'Super admin',
        'admin' => 'Admin',
        'staff' => 'Staff',
        'client' => 'Client',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        $sort_field = (string) $request->query('sort_field', 'created_at');
        $sort_direction = $request->query('sort_direction') === 'asc' ? 'asc' : 'desc';

        $query = AdminUserQuery::fromRequest($request);
        AdminUserQuery::applySort(
            $query,
            in_array($sort_field, AdminUserQuery::ALLOWED_SORT_FIELDS, true) ? $sort_field : 'created_at',
            $sort_direction,
        );

        $fields = UserProfileField::query()->orderBy('position')->orderBy('id')->get();

        $headings = ['Name', 'Email', 'Role', 'Department', 'Status', 'Email verified', 'Last active', 'Date added'];
        foreach ($fields as $field) {
            $headings[] = $field->name;
        }

        AuditLogger::log('user.exported', 'Exported the user directory to CSV.', $request->user(), array_filter([
            'filters' => array_filter($request->except(['page', 'per_page'])),
        ]));

        $rows = (function () use ($query, $fields) {
            foreach ($query->lazy(500) as $user) {
                yield $this->row($user, $fields);
            }
        })();

        return CsvExport::download('users-'.now()->format('Y-m-d').'.csv', $headings, $rows);
    }

    /**
     * @param  Collection<int, UserProfileField>  $fields
     * @return array<int, string|null>
     */
    private function row(User $user, $fields): array
    {
        $role = collect(array_keys(self::ROLE_LABELS))->first(fn (string $name) => $user->roles->contains('name', $name));
        $last_active = $user->getAttribute('last_active_at');
        $values = $user->profileFieldValues->keyBy('field_id');

        $row = [
            $user->full_name,
            $user->email,
            $role ? self::ROLE_LABELS[$role] : '',
            $user->department?->name ?? '',
            $user->trashed() ? 'Deleted' : ($user->is_active ? 'Active' : 'Deactivated'),
            $user->email_verified_at ? 'Yes' : 'No',
            $last_active ? Carbon::parse($last_active)->format('Y-m-d H:i') : 'Never',
            $user->created_at?->format('Y-m-d'),
        ];

        foreach ($fields as $field) {
            $value = $values->get($field->id)?->value;
            if ($value !== null && $field->type === UserProfileField::TYPE_DROPDOWN) {
                $value = collect($field->options ?? [])->firstWhere('id', $value)['label'] ?? $value;
            }
            $row[] = $value;
        }

        return $row;
    }
}
