<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin defined extra profile fields (text, number, date, dropdown) shown on every
     * user's profile and as filterable columns in Administration > Users.
     */
    public function up(): void
    {
        Schema::create('user_profile_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('user_profile_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('field_id')->constrained('user_profile_fields')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'field_id']);
            $table->index('field_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_field_values');
        Schema::dropIfExists('user_profile_fields');
    }
};
