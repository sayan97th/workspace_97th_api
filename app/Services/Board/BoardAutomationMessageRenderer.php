<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\BoardItemValue;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Fills in the text an automation writes: a notification, email, Slack message or update body,
 * an email subject and the name of an item it creates. Supported tokens: `{item_name}`,
 * `{board_name}`, `{group_name}` (the group the item is in), `{actor_name}`, `{column_name}`, `{old_value}`, `{new_value}`, `{update_text}`,
 * `{subitem_name}` (the subitem a subitem column trigger fired for), `{mentioned_name}` (the person a
 * mention trigger fired for), `{automation_name}`, `{date}` (today, `YYYY-MM-DD`), `{week}` (ISO week number), `{month}` (e.g.
 * "October 2026") and, for a webhook trigger, `{payload.some.key}` (a value of the JSON it received,
 * read with dot notation). `{column:12}` is the value column 12 holds on the item, as the board shows
 * it (a status by its label, people by name), an item column read from a subitem's parent. An
 * unknown token is left as typed.
 */
class BoardAutomationMessageRenderer
{
    /**
     * `$template` with its tokens filled in, or the default sentence for the trigger when blank.
     *
     * @param  array<string, mixed>  $context  what the trigger knows: `column`, `old_value`, `new_value`, `update_text`, `old_text`/`new_text` (a rename), `payload` (a webhook)
     */
    public function render(?string $template, BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = []): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            $template = $this->defaultMessageTemplate($automation->trigger_type);
        }

        return $this->fill($template, $automation, $item, $actor, $context);
    }

    /**
     * The email subject, `Update on "<item>"` when none was written.
     */
    public function renderSubject(?string $subject, BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = []): string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return $item ? "Update on \"{$item->name}\"" : 'Update from '.($automation->board?->label ?? 'your board');
        }

        return $this->fill($subject, $automation, $item, $actor, $context);
    }

    /**
     * The name of an item an automation creates, "New item" when none was written.
     *
     * @param  array<string, mixed>  $context
     */
    public function renderItemName(?string $name, BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = []): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 'New item';
        }

        return Str::limit($this->fill($name, $automation, $item, $actor, $context), 250, '');
    }

    /**
     * Any other short text an automation writes (a group name, a value it maps), `$fallback` when
     * nothing was written.
     *
     * @param  array<string, mixed>  $context
     */
    public function renderPlain(?string $template, string $fallback, BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context = []): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            return $fallback;
        }

        return Str::limit(trim($this->fill($template, $automation, $item, $actor, $context)), 250, '') ?: $fallback;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fill(string $template, BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context): string
    {
        $column = $context['column'] ?? null;
        $today = Carbon::today();
        $filled = $this->fillColumnTokens($this->fillPayloadTokens($template, $context['payload'] ?? null), $item);

        return strtr($filled, [
            '{item_name}' => $item?->name ?? '',
            '{board_name}' => $item?->board?->label ?? $automation->board?->label ?? '',
            '{group_name}' => $item?->group?->name ?? '',
            '{actor_name}' => $actor?->full_name ?: 'Someone',
            '{column_name}' => $column instanceof BoardColumn ? $this->columnLabel($column) : 'a column',
            '{old_value}' => $column instanceof BoardColumn ? $this->displayValue($column, $context['old_value'] ?? null) : (string) ($context['old_text'] ?? ''),
            '{new_value}' => $column instanceof BoardColumn ? $this->displayValue($column, $context['new_value'] ?? null) : (string) ($context['new_text'] ?? ''),
            '{update_text}' => Str::limit(trim((string) ($context['update_text'] ?? '')), 300),
            '{subitem_name}' => isset($context['subitem_id']) ? (string) (BoardItem::withTrashed()->whereKey((int) $context['subitem_id'])->value('name') ?? '') : '',
            '{mentioned_name}' => isset($context['mentioned_user_id']) ? (string) (User::whereKey((int) $context['mentioned_user_id'])->first()?->full_name ?? '') : '',
            '{automation_name}' => $automation->name ?: 'Automation',
            '{date}' => $today->toDateString(),
            '{week}' => (string) $today->isoWeek(),
            '{month}' => $today->format('F Y'),
        ]);
    }

    /**
     * Replaces every `{payload.path}` with the matching value of a webhook's JSON body, a list or
     * object value as compact JSON and a missing one as empty text.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function fillPayloadTokens(string $template, ?array $payload): string
    {
        if (! str_contains($template, '{payload.')) {
            return $template;
        }

        return (string) preg_replace_callback('/\{payload\.([A-Za-z0-9_.\-]+)\}/', function (array $matches) use ($payload) {
            $value = data_get($payload ?? [], $matches[1]);

            return match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => Str::limit((string) $value, 2000, ''),
                default => Str::limit((string) json_encode($value), 2000, ''),
            };
        }, $template);
    }

    /**
     * Replaces every `{column:<id>}` with what that column holds on `$item`. A column of another
     * table, or a subitem column read from an item, is left empty.
     */
    private function fillColumnTokens(string $template, ?BoardItem $item): string
    {
        if (! str_contains($template, '{column:')) {
            return $template;
        }

        return (string) preg_replace_callback('/\{column:(\d+)\}/', function (array $matches) use ($item) {
            $column = $item ? BoardColumn::find((int) $matches[1]) : null;
            if (! $item || ! $column) {
                return '';
            }

            $subject = $item;
            if ($column->scope === BoardColumn::SCOPE_ITEM) {
                while ($subject->parent_id !== null && ($parent = BoardItem::find($subject->parent_id))) {
                    $subject = $parent;
                }
            } elseif ($item->parent_id === null) {
                return '';
            }

            $value = BoardItemValue::where('item_id', $subject->id)->where('column_id', $column->id)->first()?->value;

            return Str::limit($this->displayValue($column, $value), 500, '');
        }, $template);
    }

    /**
     * `$column` may have been loaded with a partial `select()`, so a missing label is
     * read from the database instead of being rendered as empty text.
     */
    public function columnLabel(BoardColumn $column): string
    {
        return (string) ($column->getAttribute('label') ?? BoardColumn::whereKey($column->id)->value('label') ?? 'a column');
    }

    private function defaultMessageTemplate(string $trigger_type): string
    {
        return match ($trigger_type) {
            BoardAutomation::TRIGGER_STATUS_CHANGED, BoardAutomation::TRIGGER_COLUMN_CHANGED => '{column_name} changed to "{new_value}" on "{item_name}".',
            BoardAutomation::TRIGGER_DATE_ARRIVED => 'The date in {column_name} has arrived on "{item_name}".',
            BoardAutomation::TRIGGER_ITEM_CREATED => 'A new item "{item_name}" was created on {board_name}.',
            BoardAutomation::TRIGGER_SUBITEM_CREATED => 'A new subitem "{item_name}" was created on {board_name}.',
            BoardAutomation::TRIGGER_PERSON_ASSIGNED => '{new_value} was assigned to "{item_name}".',
            BoardAutomation::TRIGGER_UPDATE_POSTED => '{actor_name} posted an update on "{item_name}": {update_text}',
            BoardAutomation::TRIGGER_ITEM_MOVED_TO_GROUP => '"{item_name}" was moved to another group on {board_name}.',
            BoardAutomation::TRIGGER_ITEM_ARCHIVED => '"{item_name}" was archived on {board_name}.',
            BoardAutomation::TRIGGER_ITEM_DELETED => '"{item_name}" was deleted on {board_name}.',
            BoardAutomation::TRIGGER_RECURRING => 'Scheduled reminder from {automation_name} on {board_name}.',
            BoardAutomation::TRIGGER_ITEM_SCAN => 'Scheduled check from {automation_name} on "{item_name}".',
            BoardAutomation::TRIGGER_ALL_SUBITEMS_STATUS => 'Every subitem of "{item_name}" is now {new_value}.',
            BoardAutomation::TRIGGER_ALL_GROUP_ITEMS_STATUS => 'Every item in the group of "{item_name}" is now {new_value}.',
            BoardAutomation::TRIGGER_FORM_SUBMITTED => 'A form was submitted and created "{item_name}" on {board_name}.',
            BoardAutomation::TRIGGER_NAME_CHANGED => '"{old_value}" was renamed to "{new_value}" on {board_name}.',
            BoardAutomation::TRIGGER_DATE_CHANGED => '{column_name} changed to {new_value} on "{item_name}".',
            BoardAutomation::TRIGGER_WEBHOOK_RECEIVED => '{automation_name} received a webhook on {board_name}.',
            BoardAutomation::TRIGGER_BUTTON_CLICKED => '{actor_name} pressed {column_name} on "{item_name}".',
            BoardAutomation::TRIGGER_NUMBER_THRESHOLD => '{column_name} reached {new_value} on "{item_name}".',
            BoardAutomation::TRIGGER_ITEM_MOVED_TO_BOARD => '"{item_name}" was moved to {board_name}.',
            BoardAutomation::TRIGGER_ITEM_RESTORED => '"{item_name}" was restored on {board_name}.',
            BoardAutomation::TRIGGER_CHECKLIST_COMPLETED => 'Every task of {column_name} is done on "{item_name}".',
            BoardAutomation::TRIGGER_CHECKLIST_ITEM_CHECKED => '"{new_value}" was checked in {column_name} on "{item_name}".',
            BoardAutomation::TRIGGER_SUBITEM_COLUMN_CHANGED => '{column_name} changed to "{new_value}" on the subitem "{subitem_name}" of "{item_name}".',
            BoardAutomation::TRIGGER_USER_MENTIONED => '{actor_name} mentioned {mentioned_name} on "{item_name}": {update_text}',
            BoardAutomation::TRIGGER_UPDATE_REPLIED => '{actor_name} replied on "{item_name}": {update_text}',
            BoardAutomation::TRIGGER_UPDATE_KEYWORD => '{actor_name} wrote on "{item_name}": {update_text}',
            default => 'An automation ran on "{item_name}".',
        };
    }

    /**
     * A human readable version of a raw stored value: a status/label option id becomes
     * its label, a people value becomes names, other arrays are joined with commas.
     */
    public function displayValue(BoardColumn $column, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (in_array($column->type, [BoardColumn::TYPE_STATUS, BoardColumn::TYPE_LABEL, BoardColumn::TYPE_DROPDOWN], true)) {
            $labels = collect($column->config['options'] ?? [])->mapWithKeys(fn (array $option) => [(string) ($option['id'] ?? '') => (string) ($option['label'] ?? '')]);

            return collect((array) $value)->map(fn ($id) => $labels->get((string) $id, (string) $id))->implode(', ');
        }

        if ($column->type === BoardColumn::TYPE_PEOPLE && is_array($value)) {
            return User::whereIn('id', $value)->get()->map(fn (User $user) => $user->full_name)->implode(', ');
        }

        if ($column->type === BoardColumn::TYPE_CHECKBOX) {
            return in_array($value, [true, 1, '1', 'true'], true) ? 'Checked' : 'Unchecked';
        }

        if ($column->type === BoardColumn::TYPE_TIMELINE && is_array($value)) {
            return trim(($value['start'] ?? '').' to '.($value['end'] ?? ''), ' to');
        }

        if ($column->type === BoardColumn::TYPE_LINK && is_array($value)) {
            return (string) (($value['text'] ?? '') !== '' ? $value['text'] : ($value['url'] ?? ''));
        }

        if ($column->type === BoardColumn::TYPE_CHECKLIST && is_array($value)) {
            $tasks = array_values(array_filter($value, 'is_array'));
            if ($tasks === []) {
                return collect($value)->filter(fn ($entry) => is_scalar($entry))->implode(', ');
            }

            return count(array_filter($tasks, fn (array $task) => ! empty($task['is_done']))).' of '.count($tasks).' done';
        }

        if ($column->type === BoardColumn::TYPE_TIME_TRACKING && is_array($value)) {
            $seconds = (int) ($value['seconds'] ?? 0);

            return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return is_array($value) ? collect($value)->flatten()->implode(', ') : (string) $value;
    }
}
