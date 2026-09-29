<?php

namespace App\Services\Board;

use App\Models\BoardAutomationRunLog;
use App\Models\BoardItem;

/**
 * What came of one automation action, written to the run history by {@see BoardAutomationService}.
 *
 * `stops_chain` is set once the item left the tab (archived, deleted, moved to another board), so
 * the actions after it are skipped. `created_item` is the item a "create an item" action made,
 * which the actions after it act on when the automation has no triggering item (recurring ones).
 */
final class BoardAutomationActionOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly bool $stops_chain = false,
        public readonly ?BoardItem $created_item = null,
    ) {}

    public static function success(string $message, bool $stops_chain = false, ?BoardItem $created_item = null): self
    {
        return new self(BoardAutomationRunLog::STATUS_SUCCESS, $message, $stops_chain, $created_item);
    }

    public static function skipped(string $message): self
    {
        return new self(BoardAutomationRunLog::STATUS_SKIPPED, $message);
    }

    public static function failed(string $message): self
    {
        return new self(BoardAutomationRunLog::STATUS_FAILED, $message);
    }
}
