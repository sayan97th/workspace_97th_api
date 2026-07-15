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
        Schema::create('workspace_navigation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('workspace_navigation_items')->cascadeOnDelete();
            $table->string('type')->default('leaf'); // 'group' (folder) or 'leaf' (view)
            $table->string('label');
            $table->string('slug');
            $table->string('icon')->nullable();
            $table->string('view_key')->nullable();
            $table->string('href')->nullable();
            $table->string('display_style')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'parent_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_navigation_items');
    }
};
