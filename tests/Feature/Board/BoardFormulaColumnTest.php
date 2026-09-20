<?php

use App\Models\BoardColumn;
use App\Models\BoardView;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNavigationItem;
use App\Support\FormulaReferences;

function createFormulaTestBoard(): WorkspaceNavigationItem
{
    $workspace = Workspace::factory()->create();

    return WorkspaceNavigationItem::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => WorkspaceNavigationItem::TYPE_LEAF,
        'parent_id' => null,
    ]);
}

/**
 * @return array{0: WorkspaceNavigationItem, 1: BoardView, 2: BoardColumn, 3: BoardColumn}
 */
function createFormulaTestTable(): array
{
    $board = createFormulaTestBoard();
    $view = BoardView::factory()->create(['board_id' => $board->id, 'is_primary' => true, 'label' => 'Main table']);
    $budget = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'budget', 'type' => BoardColumn::TYPE_NUMBER, 'position' => 0]);
    $spent = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'spent', 'type' => BoardColumn::TYPE_NUMBER, 'position' => 1]);

    return [$board, $view, $budget, $spent];
}

test('a formula column saves an expression that reads columns by id', function () {
    $user = User::factory()->create();
    [$board, $view, $budget, $spent] = createFormulaTestTable();
    $formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'key' => 'left', 'type' => BoardColumn::TYPE_FORMULA, 'position' => 2]);
    $expression = "IF({#{$budget->id}} > 0, {#{$budget->id}} - {#{$spent->id}}, \"{Not a column}\") & {#__name}";

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$formula->id}", ['config' => ['expression' => $expression]])
        ->assertOk()
        ->assertJsonPath('column.config.expression', $expression);
});

test('a formula column can be created with an expression', function () {
    $user = User::factory()->create();
    [$board, $view, $budget] = createFormulaTestTable();

    $this->actingAs($user, 'api')
        ->postJson("/api/boards/{$board->id}/columns", [
            'view_id' => $view->id,
            'key' => 'double',
            'label' => 'Double',
            'type' => BoardColumn::TYPE_FORMULA,
            'config' => ['expression' => "{#{$budget->id}} * 2"],
        ])
        ->assertCreated()
        ->assertJsonPath('column.config.expression', "{#{$budget->id}} * 2");
});

test('a formula that reads a column from another table is rejected', function () {
    $user = User::factory()->create();
    [$board, $view] = createFormulaTestTable();
    $other_board = createFormulaTestBoard();
    $other_view = BoardView::factory()->create(['board_id' => $other_board->id, 'is_primary' => true]);
    $foreign = BoardColumn::factory()->create(['board_id' => $other_board->id, 'board_view_id' => $other_view->id, 'type' => BoardColumn::TYPE_NUMBER]);
    $formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FORMULA]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$formula->id}", ['config' => ['expression' => "{#{$foreign->id}} + 1"]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('config.expression');
});

test('a formula that reads a column from the other scope is rejected', function () {
    $user = User::factory()->create();
    [$board, $view, $budget] = createFormulaTestTable();
    $subitem_formula = BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'scope' => BoardColumn::SCOPE_SUBITEM,
        'type' => BoardColumn::TYPE_FORMULA,
    ]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$subitem_formula->id}", ['config' => ['expression' => "{#{$budget->id}} + 1"]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('config.expression');
});

test('a formula cannot read itself, another formula or an unsupported column type', function () {
    $user = User::factory()->create();
    [$board, $view] = createFormulaTestTable();
    $formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FORMULA]);
    $other_formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FORMULA]);
    $files = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FILES]);

    foreach ([$formula->id, $other_formula->id, $files->id] as $referenced_id) {
        $this->actingAs($user, 'api')
            ->patchJson("/api/boards/{$board->id}/columns/{$formula->id}", ['config' => ['expression' => "{#{$referenced_id}} + 1"]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.expression');
    }
});

test('a malformed formula expression is rejected', function (string $expression) {
    $user = User::factory()->create();
    [$board, $view] = createFormulaTestTable();
    $formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FORMULA]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$formula->id}", ['config' => ['expression' => $expression]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('config.expression');
})->with([
    'unclosed parenthesis' => 'SUM(1, 2',
    'extra closing parenthesis' => '1 + 2)',
    'unclosed text' => 'CONCATENATE("open, 1)',
    'unclosed column reference' => '{#12 + 1',
    'column named by title instead of id' => '{Budget} + 1',
    'empty column reference' => '{#} + 1',
    'too long' => str_repeat('1+', 1001).'1',
]);

test('a saved expression that only mentions a column inside text is not treated as a reference', function () {
    $user = User::factory()->create();
    [$board, $view] = createFormulaTestTable();
    $formula = BoardColumn::factory()->create(['board_id' => $board->id, 'board_view_id' => $view->id, 'type' => BoardColumn::TYPE_FORMULA]);

    $this->actingAs($user, 'api')
        ->patchJson("/api/boards/{$board->id}/columns/{$formula->id}", ['config' => ['expression' => '"Use {#999999} to read a column"']])
        ->assertOk();
});

test('duplicating a view repoints a formula at the cloned columns', function () {
    $user = User::factory()->create();
    [$board, $view, $budget, $spent] = createFormulaTestTable();
    BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'key' => 'left',
        'type' => BoardColumn::TYPE_FORMULA,
        'position' => 2,
        'config' => ['expression' => "{#{$budget->id}} - {#{$spent->id}} & \"{#{$budget->id}}\" & {#__name}"],
    ]);
    BoardColumn::factory()->create([
        'board_id' => $board->id,
        'board_view_id' => $view->id,
        'key' => 'old_style',
        'type' => BoardColumn::TYPE_FORMULA,
        'position' => 3,
        'config' => ['operation' => 'sum', 'source_column_ids' => [$budget->id, $spent->id]],
    ]);

    $response = $this->actingAs($user, 'api')->postJson("/api/boards/{$board->id}/views/{$view->id}/duplicate")->assertCreated();

    $copy_columns = BoardColumn::where('board_view_id', $response->json('view.id'))->get()->keyBy('key');
    $new_budget = $copy_columns['budget']->id;
    $new_spent = $copy_columns['spent']->id;

    expect($copy_columns['left']->config['expression'])->toBe("{#{$new_budget}} - {#{$new_spent}} & \"{#{$budget->id}}\" & {#__name}")
        ->and($copy_columns['old_style']->config['source_column_ids'])->toBe([$new_budget, $new_spent])
        // The source view's own formulas are untouched.
        ->and(BoardColumn::where('board_view_id', $view->id)->where('key', 'left')->first()->config['expression'])->toContain("{#{$budget->id}}");
});

test('formula references are read and remapped outside of text', function () {
    $expression = 'IF({#5} > {#7}, "{#5}", {#5} & {#__name})';

    expect(FormulaReferences::columnIds($expression))->toBe([5, 7])
        ->and(FormulaReferences::remap($expression, [5 => 50, 7 => 70]))->toBe('IF({#50} > {#70}, "{#5}", {#50} & {#__name})')
        ->and(FormulaReferences::remap('{#5} + {#6}', [5 => 50]))->toBe('{#50} + {#6}')
        ->and(FormulaReferences::structureProblem($expression))->toBeNull()
        ->and(FormulaReferences::structureProblem('"say ""hi"" {#1}"'))->toBeNull();
});
