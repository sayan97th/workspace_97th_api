<?php

use App\Events\WebsocketTestPong;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('staff can read the server broadcasting status', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    config(['broadcasting.default' => 'reverb']);
    config(['broadcasting.connections.reverb.options.host' => 'localhost']);
    config(['broadcasting.connections.reverb.options.port' => 8081]);
    config(['broadcasting.connections.reverb.options.scheme' => 'http']);

    $response = $this->actingAs($staff, 'api')->getJson('/api/admin/websocket-test/status');

    $response->assertOk()->assertJson([
        'data' => [
            'broadcast_driver' => 'reverb',
            'is_reverb_driver' => true,
            'reverb' => [
                'host' => 'localhost',
                'port' => 8081,
                'scheme' => 'http',
            ],
        ],
    ]);
});

test('status flags a non reverb broadcast driver', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    config(['broadcasting.default' => 'log']);

    $response = $this->actingAs($staff, 'api')->getJson('/api/admin/websocket-test/status');

    $response->assertOk()->assertJson([
        'data' => [
            'broadcast_driver' => 'log',
            'is_reverb_driver' => false,
        ],
    ]);
});

test('a client cannot access the websocket test endpoints', function () {
    $client = User::factory()->create();
    $client->assignRole('client');

    $this->actingAs($client, 'api')->getJson('/api/admin/websocket-test/status')->assertForbidden();
    $this->actingAs($client, 'api')->postJson('/api/admin/websocket-test/ping', ['ping_id' => 'abc'])->assertForbidden();
});

test('ping dispatches a pong broadcast on the caller private channel', function () {
    Event::fake([WebsocketTestPong::class]);

    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $response = $this->actingAs($staff, 'api')->postJson('/api/admin/websocket-test/ping', [
        'ping_id' => 'ping-123',
        'client_sent_at' => now()->toISOString(),
    ]);

    $response->assertOk()->assertJson([
        'data' => [
            'ping_id' => 'ping-123',
            'channel' => 'websocket-test.'.$staff->id,
        ],
    ]);

    Event::assertDispatched(WebsocketTestPong::class, function (WebsocketTestPong $event) use ($staff) {
        return $event->ping_id === 'ping-123' && $event->recipient->is($staff);
    });
});

test('ping requires a ping id', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff, 'api')
        ->postJson('/api/admin/websocket-test/ping', [])
        ->assertStatus(422);
});
