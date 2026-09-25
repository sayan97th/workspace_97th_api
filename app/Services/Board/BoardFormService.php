<?php

namespace App\Services\Board;

use App\Enums\BoardViewType;
use App\Models\BoardColumn;
use App\Models\BoardGroup;
use App\Models\BoardItem;
use App\Models\BoardView;
use App\Models\WorkspaceNavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * monday.com's Form view. A `form`-type tab collects answers for the
 * columns of another tab (`source_view_id`, the board's main table by
 * default) and every submission becomes a new item in `target_group_id`.
 *
 * The builder settings live in `board_views.form_config`, and the form is
 * reachable publicly, without an account, through `board_views.form_token`.
 */
class BoardFormService
{
    /** Column types a form question can be built from. */
    public const SUPPORTED_TYPES = [
        BoardColumn::TYPE_TEXT,
        BoardColumn::TYPE_LONG_TEXT,
        BoardColumn::TYPE_NUMBER,
        BoardColumn::TYPE_EMAIL,
        BoardColumn::TYPE_PHONE,
        BoardColumn::TYPE_LINK,
        BoardColumn::TYPE_DATE,
        BoardColumn::TYPE_TIMELINE,
        BoardColumn::TYPE_STATUS,
        BoardColumn::TYPE_LABEL,
        BoardColumn::TYPE_DROPDOWN,
        BoardColumn::TYPE_CHECKBOX,
        BoardColumn::TYPE_RATING,
    ];

    /** Tabs that own columns and groups, so a form can write into them. */
    private const SOURCE_VIEW_TYPES = ['table', 'kanban', 'calendar', 'gantt'];

    public function __construct(
        private readonly BoardItemValueService $value_service,
        private readonly BoardAutomationService $automation_service,
    ) {}

    /**
     * Everything the builder needs: the saved settings merged over the
     * defaults, the columns and groups it can pick from, and the public token
     * (created the first time the builder opens).
     *
     * @return array<string, mixed>
     */
    public function builder(WorkspaceNavigationItem $board, BoardView $form_view): array
    {
        if ($form_view->form_token === null) {
            $form_view->update(['form_token' => $this->newToken()]);
        }

        $source_view = $this->resolveSourceView($board, $form_view->form_config['source_view_id'] ?? null);
        $columns = $source_view ? $this->questionColumns($source_view) : collect();
        $groups = $source_view ? $this->activeGroups($source_view) : collect();

        return [
            'view_id' => $form_view->id,
            'token' => $form_view->form_token,
            'config' => $this->resolvedConfig($board, $form_view, $source_view, $columns, $groups),
            'columns' => $columns->map(fn (BoardColumn $column) => $this->presentColumn($column))->values(),
            'groups' => $groups->map(fn (BoardGroup $group) => ['id' => $group->id, 'name' => $group->name, 'color' => $group->accent_color])->values(),
            'source_views' => $board->views()
                ->whereIn('view_type', self::SOURCE_VIEW_TYPES)
                ->orderBy('position')
                ->get(['id', 'label'])
                ->map(fn (BoardView $view) => ['id' => $view->id, 'label' => $view->label])
                ->values(),
        ];
    }

    /**
     * Saves the builder settings after checking every id belongs to the board.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(WorkspaceNavigationItem $board, BoardView $form_view, array $input): void
    {
        $config = $form_view->form_config ?? [];

        if (array_key_exists('source_view_id', $input)) {
            $source_view = $board->views()->whereIn('view_type', self::SOURCE_VIEW_TYPES)->find($input['source_view_id']);

            if ($source_view === null) {
                throw ValidationException::withMessages(['source_view_id' => 'Pick a table from this board.']);
            }

            // Questions and the target group belong to the old source tab.
            if ((int) ($config['source_view_id'] ?? 0) !== $source_view->id) {
                unset($config['questions'], $config['target_group_id']);
            }
            $config['source_view_id'] = $source_view->id;
        }

        $source_view = $this->resolveSourceView($board, $config['source_view_id'] ?? null);

        if (array_key_exists('target_group_id', $input)) {
            $group_exists = $input['target_group_id'] === null
                || ($source_view && $this->activeGroups($source_view)->contains('id', (int) $input['target_group_id']));

            if (! $group_exists) {
                throw ValidationException::withMessages(['target_group_id' => 'Pick a group from the selected table.']);
            }
            $config['target_group_id'] = $input['target_group_id'];
        }

        if (array_key_exists('questions', $input)) {
            $column_ids = $source_view ? $this->questionColumns($source_view)->pluck('id')->all() : [];

            $config['questions'] = collect($input['questions'])
                ->filter(fn ($question) => in_array((int) $question['column_id'], $column_ids, true))
                ->map(fn ($question) => [
                    'column_id' => (int) $question['column_id'],
                    'label' => filled($question['label'] ?? null) ? $question['label'] : null,
                    'description' => filled($question['description'] ?? null) ? $question['description'] : null,
                    'is_required' => (bool) ($question['is_required'] ?? false),
                    'is_visible' => (bool) ($question['is_visible'] ?? true),
                ])
                ->values()
                ->all();
        }

        foreach (['title', 'description', 'name_label', 'submit_label', 'success_message', 'accent_color', 'is_active'] as $key) {
            if (array_key_exists($key, $input)) {
                $config[$key] = $input[$key];
            }
        }

        $form_view->update(['form_config' => $config]);
    }

    /**
     * Replaces the public token, which breaks every link shared so far.
     */
    public function regenerateToken(BoardView $form_view): string
    {
        $form_view->update(['form_token' => $this->newToken()]);

        return $form_view->form_token;
    }

    /**
     * Finds an active form by its public token, or aborts with a 404.
     */
    public function findPublicForm(string $token): BoardView
    {
        $form_view = BoardView::query()
            ->where('form_token', $token)
            ->where('view_type', BoardViewType::Form->value)
            ->with('board')
            ->first();

        abort_if($form_view === null || $form_view->board === null || $form_view->board->is_archived, 404, 'This form does not exist.');
        abort_if(($form_view->form_config['is_active'] ?? true) === false, 404, 'This form is no longer accepting responses.');

        return $form_view;
    }

    /**
     * What the public page renders: only the visible questions, with just
     * the option lists they need (never other board data).
     *
     * @return array<string, mixed>
     */
    public function publicDefinition(BoardView $form_view): array
    {
        $board = $form_view->board;
        $source_view = $this->resolveSourceView($board, $form_view->form_config['source_view_id'] ?? null);
        $columns = $source_view ? $this->questionColumns($source_view) : collect();
        $config = $this->resolvedConfig($board, $form_view, $source_view, $columns, $source_view ? $this->activeGroups($source_view) : collect());
        $columns_by_id = $columns->keyBy('id');

        return [
            'title' => $config['title'],
            'description' => $config['description'],
            'name_label' => $config['name_label'],
            'submit_label' => $config['submit_label'],
            'success_message' => $config['success_message'],
            'accent_color' => $config['accent_color'],
            'questions' => collect($config['questions'])
                ->filter(fn ($question) => $question['is_visible'] && $columns_by_id->has($question['column_id']))
                ->map(function ($question) use ($columns_by_id) {
                    $column = $columns_by_id->get($question['column_id']);

                    return [
                        'column_id' => $column->id,
                        'type' => $column->type,
                        'label' => $question['label'] ?? $column->label,
                        'description' => $question['description'],
                        'is_required' => $question['is_required'],
                        'options' => $this->presentColumn($column)['options'],
                    ];
                })
                ->values(),
        ];
    }

    /**
     * Validates a public submission and turns it into a new item.
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(BoardView $form_view, array $input): BoardItem
    {
        $board = $form_view->board;
        $definition = $this->publicDefinition($form_view);
        $source_view = $this->resolveSourceView($board, $form_view->form_config['source_view_id'] ?? null);
        abort_if($source_view === null, 404, 'This form is not connected to a table.');

        $name = trim((string) ($input['name'] ?? ''));
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $errors = [];
        $values = [];

        if ($name === '') {
            $errors['name'] = ["{$definition['name_label']} is required."];
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = ["{$definition['name_label']} may not be longer than 255 characters."];
        }

        foreach ($definition['questions'] as $question) {
            $raw = $answers[$question['column_id']] ?? $answers[(string) $question['column_id']] ?? null;
            $key = "answers.{$question['column_id']}";

            if ($this->isBlank($raw)) {
                if ($question['is_required']) {
                    $errors[$key] = ["{$question['label']} is required."];
                }

                continue;
            }

            $result = $this->normalizeAnswer($question, $raw);
            if (is_string($result['error'])) {
                $errors[$key] = [$result['error']];

                continue;
            }

            $values[(string) $question['column_id']] = $result['value'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $board_item = DB::transaction(function () use ($board, $form_view, $source_view, $name, $values) {
            $group = $this->targetGroup($form_view, $source_view);

            $board_item = $board->items()->create([
                'group_id' => $group->id,
                'parent_id' => null,
                'name' => $name,
                'position' => (int) $board->items()->where('group_id', $group->id)->whereNull('parent_id')->max('position') + 1,
                'created_by_id' => null,
            ]);

            $this->value_service->assignAutoNumbers($board_item, BoardColumn::SCOPE_ITEM);

            if ($values !== []) {
                $this->value_service->sync($board, $board_item, $values, null, false);
            }

            return $board_item;
        });

        // Same "When an item is created" automations a regular new item runs.
        $this->automation_service->handleItemCreated($board_item->fresh('group'), null);

        return $board_item;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array{value: mixed, error: string|null}
     */
    private function normalizeAnswer(array $question, mixed $raw): array
    {
        $label = $question['label'];
        $option_ids = collect($question['options'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $ok = fn (mixed $value) => ['value' => $value, 'error' => null];
        $fail = fn (string $message) => ['value' => null, 'error' => $message];

        switch ($question['type']) {
            case BoardColumn::TYPE_TEXT:
            case BoardColumn::TYPE_LONG_TEXT:
                $limit = $question['type'] === BoardColumn::TYPE_TEXT ? 2000 : 10000;

                return is_scalar($raw) && mb_strlen((string) $raw) <= $limit
                    ? $ok(trim((string) $raw))
                    : $fail("{$label} may not be longer than {$limit} characters.");

            case BoardColumn::TYPE_NUMBER:
                return is_numeric($raw) ? $ok($raw + 0) : $fail("{$label} must be a number.");

            case BoardColumn::TYPE_EMAIL:
                return is_string($raw) && filter_var(trim($raw), FILTER_VALIDATE_EMAIL) ? $ok(trim($raw)) : $fail("{$label} must be a valid email address.");

            case BoardColumn::TYPE_PHONE:
                return is_string($raw) && preg_match('/^[0-9+()\-.\s]{4,30}$/', trim($raw)) ? $ok(trim($raw)) : $fail("{$label} must be a valid phone number.");

            case BoardColumn::TYPE_LINK:
                $url = is_array($raw) ? ($raw['url'] ?? '') : $raw;
                if (! is_string($url) || ! filter_var(trim($url), FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', trim($url))) {
                    return $fail("{$label} must be a valid link starting with http:// or https://.");
                }

                return $ok(['url' => trim($url), 'text' => trim($url)]);

            case BoardColumn::TYPE_DATE:
                return $this->isDate($raw) ? $ok($raw) : $fail("{$label} must be a valid date.");

            case BoardColumn::TYPE_TIMELINE:
                $start = is_array($raw) ? ($raw['start'] ?? null) : null;
                $end = is_array($raw) ? ($raw['end'] ?? null) : null;
                if (! $this->isDate($start) || ! $this->isDate($end) || $start > $end) {
                    return $fail("{$label} must be a valid date range.");
                }

                // Stored as "start..end", the format the Table view's
                // Timeline cell reads.
                return $ok("{$start}..{$end}");

            case BoardColumn::TYPE_STATUS:
            case BoardColumn::TYPE_LABEL:
                return is_scalar($raw) && in_array((string) $raw, $option_ids, true) ? $ok((string) $raw) : $fail("Pick a valid option for {$label}.");

            case BoardColumn::TYPE_DROPDOWN:
                $picked = array_values(array_unique(array_map('strval', is_array($raw) ? $raw : [$raw])));

                return array_diff($picked, $option_ids) === [] ? $ok($picked) : $fail("Pick valid options for {$label}.");

            case BoardColumn::TYPE_CHECKBOX:
                return $ok(filter_var($raw, FILTER_VALIDATE_BOOLEAN));

            case BoardColumn::TYPE_RATING:
                return is_numeric($raw) && (int) $raw >= 1 && (int) $raw <= 5 ? $ok((int) $raw) : $fail("{$label} must be between 1 and 5.");
        }

        return $fail("{$label} can't be answered from a form.");
    }

    private function isBlank(mixed $raw): bool
    {
        return $raw === null
            || (is_string($raw) && trim($raw) === '')
            || (is_array($raw) && array_filter($raw, fn ($value) => ! $this->isBlank($value)) === [])
            || $raw === false;
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    /**
     * The saved settings merged over the defaults. Questions default to every
     * supported column, and a column added after the form was last saved
     * shows up hidden at the end, so the builder can switch it on.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @param  Collection<int, BoardGroup>  $groups
     * @return array<string, mixed>
     */
    private function resolvedConfig(WorkspaceNavigationItem $board, BoardView $form_view, ?BoardView $source_view, Collection $columns, Collection $groups): array
    {
        $saved = $form_view->form_config ?? [];
        $column_ids = $columns->pluck('id')->all();

        $questions = collect($saved['questions'] ?? [])
            ->filter(fn ($question) => in_array((int) $question['column_id'], $column_ids, true))
            ->map(fn ($question) => [
                'column_id' => (int) $question['column_id'],
                'label' => $question['label'] ?? null,
                'description' => $question['description'] ?? null,
                'is_required' => (bool) ($question['is_required'] ?? false),
                'is_visible' => (bool) ($question['is_visible'] ?? true),
            ])
            ->values();

        $has_saved_questions = array_key_exists('questions', $saved);
        foreach ($columns as $column) {
            if (! $questions->contains('column_id', $column->id)) {
                $questions->push([
                    'column_id' => $column->id,
                    'label' => null,
                    'description' => null,
                    'is_required' => false,
                    'is_visible' => ! $has_saved_questions,
                ]);
            }
        }

        $target_group_id = $saved['target_group_id'] ?? null;
        if ($target_group_id !== null && ! $groups->contains('id', (int) $target_group_id)) {
            $target_group_id = null;
        }

        return [
            'source_view_id' => $source_view?->id,
            'target_group_id' => $target_group_id,
            'title' => $saved['title'] ?? $board->label,
            'description' => $saved['description'] ?? null,
            'name_label' => $saved['name_label'] ?? 'Name',
            'submit_label' => $saved['submit_label'] ?? 'Submit',
            'success_message' => $saved['success_message'] ?? 'Thank you, your response was submitted.',
            'accent_color' => $saved['accent_color'] ?? '#6161ff',
            'is_active' => (bool) ($saved['is_active'] ?? true),
            'questions' => $questions->all(),
        ];
    }

    private function resolveSourceView(WorkspaceNavigationItem $board, mixed $configured_id): ?BoardView
    {
        $views = $board->views()->whereIn('view_type', self::SOURCE_VIEW_TYPES);

        return ($configured_id !== null ? (clone $views)->find((int) $configured_id) : null)
            ?? (clone $views)->where('is_primary', true)->first()
            ?? $views->orderBy('position')->first();
    }

    /**
     * Item-scope columns of the source tab a form can ask about. Columns with
     * a view or edit restriction are left out, since an anonymous respondent
     * never passes one.
     *
     * @return Collection<int, BoardColumn>
     */
    private function questionColumns(BoardView $source_view): Collection
    {
        return $source_view->columns()
            ->where('scope', BoardColumn::SCOPE_ITEM)
            ->whereIn('type', self::SUPPORTED_TYPES)
            ->whereNull('view_restriction')
            ->whereNull('edit_restriction')
            ->get();
    }

    /**
     * @return Collection<int, BoardGroup>
     */
    private function activeGroups(BoardView $source_view): Collection
    {
        return $source_view->groups()->where('is_archived', false)->get();
    }

    /**
     * The group a submission lands in: the configured one, else the first
     * group of the source tab, else a new "Form submissions" group.
     */
    private function targetGroup(BoardView $form_view, BoardView $source_view): BoardGroup
    {
        $groups = $this->activeGroups($source_view);
        $target_group_id = $form_view->form_config['target_group_id'] ?? null;

        return ($target_group_id !== null ? $groups->firstWhere('id', (int) $target_group_id) : null)
            ?? $groups->first()
            ?? $source_view->groups()->create([
                'board_id' => $source_view->board_id,
                'name' => 'Form submissions',
                'accent_color' => '#579bfc',
                'position' => 0,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentColumn(BoardColumn $column): array
    {
        $has_options = in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN], true);

        return [
            'id' => $column->id,
            'label' => $column->label,
            'type' => $column->type,
            'options' => $has_options
                ? collect($column->config['options'] ?? [])
                    ->filter(fn ($option) => ($option['is_active'] ?? true) !== false)
                    ->map(fn ($option) => ['id' => (string) $option['id'], 'label' => (string) $option['label'], 'color' => (string) ($option['color'] ?? '#c4c4c4')])
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function newToken(): string
    {
        return Str::random(40);
    }
}
