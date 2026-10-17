<?php

use App\Models\ExternalAccount;
use App\Models\IntegrationAppSetting;
use App\Models\SlackConnection;
use App\Models\SlackInstallation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function overviewUser(string $role = 'client'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('every app needs setup until an administrator saves its app, email is always ready', function () {
    $this->actingAs(overviewUser(), 'api')->getJson('/api/integrations/overview')
        ->assertOk()
        ->assertJsonPath('can_manage', false)
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.*.key', ['email', 'slack', 'gmail', 'outlook', 'google_calendar'])
        ->assertJsonPath('data.0.status', 'ready')
        ->assertJsonPath('data.1.status', 'needs_setup')
        ->assertJsonPath('data.1.can_connect', false)
        ->assertJsonPath('data.2.status', 'needs_setup')
        ->assertJsonPath('data.4.category', 'calendar');
});

test('apps are available once set up and connected once the caller has an account of their own', function () {
    saveSlackAppCredentials(['client_id' => 'client-id']);
    IntegrationAppSetting::create(['provider' => 'google', 'client_id' => 'google-client', 'client_secret' => 'google-secret']);

    $member = overviewUser();
    $installation = SlackInstallation::create(['team_id' => 'T100', 'team_name' => 'Acme', 'bot_token' => 'xoxb-acme', 'scopes' => 'chat:write']);
    SlackConnection::create(['user_id' => $member->id, 'slack_installation_id' => $installation->id, 'slack_user_name' => 'Ada', 'connected_at' => now()]);
    ExternalAccount::create([
        'user_id' => $member->id,
        'provider' => 'google',
        'provider_user_id' => 'google-ada',
        'email' => 'ada@example.com',
        'access_token' => 'access-token',
        'scopes' => ['openid', 'https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send'],
        'connected_at' => now(),
    ]);

    // Someone else's accounts never count.
    SlackConnection::create(['user_id' => overviewUser()->id, 'slack_installation_id' => $installation->id, 'connected_at' => now()]);

    $this->actingAs($member, 'api')->getJson('/api/integrations/overview')
        ->assertOk()
        ->assertJsonPath('data.1.status', 'connected')
        ->assertJsonPath('data.1.accounts_count', 1)
        ->assertJsonPath('data.1.accounts.0.label', 'Ada (Acme)')
        ->assertJsonPath('data.2.status', 'connected')
        ->assertJsonPath('data.2.accounts.0.label', 'ada@example.com')
        ->assertJsonPath('data.3.status', 'needs_setup')
        // Google is set up, but this Google account was never granted the Calendar scopes.
        ->assertJsonPath('data.4.status', 'available')
        ->assertJsonPath('data.4.can_connect', true);
});

test('administrators are told they can set the apps up', function () {
    $this->actingAs(overviewUser('admin'), 'api')->getJson('/api/integrations/overview')->assertOk()->assertJsonPath('can_manage', true);
});

test('the overview requires authentication', function () {
    $this->getJson('/api/integrations/overview')->assertUnauthorized();
});
