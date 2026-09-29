<?php

namespace App\Services\Board;

use App\Models\BoardAutomation;

/**
 * What is running right now inside {@see BoardAutomationService}, shared for one request or job.
 *
 * - The automation whose action is writing, so {@see BoardItemActivityService} can credit a cell
 *   change to "Automation: <name>" instead of the person who set it off.
 * - Whether this is a test run ("Test run on an item"): every change is made inside a database
 *   transaction that is rolled back afterwards, and nothing leaves the app (no notification, email,
 *   Slack message or webhook), the actions only say what they would have done.
 * - The run id of the innermost running automation, so {@see BoardAutomationRunJournal} can file
 *   every change it makes under that run for "Undo".
 */
class AutomationRunContext
{
    /** @var array<int, BoardAutomation> */
    private array $stack = [];

    /** @var array<int, string|null> the run id of each entry of `$stack`, null until its branch starts */
    private array $run_uuids = [];

    private bool $is_dry_run = false;

    public function enter(BoardAutomation $automation): void
    {
        $this->stack[] = $automation;
        $this->run_uuids[] = null;
    }

    public function leave(): void
    {
        array_pop($this->stack);
        array_pop($this->run_uuids);
    }

    /**
     * Files the changes the innermost automation makes from now on under `$run_uuid`.
     */
    public function bindRun(string $run_uuid): void
    {
        if ($this->run_uuids !== []) {
            $this->run_uuids[array_key_last($this->run_uuids)] = $run_uuid;
        }
    }

    /**
     * The run id of the innermost running automation, null outside a run.
     */
    public function currentRunUuid(): ?string
    {
        return $this->run_uuids === [] ? null : $this->run_uuids[array_key_last($this->run_uuids)];
    }

    /**
     * The automation running the current action, the innermost one of a chain.
     */
    public function current(): ?BoardAutomation
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    public function isDryRun(): bool
    {
        return $this->is_dry_run;
    }

    public function setDryRun(bool $is_dry_run): void
    {
        $this->is_dry_run = $is_dry_run;
    }
}
