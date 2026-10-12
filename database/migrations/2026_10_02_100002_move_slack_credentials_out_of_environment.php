<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Used to copy the SLACK_* environment values into `slack_app_settings`. The environment is
     * the production Slack app again and the saved row is only the testing app, so copying would
     * turn the production app into the testing one. Kept as a no-op so the migration history of
     * servers that already ran it stays the same.
     */
    public function up(): void {}

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
