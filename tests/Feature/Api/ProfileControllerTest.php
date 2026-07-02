<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

test('an authenticated user can view their profile', function () {
    $user = User::factory()->create(['phone' => '555-0100']);

    $response = $this->actingAs($user, 'api')->getJson('/api/profile');

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.phone', '555-0100');
});

test('an authenticated user can fully update their profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->putJson('/api/profile', [
        'first_name' => 'Updated',
        'last_name' => 'Name',
        'email' => 'updated@example.com',
        'phone' => '555-0199',
    ]);

    $response->assertOk()->assertJsonPath('user.first_name', 'Updated')->assertJsonPath('user.last_name', 'Name');

    $user->refresh();
    expect($user->first_name)->toBe('Updated');
    expect($user->last_name)->toBe('Name');
    expect($user->email)->toBe('updated@example.com');
    expect($user->phone)->toBe('555-0199');
});

test('an authenticated user can partially update their profile', function () {
    $user = User::factory()->create(['first_name' => 'Original', 'last_name' => 'Name']);

    $response = $this->actingAs($user, 'api')->patchJson('/api/profile', [
        'phone' => '555-0177',
    ]);

    $response->assertOk();

    $user->refresh();
    expect($user->first_name)->toBe('Original');
    expect($user->last_name)->toBe('Name');
    expect($user->phone)->toBe('555-0177');
});

test('profile update rejects an email already in use', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->putJson('/api/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'taken@example.com',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('a user can update their password with the correct current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    $response = $this->actingAs($user, 'api')->putJson('/api/profile/password', [
        'current_password' => 'old-password',
        'password' => 'new-password123',
        'password_confirmation' => 'new-password123',
    ]);

    $response->assertOk();
    expect(Hash::check('new-password123', $user->fresh()->password))->toBeTrue();
});

test('password update is rejected with the wrong current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    $this->actingAs($user, 'api')
        ->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');
});

test('a user can upload and remove a profile photo', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $upload = $this->actingAs($user, 'api')->postJson('/api/profile/photo', [
        'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
    ]);

    $upload->assertOk();
    $user->refresh();
    expect($user->profile_photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->profile_photo_path);

    $destroy = $this->actingAs($user, 'api')->deleteJson('/api/profile/photo');

    $destroy->assertOk()->assertJsonPath('profile_photo_url', null);
    expect($user->fresh()->profile_photo_path)->toBeNull();
});
