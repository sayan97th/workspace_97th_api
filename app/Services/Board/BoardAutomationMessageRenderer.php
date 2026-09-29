<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;
use App\Models\BoardColumn;
use App\Models\BoardItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Fills in the text an automation writes: a notification, email, Slack message or update body,
 * an email subject and the name of an item it creates. Supported tokens: `{item_name}`,
 * `{board_name}`, `{actor_name}`, `{column_name}`, `{old_value}`, `{new_value}`, `{update_text}`,
 * `{automation_name}` and `{date}` (today, `YYYY-MM-DD`). An unknown token is left as typed.
 */
class BoardAutomationMessageRenderer
{
    /**
     * `$template` with its tokens filled in, or the default sentence for the trigger when blank.
     *
     * @param  array<string, mixed>  $context  what the trigger knows: `column`, `old_value`, `new_value`, `update_text`
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
     * @param  array<string, mixed>  $context
     */
    private function fill(string $template, BoardAutomation $automation, ?BoardItem $item, ?User $actor, array $context): string
    {
        $column = $context['column'] ?? null;

        return strtr($template, [
            '{item_name}' => $item?->name ?? '',
            '{board_name}' => $item?->board?->label ?? $automation->board?->label ?? '',
            '{actor_name}' => $actor?->full_name ?: 'Someone',
            '{column_name}' => $column instanceof BoardColumn ? $this->columnLabel($column) : 'a column',
            '{old_value}' => $column instanceof BoardColumn ? $this->displayValue($column, $context['old_value'] ?? null) : '',
            '{new_value}' => $column instanceof BoardColumn ? $this->displayValue($column, $context['new_value'] ?? null) : '',
            '{update_text}' => Str::limit(trim((string) ($context['update_text'] ?? '')), 300),
            '{automation_name}' => $automation->name ?: 'Automation',
            '{date}' => Carbon::today()->toDateString(),
        ]);
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

        return is_array($value) ? collect($value)->flatten()->implode(', ') : (string) $value;
    }
}
