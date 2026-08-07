<?php

namespace App\Support;

/**
 * The permission catalog for Manage Workspace's "Permissions" tab: the three
 * default workspace roles, every toggleable permission (grouped the same way
 * as the approved design), and the default checked/unchecked state seeded
 * into {@link \App\Models\WorkspaceRolePermission} the first time the app
 * boots (see `WorkspacePermissionSeeder`).
 *
 * This catalog itself is application config, not per-workspace data — every
 * workspace shares the same set of roles/permissions, only the *grant*
 * (allowed true/false per role+permission) is stored in the database and
 * editable via `PATCH /api/workspace-permissions`.
 */
class WorkspacePermissionCatalog
{
    /**
     * @return array<int, array{id: string, label: string}>
     */
    public static function roles(): array
    {
        return [
            ['id' => 'owner', 'label' => 'Workspace owner'],
            ['id' => 'member', 'label' => 'Workspace member'],
            ['id' => 'viewer', 'label' => 'Workspace viewer'],
            ['id' => 'nonmember', 'label' => 'Workspace non-member'],
        ];
    }

    /**
     * @return array<int, array{id: string, title: string, items: array<int, array{key: string, label: string, has_info?: bool, has_chevron?: bool}>}>
     */
    public static function groups(): array
    {
        return [
            ['id' => 'workspace', 'title' => 'Workspace', 'items' => [
                ['key' => 'upload_files', 'label' => 'Upload files in boards and docs', 'has_info' => true, 'has_chevron' => true],
                ['key' => 'delete_files', 'label' => 'Delete files', 'has_info' => true],
                ['key' => 'ai_automations', 'label' => 'Create and edit AI-powered automations/columns and utilize AI Assistants'],
                ['key' => 'create_automations', 'label' => 'Create automations / integrations'],
                ['key' => 'create_integrations', 'label' => 'Create integrations', 'has_info' => true, 'has_chevron' => true],
                ['key' => 'reorder_content', 'label' => 'Reorder workspace content in the left pane'],
            ]],
            ['id' => 'boards', 'title' => 'Boards', 'items' => [
                ['key' => 'create_main_boards', 'label' => 'Create main boards', 'has_info' => true],
                ['key' => 'create_private_boards', 'label' => 'Create private boards', 'has_info' => true],
                ['key' => 'create_shareable_boards', 'label' => 'Create shareable boards', 'has_info' => true],
                ['key' => 'delete_own_boards', 'label' => 'Delete/Archive self owned boards', 'has_info' => true],
                ['key' => 'create_board_views', 'label' => 'Create board views', 'has_info' => true],
                ['key' => 'delete_own_views', 'label' => 'Delete self-created views', 'has_info' => true],
                ['key' => 'delete_others_views', 'label' => 'Delete views created by other users', 'has_info' => true],
                ['key' => 'move_groups', 'label' => 'Move groups to other boards', 'has_info' => true],
            ]],
            ['id' => 'items', 'title' => 'Items', 'items' => [
                ['key' => 'delete_own_items', 'label' => 'Delete self-created items', 'has_info' => true],
                ['key' => 'delete_others_items', 'label' => 'Delete items created by other users', 'has_info' => true],
                ['key' => 'move_items', 'label' => 'Move items to other boards', 'has_info' => true],
                ['key' => 'create_docs_on_items', 'label' => 'Create docs on items', 'has_info' => true],
            ]],
            ['id' => 'dashboards', 'title' => 'Dashboards', 'items' => [
                ['key' => 'create_main_dashboards', 'label' => 'Create main dashboards'],
            ]],
            ['id' => 'workflow', 'title' => 'Workflow', 'items' => [
                ['key' => 'create_main_workflow', 'label' => 'Create main workflow'],
                ['key' => 'create_private_workflow', 'label' => 'Create private workflow'],
            ]],
            ['id' => 'docs', 'title' => 'Docs', 'items' => [
                ['key' => 'create_main_docs', 'label' => 'Create main docs'],
                ['key' => 'create_private_docs', 'label' => 'Create private docs'],
                ['key' => 'create_shareable_docs', 'label' => 'Create shareable docs'],
            ]],
            ['id' => 'communication', 'title' => 'Communication', 'items' => [
                ['key' => 'mute_notifications', 'label' => 'Board owners can mute board notifications for all users'],
                ['key' => 'create_updates_comments', 'label' => 'Create item updates and doc comments', 'has_info' => true],
                ['key' => 'edit_own_updates_comments', 'label' => 'Edit self-owned item updates and doc comments'],
                ['key' => 'delete_own_updates_comments', 'label' => 'Delete self-owned item updates and doc comments'],
            ]],
        ];
    }

    /**
     * Default checked/unchecked state per role, seeded on first boot.
     *
     * @return array<string, array<string, bool>>
     */
    public static function defaults(): array
    {
        return [
            'owner' => [
                'upload_files' => true, 'delete_files' => true, 'ai_automations' => true, 'create_automations' => true, 'create_integrations' => true, 'reorder_content' => true,
                'create_main_boards' => true, 'create_private_boards' => true, 'create_shareable_boards' => true, 'delete_own_boards' => true, 'create_board_views' => true, 'delete_own_views' => true, 'delete_others_views' => true, 'move_groups' => true,
                'delete_own_items' => true, 'delete_others_items' => true, 'move_items' => true, 'create_docs_on_items' => true,
                'create_main_dashboards' => false,
                'create_main_workflow' => true, 'create_private_workflow' => true,
                'create_main_docs' => true, 'create_private_docs' => true, 'create_shareable_docs' => true,
                'mute_notifications' => true, 'create_updates_comments' => true, 'edit_own_updates_comments' => true, 'delete_own_updates_comments' => true,
            ],
            'member' => [
                'upload_files' => true, 'delete_files' => true, 'ai_automations' => true, 'create_automations' => true, 'create_integrations' => true, 'reorder_content' => true,
                'create_main_boards' => true, 'create_private_boards' => true, 'create_shareable_boards' => true, 'delete_own_boards' => true, 'create_board_views' => true, 'delete_own_views' => true, 'delete_others_views' => false, 'move_groups' => false,
                'delete_own_items' => true, 'delete_others_items' => false, 'move_items' => false, 'create_docs_on_items' => true,
                'create_main_dashboards' => false,
                'create_main_workflow' => true, 'create_private_workflow' => true,
                'create_main_docs' => true, 'create_private_docs' => true, 'create_shareable_docs' => true,
                'mute_notifications' => false, 'create_updates_comments' => true, 'edit_own_updates_comments' => true, 'delete_own_updates_comments' => true,
            ],
            // Read-only by design: every grant defaults to false so a Viewer can
            // open and browse a workspace's boards/docs but never create, edit,
            // delete or comment on anything.
            'viewer' => [
                'upload_files' => false, 'delete_files' => false, 'ai_automations' => false, 'create_automations' => false, 'create_integrations' => false, 'reorder_content' => false,
                'create_main_boards' => false, 'create_private_boards' => false, 'create_shareable_boards' => false, 'delete_own_boards' => false, 'create_board_views' => false, 'delete_own_views' => false, 'delete_others_views' => false, 'move_groups' => false,
                'delete_own_items' => false, 'delete_others_items' => false, 'move_items' => false, 'create_docs_on_items' => false,
                'create_main_dashboards' => false,
                'create_main_workflow' => false, 'create_private_workflow' => false,
                'create_main_docs' => false, 'create_private_docs' => false, 'create_shareable_docs' => false,
                'mute_notifications' => false, 'create_updates_comments' => false, 'edit_own_updates_comments' => false, 'delete_own_updates_comments' => false,
            ],
            'nonmember' => [
                'upload_files' => true, 'delete_files' => false, 'ai_automations' => false, 'create_automations' => false, 'create_integrations' => false, 'reorder_content' => false,
                'create_main_boards' => false, 'create_private_boards' => false, 'create_shareable_boards' => false, 'delete_own_boards' => false, 'create_board_views' => false, 'delete_own_views' => false, 'delete_others_views' => false, 'move_groups' => false,
                'delete_own_items' => false, 'delete_others_items' => false, 'move_items' => false, 'create_docs_on_items' => false,
                'create_main_dashboards' => false,
                'create_main_workflow' => false, 'create_private_workflow' => false,
                'create_main_docs' => false, 'create_private_docs' => false, 'create_shareable_docs' => false,
                'mute_notifications' => false, 'create_updates_comments' => true, 'edit_own_updates_comments' => true, 'delete_own_updates_comments' => false,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function roleIds(): array
    {
        return array_column(self::roles(), 'id');
    }

    /**
     * Roles a workspace owner can actually assign someone via an invitation —
     * every catalog role except `nonmember`, which describes default access for
     * people outside the workspace rather than a role a membership row can hold.
     *
     * @return array<int, string>
     */
    public static function invitableRoleIds(): array
    {
        return array_values(array_diff(self::roleIds(), ['nonmember']));
    }

    /**
     * Human label for a single role id, e.g. for an invitation email/preview.
     */
    public static function labelFor(string $role_id): string
    {
        foreach (self::roles() as $role) {
            if ($role['id'] === $role_id) {
                return $role['label'];
            }
        }

        return ucfirst($role_id);
    }

    /**
     * Every permission key across every group, flattened.
     *
     * @return array<int, string>
     */
    public static function permissionKeys(): array
    {
        $keys = [];
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }
}
