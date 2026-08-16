<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('invite_code', 48)->nullable()->unique()->after('slug');
            $table->string('invite_role')->default('member')->after('invite_code');
            $table->boolean('invite_enabled')->default(true)->after('invite_role');
            $table->foreignId('invite_generated_by')->nullable()->after('invite_enabled')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invite_generated_by');
            $table->dropColumn(['invite_code', 'invite_role', 'invite_enabled']);
        });
    }
};
