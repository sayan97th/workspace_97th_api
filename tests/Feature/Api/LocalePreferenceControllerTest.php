<?php

use App\Models\User;

test('an authenticated user can update their locale preferences', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'api')->patchJson('/api/profile/locale', [
        'language' => 'es',
        'timezone' => 'Europe/Madrid',
        'time_format' => '24',
        'date_format' => 'euro',
        'first_day_of_week' => 'monday',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.language', 'es')
        ->assertJsonPath('user.timezone', 'Europe/Madrid')
        ->assertJsonPath('user.time_format', '24')
        ->assertJsonPath('user.date_format', 'euro')
        ->assertJsonPath('user.first_day_of_week', 'monday');

    expect($user->fresh()->language)->toBe('es');
});

test('locale update rejects an unsupported language', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson('/api/profile/locale', ['language' => 'xx'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('language');
});

test('locale update rejects an invalid timezone', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->patchJson('/api/profile/locale', ['timezone' => 'Not/AZone'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('timezone');
});
