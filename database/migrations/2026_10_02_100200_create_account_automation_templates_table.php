<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Automation templates an administrator publishes for every board of the account, listed
     * under the Create tab's "Created by" category. Unlike board templates they never point at
     * one board's columns or groups: `definition` has those ids cleared and `column_kinds`
     * remembers which kind of column each cleared spot needs, so any board can fill them in.
     */
    public function up(): void
    {
        Schema::create('account_automation_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('definition');
            $table->json('column_kinds')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_automation_templates');
    }
};
