<?php

use App\Models\AccountSetting;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake(config('filesystems.app_disk'));

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('admins update the company profile and the change is audited', function () {
    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization', [
            'company_name' => 'Acme Studio',
            'company_tagline' => 'Content that ranks',
            'company_size' => '51-200',
            'company_founded_year' => 2009,
            'company_website' => 'https://acme.test',
            'support_email' => ' Help@Acme.TEST ',
            'address_country' => 'us',
            'brand_color' => '#0073EA',
            'show_name_in_top_bar' => true,
        ])
        ->assertOk()
        ->assertJsonPath('organization.company_name', 'Acme Studio')
        ->assertJsonPath('organization.support_email', 'help@acme.test')
        ->assertJsonPath('organization.address_country', 'US')
        ->assertJsonPath('organization.brand_color', '#0073ea')
        ->assertJsonPath('organization.can_edit', true);

    expect(AccountSetting::current()->account_name)->toBe('Acme Studio')
        ->and(AuditLog::where('event', 'organization.updated')->exists())->toBeTrue();
});

test('invalid organization values are rejected', function () {
    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization', [
            'company_name' => '',
            'company_size' => '12',
            'company_founded_year' => now()->year + 1,
            'company_website' => 'javascript:alert(1)',
            'brand_color' => 'red',
            'social_links' => ['myspace' => 'https://myspace.test/acme'],
            'announcement_enabled' => true,
            'announcement_message' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'company_name', 'company_size', 'company_founded_year', 'company_website',
            'brand_color', 'social_links', 'announcement_message',
        ]);
});

test('social links keep every network and drop the empty ones', function () {
    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization', [
            'social_links' => ['linkedin' => 'https://linkedin.com/company/acme', 'x' => null],
        ])
        ->assertOk()
        ->assertJsonPath('organization.social_links.linkedin', 'https://linkedin.com/company/acme')
        ->assertJsonPath('organization.social_links.x', null)
        ->assertJsonPath('organization.social_links.youtube', null);

    expect(AccountSetting::current()->social_links)->toBe(['linkedin' => 'https://linkedin.com/company/acme']);
});

test('editing the announcement republishes it so dismissed banners show again', function () {
    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization', [
            'announcement_enabled' => true,
            'announcement_message' => 'Office closed on Friday',
            'announcement_tone' => 'warning',
        ])
        ->assertOk();

    $first_published_at = AccountSetting::current()->announcement_published_at;
    expect($first_published_at)->not->toBeNull();

    $this->travel(5)->minutes();

    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization', ['company_tagline' => 'Unrelated change'])
        ->assertOk();
    expect(AccountSetting::current()->announcement_published_at->equalTo($first_published_at))->toBeTrue();

    $this->actingAs($this->admin, 'api')
        ->patchJson('/api/admin/organization', ['announcement_message' => 'Office closed on Monday'])
        ->assertOk();
    expect(AccountSetting::current()->announcement_published_at->greaterThan($first_published_at))->toBeTrue();
});

test('admins upload and remove the logo, dark logo and favicon', function (string $asset, string $file_name, string $response_key) {
    $this->actingAs($this->admin, 'api')
        ->post("/api/admin/organization/assets/{$asset}", ['file' => UploadedFile::fake()->image($file_name, 64, 64)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath("organization.{$response_key}", fn ($url) => is_string($url) && $url !== '');

    $column = AccountSetting::BRANDING_ASSET_COLUMNS[$asset];
    $path = AccountSetting::current()->{$column};
    Storage::disk(config('filesystems.app_disk'))->assertExists($path);

    $this->actingAs($this->admin, 'api')
        ->deleteJson("/api/admin/organization/assets/{$asset}")
        ->assertOk()
        ->assertJsonPath("organization.{$response_key}", null);

    Storage::disk(config('filesystems.app_disk'))->assertMissing($path);
})->with([
    ['logo', 'logo.png', 'logo_url'],
    ['logo_dark', 'logo-dark.png', 'logo_dark_url'],
    ['favicon', 'favicon.png', 'favicon_url'],
]);

test('unknown assets and svg uploads are rejected', function () {
    $this->actingAs($this->admin, 'api')
        ->post('/api/admin/organization/assets/cover', ['file' => UploadedFile::fake()->image('cover.png')], ['Accept' => 'application/json'])
        ->assertNotFound();

    $this->actingAs($this->admin, 'api')
        ->post('/api/admin/organization/assets/logo', ['file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('staff can read the organization but not change it', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')
        ->getJson('/api/admin/organization')
        ->assertOk()
        ->assertJsonPath('can_edit', false);

    $this->actingAs($staff, 'api')->patchJson('/api/admin/organization', ['company_name' => 'Nope'])->assertForbidden();
    $this->actingAs($staff, 'api')
        ->post('/api/admin/organization/assets/logo', ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('members get the app shell branding with the active announcement', function () {
    AccountSetting::current()->update([
        'brand_color' => '#00854d',
        'support_email' => 'help@acme.test',
        'announcement_enabled' => true,
        'announcement_message' => 'Welcome to the new workspace',
        'announcement_tone' => 'success',
        'contact_phone' => '+1 555 0100',
    ]);

    $member = User::factory()->create();

    $this->actingAs($member, 'api')
        ->getJson('/api/branding')
        ->assertOk()
        ->assertJsonPath('brand_color', '#00854d')
        ->assertJsonPath('support_email', 'help@acme.test')
        ->assertJsonPath('announcement.message', 'Welcome to the new workspace')
        ->assertJsonPath('announcement.tone', 'success')
        ->assertJsonMissingPath('contact_phone');
});

test('the sign in branding is public and leaves internal details out', function () {
    AccountSetting::current()->update([
        'account_name' => 'Acme Studio',
        'login_headline' => 'Welcome back',
        'support_email' => 'help@acme.test',
        'announcement_enabled' => true,
        'announcement_message' => 'Internal only',
    ]);

    $this->getJson('/api/public/branding')
        ->assertOk()
        ->assertJsonPath('company_name', 'Acme Studio')
        ->assertJsonPath('login_headline', 'Welcome back')
        ->assertJsonMissingPath('support_email')
        ->assertJsonMissingPath('announcement');
});
