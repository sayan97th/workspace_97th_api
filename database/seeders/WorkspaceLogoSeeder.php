<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAvatarService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Replaces the generated "97" monogram with the official 97th Floor logo.
 * Safe to run again: a workspace that already has an avatar keeps it, so an
 * image uploaded from the Edit workspace dialog is never overwritten.
 */
class WorkspaceLogoSeeder extends Seeder
{
    private const COMPANY_MONOGRAM = '97';

    private const LOGO_FILE_NAME = '97-logo-icon-red.png';

    public function run(WorkspaceAvatarService $avatars): void
    {
        $logo = new UploadedFile(
            resource_path('images/workspace/'.self::LOGO_FILE_NAME),
            self::LOGO_FILE_NAME,
            'image/png',
            test: true,
        );

        Workspace::where('mono', self::COMPANY_MONOGRAM)
            ->whereNull('avatar_path')
            ->get()
            ->each(fn (Workspace $workspace) => $avatars->store($workspace, $logo));
    }
}
