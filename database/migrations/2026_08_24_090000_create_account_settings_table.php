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
        Schema::create('account_settings', function (Blueprint $table) {
            $table->id();

            // Profile
            $table->string('account_name')->default('97th Floor');
            $table->string('account_url')->unique();

            // Account preferences
            $table->string('weekend_start')->default('sat_sun');
            $table->boolean('show_weekends')->default(true);
            $table->string('home_page')->default('default');

            // Branding
            $table->string('logo_path')->nullable();
            $table->string('email_header_path')->nullable();

            // Authentication policy
            $table->boolean('two_factor_enforced')->default(false);
            $table->boolean('google_sso_enabled')->default(false);
            $table->boolean('saml_sso_enabled')->default(false);
            $table->json('saml_metadata')->nullable();
            $table->boolean('scim_enabled')->default(false);
            $table->string('scim_token')->nullable();
            $table->boolean('guest_approval_enabled')->default(false);
            $table->json('approved_domains')->nullable();
            $table->boolean('ip_restriction_enabled')->default(false);
            $table->json('ip_ranges')->nullable();
            $table->string('default_product')->nullable();

            // Advanced
            $table->unsignedInteger('session_inactivity_minutes')->nullable();
            $table->unsignedInteger('session_max_duration_minutes')->nullable();
            $table->boolean('panic_mode_active')->default(false);
            $table->timestamp('panic_mode_activated_at')->nullable();
            $table->foreignId('panic_mode_activated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_settings');
    }
};
