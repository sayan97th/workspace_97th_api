<?php

use Database\Seeders\WorkspaceLogoSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Ships the official logo with the deploy, so each environment gets it without a manual upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()->call([new WorkspaceLogoSeeder, 'run']);
    }

    /**
     * The avatar stays in place, owners can remove it from the Edit workspace dialog.
     */
    public function down(): void {}
};
