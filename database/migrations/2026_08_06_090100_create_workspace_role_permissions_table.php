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
        Schema::create('workspace_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->string('permission_key');
            $table->boolean('allowed')->default(false);
            $table->timestamps();

            $table->unique(['role', 'permission_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_role_permissions');
    }
};
