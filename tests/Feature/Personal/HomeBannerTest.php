<?php

use App\Models\AuditLog;
use App\Models\HomeBanner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake(config('filesystems.app_disk'));

    $this->member = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

function bannerDisk()
{
    return Storage::disk(config('filesystems.app_disk'));
}

test('without any banner both slots are empty', function () {
    $this->actingAs($this->member, 'api')
        ->getJson('/api/home-banner')
        ->assertOk()
        ->assertJsonPath('personal', null)
        ->assertJsonPath('organization', null)
        ->assertJsonPath('can_manage_organization', false);
});

test('a member uploads a personal banner, re-encoded as two webp sizes with a placeholder', function () {
    $response = $this->actingAs($this->member, 'api')->post('/api/profile/home-banner', [
        'file' => UploadedFile::fake()->image('team-photo.jpg', 4000, 1200),
        'focal_x' => 30,
        'focal_y' => 70,
        'zoom' => 1.4,
        'text_tone' => 'light',
        'overlay_opacity' => 50,
    ], ['Accept' => 'application/json']);

    $response->assertOk()
        ->assertJsonPath('personal.scope', 'personal')
        ->assertJsonPath('personal.width', 2560)
        ->assertJsonPath('personal.height', 768)
        ->assertJsonPath('personal.focal_x', 30)
        ->assertJsonPath('personal.zoom', 1.4)
        ->assertJsonPath('personal.text_tone', 'light')
        ->assertJsonPath('personal.overlay_opacity', 50)
        ->assertJsonPath('personal.original_name', 'team-photo.jpg')
        ->assertJsonPath('personal.placeholder', fn ($placeholder) => str_starts_with($placeholder, 'data:image/webp;base64,'))
        ->assertJsonPath('personal.dominant_color', fn ($color) => (bool) preg_match('/^#[0-9a-f]{6}$/', $color))
        ->assertJsonPath('organization', null);

    $banner = HomeBanner::personalBannerFor($this->member);
    expect($banner->image_path)->toEndWith('-large.webp')
        ->and($banner->image_small_path)->toEndWith('-small.webp');
    bannerDisk()->assertExists([$banner->image_path, $banner->image_small_path]);

    [$small_width] = getimagesizefromstring(bannerDisk()->get($banner->image_small_path));
    expect($small_width)->toBe(1280);
});

test('a smaller image is never upscaled', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('small.png', 1000, 300)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('personal.width', 1000)
        ->assertJsonPath('personal.height', 300);
});

test('replacing the personal banner deletes the old files and keeps a single row', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('first.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();
    $first = HomeBanner::personalBannerFor($this->member);

    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('second.webp', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();

    bannerDisk()->assertMissing([$first->image_path, $first->image_small_path]);
    expect(HomeBanner::where('user_id', $this->member->id)->count())->toBe(1);
});

test('the personal banner can be reframed without uploading it again', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('banner.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();
    $image_path = HomeBanner::personalBannerFor($this->member)->image_path;

    $this->actingAs($this->member, 'api')
        ->patchJson('/api/profile/home-banner', ['focal_y' => 12.5, 'zoom' => 2, 'text_tone' => 'dark'])
        ->assertOk()
        ->assertJsonPath('personal.focal_y', 12.5)
        ->assertJsonPath('personal.zoom', 2)
        ->assertJsonPath('personal.text_tone', 'dark')
        ->assertJsonPath('personal.image_url', fn ($url) => str_ends_with($url, $image_path));
});

test('framing values out of range are rejected', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('banner.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();

    $this->actingAs($this->member, 'api')
        ->patchJson('/api/profile/home-banner', ['focal_x' => 140, 'zoom' => 5, 'text_tone' => 'neon', 'overlay_opacity' => 95])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['focal_x', 'zoom', 'text_tone', 'overlay_opacity']);
});

test('reframing without a personal banner is a 404', function () {
    $this->actingAs($this->member, 'api')
        ->patchJson('/api/profile/home-banner', ['focal_y' => 20])
        ->assertNotFound();
});

test('svg, tiny images and non images are refused', function (UploadedFile $file) {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    expect(HomeBanner::count())->toBe(0);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('art.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'too small' => fn () => UploadedFile::fake()->image('icon.png', 300, 100),
    'text file' => fn () => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
]);

test('removing the personal banner deletes its files', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('banner.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();
    $banner = HomeBanner::personalBannerFor($this->member);

    $this->actingAs($this->member, 'api')
        ->deleteJson('/api/profile/home-banner')
        ->assertOk()
        ->assertJsonPath('personal', null);

    bannerDisk()->assertMissing([$banner->image_path, $banner->image_small_path]);
});

test('admins set the organization banner, everyone receives it next to their own', function () {
    $this->actingAs($this->admin, 'api')
        ->post('/api/admin/organization/home-banner', [
            'file' => UploadedFile::fake()->image('brand.jpg', 2400, 600),
            'overlay_opacity' => 20,
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('organization.scope', 'organization')
        ->assertJsonPath('organization.overlay_opacity', 20)
        ->assertJsonPath('organization.updated_by.id', $this->admin->id)
        ->assertJsonPath('can_manage_organization', true);

    expect(AuditLog::where('event', 'organization.home_banner_uploaded')->exists())->toBeTrue();

    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', ['file' => UploadedFile::fake()->image('mine.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk();

    $this->actingAs($this->member, 'api')
        ->getJson('/api/home-banner')
        ->assertOk()
        ->assertJsonPath('personal.scope', 'personal')
        ->assertJsonPath('organization.scope', 'organization');

    // A personal banner never replaces the organization one, and vice versa.
    expect(HomeBanner::whereNull('user_id')->count())->toBe(1)
        ->and(HomeBanner::whereNotNull('user_id')->count())->toBe(1);
});

test('admins reframe and remove the organization banner', function () {
    $this->actingAs($this->admin, 'api')
        ->post('/api/admin/organization/home-banner', ['file' => UploadedFile::fake()->image('brand.jpg', 2400, 600)], ['Accept' => 'application/json'])
        ->assertOk();
    $banner = HomeBanner::organizationBanner();

    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization/home-banner', ['focal_x' => 80])
        ->assertOk()
        ->assertJsonPath('organization.focal_x', 80);

    $this->actingAs($this->admin, 'api')
        ->deleteJson('/api/admin/organization/home-banner')
        ->assertOk()
        ->assertJsonPath('organization', null);

    bannerDisk()->assertMissing([$banner->image_path, $banner->image_small_path]);
});

test('members cannot change the organization banner', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/admin/organization/home-banner', ['file' => UploadedFile::fake()->image('brand.jpg', 2400, 600)], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($this->member, 'api')
        ->deleteJson('/api/admin/organization/home-banner')
        ->assertForbidden();

    expect(HomeBanner::count())->toBe(0);
});

test('guests cannot read the banner', function () {
    $this->getJson('/api/home-banner')->assertUnauthorized();
});

test('the original file name and size picked in the browser are kept', function () {
    $this->actingAs($this->member, 'api')
        ->post('/api/profile/home-banner', [
            'file' => UploadedFile::fake()->image('brand.webp', 1600, 400),
            'source_name' => 'brand.svg',
            'source_size' => 5242880,
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('personal.original_name', 'brand.svg')
        ->assertJsonPath('personal.original_size', 5242880);
});
