<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organization settings (`/admin/organization`): the company profile, contact and support
     * details, extra branding assets, the brand color, the sign in page copy and the account
     * wide announcement banner. The company display name reuses `account_name` and the main
     * logo reuses `logo_path`, both already on this singleton row.
     */
    public function up(): void
    {
        Schema::table('account_settings', function (Blueprint $table) {
            $table->string('company_legal_name')->nullable()->after('account_url');
            $table->string('company_tagline', 120)->nullable()->after('company_legal_name');
            $table->text('company_description')->nullable()->after('company_tagline');
            $table->string('company_industry', 64)->nullable()->after('company_description');
            $table->string('company_size', 16)->nullable()->after('company_industry');
            $table->unsignedSmallInteger('company_founded_year')->nullable()->after('company_size');
            $table->string('company_website')->nullable()->after('company_founded_year');

            $table->string('support_email')->nullable()->after('company_website');
            $table->string('support_url')->nullable()->after('support_email');
            $table->string('contact_phone', 40)->nullable()->after('support_url');
            $table->string('address_line_1')->nullable()->after('contact_phone');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('address_city', 120)->nullable()->after('address_line_2');
            $table->string('address_state', 120)->nullable()->after('address_city');
            $table->string('address_postal_code', 20)->nullable()->after('address_state');
            $table->string('address_country', 2)->nullable()->after('address_postal_code');
            $table->json('social_links')->nullable()->after('address_country');

            $table->string('logo_dark_path')->nullable()->after('logo_path');
            $table->string('favicon_path')->nullable()->after('logo_dark_path');
            // Hex like "#e53e2e", null keeps the default 97th Floor red.
            $table->string('brand_color', 7)->nullable()->after('favicon_path');
            $table->boolean('show_name_in_top_bar')->default(false)->after('brand_color');

            $table->string('login_headline', 120)->nullable()->after('show_name_in_top_bar');
            $table->string('login_message', 280)->nullable()->after('login_headline');

            $table->boolean('announcement_enabled')->default(false)->after('login_message');
            $table->string('announcement_message', 280)->nullable()->after('announcement_enabled');
            $table->string('announcement_tone', 16)->default('info')->after('announcement_message');
            $table->string('announcement_link_label', 40)->nullable()->after('announcement_tone');
            $table->string('announcement_link_url')->nullable()->after('announcement_link_label');
            $table->boolean('announcement_dismissible')->default(true)->after('announcement_link_url');
            // Bumped whenever the banner content changes, so a banner people dismissed shows again once it is edited.
            $table->timestamp('announcement_published_at')->nullable()->after('announcement_dismissible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_settings', function (Blueprint $table) {
            $table->dropColumn([
                'company_legal_name', 'company_tagline', 'company_description', 'company_industry',
                'company_size', 'company_founded_year', 'company_website',
                'support_email', 'support_url', 'contact_phone',
                'address_line_1', 'address_line_2', 'address_city', 'address_state', 'address_postal_code', 'address_country',
                'social_links',
                'logo_dark_path', 'favicon_path', 'brand_color', 'show_name_in_top_bar',
                'login_headline', 'login_message',
                'announcement_enabled', 'announcement_message', 'announcement_tone',
                'announcement_link_label', 'announcement_link_url', 'announcement_dismissible', 'announcement_published_at',
            ]);
        });
    }
};
