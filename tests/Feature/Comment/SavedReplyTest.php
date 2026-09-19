<?php

use App\Models\SavedReply;
use App\Models\User;

test('a user can create, list, update and delete their saved replies', function () {
    $user = User::factory()->create();

    $created = $this->actingAs($user, 'api')->postJson('/api/saved-replies', [
        'title' => '  Weekly status  ',
        'body' => "**Done**\n\n- Item one",
    ])->assertCreated()->assertJsonPath('data.title', 'Weekly status');

    $id = $created->json('data.id');

    $this->actingAs($user, 'api')->getJson('/api/saved-replies')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', "**Done**\n\n- Item one");

    $this->actingAs($user, 'api')->patchJson("/api/saved-replies/{$id}", ['title' => 'Friday status'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Friday status');

    $this->actingAs($user, 'api')->deleteJson("/api/saved-replies/{$id}")->assertOk();
    $this->assertDatabaseMissing('saved_replies', ['id' => $id]);
});

test('saved replies are private to their owner', function () {
    $owner = User::factory()->create();
    $reply = SavedReply::create(['user_id' => $owner->id, 'title' => 'Mine', 'body' => 'Secret template']);
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'api')->getJson('/api/saved-replies')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($stranger, 'api')->patchJson("/api/saved-replies/{$reply->id}", ['title' => 'Stolen'])->assertForbidden();
    $this->actingAs($stranger, 'api')->deleteJson("/api/saved-replies/{$reply->id}")->assertForbidden();

    expect($reply->fresh()->title)->toBe('Mine');
});

test('a saved reply needs a title and a body within the limits', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->postJson('/api/saved-replies', ['title' => '', 'body' => 'x'])->assertUnprocessable();
    $this->actingAs($user, 'api')->postJson('/api/saved-replies', ['title' => 'Ok', 'body' => ''])->assertUnprocessable();
    $this->actingAs($user, 'api')->postJson('/api/saved-replies', ['title' => str_repeat('a', 61), 'body' => 'x'])->assertUnprocessable();
    $this->actingAs($user, 'api')->postJson('/api/saved-replies', ['title' => 'Ok', 'body' => str_repeat('a', 5001)])->assertUnprocessable();
});

test('a user cannot keep more than the maximum number of saved replies', function () {
    $user = User::factory()->create();
    foreach (range(1, SavedReply::MAX_PER_USER) as $index) {
        SavedReply::create(['user_id' => $user->id, 'title' => "Reply {$index}", 'body' => 'Body']);
    }

    $this->actingAs($user, 'api')->postJson('/api/saved-replies', ['title' => 'One too many', 'body' => 'Body'])
        ->assertUnprocessable();
});
