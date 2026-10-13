<?php

use App\Models\BoardColumn;
use App\Models\BoardItem;

/**
 * A Rating column's own scale (`config.rating_max`) and icon (`config.rating_icon`),
 * set from the column menu's "Customize Rating column" and honored by the public form.
 * Reuses `createFormBoard()` from BoardFormAndShareTest.
 */
function createRatingColumn(int $board_id, int $view_id, array $config = []): BoardColumn
{
    return BoardColumn::factory()->create([
        'board_id' => $board_id,
        'board_view_id' => $view_id,
        'type' => BoardColumn::TYPE_RATING,
        'label' => 'Score',
        'position' => 3,
        'config' => $config,
    ]);
}

test('a rating column keeps its scale and icon, within the allowed range', function () {
    [$owner, , $board, , $table_view] = createFormBoard();
    $rating = createRatingColumn($board->id, $table_view->id);

    expect($rating->ratingMax())->toBe(BoardColumn::RATING_DEFAULT_MAX);

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$rating->id}", ['config' => ['rating_max' => 10, 'rating_icon' => 'heart']])
        ->assertOk();

    expect($rating->fresh()->ratingMax())->toBe(10);
    expect($rating->fresh()->config['rating_icon'])->toBe('heart');

    $this->actingAs($owner, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$rating->id}", ['config' => ['rating_max' => 11, 'rating_icon' => 'rocket']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['config.rating_max', 'config.rating_icon']);
});

test('the public form offers and accepts the rating column scale', function () {
    [$owner, , $board, , $table_view, , , $form_view] = createFormBoard();
    $rating = createRatingColumn($board->id, $table_view->id, ['rating_max' => 8]);
    $this->actingAs($owner, 'api')->patchJson("/api/boards/{$board->id}/views/{$form_view->id}/form", [
        'questions' => [['column_id' => $rating->id, 'is_visible' => true]],
    ])->assertOk();
    $token = $form_view->fresh()->form_token;
    auth()->forgetGuards();

    $this->getJson("/api/public/forms/{$token}")->assertJsonPath('questions.0.rating_max', 8);

    $this->postJson("/api/public/forms/{$token}/submissions", ['name' => 'Too high', 'answers' => [(string) $rating->id => 9]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["answers.{$rating->id}"]);

    $this->postJson("/api/public/forms/{$token}/submissions", ['name' => 'Top score', 'answers' => [(string) $rating->id => 8]])->assertCreated();

    expect(BoardItem::where('name', 'Top score')->firstOrFail()->values->first()->value)->toBe(8);
});
