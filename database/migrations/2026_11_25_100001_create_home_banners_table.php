<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom background images for the welcome banner at the top of Home.
     *
     * The row with a null `user_id` is the organization banner an admin sets for everyone, every
     * other row is one person's own banner. Home shows the personal banner first, then the
     * organization one, then the built in gradient. The image is stored once, already resized and
     * re-encoded, and how it is framed (focal point, zoom, text color, overlay) lives in its own
     * columns, so moving it around never uploads it again.
     */
    public function up(): void
    {
        Schema::create('home_banners', function (Blueprint $table) {
            $table->id();
            // Null for the organization banner. MySQL allows many nulls in a unique index, so the
            // single organization row is enforced by HomeBanner::organizationBanner() instead.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();

            // Large rendition (up to 2560px wide) and small one (up to 1280px) for phones.
            $table->string('image_path');
            $table->string('image_small_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('stored_size')->default(0);
            $table->string('original_name')->nullable();
            $table->unsignedInteger('original_size')->default(0);

            // Painted while the image downloads: a tiny blurred WebP data URI and the average color.
            $table->text('placeholder')->nullable();
            $table->string('dominant_color', 7)->nullable();
            // Average brightness (0 to 1) of the left part of the image, where the greeting sits.
            $table->decimal('luminance', 4, 3)->default(0.5);

            // Framing: focal point in percent, zoom factor, greeting color and the shade over the image.
            $table->decimal('focal_x', 5, 2)->default(50);
            $table->decimal('focal_y', 5, 2)->default(50);
            $table->decimal('zoom', 3, 2)->default(1);
            $table->string('text_tone', 8)->default('auto');
            $table->unsignedTinyInteger('overlay_opacity')->default(35);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_banners');
    }
};
